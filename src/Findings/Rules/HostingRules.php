<?php
/**
 * Rules about hosting and third-party services (HP-HST, HP-SVC).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Findings\Rules;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Findings\Candidate;
use Estasoft\HandoverPassport\Findings\Context;
use Estasoft\HandoverPassport\Findings\Level;
use Estasoft\HandoverPassport\Findings\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Hosting and service rules.
 */
final class HostingRules extends Base {

	/**
	 * The rules.
	 *
	 * @return array<int, Rule>
	 */
	public static function all(): array {
		return array(
			new Rule(
				'HP-HST-001',
				'hosting',
				Level::WORTH,
				true,
				__( 'No hosting record or no support contact', 'estasoft-handover-passport' ),
				self::inventory( 'hosting' ),
				static function ( Context $c ): ?array {
					$hosts = $c->items( Kinds::HOST );
					if ( array() === $hosts ) {
						return array( Candidate::site( Level::WORTH, array( 'name' => '' ) ) );
					}
					$out = array();
					foreach ( $hosts as $host ) {
						if ( '' === Context::field( $host, 'support_address' ) ) {
							$out[] = Candidate::item( (int) $host['id'], Level::WORTH, array( 'name' => self::nameOf( $host ) ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					if ( '' === (string) ( $v['name'] ?? '' ) ) {
						return __( 'There is no hosting record.', 'estasoft-handover-passport' );
					}
					/* translators: %s: hosting provider. */
					return sprintf( __( 'The hosting record for %s has no way to reach support.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
			new Rule(
				'HP-HST-002',
				'hosting',
				Level::WORTH,
				false,
				__( 'WordPress cron off with no server cron recorded', 'estasoft-handover-passport' ),
				self::inventory( 'hosting' ),
				static function ( Context $c ): ?array {
					if ( ! $c->hasFacts() ) {
						return null;
					}
					if ( true !== $c->fact( 'core.cron_disabled', false ) ) {
						return array();
					}
					foreach ( $c->items( Kinds::HOST ) as $host ) {
						if ( 'yes' === Context::field( $host, 'cron_server' ) ) {
							return array();
						}
					}
					return array( Candidate::site( Level::WORTH ) );
				},
				static function (): string {
					return __( 'WordPress cron is switched off and no server cron is recorded, so scheduled tasks may not run.', 'estasoft-handover-passport' );
				}
			),
			new Rule(
				'HP-HST-003',
				'hosting',
				Level::NEEDS,
				false,
				__( 'Hosting renewal date passed or near', 'estasoft-handover-passport' ),
				self::inventory( 'hosting' ),
				static function ( Context $c ): ?array {
					$warn = $c->threshold( 'hosting_warn_days', 30 );
					$out  = array();
					foreach ( $c->items( Kinds::HOST ) as $host ) {
						$date = Context::field( $host, 'renews_on' );
						$days = self::days( $c->today, $date );
						if ( null !== $days && $days <= $warn ) {
							$out[] = Candidate::item( (int) $host['id'], $days < 0 ? Level::NEEDS : Level::WORTH, array( 'name' => self::nameOf( $host ), 'date' => $date, 'past' => $days < 0, 'days' => $days ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					if ( ! empty( $v['past'] ) ) {
						/* translators: 1: hosting provider, 2: date. */
						return sprintf( __( 'The hosting at %1$s was due for renewal on %2$s.', 'estasoft-handover-passport' ), $v['name'], self::when( $v['date'] ) );
					}
					/* translators: 1: hosting provider, 2: date, 3: days left. */
					return sprintf( __( 'The hosting at %1$s renews on %2$s, which is %3$d days away.', 'estasoft-handover-passport' ), $v['name'], self::when( $v['date'] ), (int) $v['days'] );
				},
				'hosting_warn_days'
			),
			new Rule(
				'HP-HST-004',
				'hosting',
				Level::INFO,
				false,
				__( 'No exit note for leaving the host', 'estasoft-handover-passport' ),
				self::inventory( 'hosting' ),
				static function ( Context $c ): ?array {
					$has_runbook = false;
					foreach ( $c->runbooks as $runbook ) {
						if ( 'move_host' === (string) $runbook['template_key'] ) {
							$has_runbook = true;
							break;
						}
					}
					$out = array();
					foreach ( $c->items( Kinds::HOST ) as $host ) {
						if ( ! $has_runbook && '' === Context::field( $host, 'exit_notes' ) ) {
							$out[] = Candidate::item( (int) $host['id'], Level::INFO, array( 'name' => self::nameOf( $host ) ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: %s: hosting provider. */
					return sprintf( __( 'There is no exit note or runbook for leaving %s.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
			new Rule(
				'HP-SVC-001',
				'services',
				Level::INFO,
				false,
				__( 'Plugin that implies a service with no record', 'estasoft-handover-passport' ),
				self::inventory( 'services' ),
				static function ( Context $c ): ?array {
					if ( ! $c->hasFacts() ) {
						return null;
					}
					$names = array();
					foreach ( $c->items( Kinds::SERVICE ) as $service ) {
						$names[ strtolower( Context::field( $service, 'service', (string) $service['title'] ) ) ] = true;
					}
					$out = array();
					foreach ( $c->service_hints as $hint ) {
						if ( ! isset( $names[ strtolower( $hint['service'] ) ] ) ) {
							$out[] = Candidate::keyed( 'plugin', $hint['slug'], Level::INFO, array( 'name' => $hint['plugin'], 'service' => $hint['service'] ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: 1: plugin name, 2: service name. */
					return sprintf( __( 'The active plugin %1$s usually works with %2$s, which has no Services record.', 'estasoft-handover-passport' ), $v['name'], $v['service'] );
				}
			),
			new Rule(
				'HP-SVC-002',
				'services',
				Level::WORTH,
				true,
				__( 'Service without an account holder', 'estasoft-handover-passport' ),
				self::inventory( 'services' ),
				static function ( Context $c ): ?array {
					$out = array();
					foreach ( $c->items( Kinds::SERVICE ) as $service ) {
						if ( array() === Context::owners( $service, 'account_holder' ) ) {
							$out[] = Candidate::item( (int) $service['id'], Level::WORTH, array( 'name' => self::nameOf( $service ) ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: %s: service name. */
					return sprintf( __( 'The service %s has no account holder.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
		);
	}
}
