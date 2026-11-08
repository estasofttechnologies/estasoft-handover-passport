<?php
/**
 * People directory and roles (PPL-1 to PPL-4, PRV-7).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Services;

use Estasoft\HandoverPassport\Changes\ChangeLog;
use Estasoft\HandoverPassport\Data\LinkRepository;
use Estasoft\HandoverPassport\Data\PeopleRepository;
use Estasoft\HandoverPassport\Domain\Fields;
use Estasoft\HandoverPassport\Domain\Freshness;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Security\SecretGuard;
use Estasoft\HandoverPassport\Settings\Settings;
use Estasoft\HandoverPassport\Support\Clock;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Saves people and the roles they hold.
 */
class PeopleService {

	public const PASSPORT_ID = 1;

	/**
	 * The name that replaces a person after a personal-data erase (PPL-5). Not translated: stored text must not
	 * change with the language of whoever processes the request.
	 */
	public const FORMER_CONTACT = 'Former contact';

	/**
	 * Constructor.
	 *
	 * @param PeopleRepository $people   People.
	 * @param LinkRepository   $links    Links.
	 * @param SecretGuard      $guard    Secret guard.
	 * @param ChangeLog        $log      Change log.
	 * @param Settings         $settings Settings.
	 */
	public function __construct(
		private PeopleRepository $people,
		private LinkRepository $links,
		private SecretGuard $guard,
		private ChangeLog $log,
		private Settings $settings
	) {}

	/**
	 * Roles a person can hold (PPL-2).
	 *
	 * @return array<string, string>
	 */
	public static function roles(): array {
		return array(
			'site_owner'           => __( 'Site owner', 'estasoft-handover-passport' ),
			'day_to_day_admin'     => __( 'Day-to-day admin', 'estasoft-handover-passport' ),
			'technical_maintainer' => __( 'Technical maintainer', 'estasoft-handover-passport' ),
			'billing_contact'      => __( 'Billing contact', 'estasoft-handover-passport' ),
			'emergency_contact'    => __( 'Emergency contact', 'estasoft-handover-passport' ),
			'content_owner'        => __( 'Content owner', 'estasoft-handover-passport' ),
			'developer_of_record'  => __( 'Developer of record', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Kinds of person (PPL-1).
	 *
	 * @return array<string, string>
	 */
	public static function kinds(): array {
		return array(
			'client'       => __( 'Client', 'estasoft-handover-passport' ),
			'agency'       => __( 'Agency', 'estasoft-handover-passport' ),
			'host_support' => __( 'Host support', 'estasoft-handover-passport' ),
			'vendor'       => __( 'Vendor', 'estasoft-handover-passport' ),
			'freelancer'   => __( 'Freelancer', 'estasoft-handover-passport' ),
			'other'        => __( 'Other', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Field definitions, in the same shape as record kinds so forms and the API can share code.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			'name'          => array( 'type' => 'text', 'label' => __( 'Name', 'estasoft-handover-passport' ), 'required' => true, 'max' => 190 ),
			'organisation'  => array( 'type' => 'text', 'label' => __( 'Organisation', 'estasoft-handover-passport' ), 'max' => 190 ),
			'kind'          => array( 'type' => 'select', 'label' => __( 'Kind', 'estasoft-handover-passport' ), 'options' => self::kinds() ),
			'title'         => array( 'type' => 'text', 'label' => __( 'Title', 'estasoft-handover-passport' ), 'max' => 190 ),
			'email'         => array( 'type' => 'email', 'label' => __( 'Email', 'estasoft-handover-passport' ) ),
			'phone'         => array( 'type' => 'text', 'label' => __( 'Phone', 'estasoft-handover-passport' ), 'max' => 60 ),
			'language'      => array( 'type' => 'text', 'label' => __( 'Language', 'estasoft-handover-passport' ), 'max' => 20 ),
			'timezone'      => array( 'type' => 'text', 'label' => __( 'Time zone', 'estasoft-handover-passport' ), 'max' => 60 ),
			'urgent_method' => array( 'type' => 'text', 'label' => __( 'How to reach them urgently', 'estasoft-handover-passport' ), 'max' => 255 ),
			'left_on'       => array( 'type' => 'date', 'label' => __( 'Left on', 'estasoft-handover-passport' ), 'help' => __( 'Fill this in when the person no longer works on the site.', 'estasoft-handover-passport' ) ),
			'note'          => array( 'type' => 'textarea', 'label' => __( 'Note', 'estasoft-handover-passport' ), 'max' => 1000 ),
		);
	}

	/**
	 * Default sharing flags: name and role yes, email and phone no (PPL-1, PRV-7).
	 *
	 * @return array<string, bool>
	 */
	public static function defaultShare(): array {
		return array( 'name' => true, 'role' => true, 'email' => false, 'phone' => false );
	}

	/**
	 * Create a person.
	 *
	 * @param array<string, mixed> $input   Raw input, including optional "roles" (list of role keys) and "share" flags.
	 * @param string               $channel Origin for the log.
	 * @return array<string, mixed> Hydrated person.
	 * @throws ValidationError When invalid.
	 * @throws RuntimeException When the person cannot be stored.
	 */
	public function create( array $input, string $channel = 'screen' ): array {
		$clean = $this->clean( $input, null );
		$now   = Clock::sql();
		$id    = $this->people->insert( $clean + array( 'created_at' => $now, 'updated_at' => $now, 'verified_at' => null, 'verified_by' => 0, 'deleted_at' => null ) );
		if ( 0 === $id ) {
			throw new RuntimeException( 'estasoft_hp_db_insert' );
		}
		if ( isset( $input['roles'] ) ) {
			$this->setRolesFor( $id, (array) $input['roles'] );
		}
		$this->log->add( 'person_added', 'person', $id, (string) $clean['name'], array(), array(), '', $channel );
		do_action( 'estasoft_hp_record_saved', $id, 'person', array(), array( 'id' => $id, 'name' => $clean['name'] ) );
		return $this->get( $id );
	}

	/**
	 * Update a person.
	 *
	 * @param int                  $id      Person ID.
	 * @param array<string, mixed> $input   Raw input (only the fields to change).
	 * @param string               $channel Origin for the log.
	 * @return array<string, mixed> Hydrated person.
	 * @throws ValidationError When invalid or missing.
	 */
	public function update( int $id, array $input, string $channel = 'screen' ): array {
		$row = $this->people->find( $id );
		if ( null === $row || null !== $row['deleted_at'] ) {
			throw new ValidationError( __( 'Person', 'estasoft-handover-passport' ), __( 'That person no longer exists.', 'estasoft-handover-passport' ) );
		}
		$clean = $this->clean( $input, $row );
		$this->people->update( $id, $clean + array( 'updated_at' => Clock::sql() ) );
		if ( isset( $input['roles'] ) ) {
			$this->setRolesFor( $id, (array) $input['roles'] );
		}
		$left_now = empty( $row['left_on'] ) && ! empty( $clean['left_on'] );
		$this->log->add( $left_now ? 'person_left' : 'person_edited', 'person', $id, (string) $clean['name'], array(), array(), '', $channel );
		do_action( 'estasoft_hp_record_saved', $id, 'person', array(), array( 'id' => $id, 'name' => $clean['name'] ) );
		return $this->get( $id );
	}

	/**
	 * Delete a person: kept 30 days with Undo (PRT-9).
	 *
	 * @param int    $id      Person ID.
	 * @param string $channel Origin for the log.
	 */
	public function delete( int $id, string $channel = 'wordpress' ): void {
		$row = $this->people->find( $id );
		if ( null === $row ) {
			return;
		}
		$this->people->softDelete( $id );
		$this->log->add( 'person_deleted', 'person', $id, (string) $row['name'], source: $channel );
		do_action( 'estasoft_hp_record_saved', $id, 'person', array(), array() );
	}

	/**
	 * Replace a person's details with "Former contact" and keep their place in the roles and on the records, so a role
	 * still shows that someone held it (PPL-5). Used by the WordPress personal-data eraser. The change log is not
	 * written here; the caller records one entry for the whole request.
	 *
	 * @param int $id Person ID.
	 * @return bool Whether the row changed.
	 */
	public function anonymise( int $id ): bool {
		$row = $this->people->find( $id );
		if ( null === $row ) {
			return false;
		}
		$changed = $this->people->update(
			$id,
			array(
				'name'          => self::FORMER_CONTACT,
				'organisation'  => '',
				'title'         => '',
				'email'         => '',
				'phone'         => '',
				'language'      => '',
				'timezone'      => '',
				'urgent_method' => '',
				'note'          => '',
				'left_on'       => ! empty( $row['left_on'] ) ? $row['left_on'] : Clock::today(),
				'active'        => 0,
				'updated_at'    => Clock::sql(),
			)
		);
		if ( $changed ) {
			do_action( 'estasoft_hp_record_saved', $id, 'person', array(), array() );
		}
		return $changed;
	}

	/**
	 * Bring back a deleted person.
	 *
	 * @param int $id Person ID.
	 */
	public function undelete( int $id ): void {
		$row = $this->people->find( $id );
		if ( null === $row || null === $row['deleted_at'] ) {
			return;
		}
		$this->people->restore( $id );
		$this->log->add( 'person_restored', 'person', $id, (string) $row['name'] );
		do_action( 'estasoft_hp_record_saved', $id, 'person', array(), array() );
	}

	/**
	 * Verify a person's record.
	 *
	 * @param int    $id      Person ID.
	 * @param string $channel Origin for the log.
	 */
	public function verify( int $id, string $channel = 'wordpress' ): void {
		$row = $this->people->find( $id );
		if ( null === $row || null !== $row['deleted_at'] ) {
			return;
		}
		$this->people->update( $id, array( 'verified_at' => Clock::sql(), 'verified_by' => get_current_user_id() ) );
		$this->log->add( 'record_verified', 'person', $id, (string) $row['name'], source: $channel );
	}

	/**
	 * One person with roles and freshness, or null.
	 *
	 * @param int $id Person ID.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->people->find( $id );
		return ( null === $row || null !== $row['deleted_at'] ) ? null : $this->hydrate( $row, $this->roleMap() );
	}

	/**
	 * Every live person.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$map = $this->roleMap();
		return array_map( fn( array $row ): array => $this->hydrate( $row, $map ), $this->people->allLive() );
	}

	/**
	 * People holding a role, in order (the order matters for emergency contacts, PPL-3).
	 *
	 * @param string $role Role key.
	 * @return array<int, int>
	 */
	public function inRole( string $role ): array {
		return $this->links->people( LinkRepository::ROLE_PREFIX . $role, 'passport', self::PASSPORT_ID );
	}

	/**
	 * Replace the people who hold one role.
	 *
	 * @param string          $role       Role key.
	 * @param array<int, int> $person_ids Ordered person IDs.
	 */
	public function setRole( string $role, array $person_ids ): void {
		if ( ! array_key_exists( $role, self::roles() ) ) {
			return;
		}
		$this->links->setPeople( LinkRepository::ROLE_PREFIX . $role, 'passport', self::PASSPORT_ID, $person_ids );
		$this->log->add( 'role_changed', 'passport', self::PASSPORT_ID, self::roles()[ $role ] );
		do_action( 'estasoft_hp_record_saved', self::PASSPORT_ID, 'role', array(), array() );
	}

	/**
	 * Assets a person owns: account holder, payer or administrator.
	 *
	 * @param int $person_id Person ID.
	 * @return array<int, array{item_id: int, relation: string}>
	 */
	public function assetsFor( int $person_id ): array {
		$out = array();
		foreach ( $this->links->forPerson( $person_id ) as $link ) {
			if ( 'item' === $link['to_type'] && in_array( $link['relation'], array( LinkRepository::HOLDER, LinkRepository::PAYER, LinkRepository::ADMIN, LinkRepository::ACCESS ), true ) ) {
				$out[] = array( 'item_id' => (int) $link['to_id'], 'relation' => (string) $link['relation'] );
			}
		}
		return $out;
	}

	/**
	 * Underlying repository.
	 */
	public function repo(): PeopleRepository {
		return $this->people;
	}

	/**
	 * Validate and shape a person's columns.
	 *
	 * @param array<string, mixed>      $input    Raw input.
	 * @param array<string, mixed>|null $existing Stored row on update.
	 * @return array<string, mixed>
	 * @throws ValidationError When invalid.
	 * @throws \Estasoft\HandoverPassport\Security\SecretFound When a value looks like a secret.
	 */
	private function clean( array $input, ?array $existing ): array {
		$row = array();
		foreach ( self::fields() as $key => $field ) {
			if ( array_key_exists( $key, $input ) ) {
				$value = Fields::clean( $field, $input[ $key ] );
			} elseif ( null !== $existing ) {
				$value = (string) ( $existing[ $key ] ?? '' );
			} else {
				$value = '';
			}
			if ( ! empty( $field['required'] ) && '' === $value ) {
				throw new ValidationError( (string) $field['label'], __( 'This field is required.', 'estasoft-handover-passport' ) );
			}
			$this->guard->assertClean( $value, (string) $field['label'] );
			$row[ $key ] = 'left_on' === $key && '' === $value ? null : $value;
		}
		if ( '' === ( $row['kind'] ?? '' ) ) {
			$row['kind'] = 'other';
		}
		$row['active'] = empty( $row['left_on'] ) ? 1 : 0;

		$share = $existing['share_flags'] ?? self::defaultShare();
		if ( isset( $input['share'] ) && is_array( $input['share'] ) ) {
			foreach ( array_keys( self::defaultShare() ) as $flag ) {
				$share[ $flag ] = ! empty( $input['share'][ $flag ] );
			}
		}
		$row['share_flags'] = $share + self::defaultShare();

		if ( array_key_exists( 'review_days', $input ) ) {
			$days               = (int) $input['review_days'];
			$row['review_days'] = $days > 0 ? min( $days, 3650 ) : null;
		}
		return $row;
	}

	/**
	 * Replace all roles of one person.
	 *
	 * @param int          $person_id Person ID.
	 * @param array<mixed> $roles     Role keys.
	 */
	private function setRolesFor( int $person_id, array $roles ): void {
		$wanted = array_intersect( array_keys( self::roles() ), array_map( 'sanitize_key', array_map( 'strval', $roles ) ) );
		foreach ( array_keys( self::roles() ) as $role ) {
			$current = $this->inRole( $role );
			$has     = in_array( $person_id, $current, true );
			if ( in_array( $role, $wanted, true ) && ! $has ) {
				$current[] = $person_id;
				$this->links->setPeople( LinkRepository::ROLE_PREFIX . $role, 'passport', self::PASSPORT_ID, $current );
			} elseif ( ! in_array( $role, $wanted, true ) && $has ) {
				$this->links->setPeople( LinkRepository::ROLE_PREFIX . $role, 'passport', self::PASSPORT_ID, array_values( array_diff( $current, array( $person_id ) ) ) );
			}
		}
	}

	/**
	 * Role keys by person ID.
	 *
	 * @return array<int, array<int, string>>
	 */
	private function roleMap(): array {
		$map = array();
		foreach ( array_keys( self::roles() ) as $role ) {
			foreach ( $this->inRole( $role ) as $person_id ) {
				$map[ $person_id ][] = $role;
			}
		}
		return $map;
	}

	/**
	 * Add roles and freshness to a stored row.
	 *
	 * @param array<string, mixed>           $row Stored row.
	 * @param array<int, array<int, string>> $map Role keys by person ID.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row, array $map ): array {
		$interval           = (int) ( ! empty( $row['review_days'] ) ? $row['review_days'] : $this->settings->reviewDays( 'people' ) );
		$row['roles']       = $map[ (int) $row['id'] ] ?? array();
		$row['freshness']   = Freshness::state(
			$row['verified_at'] ? (string) $row['verified_at'] : null,
			(string) $row['created_at'],
			$interval,
			(int) $this->settings->get( 'due_days', 14 ),
			Clock::sql()
		);
		$row['review_days'] = $interval;
		$row['share_flags'] = (array) $row['share_flags'] + self::defaultShare();
		return $row;
	}
}
