<?php
/**
 * Rules about licences and subscriptions (HP-LIC).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Findings\Rules;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\LicenceStatus;
use Estasoft\HandoverPassport\Findings\Candidate;
use Estasoft\HandoverPassport\Findings\Context;
use Estasoft\HandoverPassport\Findings\Level;
use Estasoft\HandoverPassport\Findings\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Licence rules.
 */
final class LicenceRules extends Base {

	/**
	 * Status of a licence record as of today.
	 *
	 * @param array<string, mixed> $licence Hydrated licence.
	 * @param Context              $c       Context.
	 */
	private static function status( array $licence, Context $c ): string {
		return LicenceStatus::compute( (array) $licence['fields'], $c->today, $c->threshold( 'licence_lead_days', 30 ) );
	}

	/**
	 * The rules.
	 *
	 * @return list<Rule>
	 */
	public static function all(): array {
		return array(
			new Rule(
				'HP-LIC-001',
				'licences',
				Level::NEEDS,
				true,
				__( 'Expired licence for something still installed', 'estasoft-handover-passport' ),
				self::inventory( 'licences' ),
				static function ( Context $c ): ?array {
					if ( ! $c->hasFacts() ) {
						return null;
					}
					$installed = $c->installedSlugs();
					$out       = array();
					foreach ( $c->items( Kinds::LICENCE ) as $licence ) {
						$slug = strtolower( Context::field( $licence, 'slug' ) );
						if ( '' !== $slug && isset( $installed[ $slug ] ) && LicenceStatus::EXPIRED === self::status( $licence, $c ) ) {
							$out[] = Candidate::item( (int) $licence['id'], Level::NEEDS, array( 'name' => self::nameOf( $licence ), 'date' => Context::field( $licence, 'renews_on' ) ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: 1: licence name, 2: date. */
					return sprintf( __( 'The licence for %1$s expired on %2$s and it is still installed.', 'estasoft-handover-passport' ), $v['name'], self::when( $v['date'] ) );
				}
			),
			new Rule(
				'HP-LIC-002',
				'licences',
				Level::WORTH,
				false,
				__( 'Licence expires soon', 'estasoft-handover-passport' ),
				self::inventory( 'licences' ),
				static function ( Context $c ): ?array {
					$warn = $c->threshold( 'licence_warn_days', 60 );
					$out  = array();
					foreach ( $c->items( Kinds::LICENCE ) as $licence ) {
						$status = self::status( $licence, $c );
						if ( in_array( $status, array( LicenceStatus::EXPIRED, LicenceStatus::FREE, LicenceStatus::CANCELLED, LicenceStatus::UNKNOWN ), true ) ) {
							continue;
						}
						$date = Context::field( $licence, 'renews_on' );
						$days = self::days( $c->today, $date );
						if ( null !== $days && $days >= 0 && $days <= $warn ) {
							$out[] = Candidate::item( (int) $licence['id'], Level::WORTH, array( 'name' => self::nameOf( $licence ), 'date' => $date, 'days' => $days ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: 1: licence name, 2: date, 3: number of days. */
					return sprintf( __( 'The licence for %1$s runs to %2$s, which is %3$d days away.', 'estasoft-handover-passport' ), $v['name'], self::when( $v['date'] ), (int) $v['days'] );
				},
				'licence_warn_days'
			),
			new Rule(
				'HP-LIC-003',
				'licences',
				Level::WORTH,
				true,
				__( 'Plugin or theme from outside WordPress.org without a licence record', 'estasoft-handover-passport' ),
				self::inventory( 'licences' ),
				static function ( Context $c ): ?array {
					if ( ! $c->hasFacts() ) {
						return null;
					}
					$have = array();
					foreach ( $c->items( Kinds::LICENCE ) as $licence ) {
						$slug = strtolower( Context::field( $licence, 'slug' ) );
						if ( '' !== $slug ) {
							$have[ $slug ] = true;
						}
					}
					$out = array();
					foreach ( array( 'plugin' => $c->plugins(), 'theme' => $c->themes() ) as $type => $list ) {
						foreach ( $list as $slug => $row ) {
							if ( false === ( $row['wporg'] ?? null ) && 'estasoft-handover-passport' !== $slug && ! isset( $have[ strtolower( (string) $slug ) ] ) ) {
								$out[] = Candidate::keyed( $type, (string) $slug, Level::WORTH, array( 'name' => (string) ( $row['name'] ?? $slug ), 'type' => $type ) );
							}
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: %s: plugin or theme name. */
					return sprintf( __( '%s does not come from WordPress.org and has no licence record. Add one, or mark it Free.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
			new Rule(
				'HP-LIC-004',
				'licences',
				Level::INFO,
				false,
				__( 'Licence for something no longer installed', 'estasoft-handover-passport' ),
				self::inventory( 'licences' ),
				static function ( Context $c ): ?array {
					if ( ! $c->hasFacts() ) {
						return null;
					}
					$installed = $c->installedSlugs();
					$out       = array();
					foreach ( $c->items( Kinds::LICENCE ) as $licence ) {
						$slug = strtolower( Context::field( $licence, 'slug' ) );
						if ( '' !== $slug && in_array( Context::field( $licence, 'kind' ), array( 'plugin', 'theme' ), true ) && ! isset( $installed[ $slug ] ) ) {
							$out[] = Candidate::item( (int) $licence['id'], Level::INFO, array( 'name' => self::nameOf( $licence ) ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: %s: licence name. */
					return sprintf( __( 'The licence for %s points at something that is no longer installed.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
			new Rule(
				'HP-LIC-005',
				'licences',
				Level::WORTH,
				true,
				__( 'Licence without a payer or account holder', 'estasoft-handover-passport' ),
				self::inventory( 'licences' ),
				static function ( Context $c ): ?array {
					$out = array();
					foreach ( $c->items( Kinds::LICENCE ) as $licence ) {
						if ( in_array( self::status( $licence, $c ), array( LicenceStatus::FREE, LicenceStatus::CANCELLED ), true ) ) {
							continue;
						}
						$missing = array();
						if ( array() === Context::owners( $licence, 'payer' ) ) {
							$missing[] = 'payer';
						}
						if ( array() === Context::owners( $licence, 'account_holder' ) ) {
							$missing[] = 'account_holder';
						}
						if ( array() !== $missing ) {
							$out[] = Candidate::item( (int) $licence['id'], Level::WORTH, array( 'name' => self::nameOf( $licence ), 'missing' => $missing ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					$both = 2 === count( (array) $v['missing'] );
					if ( $both ) {
						/* translators: %s: licence name. */
						return sprintf( __( 'The licence for %s has no payer and no account holder.', 'estasoft-handover-passport' ), $v['name'] );
					}
					if ( in_array( 'payer', (array) $v['missing'], true ) ) {
						/* translators: %s: licence name. */
						return sprintf( __( 'The licence for %s has no payer.', 'estasoft-handover-passport' ), $v['name'] );
					}
					/* translators: %s: licence name. */
					return sprintf( __( 'The licence for %s has no account holder.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
		);
	}
}
