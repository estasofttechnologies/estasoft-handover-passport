<?php
/**
 * Rules about backups, runbooks and critical URLs (HP-BKP, HP-RUN, HP-URL).
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
 * Safety-net rules.
 */
final class SafetyRules extends Base {

	/**
	 * Date of the newest restore test that passed.
	 *
	 * @param Context $c Context.
	 */
	private static function lastPass( Context $c ): ?string {
		$best = null;
		foreach ( $c->tests as $test ) {
			if ( 'pass' === (string) $test['result'] && ( null === $best || (string) $test['test_date'] > $best ) ) {
				$best = (string) $test['test_date'];
			}
		}
		return $best;
	}

	/**
	 * The rules.
	 *
	 * @return list<Rule>
	 */
	public static function all(): array {
		return array(
			new Rule(
				'HP-BKP-001',
				'backups',
				Level::NEEDS,
				true,
				__( 'No backup record', 'estasoft-handover-passport' ),
				self::safety( 'backups' ),
				static fn( Context $c ): array => array() === $c->items( Kinds::BACKUP ) ? array( Candidate::site( Level::NEEDS ) ) : array(),
				static fn(): string => __( 'No backup is recorded in the Passport.', 'estasoft-handover-passport' )
			),
			new Rule(
				'HP-BKP-002',
				'backups',
				Level::NEEDS,
				true,
				__( 'No backup stored outside the hosting account', 'estasoft-handover-passport' ),
				self::safety( 'backups' ),
				static function ( Context $c ): array {
					foreach ( $c->items( Kinds::BACKUP ) as $backup ) {
						if ( 'yes' === Context::field( $backup, 'offsite' ) ) {
							return array();
						}
					}
					return array( Candidate::site( Level::NEEDS ) );
				},
				static fn(): string => __( 'No backup is marked as stored outside the hosting account.', 'estasoft-handover-passport' )
			),
			new Rule(
				'HP-BKP-003',
				'backups',
				Level::WORTH,
				true,
				__( 'No restore test logged', 'estasoft-handover-passport' ),
				self::safety( 'tests' ),
				static fn( Context $c ): array => array() === $c->tests ? array( Candidate::site( Level::WORTH ) ) : array(),
				static fn(): string => __( 'No restore test has been logged, so it is not known that a backup can be restored.', 'estasoft-handover-passport' )
			),
			new Rule(
				'HP-BKP-004',
				'backups',
				Level::WORTH,
				false,
				__( 'Restore test is old', 'estasoft-handover-passport' ),
				self::safety( 'tests' ),
				static function ( Context $c ): array {
					if ( array() === $c->tests ) {
						return array();
					}
					$last  = self::lastPass( $c );
					$limit = $c->threshold( 'restore_test_days', 180 );
					if ( null === $last ) {
						return array( Candidate::site( Level::WORTH, array( 'date' => '' ) ) );
					}
					$days = self::days( $last, $c->today );
					return ( null !== $days && $days > $limit ) ? array( Candidate::site( Level::WORTH, array( 'date' => $last, 'days' => $days ) ) ) : array();
				},
				static function ( array $v ): string {
					if ( '' === (string) ( $v['date'] ?? '' ) ) {
						return __( 'Restore tests are logged, but none has passed.', 'estasoft-handover-passport' );
					}
					/* translators: 1: date, 2: number of days. */
					return sprintf( __( 'The last restore test that passed was on %1$s, %2$d days ago.', 'estasoft-handover-passport' ), self::when( $v['date'] ), (int) $v['days'] );
				},
				'restore_test_days'
			),
			new Rule(
				'HP-BKP-005',
				'backups',
				Level::WORTH,
				false,
				__( 'Backup not confirmed for a while', 'estasoft-handover-passport' ),
				self::safety( 'backups' ),
				static function ( Context $c ): array {
					$limit = $c->threshold( 'backup_confirm_days', 45 );
					$out   = array();
					foreach ( $c->items( Kinds::BACKUP ) as $backup ) {
						$last = Context::field( $backup, 'last_confirmed' );
						$base = '' !== $last ? $last : substr( (string) $backup['created_at'], 0, 10 );
						$days = self::days( $base, $c->today );
						if ( null !== $days && $days > $limit ) {
							$out[] = Candidate::item( (int) $backup['id'], Level::WORTH, array( 'name' => self::nameOf( $backup ), 'date' => $last, 'days' => $days ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					if ( '' === (string) ( $v['date'] ?? '' ) ) {
						/* translators: 1: backup name, 2: number of days. */
						return sprintf( __( 'The backup %1$s has never been confirmed and was added %2$d days ago.', 'estasoft-handover-passport' ), $v['name'], (int) $v['days'] );
					}
					/* translators: 1: backup name, 2: date, 3: number of days. */
					return sprintf( __( 'The backup %1$s was last confirmed on %2$s, %3$d days ago.', 'estasoft-handover-passport' ), $v['name'], self::when( $v['date'] ), (int) $v['days'] );
				},
				'backup_confirm_days'
			),
			new Rule(
				'HP-BKP-006',
				'backups',
				Level::WORTH,
				false,
				__( 'Backups less frequent than the acceptable data loss', 'estasoft-handover-passport' ),
				self::safety( 'backups' ),
				static function ( Context $c ): ?array {
					$target = $c->recovery['data_loss_hours'] ?? null;
					if ( null === $target || '' === $target ) {
						return null;
					}
					$best = null;
					foreach ( $c->items( Kinds::BACKUP ) as $backup ) {
						$hours = (int) Context::field( $backup, 'frequency_hours', '0' );
						if ( $hours > 0 && ( null === $best || $hours < $best ) ) {
							$best = $hours;
						}
					}
					if ( null === $best ) {
						return null;
					}
					return $best > (int) $target ? array( Candidate::site( Level::WORTH, array( 'best' => $best, 'target' => (int) $target ) ) ) : array();
				},
				static function ( array $v ): string {
					/* translators: 1: hours between backups, 2: acceptable data loss in hours. */
					return sprintf( __( 'The most frequent backup runs every %1$d hours, and the acceptable data loss entered is %2$d hours.', 'estasoft-handover-passport' ), (int) $v['best'], (int) $v['target'] );
				}
			),
			new Rule(
				'HP-BKP-007',
				'backups',
				Level::WORTH,
				false,
				__( 'Restore took longer than the acceptable downtime', 'estasoft-handover-passport' ),
				self::safety( 'tests' ),
				static function ( Context $c ): ?array {
					$target = $c->recovery['downtime_hours'] ?? null;
					if ( null === $target || '' === $target ) {
						return null;
					}
					$measured = null;
					$date     = '';
					foreach ( $c->tests as $test ) {
						if ( 'pass' === (string) $test['result'] && (int) $test['minutes'] > 0 && (string) $test['test_date'] >= $date ) {
							$measured = (int) $test['minutes'];
							$date     = (string) $test['test_date'];
						}
					}
					if ( null === $measured ) {
						return null;
					}
					return $measured > (int) $target * 60 ? array( Candidate::site( Level::WORTH, array( 'minutes' => $measured, 'target' => (int) $target, 'date' => $date ) ) ) : array();
				},
				static function ( array $v ): string {
					/* translators: 1: minutes the restore took, 2: date of the test, 3: acceptable downtime in hours. */
					return sprintf( __( 'The restore test on %2$s took %1$d minutes, and the acceptable downtime entered is %3$d hours.', 'estasoft-handover-passport' ), (int) $v['minutes'], self::when( $v['date'] ), (int) $v['target'] );
				}
			),
			new Rule(
				'HP-BKP-008',
				'backups',
				Level::INFO,
				false,
				__( 'Backup storage not known to be encrypted', 'estasoft-handover-passport' ),
				self::safety( 'backups' ),
				static function ( Context $c ): array {
					$out = array();
					foreach ( $c->items( Kinds::BACKUP ) as $backup ) {
						if ( 'yes' !== Context::field( $backup, 'encrypted' ) ) {
							$out[] = Candidate::item( (int) $backup['id'], Level::INFO, array( 'name' => self::nameOf( $backup ), 'known' => 'no' === Context::field( $backup, 'encrypted' ) ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					if ( ! empty( $v['known'] ) ) {
						/* translators: %s: backup name. */
						return sprintf( __( 'The storage of the backup %s is recorded as not encrypted.', 'estasoft-handover-passport' ), $v['name'] );
					}
					/* translators: %s: backup name. */
					return sprintf( __( 'It is not recorded whether the storage of the backup %s is encrypted.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
			new Rule(
				'HP-RUN-001',
				'runbooks',
				Level::WORTH,
				true,
				__( 'No runbook for restoring the whole site', 'estasoft-handover-passport' ),
				self::safety( 'runbooks' ),
				static function ( Context $c ): array {
					foreach ( $c->runbooks as $runbook ) {
						if ( 'site' === (string) $runbook['restore_scope'] ) {
							return array();
						}
					}
					return array( Candidate::site( Level::WORTH ) );
				},
				static fn(): string => __( 'There is no runbook for restoring the whole site.', 'estasoft-handover-passport' )
			),
			new Rule(
				'HP-RUN-002',
				'runbooks',
				Level::WORTH,
				false,
				__( 'Runbook not reviewed for a long time', 'estasoft-handover-passport' ),
				self::safety( 'runbooks' ),
				static function ( Context $c ): array {
					$out = array();
					foreach ( $c->runbooks as $runbook ) {
						$interval = (int) ( $runbook['review_days'] ?: ( $c->review_days['runbooks'] ?? 180 ) );
						$state    = Freshness::state( $runbook['last_reviewed'] ? (string) $runbook['last_reviewed'] : null, (string) $runbook['created_at'], $interval, $c->due_days, $c->now );
						if ( Freshness::STALE === $state ) {
							$out[] = Candidate::row( 'runbook', (int) $runbook['id'], Level::WORTH, array( 'name' => (string) $runbook['title'], 'days' => $interval ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: 1: runbook title, 2: review interval in days. */
					return sprintf( __( 'The runbook %1$s was not reviewed within its %2$d-day interval.', 'estasoft-handover-passport' ), $v['name'], (int) $v['days'] );
				}
			),
			new Rule(
				'HP-RUN-003',
				'runbooks',
				Level::INFO,
				false,
				__( 'Runbook never rehearsed', 'estasoft-handover-passport' ),
				self::safety( 'runbooks' ),
				static function ( Context $c ): array {
					$out = array();
					foreach ( $c->runbooks as $runbook ) {
						if ( empty( $runbook['last_rehearsed'] ) ) {
							$out[] = Candidate::row( 'runbook', (int) $runbook['id'], Level::INFO, array( 'name' => (string) $runbook['title'] ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: %s: runbook title. */
					return sprintf( __( 'The runbook %s has never been rehearsed.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
			new Rule(
				'HP-URL-001',
				'urls',
				Level::NEEDS,
				false,
				__( 'Critical URL failed its last check', 'estasoft-handover-passport' ),
				self::safety( 'urls' ),
				static function ( Context $c ): array {
					$out = array();
					foreach ( $c->items( Kinds::URL ) as $url ) {
						$check = (array) ( $url['fields']['last_check'] ?? array() );
						if ( array() !== $check && isset( $check['ok'] ) && ! $check['ok'] ) {
							$out[] = Candidate::item( (int) $url['id'], Level::NEEDS, array( 'name' => self::nameOf( $url ), 'status' => (int) ( $check['status'] ?? 0 ), 'date' => substr( (string) ( $check['at'] ?? '' ), 0, 10 ), 'reason' => (string) ( $check['reason'] ?? '' ) ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: 1: page name, 2: date of the check. */
					return sprintf( __( 'The critical page %1$s failed its check on %2$s.', 'estasoft-handover-passport' ), $v['name'], self::when( $v['date'] ) );
				}
			),
			new Rule(
				'HP-URL-002',
				'urls',
				Level::INFO,
				false,
				__( 'No critical URLs listed', 'estasoft-handover-passport' ),
				self::safety( 'urls' ),
				static fn( Context $c ): array => array() === $c->items( Kinds::URL ) ? array( Candidate::site( Level::INFO ) ) : array(),
				static fn(): string => __( 'No critical pages are listed, so there is nothing to check after a restore.', 'estasoft-handover-passport' )
			),
		);
	}
}
