<?php
/**
 * Rules about domains, DNS and certificates (HP-DOM).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Findings\Rules;

use Estasoft\HandoverPassport\Checks\ZoneCompare;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Findings\Candidate;
use Estasoft\HandoverPassport\Findings\Context;
use Estasoft\HandoverPassport\Findings\Level;
use Estasoft\HandoverPassport\Findings\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Domain rules.
 */
final class DomainRules extends Base {

	/**
	 * The latest snapshot per domain.
	 *
	 * @param Context $c Context.
	 * @return array<string, array<string, mixed>> Snapshot record by domain.
	 */
	private static function snapshots( Context $c ): array {
		$out = array();
		foreach ( $c->items( Kinds::DNS ) as $record ) {
			$domain = strtolower( (string) $record['subject_key'] );
			if ( '' !== $domain ) {
				$out[ $domain ] = $record;
			}
		}
		return $out;
	}

	/**
	 * The rules.
	 *
	 * @return list<Rule>
	 */
	public static function all(): array {
		return array(
			new Rule(
				'HP-DOM-001',
				'domains',
				Level::NEEDS,
				true,
				__( 'Domain expired or expiring', 'estasoft-handover-passport' ),
				self::inventory( 'domains' ),
				static function ( Context $c ): ?array {
					$warn = $c->threshold( 'domain_warn_days', 30 );
					$out  = array();
					foreach ( $c->items( Kinds::DOMAIN ) as $domain ) {
						$date = Context::field( $domain, 'expires_on' );
						$days = self::days( $c->today, $date );
						if ( null !== $days && $days <= $warn ) {
							$out[] = Candidate::item( (int) $domain['id'], Level::NEEDS, array( 'name' => self::nameOf( $domain ), 'date' => $date, 'past' => $days < 0, 'days' => $days ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					if ( ! empty( $v['past'] ) ) {
						/* translators: 1: domain, 2: date. */
						return sprintf( __( 'The domain %1$s expired on %2$s.', 'estasoft-handover-passport' ), $v['name'], self::when( $v['date'] ) );
					}
					/* translators: 1: domain, 2: date, 3: days left. */
					return sprintf( __( 'The domain %1$s expires on %2$s, which is %3$d days away.', 'estasoft-handover-passport' ), $v['name'], self::when( $v['date'] ), (int) $v['days'] );
				},
				'domain_warn_days'
			),
			new Rule(
				'HP-DOM-002',
				'domains',
				Level::WORTH,
				true,
				__( 'Primary domain without an expiry date', 'estasoft-handover-passport' ),
				self::inventory( 'domains' ),
				static function ( Context $c ): ?array {
					$out = array();
					foreach ( $c->items( Kinds::DOMAIN ) as $domain ) {
						if ( 'primary' === Context::field( $domain, 'role' ) && '' === Context::field( $domain, 'expires_on' ) ) {
							$out[] = Candidate::item( (int) $domain['id'], Level::WORTH, array( 'name' => self::nameOf( $domain ) ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: %s: domain. */
					return sprintf( __( 'The primary domain %s has no expiry date entered.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
			new Rule(
				'HP-DOM-003',
				'domains',
				Level::NEEDS,
				false,
				__( 'SSL certificate expired or expiring', 'estasoft-handover-passport' ),
				self::inventory( 'domains' ),
				static function ( Context $c ): ?array {
					$checks = $c->items( Kinds::SSL );
					if ( array() === $checks ) {
						return null;
					}
					$warn = $c->threshold( 'ssl_warn_days', 21 );
					$out  = array();
					foreach ( $checks as $check ) {
						$date = Context::field( $check, 'valid_to' );
						$days = self::days( $c->today, $date );
						if ( null !== $days && $days <= $warn ) {
							$out[] = Candidate::item( (int) $check['id'], Level::NEEDS, array( 'name' => (string) $check['subject_key'], 'date' => $date, 'past' => $days < 0, 'days' => $days ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					if ( ! empty( $v['past'] ) ) {
						/* translators: 1: host, 2: date. */
						return sprintf( __( 'The certificate for %1$s, as seen from this server, expired on %2$s.', 'estasoft-handover-passport' ), $v['name'], self::when( $v['date'] ) );
					}
					/* translators: 1: host, 2: date, 3: days left. */
					return sprintf( __( 'The certificate for %1$s, as seen from this server, expires on %2$s, which is %3$d days away.', 'estasoft-handover-passport' ), $v['name'], self::when( $v['date'] ), (int) $v['days'] );
				},
				'ssl_warn_days'
			),
			new Rule(
				'HP-DOM-004',
				'domains',
				Level::NEEDS,
				false,
				__( 'Site address without HTTPS', 'estasoft-handover-passport' ),
				self::overview(),
				static function ( Context $c ): ?array {
					if ( ! $c->hasFacts() ) {
						return null;
					}
					$url = (string) $c->fact( 'core.site_url', '' );
					return false === $c->fact( 'core.https', true ) ? array( Candidate::site( Level::NEEDS, array( 'url' => $url ) ) ) : array();
				},
				static function ( array $v ): string {
					/* translators: %s: site address. */
					return sprintf( __( 'The site address %s does not use HTTPS.', 'estasoft-handover-passport' ), $v['url'] );
				}
			),
			new Rule(
				'HP-DOM-005',
				'domains',
				Level::INFO,
				false,
				__( 'No SPF or DMARC record seen', 'estasoft-handover-passport' ),
				self::inventory( 'domains' ),
				static function ( Context $c ): ?array {
					$snapshots = self::snapshots( $c );
					if ( array() === $snapshots ) {
						return null;
					}
					$out = array();
					foreach ( $snapshots as $domain => $snap ) {
						$fields = (array) $snap['fields'];
						if ( array() === (array) ( $fields['mx'] ?? array() ) || array() !== (array) ( $fields['errors'] ?? array() ) ) {
							continue;
						}
						$missing = array();
						if ( '' === (string) ( $fields['spf'] ?? '' ) ) {
							$missing[] = 'SPF';
						}
						if ( '' === (string) ( $fields['dmarc'] ?? '' ) ) {
							$missing[] = 'DMARC';
						}
						if ( array() !== $missing ) {
							$out[] = Candidate::item( (int) $snap['id'], Level::INFO, array( 'name' => $domain, 'missing' => $missing ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: 1: domain, 2: missing record names such as SPF and DMARC. */
					return sprintf( __( 'No %2$s record was found for %1$s, as seen from this server.', 'estasoft-handover-passport' ), $v['name'], implode( ' ' . __( 'or', 'estasoft-handover-passport' ) . ' ', (array) $v['missing'] ) );
				}
			),
			new Rule(
				'HP-DOM-006',
				'domains',
				Level::WORTH,
				false,
				__( 'DNS records changed between checks', 'estasoft-handover-passport' ),
				self::inventory( 'domains' ),
				static function ( Context $c ): ?array {
					$snapshots = self::snapshots( $c );
					if ( array() === $snapshots ) {
						return null;
					}
					$out = array();
					foreach ( $snapshots as $domain => $snap ) {
						$changed = array_values( array_intersect( array( 'a', 'mx', 'ns' ), array_map( 'strval', (array) ( $snap['fields']['changed'] ?? array() ) ) ) );
						if ( array() !== $changed ) {
							$out[] = Candidate::item( (int) $snap['id'], Level::WORTH, array( 'name' => $domain, 'changed' => $changed, 'date' => substr( (string) ( $snap['fields']['checked_at'] ?? '' ), 0, 10 ) ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					$names = array( 'a' => 'A', 'mx' => 'MX', 'ns' => __( 'name server', 'estasoft-handover-passport' ) );
					$list  = implode( ', ', array_map( static fn( string $k ): string => $names[ $k ] ?? $k, (array) $v['changed'] ) );
					/* translators: 1: domain, 2: changed record kinds, 3: date of the check. */
					return sprintf( __( 'The %2$s records of %1$s changed between two DNS checks; the later one ran on %3$s.', 'estasoft-handover-passport' ), $v['name'], $list, self::when( $v['date'] ) );
				}
			),
			new Rule(
				'HP-DOM-007',
				'domains',
				Level::WORTH,
				false,
				__( 'Intended DNS zone differs from the last check', 'estasoft-handover-passport' ),
				self::inventory( 'domains' ),
				static function ( Context $c ): ?array {
					$zones = $c->items( Kinds::ZONE );
					if ( array() === $zones ) {
						return array();
					}
					$snapshots = self::snapshots( $c );
					$out       = array();
					$checked   = false;
					foreach ( $zones as $zone ) {
						$domain = strtolower( Context::field( $zone, 'domain' ) );
						if ( '' === $domain || ! isset( $snapshots[ $domain ] ) ) {
							continue;
						}
						$checked = true;
						$sum     = ZoneCompare::summary( ZoneCompare::compare( $domain, ZoneCompare::parse( Context::field( $zone, 'rows' ) ), (array) $snapshots[ $domain ]['fields'] ) );
						if ( $sum[ ZoneCompare::DIFFERS ] + $sum[ ZoneCompare::MISSING ] > 0 ) {
							$out[] = Candidate::item( (int) $zone['id'], Level::WORTH, array( 'name' => $domain, 'differs' => $sum[ ZoneCompare::DIFFERS ], 'missing' => $sum[ ZoneCompare::MISSING ] ) );
						}
					}
					return $checked ? $out : null;
				},
				static function ( array $v ): string {
					/* translators: 1: domain, 2: number of records that differ, 3: number of records missing. */
					return sprintf( __( 'The intended zone for %1$s differs from the latest DNS check: %2$d records differ and %3$d are missing.', 'estasoft-handover-passport' ), $v['name'], (int) $v['differs'], (int) $v['missing'] );
				}
			),
		);
	}
}
