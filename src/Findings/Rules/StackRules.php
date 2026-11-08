<?php
/**
 * Rules about the stack: versions, updates and what each part does (HP-STK).
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
 * Stack rules.
 */
final class StackRules extends Base {

	/**
	 * The rules.
	 *
	 * @return list<Rule>
	 */
	public static function all(): array {
		return array(
			new Rule(
				'HP-STK-001',
				'stack',
				Level::NEEDS,
				true,
				__( 'PHP or WordPress version past its support date', 'estasoft-handover-passport' ),
				self::inventory( 'stack' ),
				static function ( Context $c ): ?array {
					if ( ! $c->hasFacts() ) {
						return null;
					}
					$warn  = $c->threshold( 'eol_warn_days', 90 );
					$out   = array();
					$php   = (string) $c->fact( 'core.php_version', '' );
					$wp    = (string) $c->fact( 'core.wp_version', '' );
					$items = array(
						array( 'php', 'PHP ' . self::branch( $php ), '' !== $php ? $c->support()->phpEnd( $php ) : null ),
						array( 'wordpress', 'WordPress ' . self::branch( $wp ), '' !== $wp ? $c->support()->wordpressEnd( $wp ) : null ),
					);
					foreach ( $items as list( $key, $what, $end ) ) {
						$days = null === $end ? null : self::days( $c->today, $end );
						if ( null === $days || $days > $warn ) {
							continue;
						}
						$out[] = Candidate::keyed( 'component', $key, $days < 0 ? Level::NEEDS : Level::WORTH, array( 'product' => $key, 'what' => $what, 'date' => $end, 'past' => $days < 0 ) );
					}
					return $out;
				},
				static function ( array $v ): string {
					$kind = 'php' === ( $v['product'] ?? '' ) ? __( 'security support', 'estasoft-handover-passport' ) : __( 'support', 'estasoft-handover-passport' );
					if ( ! empty( $v['past'] ) ) {
						/* translators: 1: product and branch such as PHP 8.1, 2: kind of support, 3: date. */
						return sprintf( __( '%1$s %2$s ended on %3$s.', 'estasoft-handover-passport' ), $v['what'], $kind, self::when( $v['date'] ) );
					}
					/* translators: 1: product and branch such as PHP 8.2, 2: kind of support, 3: date. */
					return sprintf( __( '%1$s %2$s ends on %3$s.', 'estasoft-handover-passport' ), $v['what'], $kind, self::when( $v['date'] ) );
				},
				'eol_warn_days'
			),
			new Rule(
				'HP-STK-002',
				'stack',
				Level::WORTH,
				false,
				__( 'Update waiting for a long time', 'estasoft-handover-passport' ),
				self::inventory( 'stack' ),
				static function ( Context $c ): ?array {
					if ( ! $c->hasFacts() ) {
						return null;
					}
					$limit = $c->threshold( 'update_age_days', 30 );
					$out   = array();
					foreach ( array( 'plugin' => $c->plugins(), 'theme' => $c->themes() ) as $type => $list ) {
						foreach ( $list as $slug => $row ) {
							$since = substr( (string) ( $row['update_since'] ?? '' ), 0, 10 );
							$days  = '' === (string) ( $row['update'] ?? '' ) ? null : self::days( $since, $c->today );
							if ( null !== $days && $days >= $limit ) {
								$out[] = Candidate::keyed( $type, (string) $slug, Level::WORTH, array( 'name' => (string) ( $row['name'] ?? $slug ), 'version' => (string) ( $row['update'] ?? '' ), 'days' => $days ) );
							}
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: 1: plugin or theme name, 2: version waiting, 3: number of days. */
					return sprintf( __( 'An update of %1$s to version %2$s has been available for %3$d days.', 'estasoft-handover-passport' ), $v['name'], $v['version'], (int) $v['days'] );
				},
				'update_age_days'
			),
			new Rule(
				'HP-STK-003',
				'stack',
				Level::INFO,
				false,
				__( 'Installed but inactive', 'estasoft-handover-passport' ),
				self::inventory( 'stack' ),
				static function ( Context $c ): ?array {
					if ( ! $c->hasFacts() ) {
						return null;
					}
					$out     = array();
					$parents = array();
					foreach ( $c->themes() as $row ) {
						if ( ! empty( $row['active'] ) && '' !== (string) ( $row['parent'] ?? '' ) ) {
							$parents[ (string) $row['parent'] ] = true;
						}
					}
					foreach ( $c->plugins() as $slug => $row ) {
						if ( empty( $row['active'] ) ) {
							$out[] = Candidate::keyed( 'plugin', (string) $slug, Level::INFO, array( 'name' => (string) ( $row['name'] ?? $slug ), 'type' => 'plugin' ) );
						}
					}
					foreach ( $c->themes() as $slug => $row ) {
						if ( empty( $row['active'] ) && ! isset( $parents[ (string) $slug ] ) ) {
							$out[] = Candidate::keyed( 'theme', (string) $slug, Level::INFO, array( 'name' => (string) ( $row['name'] ?? $slug ), 'type' => 'theme' ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					if ( 'theme' === ( $v['type'] ?? '' ) ) {
						/* translators: %s: theme name. */
						return sprintf( __( 'The theme %s is installed but not in use.', 'estasoft-handover-passport' ), $v['name'] );
					}
					/* translators: %s: plugin name. */
					return sprintf( __( 'The plugin %s is installed but not active.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
			new Rule(
				'HP-STK-004',
				'stack',
				Level::WORTH,
				true,
				__( 'Custom code without a recorded source', 'estasoft-handover-passport' ),
				self::inventory( 'stack' ),
				static function ( Context $c ): ?array {
					$out = array();
					foreach ( $c->items( Kinds::STACKNOTE ) as $note ) {
						if ( 'yes' === Context::field( $note, 'custom_code' ) && '' === Context::field( $note, 'repo_address' ) ) {
							$name  = Context::field( $note, 'name', self::nameOf( $note ) );
							$out[] = Candidate::item( (int) $note['id'], Level::WORTH, array( 'name' => '' !== $name ? $name : self::nameOf( $note ) ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: %s: name of a plugin or theme. */
					return sprintf( __( '%s is marked as custom code and has no recorded location for its source.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
			new Rule(
				'HP-STK-005',
				'stack',
				Level::INFO,
				false,
				__( 'Active plugin without a recorded purpose', 'estasoft-handover-passport' ),
				self::inventory( 'stack' ),
				static function ( Context $c ): ?array {
					if ( ! $c->hasFacts() ) {
						return null;
					}
					$known = array();
					foreach ( $c->items( Kinds::STACKNOTE ) as $note ) {
						if ( '' !== Context::field( $note, 'what_it_does' ) || '' !== Context::field( $note, 'why_installed' ) ) {
							$known[ strtolower( Context::field( $note, 'ref' ) ) ] = true;
						}
					}
					$out = array();
					foreach ( $c->plugins() as $slug => $row ) {
						if ( ! empty( $row['active'] ) && 'estasoft-handover-passport' !== $slug && ! isset( $known[ 'plugin:' . strtolower( (string) $slug ) ] ) ) {
							$out[] = Candidate::keyed( 'plugin', (string) $slug, Level::INFO, array( 'name' => (string) ( $row['name'] ?? $slug ) ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: %s: plugin name. */
					return sprintf( __( 'The active plugin %s has no purpose recorded.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
		);
	}
}
