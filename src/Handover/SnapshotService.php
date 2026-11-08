<?php
/**
 * Handover snapshots: make, verify, compare, acknowledge and delete (HND-2 to HND-6, PRT-8).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Handover;

use Estasoft\HandoverPassport\Data\SnapshotRepository;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Export\ContentBuilder;
use Estasoft\HandoverPassport\Export\ExportDataLoader;
use Estasoft\HandoverPassport\Export\ExportOptions;
use Estasoft\HandoverPassport\Export\ExportRefused;
use Estasoft\HandoverPassport\Export\Profiles;
use Estasoft\HandoverPassport\Findings\SnapshotIntegrity;
use Estasoft\HandoverPassport\Passport\Passport;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Dates;
use Estasoft\HandoverPassport\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * A snapshot freezes the content of a pack at one moment. The content is stored as canonical JSON text and its SHA-256
 * hash is stored beside it. Nothing in the plugin changes either afterwards. The hash proves the stored text has not
 * changed since it was made; it does not prove who made it, because anyone with database access could change both.
 */
final class SnapshotService {

	/**
	 * Option that remembers how many records each snapshot holds. A snapshot never changes, so the count is counted once.
	 */
	public const COUNT_OPTION = 'estasoft_hp_snapshot_counts';

	/**
	 * Ways an acknowledgement can have been received (HND-4).
	 *
	 * @return array<string, string>
	 */
	public static function methods(): array {
		return array(
			'in_person' => __( 'In person', 'estasoft-handover-passport' ),
			'email'     => __( 'Email', 'estasoft-handover-passport' ),
			'phone'     => __( 'Phone', 'estasoft-handover-passport' ),
			'other'     => __( 'Other', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Constructor.
	 *
	 * @param Plugin             $plugin    Container.
	 * @param SnapshotRepository $snapshots Snapshots table.
	 */
	public function __construct( private Plugin $plugin, private SnapshotRepository $snapshots ) {}

	/**
	 * Snapshots, newest first, without their content.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		return $this->snapshots->listAll();
	}

	/**
	 * One snapshot with its content, or null.
	 *
	 * @param int $id Snapshot ID.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		return $this->snapshots->find( $id );
	}

	/**
	 * The content of a snapshot as an array, or null when it cannot be read.
	 *
	 * @param int $id Snapshot ID.
	 * @return array<string, mixed>|null
	 */
	public function content( int $id ): ?array {
		$row = $this->snapshots->find( $id );
		if ( null === $row ) {
			return null;
		}
		$content = Json::decode( (string) $row['content'] );
		return array() === $content ? null : $content;
	}

	/**
	 * How many records a snapshot holds, counted over its chapters the first time and remembered after that.
	 *
	 * @param int $id Snapshot ID.
	 */
	public function recordCount( int $id ): int {
		$counts = (array) get_option( self::COUNT_OPTION, array() );
		if ( isset( $counts[ $id ] ) ) {
			return (int) $counts[ $id ];
		}
		$content = $this->content( $id );
		if ( null === $content ) {
			return 0;
		}
		$total = 0;
		foreach ( (array) ( $content['sections'] ?? array() ) as $section ) {
			foreach ( (array) ( is_array( $section ) ? ( $section['groups'] ?? array() ) : array() ) as $group ) {
				$total += count( (array) ( is_array( $group ) ? ( $group['items'] ?? array() ) : array() ) );
			}
		}
		$counts[ $id ] = $total;
		update_option( self::COUNT_OPTION, $counts, false );
		return $total;
	}

	/**
	 * Make a snapshot.
	 *
	 * @param string        $profile_key Export profile (EXP-3).
	 * @param string        $name        Name, required.
	 * @param string        $note        Optional note.
	 * @param ExportOptions $options     Rights and ticked Sensitive records.
	 * @return int Snapshot ID.
	 * @throws ValidationError When the name or profile is not valid.
	 * @throws ExportRefused   When the person may not make this pack.
	 * @throws SecretFound     When a value looks like a secret (SEC-6).
	 */
	public function create( string $profile_key, string $name, string $note, ExportOptions $options ): int {
		$name = trim( preg_replace( '/\s+/', ' ', $name ) ?? '' );
		$note = trim( $note );
		if ( '' === $name || mb_strlen( $name ) > 190 ) {
			throw new ValidationError( __( 'Name', 'estasoft-handover-passport' ), __( 'Give the snapshot a name of up to 190 characters.', 'estasoft-handover-passport' ) );
		}
		if ( mb_strlen( $note ) > 2000 ) {
			throw new ValidationError( __( 'Note', 'estasoft-handover-passport' ), __( 'Keep the note under 2000 characters.', 'estasoft-handover-passport' ) );
		}
		$guard = $this->plugin->secretGuard();
		$guard->assertClean( $name, __( 'Name', 'estasoft-handover-passport' ) );
		$guard->assertClean( $note, __( 'Note', 'estasoft-handover-passport' ) );

		$profile = ( new Profiles( (array) $this->plugin->settings()->get( 'profiles', array() ) ) )->get( $profile_key );
		if ( null === $profile ) {
			throw new ValidationError( __( 'Pack', 'estasoft-handover-passport' ), __( 'Pick one of the listed packs.', 'estasoft-handover-passport' ) );
		}
		$version = defined( 'ESTASOFT_HP_VERSION' ) ? (string) ESTASOFT_HP_VERSION : '';
		$content = ( new ContentBuilder( $guard, $version ) )->build( $profile, ( new ExportDataLoader( $this->plugin ) )->load(), $options );
		$text    = Json::canonical( $content );

		$id = $this->snapshots->create(
			array(
				'name'           => $name,
				'profile_key'    => $profile->key,
				'note'           => $note,
				'content'        => $text,
				'hash'           => hash( 'sha256', $text ),
				'schema_version' => ContentBuilder::SCHEMA_VERSION,
				'plugin_version' => $version,
				'created_at'     => Clock::sql(),
				'created_by'     => $options->user_id,
			)
		);
		$this->plugin->changeLog()->add( 'snapshot_created', 'snapshot', $id, $name, array(), array( 'pack' => $profile->label, 'hash' => hash( 'sha256', $text ) ), $note );
		$this->plugin->findings()->queue();
		do_action( 'estasoft_hp_snapshot_created', $id );
		return $id;
	}

	/**
	 * Recompute the hash and say whether it matches (HND-5).
	 *
	 * @param int $id Snapshot ID.
	 * @return array{ok: bool, checked_at: string, stored: string, found: string}|null Null when the snapshot does not exist.
	 */
	public function verify( int $id ): ?array {
		$row = $this->snapshots->find( $id );
		if ( null === $row ) {
			return null;
		}
		$found          = hash( 'sha256', (string) $row['content'] );
		$ok             = SnapshotIntegrity::matches( (string) $row['content'], (string) $row['hash'] );
		$now            = Clock::sql();
		$results        = (array) get_option( SnapshotIntegrity::OPTION, array() );
		$results[ $id ] = array( 'ok' => $ok, 'at' => $now );
		update_option( SnapshotIntegrity::OPTION, $results, false );
		$this->plugin->findings()->queue();
		return array( 'ok' => $ok, 'checked_at' => $now, 'stored' => (string) $row['hash'], 'found' => $found );
	}

	/**
	 * Compare a snapshot with another snapshot or with the live Passport (HND-6).
	 *
	 * @param int           $a            Snapshot ID.
	 * @param int|string    $b            Snapshot ID, or "live".
	 * @param ExportOptions $options      Rights of the person.
	 * @param bool          $hide_sensitive Count only records that are not Sensitive, whatever the person's rights. The
	 *                                    live Passport is then built in memory with every right and cut before anything is returned.
	 * @return array<string, mixed>|null Null when a snapshot does not exist.
	 * @throws ExportRefused When the live Passport cannot be built for this person.
	 */
	public function compare( int $a, $b, ExportOptions $options, bool $hide_sensitive = false ): ?array {
		$old = $this->content( $a );
		if ( null === $old ) {
			return null;
		}
		$skipped = 0;
		if ( 'live' === $b ) {
			$profile = ( new Profiles( (array) $this->plugin->settings()->get( 'profiles', array() ) ) )->get( (string) ( $old['profile']['key'] ?? '' ) );
			if ( null === $profile ) {
				throw new ExportRefused( __( 'The pack this snapshot was made with no longer exists, so it cannot be compared with the live Passport.', 'estasoft-handover-passport' ) );
			}
			$ticked = array();
			foreach ( (array) ( $old['sensitive_included'] ?? array() ) as $entry ) {
				if ( 1 === preg_match( '/^item:(\d+)$/', (string) ( $entry['ref'] ?? '' ), $m ) ) {
					$ticked[] = (int) $m[1];
				}
			}
			$live_options = new ExportOptions( $options->may_see_sensitive || $hide_sensitive, $ticked, $options->user_id );
			$new          = ( new ContentBuilder( $this->plugin->secretGuard(), defined( 'ESTASOFT_HP_VERSION' ) ? (string) ESTASOFT_HP_VERSION : '' ) )->build( $profile, ( new ExportDataLoader( $this->plugin ) )->load(), $live_options );
		} else {
			$new = $this->content( (int) $b );
			if ( null === $new ) {
				return null;
			}
		}
		if ( ! $options->may_see_sensitive || $hide_sensitive ) {
			$cut_old = Comparer::withoutSensitive( $old );
			$cut_new = Comparer::withoutSensitive( $new );
			$old     = $cut_old['content'];
			$new     = $cut_new['content'];
			$skipped = $cut_old['removed'] + $cut_new['removed'];
		}
		return Comparer::diff( $old, $new ) + array( 'sensitive_skipped' => $skipped );
	}

	/**
	 * Record that the receiver acknowledged a snapshot, and mark the Passport as handed over (HND-4, PRT-8).
	 *
	 * @param int                  $id    Snapshot ID.
	 * @param array<string, mixed> $input name, organisation, role, date, method and note.
	 * @throws ValidationError When the snapshot or the input is not valid.
	 * @throws SecretFound     When a text looks like a secret.
	 */
	public function acknowledge( int $id, array $input ): void {
		$row = $this->snapshots->find( $id );
		if ( null === $row ) {
			throw new ValidationError( __( 'Snapshot', 'estasoft-handover-passport' ), __( 'That snapshot does not exist.', 'estasoft-handover-passport' ) );
		}
		$text = static fn( string $key, int $max ): string => mb_substr( trim( sanitize_text_field( (string) ( $input[ $key ] ?? '' ) ) ), 0, $max );
		$name = $text( 'name', 190 );
		if ( '' === $name ) {
			throw new ValidationError( __( 'Name', 'estasoft-handover-passport' ), __( 'Say who received the handover.', 'estasoft-handover-passport' ) );
		}
		$date = trim( (string) ( $input['date'] ?? '' ) );
		$date = '' === $date ? Clock::today() : $date;
		if ( ! Dates::isDate( $date ) || $date > Clock::today() ) {
			throw new ValidationError( __( 'Date', 'estasoft-handover-passport' ), __( 'Use a real date that is not in the future.', 'estasoft-handover-passport' ) );
		}
		$method = sanitize_key( (string) ( $input['method'] ?? '' ) );
		if ( ! array_key_exists( $method, self::methods() ) ) {
			throw new ValidationError( __( 'How it was received', 'estasoft-handover-passport' ), __( 'Pick one of the listed choices.', 'estasoft-handover-passport' ) );
		}
		$note = mb_substr( trim( sanitize_textarea_field( (string) ( $input['note'] ?? '' ) ) ), 0, 1000 );
		$ack  = array(
			'name'         => $name,
			'organisation' => $text( 'organisation', 190 ),
			'role'         => $text( 'role', 190 ),
			'date'         => $date,
			'method'       => $method,
			'note'         => $note,
			'recorded_by'  => (string) wp_get_current_user()->display_name,
			'recorded_at'  => Clock::sql(),
		);
		$this->plugin->secretGuard()->assertClean( $ack, __( 'Acknowledgement', 'estasoft-handover-passport' ) );

		$this->snapshots->appendAcknowledgement( $id, $ack );
		$this->plugin->changeLog()->add( 'snapshot_acknowledged', 'snapshot', $id, (string) $row['name'], array(), array( 'received_by' => $name, 'organisation' => $ack['organisation'], 'how' => self::methods()[ $method ] ), $note );
		$this->plugin->handoverStatus()->set( Passport::STATUS_HANDED, (string) $row['name'] );
		do_action( 'estasoft_hp_snapshot_acknowledged', $id, $ack );
	}

	/**
	 * Delete a snapshot. The name, hash and time go to the change log (HND-3). The caller checks the capability.
	 *
	 * @param int $id Snapshot ID.
	 * @throws ValidationError When the snapshot does not exist.
	 */
	public function delete( int $id ): void {
		$row = $this->snapshots->find( $id );
		if ( null === $row ) {
			throw new ValidationError( __( 'Snapshot', 'estasoft-handover-passport' ), __( 'That snapshot does not exist.', 'estasoft-handover-passport' ) );
		}
		$this->snapshots->delete( $id );
		$results = (array) get_option( SnapshotIntegrity::OPTION, array() );
		unset( $results[ $id ] );
		update_option( SnapshotIntegrity::OPTION, $results, false );
		$counts = (array) get_option( self::COUNT_OPTION, array() );
		unset( $counts[ $id ] );
		update_option( self::COUNT_OPTION, $counts, false );
		$this->plugin->changeLog()->add( 'snapshot_deleted', 'snapshot', $id, (string) $row['name'], array( 'hash' => (string) $row['hash'], 'created_at' => (string) $row['created_at'] ), array() );
		$this->plugin->findings()->queue();
	}

	/**
	 * Underlying repository.
	 */
	public function repo(): SnapshotRepository {
		return $this->snapshots;
	}
}
