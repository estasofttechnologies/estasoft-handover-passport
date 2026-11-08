<?php
/**
 * Rules about freshness, the scan, reminders, copies and snapshots (HP-PRT, HP-SCN, HP-RMD, HP-IMP, HP-HND).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Findings\Rules;

use Estasoft\HandoverPassport\Domain\Freshness;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Findings\Candidate;
use Estasoft\HandoverPassport\Findings\Context;
use Estasoft\HandoverPassport\Findings\Level;
use Estasoft\HandoverPassport\Findings\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Rules about the Passport itself.
 */
final class SystemRules extends Base {

	/**
	 * The rules.
	 *
	 * @return array<int, Rule>
	 */
	public static function all(): array {
		return array(
			new Rule(
				'HP-PRT-001',
				'records',
				Level::INFO,
				false,
				__( 'Record is stale', 'estasoft-handover-passport' ),
				self::overview(),
				static function ( Context $c ): array {
					$out = array();
					foreach ( Kinds::registerKinds() as $kind ) {
						if ( Kinds::CHECKLIST === $kind ) {
							continue;
						}
						foreach ( $c->items( $kind ) as $record ) {
							if ( Freshness::STALE === (string) $record['freshness'] ) {
								$out[] = Candidate::item( (int) $record['id'], Level::INFO, array( 'name' => self::nameOf( $record ), 'since' => '' !== (string) $record['verified_at'] ? substr( (string) $record['verified_at'], 0, 10 ) : '' ) );
							}
						}
					}
					foreach ( $c->people as $person ) {
						if ( Freshness::STALE === (string) $person['freshness'] ) {
							$out[] = Candidate::row( 'person', (int) $person['id'], Level::INFO, array( 'name' => (string) $person['name'], 'since' => '' !== (string) $person['verified_at'] ? substr( (string) $person['verified_at'], 0, 10 ) : '' ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					if ( '' === (string) ( $v['since'] ?? '' ) ) {
						/* translators: %s: record name. */
						return sprintf( __( '%s was never verified and is past its review period.', 'estasoft-handover-passport' ), $v['name'] );
					}
					/* translators: 1: record name, 2: date. */
					return sprintf( __( '%1$s was last verified on %2$s and is past its review period.', 'estasoft-handover-passport' ), $v['name'], self::when( $v['since'] ) );
				}
			),
			new Rule(
				'HP-PRT-002',
				'records',
				Level::WORTH,
				false,
				__( 'Detected value disagrees with an entered one', 'estasoft-handover-passport' ),
				self::inventory( 'domains' ),
				static function ( Context $c ): ?array {
					$host = $c->siteHost();
					if ( '' === $host || ! $c->hasFacts() ) {
						return null;
					}
					$out = array();
					foreach ( $c->items( Kinds::ENV ) as $env ) {
						if ( 'production' !== Context::field( $env, 'name' ) || '' === Context::field( $env, 'address' ) ) {
							continue;
						}
						$entered = wp_parse_url( Context::field( $env, 'address' ), PHP_URL_HOST );
						if ( is_string( $entered ) && strtolower( $entered ) !== $host ) {
							$out[] = Candidate::item( (int) $env['id'], Level::WORTH, array( 'name' => self::nameOf( $env ), 'entered' => strtolower( $entered ), 'detected' => $host ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: 1: record name, 2: address entered, 3: address detected. */
					return sprintf( __( '%1$s lists the address %2$s, but this site answers as %3$s.', 'estasoft-handover-passport' ), $v['name'], $v['entered'], $v['detected'] );
				}
			),
			new Rule(
				'HP-SCN-001',
				'scan',
				Level::WORTH,
				false,
				__( 'Scan failed or is out of date', 'estasoft-handover-passport' ),
				self::overview(),
				static function ( Context $c ): ?array {
					if ( null === $c->last_scan ) {
						return null;
					}
					$limit = $c->threshold( 'scan_stale_hours', 48 );
					if ( 'failed' === (string) $c->last_scan['status'] ) {
						return array( Candidate::site( Level::WORTH, array( 'failed' => true, 'hours' => $c->scan_age_hours ) ) );
					}
					if ( null !== $c->scan_age_hours && $c->scan_age_hours > $limit ) {
						return array( Candidate::site( Level::WORTH, array( 'failed' => false, 'hours' => $c->scan_age_hours ) ) );
					}
					return array();
				},
				static function ( array $v ): string {
					if ( ! empty( $v['failed'] ) ) {
						return __( 'The last scan did not finish.', 'estasoft-handover-passport' );
					}
					/* translators: %d: hours since the last scan. */
					return sprintf( __( 'The last scan finished %d hours ago.', 'estasoft-handover-passport' ), (int) $v['hours'] );
				},
				'scan_stale_hours'
			),
			new Rule(
				'HP-SCN-002',
				'scan',
				Level::INFO,
				false,
				__( 'An optional server ability is missing', 'estasoft-handover-passport' ),
				self::overview(),
				static function ( Context $c ): ?array {
					if ( ! $c->hasFacts() ) {
						return null;
					}
					$out = array();
					foreach ( (array) $c->fact( 'gaps', array() ) as $key => $reason ) {
						$out[] = Candidate::keyed( 'gap', (string) $key, Level::INFO, array( 'what' => (string) $key, 'reason' => (string) $reason ) );
					}
					return $out;
				},
				static function ( array $v ): string {
					$labels = array(
						'db_size'      => __( 'the database size', 'estasoft-handover-passport' ),
						'uploads_size' => __( 'the size of the uploads folder', 'estasoft-handover-passport' ),
						'dns'          => __( 'DNS lookups', 'estasoft-handover-passport' ),
						'ssl'          => __( 'the certificate check', 'estasoft-handover-passport' ),
					);
					/* translators: %s: what could not be read, such as the database size. */
					return sprintf( __( 'This server does not allow reading %s, so that part of the Passport is off.', 'estasoft-handover-passport' ), $labels[ $v['what'] ] ?? (string) $v['what'] );
				}
			),
			new Rule(
				'HP-RMD-001',
				'reminders',
				Level::NEEDS,
				false,
				__( 'Reminder emails failing', 'estasoft-handover-passport' ),
				self::settings( 'general' ),
				static function ( Context $c ): array {
					$health = (array) $c->state( 'reminder_health', array() );
					$days   = (int) ( $health['failed_days'] ?? 0 );
					return $days >= 3 ? array( Candidate::site( Level::NEEDS, array( 'days' => $days ) ) ) : array();
				},
				static function ( array $v ): string {
					/* translators: %d: number of days in a row. */
					return sprintf( __( 'Reminder emails could not be sent on %d days in a row.', 'estasoft-handover-passport' ), (int) $v['days'] );
				}
			),
			new Rule(
				'HP-IMP-001',
				'import',
				Level::WORTH,
				false,
				__( 'Site address changed and the copy question is open', 'estasoft-handover-passport' ),
				self::overview(),
				static function ( Context $c ): array {
					$guard = (array) $c->state( 'copy_guard', array() );
					return ! empty( $guard['pending'] ) ? array( Candidate::site( Level::WORTH, array( 'from' => (string) ( $guard['from'] ?? '' ), 'to' => (string) ( $guard['to'] ?? '' ) ) ) ) : array();
				},
				static function ( array $v ): string {
					/* translators: 1: old address, 2: new address. */
					return sprintf( __( 'The site address changed from %1$s to %2$s and it has not been said whether this is a copy of the site.', 'estasoft-handover-passport' ), $v['from'], $v['to'] );
				}
			),
			new Rule(
				'HP-HND-001',
				'handover',
				Level::INFO,
				true,
				__( 'No handover snapshot', 'estasoft-handover-passport' ),
				self::overview(),
				static fn( Context $c ): array => 0 === $c->snapshot_count ? array( Candidate::site( Level::INFO ) ) : array(),
				static fn(): string => __( 'No handover snapshot has been taken.', 'estasoft-handover-passport' )
			),
			new Rule(
				'HP-HND-002',
				'handover',
				Level::NEEDS,
				false,
				__( 'Snapshot does not match its hash', 'estasoft-handover-passport' ),
				self::overview(),
				static function ( Context $c ): array {
					$out = array();
					foreach ( $c->snapshot_mismatches as $snapshot ) {
						$out[] = Candidate::row( 'snapshot', (int) $snapshot['id'], Level::NEEDS, array( 'name' => (string) $snapshot['name'] ) );
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: %s: snapshot name. */
					return sprintf( __( 'The content of the snapshot %s no longer matches its stored hash.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
		);
	}
}
