<?php
/**
 * Register records: validate, guard, store, log (PRT-2 to PRT-9, ACC-3, SEC-5, SEC-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Services;

use Estasoft\HandoverPassport\Changes\ChangeLog;
use Estasoft\HandoverPassport\Data\ItemRepository;
use Estasoft\HandoverPassport\Data\LinkRepository;
use Estasoft\HandoverPassport\Domain\Fields;
use Estasoft\HandoverPassport\Domain\Freshness;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\LeadTimes;
use Estasoft\HandoverPassport\Domain\Sensitivity;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Security\SecretGuard;
use Estasoft\HandoverPassport\Settings\Settings;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Dates;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Every route in (forms, REST, WP-CLI, import) saves records through this class.
 */
class RecordService {

	/**
	 * Cached map of links by target, cleared on every write.
	 *
	 * @var array<string, array<int, array<string, list<int>>>>|null
	 */
	private ?array $owner_index = null;

	/**
	 * Constructor.
	 *
	 * @param ItemRepository $items    Items.
	 * @param LinkRepository $links    Links.
	 * @param SecretGuard    $guard    Secret guard.
	 * @param ChangeLog      $log      Change log.
	 * @param Settings       $settings Settings.
	 */
	public function __construct(
		private ItemRepository $items,
		private LinkRepository $links,
		private SecretGuard $guard,
		private ChangeLog $log,
		private Settings $settings
	) {}

	/**
	 * Create a record.
	 *
	 * @param string               $kind    Kind key.
	 * @param array<string, mixed> $input   Raw input.
	 * @param string               $source  "entered" or "imported".
	 * @param string               $channel Where it came from, for the log ("screen", "rest", "cli", "import").
	 * @return array<string, mixed> Hydrated record.
	 * @throws ValidationError            When a value is invalid.
	 * @throws \Estasoft\HandoverPassport\Security\SecretFound When a value looks like a secret.
	 */
	public function create( string $kind, array $input, string $source = 'entered', string $channel = 'screen' ): array {
		$def = $this->definition( $kind, true );
		$n   = $this->normalise( $def, $input, null );

		$now = Clock::sql();
		$id  = $this->items->insert(
			$n['row'] + array(
				'kind'        => $kind,
				'source'      => $source,
				'created_at'  => $now,
				'created_by'  => get_current_user_id(),
				'updated_at'  => $now,
				'verified_at' => null,
				'verified_by' => 0,
				'deleted_at'  => null,
			)
		);
		if ( 0 === $id ) {
			throw new RuntimeException( 'estasoft_hp_db_insert' );
		}
		$this->saveLinks( $id, $n['links'] );

		$record = $this->get( $id, true );
		$this->log->add( 'record_added', 'item', $id, (string) $record['title'], array(), $this->logFields( $kind, (array) $record['fields'] ), '', $channel, sensitive: (bool) $record['sensitive'] );
		do_action( 'estasoft_hp_record_saved', $id, $kind, array(), $this->forHook( $record ) );
		return $record;
	}

	/**
	 * Update a record.
	 *
	 * @param int                  $id      Record ID.
	 * @param array<string, mixed> $input   Raw input (only the fields to change).
	 * @param string               $channel Origin for the log.
	 * @return array<string, mixed> Hydrated record.
	 * @throws ValidationError When invalid or the record is missing.
	 */
	public function update( int $id, array $input, string $channel = 'screen' ): array {
		$row = $this->items->find( $id );
		if ( null === $row || null !== $row['deleted_at'] ) {
			throw new ValidationError( __( 'Record', 'estasoft-handover-passport' ), __( 'That record no longer exists.', 'estasoft-handover-passport' ) );
		}
		$before = $this->hydrate( $row );
		$def    = $this->definition( (string) $row['kind'], false );
		$n      = $this->normalise( $def, $input, $before );

		$this->items->update( $id, $n['row'] + array( 'updated_at' => Clock::sql() ) );
		$this->saveLinks( $id, $n['links'] );

		$after = $this->get( $id, true );
		$this->log->add( 'record_edited', 'item', $id, (string) $after['title'], $this->diff( (string) $row['kind'], (array) $before['fields'], (array) $after['fields'], true ), $this->diff( (string) $row['kind'], (array) $before['fields'], (array) $after['fields'], false ), '', $channel, sensitive: (bool) $before['sensitive'] || (bool) $after['sensitive'] );
		do_action( 'estasoft_hp_record_saved', $id, (string) $row['kind'], $this->forHook( $before ), $this->forHook( $after ) );
		return $after;
	}

	/**
	 * Delete a record: hidden for 30 days with Undo (PRT-9). A Detected record is never deleted.
	 *
	 * @param int    $id      Record ID.
	 * @param string $channel Origin for the log.
	 * @throws ValidationError When the record is Detected or missing.
	 */
	public function delete( int $id, string $channel = 'wordpress' ): void {
		$row = $this->items->find( $id );
		if ( null === $row ) {
			return;
		}
		if ( 'detected' === $row['source'] ) {
			throw new ValidationError( __( 'Record', 'estasoft-handover-passport' ), __( 'A detected item cannot be deleted. Hide it with a reason instead.', 'estasoft-handover-passport' ) );
		}
		$this->items->softDelete( $id );
		$this->log->add( 'record_deleted', 'item', $id, (string) $row['title'], source: $channel, sensitive: (bool) $row['sensitive'] );
		do_action( 'estasoft_hp_record_saved', $id, (string) $row['kind'], $this->forHook( $this->hydrate( $row ) ), array() );
	}

	/**
	 * Bring a deleted record back.
	 *
	 * @param int $id Record ID.
	 */
	public function undelete( int $id ): void {
		$row = $this->items->find( $id );
		if ( null === $row || null === $row['deleted_at'] ) {
			return;
		}
		$this->items->restore( $id );
		$this->log->add( 'record_restored', 'item', $id, (string) $row['title'], sensitive: (bool) $row['sensitive'] );
		do_action( 'estasoft_hp_record_saved', $id, (string) $row['kind'], array(), $this->forHook( $this->hydrate( $row ) ) );
	}

	/**
	 * Verify a record: stores the date and name, restarts freshness (PRT-3).
	 *
	 * @param int    $id      Record ID.
	 * @param string $channel Origin for the log.
	 */
	public function verify( int $id, string $channel = 'wordpress' ): void {
		$row = $this->items->find( $id );
		if ( null === $row || null !== $row['deleted_at'] ) {
			return;
		}
		$this->items->update( $id, array( 'verified_at' => Clock::sql(), 'verified_by' => get_current_user_id() ) );
		$this->log->add( 'record_verified', 'item', $id, (string) $row['title'], source: $channel, sensitive: (bool) $row['sensitive'] );
		do_action( 'estasoft_hp_record_saved', $id, (string) $row['kind'], array(), $this->forHook( $this->hydrate( $row ) ) );
	}

	/**
	 * Renewed: enter the next date, which starts a new reminder cycle (LIC-7, RMD-4).
	 *
	 * @param int    $id       Record ID.
	 * @param string $new_date The next renewal or expiry date, Y-m-d, later than the current one.
	 * @param string $channel  Origin for the log.
	 * @return array<string, mixed> Hydrated record.
	 * @throws ValidationError When the record has no date to renew or the new date is not acceptable.
	 */
	public function renew( int $id, string $new_date, string $channel = 'screen' ): array {
		$row = $this->items->find( $id );
		if ( null === $row || null !== $row['deleted_at'] ) {
			throw new ValidationError( __( 'Record', 'estasoft-handover-passport' ), __( 'That record no longer exists.', 'estasoft-handover-passport' ) );
		}
		$def   = Kinds::get( (string) $row['kind'] );
		$field = (string) ( $def['due'] ?? '' );
		if ( '' === $field ) {
			throw new ValidationError( __( 'Record', 'estasoft-handover-passport' ), __( 'This kind of record has no renewal date.', 'estasoft-handover-passport' ) );
		}
		$new_date = trim( $new_date );
		if ( ! Dates::isDate( $new_date ) ) {
			throw new ValidationError( __( 'New date', 'estasoft-handover-passport' ), __( 'Use a real date in the form YYYY-MM-DD.', 'estasoft-handover-passport' ) );
		}
		$data = is_array( $row['data'] ) ? $row['data'] : array();
		$old  = (string) ( $data[ $field ] ?? '' );
		if ( '' !== $old && $new_date <= $old ) {
			throw new ValidationError( __( 'New date', 'estasoft-handover-passport' ), __( 'The new date must be later than the current one.', 'estasoft-handover-passport' ) );
		}

		$before          = $this->hydrate( $row );
		$data[ $field ] = $new_date;
		$this->items->update( $id, array( 'data' => $data, 'due_date' => $new_date, 'updated_at' => Clock::sql() ) );
		$this->log->add( 'record_renewed', 'item', $id, (string) $row['title'], array( 'value' => $old ), array( 'value' => $new_date ), '', $channel, sensitive: (bool) $row['sensitive'] );

		$after = $this->get( $id, true );
		do_action( 'estasoft_hp_record_saved', $id, (string) $row['kind'], $this->forHook( $before ), $this->forHook( (array) $after ) );
		return (array) $after;
	}

	/**
	 * Fetch one record for a viewer.
	 *
	 * @param int  $id       Record ID.
	 * @param bool $can_view Whether the viewer may see Sensitive values.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id, bool $can_view ): ?array {
		$row = $this->items->find( $id );
		if ( null === $row || null !== $row['deleted_at'] ) {
			return null;
		}
		return Sensitivity::forViewer( $this->hydrate( $row ), $can_view );
	}

	/**
	 * Live records of a kind for a viewer, sorted by title.
	 *
	 * @param string $kind     Kind key.
	 * @param bool   $can_view Whether the viewer may see Sensitive values.
	 * @return list<array<string, mixed>>
	 */
	public function listKind( string $kind, bool $can_view ): array {
		$out = array();
		foreach ( $this->items->ofKind( $kind ) as $row ) {
			$out[] = Sensitivity::forViewer( $this->hydrate( $row ), $can_view );
		}
		return $out;
	}

	/**
	 * Every live record, unredacted. For rules and exports, which apply their own redaction.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function everything(): array {
		return array_map( fn( array $row ): array => $this->hydrate( $row ), $this->items->allLive() );
	}

	/**
	 * Add computed fields to a stored row.
	 *
	 * @param array<string, mixed> $row Stored row.
	 * @return array<string, mixed>
	 */
	public function hydrate( array $row ): array {
		$kind   = (string) $row['kind'];
		$def    = Kinds::get( $kind ) ?? array( 'fields' => array(), 'section' => 'other' );
		$data   = is_array( $row['data'] ) ? $row['data'] : array();
		$fields = array();
		$owners = array();
		$id     = (int) $row['id'];

		foreach ( (array) $def['fields'] as $key => $field ) {
			if ( 'people' === $field['type'] ) {
				$owners[ $key ] = $this->ownerIndex()[ $id ][ (string) $field['relation'] ] ?? array();
				continue;
			}
			if ( ! empty( $field['column'] ) ) {
				$fields[ $key ] = (string) $row[ $field['column'] ];
				continue;
			}
			$fields[ $key ] = $data[ $key ] ?? ( 'lines' === $field['type'] || 'links' === $field['type'] ? array() : ( $field['default'] ?? '' ) );
		}
		// Keep system data that has no schema (DNS snapshots, checklist states, URL check results).
		foreach ( $data as $key => $value ) {
			if ( ! array_key_exists( $key, $fields ) && ! array_key_exists( $key, $owners ) ) {
				$fields[ $key ] = $value;
			}
		}

		$interval  = (int) ( $row['review_days'] ?: $this->settings->reviewDays( (string) $def['section'] ) );
		$freshness = Freshness::state(
			$row['verified_at'] ? (string) $row['verified_at'] : null,
			(string) $row['created_at'],
			$interval,
			(int) $this->settings->get( 'due_days', 14 ),
			Clock::sql()
		);

		return array(
			'id'            => $id,
			'kind'          => $kind,
			'title'         => (string) $row['title'],
			'subject_key'   => (string) $row['subject_key'],
			'source'        => (string) $row['source'],
			'sensitive'     => (bool) $row['sensitive'],
			'vis_client'    => (bool) $row['vis_client'],
			'vis_emergency' => (bool) $row['vis_emergency'],
			'fields'        => $fields,
			'owners'        => $owners,
			'due_date'      => $row['due_date'] ? (string) $row['due_date'] : '',
			'status'        => (string) $row['status'],
			'legal_owner'   => (string) $row['legal_owner'],
			'review_days'   => $interval,
			'verified_at'   => $row['verified_at'] ? (string) $row['verified_at'] : '',
			'verified_by'   => (int) $row['verified_by'],
			'freshness'     => $freshness,
			'created_at'    => (string) $row['created_at'],
			'updated_at'    => (string) $row['updated_at'],
			'hidden'        => false,
		);
	}

	/**
	 * Save a system record (DNS snapshot, certificate check, handover state) that has no form.
	 * The secret guard runs unless the values were measured by the plugin from public sources, such as DNS text
	 * records, which can hold long keys and tokens that are not secrets.
	 *
	 * @param string               $kind    Kind key.
	 * @param string               $subject Subject key.
	 * @param string               $title   Title.
	 * @param array<string, mixed> $data    Data map.
	 * @param array<string, mixed> $columns Extra columns (status, due_date).
	 * @param bool                 $measured Whether the data was measured, not typed by a person.
	 * @return int Record ID.
	 */
	public function saveSystem( string $kind, string $subject, string $title, array $data, array $columns = array(), bool $measured = false ): int {
		if ( ! $measured ) {
			$this->guard->assertClean( $data, $title );
		}
		$existing = '' === $subject ? null : $this->items->bySubject( $kind, $subject );
		$row      = array( 'title' => mb_substr( $title, 0, 255 ), 'subject_key' => mb_substr( $subject, 0, 191 ), 'data' => $data, 'updated_at' => Clock::sql() ) + $columns;
		if ( null !== $existing ) {
			$this->items->update( (int) $existing['id'], $row );
			$this->owner_index = null;
			return (int) $existing['id'];
		}
		$def = Kinds::get( $kind );
		return $this->items->insert(
			$row + array(
				'kind'          => $kind,
				'source'        => 'entered',
				'vis_client'    => (int) ( $def['vis'][0] ?? 0 ),
				'vis_emergency' => (int) ( $def['vis'][1] ?? 0 ),
				'created_at'    => Clock::sql(),
				'created_by'    => get_current_user_id(),
			)
		);
	}

	/**
	 * Underlying item repository.
	 */
	public function items(): ItemRepository {
		return $this->items;
	}

	/**
	 * Underlying link repository.
	 */
	public function links(): LinkRepository {
		return $this->links;
	}

	/**
	 * Forget the cached link map.
	 */
	public function flush(): void {
		$this->owner_index = null;
	}

	/**
	 * Validate the input and produce the row and links to store.
	 *
	 * @param array<string, mixed>      $def      Kind schema.
	 * @param array<string, mixed>      $input    Raw input.
	 * @param array<string, mixed>|null $existing Hydrated existing record, or null on create.
	 * @return array{row: array<string, mixed>, links: array<string, list<int>>}
	 * @throws ValidationError When invalid.
	 * @throws \Estasoft\HandoverPassport\Security\SecretFound When a value looks like a secret.
	 */
	private function normalise( array $def, array $input, ?array $existing ): array {
		$data    = array();
		$columns = array();
		$links   = array();
		$locked  = null !== $existing && 'detected' === $existing['source'] ? (string) ( $def['subject'] ?? '' ) : '';

		foreach ( (array) $def['fields'] as $key => $field ) {
			$present = array_key_exists( $key, $input );
			if ( 'people' === $field['type'] ) {
				if ( $present ) {
					$links[ (string) $field['relation'] ] = Fields::clean( $field, $input[ $key ] );
				}
				continue;
			}
			if ( $present && $key !== $locked ) {
				$value = Fields::clean( $field, $input[ $key ] );
			} elseif ( null !== $existing ) {
				$value = $existing['fields'][ $key ] ?? '';
			} else {
				$value = $field['default'] ?? ( 'lines' === $field['type'] || 'links' === $field['type'] ? array() : '' );
			}
			if ( ! empty( $field['required'] ) && ( '' === $value || array() === $value ) ) {
				throw new ValidationError( (string) $field['label'], __( 'This field is required.', 'estasoft-handover-passport' ) );
			}
			// The secret guard checks the cleaned value under the field's label (ACC-3, SEC-6).
			$this->guard->assertClean( $value, (string) $field['label'] );

			if ( ! empty( $field['column'] ) ) {
				$columns[ (string) $field['column'] ] = (string) $value;
			} else {
				$data[ $key ] = $value;
			}
		}

		// A record with a date can have its own reminder days; empty uses the Settings list, "off" sends none (RMD-1).
		if ( ! empty( $def['due'] ) && array_key_exists( 'reminder_days', $input ) ) {
			$setting = LeadTimes::recordSetting( is_scalar( $input['reminder_days'] ) ? (string) $input['reminder_days'] : '' );
			if ( null === $setting ) {
				throw new ValidationError( __( 'Remind me', 'estasoft-handover-passport' ), __( 'Use whole numbers of days separated by commas, for example 30, 7, 0. Write off to send no reminders for this record.', 'estasoft-handover-passport' ) );
			}
			$data['reminder_days'] = $setting;
		}

		// Keep what the plugin itself stored on the record, such as the last result of a check.
		if ( null !== $existing ) {
			foreach ( (array) $existing['fields'] as $key => $value ) {
				if ( ! array_key_exists( $key, (array) $def['fields'] ) && ! array_key_exists( $key, $data ) ) {
					$data[ $key ] = $value;
				}
			}
		}

		$title_field = (string) $def['title'];
		$title       = $this->fieldLabel( $def, $title_field, $data, $columns );
		$subject     = '';
		if ( ! empty( $def['subject'] ) ) {
			$subject = strtolower( (string) ( $data[ (string) $def['subject'] ] ?? '' ) );
		}
		$due = '';
		if ( ! empty( $def['due'] ) ) {
			$due = (string) ( $data[ (string) $def['due'] ] ?? '' );
		} elseif ( ! empty( $def['computed_due'] ) ) {
			$base = (string) ( $data[ (string) $def['computed_due']['field'] ] ?? '' );
			$days = (int) $this->settings->get( (string) $def['computed_due']['days'], 90 );
			$due  = Dates::isDate( $base ) ? Dates::addDays( $base, $days ) : '';
		}

		$row = array(
			'title'       => mb_substr( $title, 0, 255 ),
			'subject_key' => mb_substr( $subject, 0, 191 ),
			'data'        => $data,
			'due_date'    => '' === $due ? null : $due,
		) + $columns;

		$flag = static fn( string $key, int $default ): int => array_key_exists( $key, $input ) ? (int) ( ! empty( $input[ $key ] ) && '0' !== $input[ $key ] ) : ( $existing ? (int) $existing[ $key ] : $default );
		$row['vis_client']    = $flag( 'vis_client', (int) ( $def['vis'][0] ?? 0 ) );
		$row['vis_emergency'] = $flag( 'vis_emergency', (int) ( $def['vis'][1] ?? 0 ) );
		$row['sensitive']     = $flag( 'sensitive', 0 );

		if ( array_key_exists( 'review_days', $input ) ) {
			$days                = (int) $input['review_days'];
			$row['review_days'] = $days > 0 ? min( $days, 3650 ) : null;
		}
		return array( 'row' => $row, 'links' => $links );
	}

	/**
	 * The title text: a select's label rather than its key.
	 *
	 * @param array<string, mixed> $def     Kind schema.
	 * @param string               $field   Title field key.
	 * @param array<string, mixed> $data    Cleaned data.
	 * @param array<string, mixed> $columns Cleaned column values.
	 */
	private function fieldLabel( array $def, string $field, array $data, array $columns ): string {
		$value = (string) ( $data[ $field ] ?? $columns[ $field ] ?? '' );
		$spec  = $def['fields'][ $field ] ?? array();
		if ( 'select' === ( $spec['type'] ?? '' ) && isset( $spec['options'][ $value ] ) ) {
			return (string) $spec['options'][ $value ];
		}
		return $value;
	}

	/**
	 * Write the ownership links of a record.
	 *
	 * @param int                      $id    Item ID.
	 * @param array<string, list<int>> $links Person IDs by relation.
	 */
	private function saveLinks( int $id, array $links ): void {
		foreach ( $links as $relation => $people ) {
			$this->links->setPeople( $relation, 'item', $id, $people );
		}
		$this->owner_index = null;
	}

	/**
	 * Person IDs by item and relation.
	 *
	 * @return array<int, array<string, list<int>>>
	 */
	private function ownerIndex(): array {
		if ( null === $this->owner_index ) {
			$this->owner_index = array();
			foreach ( $this->links->everything() as $link ) {
				if ( 'item' === $link['to_type'] && 'person' === $link['from_type'] ) {
					$this->owner_index[ (int) $link['to_id'] ][ (string) $link['relation'] ][] = (int) $link['from_id'];
				}
			}
		}
		return $this->owner_index;
	}

	/**
	 * Schema for a kind that people may write to.
	 *
	 * @param string $kind     Kind key.
	 * @param bool   $creating Whether a new record is being made.
	 * @return array<string, mixed>
	 * @throws ValidationError When the kind is unknown or system-only.
	 */
	private function definition( string $kind, bool $creating ): array {
		$def = Kinds::get( $kind );
		if ( null === $def || ! empty( $def['system'] ) ) {
			throw new ValidationError( __( 'Kind', 'estasoft-handover-passport' ), __( 'That kind of record does not exist.', 'estasoft-handover-passport' ) );
		}
		return $def;
	}

	/**
	 * Field values safe for the change log: Sensitive values removed.
	 *
	 * @param string               $kind   Kind key.
	 * @param array<string, mixed> $fields Field map.
	 * @return array<string, mixed>
	 */
	private function logFields( string $kind, array $fields ): array {
		foreach ( Sensitivity::fieldKeys( $kind ) as $key ) {
			if ( isset( $fields[ $key ] ) && '' !== $fields[ $key ] ) {
				$fields[ $key ] = '(Sensitive)';
			}
		}
		return array_filter( $fields, static fn( $value ): bool => '' !== $value && array() !== $value );
	}

	/**
	 * The fields that differ between two versions, for the log.
	 *
	 * @param string               $kind   Kind key.
	 * @param array<string, mixed> $before Old fields.
	 * @param array<string, mixed> $after  New fields.
	 * @param bool                 $old    Return the old values when true, the new when false.
	 * @return array<string, mixed>
	 */
	private function diff( string $kind, array $before, array $after, bool $old ): array {
		$sensitive = Sensitivity::fieldKeys( $kind );
		$out       = array();
		foreach ( $after as $key => $value ) {
			if ( ( $before[ $key ] ?? '' ) !== $value ) {
				$source        = $old ? ( $before[ $key ] ?? '' ) : $value;
				$out[ $key ] = in_array( $key, $sensitive, true ) ? '(Sensitive, changed)' : $source;
			}
		}
		return $out;
	}

	/**
	 * A copy for developer hooks without Sensitive values.
	 *
	 * @param array<string, mixed> $record Hydrated record.
	 * @return array<string, mixed>
	 */
	private function forHook( array $record ): array {
		return Sensitivity::forViewer( $record, false );
	}
}
