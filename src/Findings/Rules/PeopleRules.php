<?php
/**
 * Rules about people, ownership and access (HP-PPL, HP-OWN, HP-ACC).
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
use Estasoft\HandoverPassport\Services\PeopleService;

defined( 'ABSPATH' ) || exit;

/**
 * People, ownership and access rules.
 */
final class PeopleRules extends Base {

	/**
	 * Whether a person has left as of today.
	 *
	 * @param array<string, mixed> $person Person.
	 * @param Context              $c      Context.
	 */
	private static function hasLeft( array $person, Context $c ): bool {
		$left = (string) ( $person['left_on'] ?? '' );
		return ( '' !== $left && $left <= $c->today ) || empty( $person['active'] );
	}

	/**
	 * Kinds that carry ownership fields.
	 *
	 * @return array<int, string>
	 */
	private static function owned(): array {
		return array( Kinds::LICENCE, Kinds::DOMAIN, Kinds::HOST, Kinds::LAYER, Kinds::SERVICE, Kinds::BACKUP, Kinds::ASSET );
	}

	/**
	 * The rules.
	 *
	 * @return array<int, Rule>
	 */
	public static function all(): array {
		return array(
			new Rule(
				'HP-PPL-001',
				'people',
				Level::NEEDS,
				true,
				__( 'No site owner or emergency contact', 'estasoft-handover-passport' ),
				self::people( 'people' ),
				static function ( Context $c ): array {
					$out = array();
					foreach ( array( 'site_owner', 'emergency_contact' ) as $role ) {
						if ( array() === ( $c->role_holders[ $role ] ?? array() ) ) {
							$out[] = Candidate::keyed( 'role', $role, Level::NEEDS, array( 'role' => $role ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					$label = PeopleService::roles()[ $v['role'] ] ?? (string) $v['role'];
					/* translators: %s: role such as Site owner. */
					return sprintf( __( 'Nobody holds the role %s.', 'estasoft-handover-passport' ), $label );
				}
			),
			new Rule(
				'HP-PPL-002',
				'people',
				Level::WORTH,
				true,
				__( 'Emergency contact cannot be reached urgently', 'estasoft-handover-passport' ),
				self::people( 'people' ),
				static function ( Context $c ): array {
					$out = array();
					foreach ( $c->role_holders['emergency_contact'] ?? array() as $id ) {
						$person = $c->person( (int) $id );
						if ( null !== $person && '' === trim( (string) $person['urgent_method'] ) ) {
							$out[] = Candidate::row( 'person', (int) $id, Level::WORTH, array( 'name' => (string) $person['name'] ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: %s: person name. */
					return sprintf( __( 'The emergency contact %s has no way to be reached urgently recorded.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
			new Rule(
				'HP-PPL-003',
				'people',
				Level::NEEDS,
				true,
				__( 'Former person still holds a role, asset or access', 'estasoft-handover-passport' ),
				self::people( 'people' ),
				static function ( Context $c ): array {
					$out = array();
					foreach ( $c->people as $person ) {
						if ( ! self::hasLeft( $person, $c ) ) {
							continue;
						}
						$roles  = count( (array) $person['roles'] );
						$assets = count( $c->assets_by_person[ (int) $person['id'] ] ?? array() );
						if ( $roles + $assets > 0 ) {
							$out[] = Candidate::row( 'person', (int) $person['id'], Level::NEEDS, array( 'name' => (string) $person['name'], 'roles' => $roles, 'assets' => $assets ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: 1: person name, 2: number of roles, 3: number of assets or access entries. */
					return sprintf( __( '%1$s is marked as having left but still holds %2$d roles and is linked to %3$d assets or access entries.', 'estasoft-handover-passport' ), $v['name'], (int) $v['roles'], (int) $v['assets'] );
				}
			),
			new Rule(
				'HP-PPL-004',
				'people',
				Level::WORTH,
				true,
				__( 'One person is the only administrator or account holder', 'estasoft-handover-passport' ),
				self::people( 'ownership' ),
				static function ( Context $c ): array {
					$out = array();
					foreach ( array( Kinds::DOMAIN, Kinds::HOST, Kinds::BACKUP ) as $kind ) {
						foreach ( $c->items( $kind ) as $record ) {
							$ids = array_unique( array_merge( Context::owners( $record, 'administrators' ), Context::owners( $record, 'account_holder' ) ) );
							if ( 1 === count( $ids ) ) {
								$person = $c->person( (int) reset( $ids ) );
								$out[]  = Candidate::item( (int) $record['id'], Level::WORTH, array( 'name' => self::nameOf( $record ), 'person' => null === $person ? '' : (string) $person['name'] ) );
							}
						}
					}
					foreach ( $c->items( Kinds::LAYER ) as $record ) {
						if ( 'dns' !== Context::field( $record, 'layer' ) ) {
							continue;
						}
						$ids = array_unique( array_merge( Context::owners( $record, 'administrators' ), Context::owners( $record, 'account_holder' ) ) );
						if ( 1 === count( $ids ) ) {
							$person = $c->person( (int) reset( $ids ) );
							$out[]  = Candidate::item( (int) $record['id'], Level::WORTH, array( 'name' => self::nameOf( $record ), 'person' => null === $person ? '' : (string) $person['name'] ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: 1: person name, 2: asset name. */
					return sprintf( __( '%1$s is the only administrator or account holder of %2$s.', 'estasoft-handover-passport' ), '' !== (string) $v['person'] ? $v['person'] : __( 'One person', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
			new Rule(
				'HP-OWN-001',
				'ownership',
				Level::NEEDS,
				true,
				__( 'Legal owner of a domain, host or backup is unknown', 'estasoft-handover-passport' ),
				self::people( 'ownership' ),
				static function ( Context $c ): array {
					$out = array();
					foreach ( array( Kinds::DOMAIN, Kinds::HOST, Kinds::BACKUP ) as $kind ) {
						foreach ( $c->items( $kind ) as $record ) {
							if ( in_array( (string) $record['legal_owner'], array( '', 'unknown' ), true ) ) {
								$out[] = Candidate::item( (int) $record['id'], Level::NEEDS, array( 'name' => self::nameOf( $record ) ) );
							}
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: %s: asset name. */
					return sprintf( __( 'The legal owner of %s is not known.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
			new Rule(
				'HP-OWN-002',
				'ownership',
				Level::WORTH,
				true,
				__( 'Transfer to the client not done', 'estasoft-handover-passport' ),
				self::people( 'ownership' ),
				static function ( Context $c ): array {
					$out = array();
					foreach ( self::owned() as $kind ) {
						foreach ( $c->items( $kind ) as $record ) {
							if ( in_array( (string) $record['legal_owner'], array( 'agency', 'third_party' ), true ) && ! in_array( Context::field( $record, 'transfer_status' ), array( 'done', 'not_needed' ), true ) ) {
								$out[] = Candidate::item( (int) $record['id'], Level::WORTH, array( 'name' => self::nameOf( $record ), 'owner' => (string) $record['legal_owner'] ) );
							}
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: %s: asset name. */
					return sprintf( __( 'The transfer of %s to the client is not marked Done.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
			new Rule(
				'HP-ACC-001',
				'access',
				Level::WORTH,
				true,
				__( 'WordPress administrator not in the directory', 'estasoft-handover-passport' ),
				self::people( 'access' ),
				static function ( Context $c ): ?array {
					if ( ! $c->hasFacts() ) {
						return null;
					}
					$emails = array();
					foreach ( $c->people as $person ) {
						if ( '' !== (string) $person['email'] ) {
							$emails[ strtolower( (string) $person['email'] ) ] = true;
						}
					}
					$out = array();
					foreach ( (array) $c->fact( 'users.admins', array() ) as $admin ) {
						$email = strtolower( (string) ( $admin['email'] ?? '' ) );
						if ( '' === $email || ! isset( $emails[ $email ] ) ) {
							$out[] = Candidate::keyed( 'user', (string) ( $admin['id'] ?? $admin['name'] ?? '' ), Level::WORTH, array( 'name' => (string) ( $admin['name'] ?? '' ) ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					/* translators: %s: WordPress user name. */
					return sprintf( __( 'The WordPress administrator %s is not in the people directory.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
			new Rule(
				'HP-ACC-002',
				'access',
				Level::WORTH,
				false,
				__( 'Access entry not reviewed for a long time', 'estasoft-handover-passport' ),
				self::people( 'access' ),
				static function ( Context $c ): array {
					$limit = $c->threshold( 'access_review_days', 90 );
					$out   = array();
					foreach ( $c->items( Kinds::ACCESS ) as $entry ) {
						$last = Context::field( $entry, 'last_reviewed' );
						$base = '' !== $last ? $last : substr( (string) $entry['created_at'], 0, 10 );
						$days = self::days( $base, $c->today );
						if ( null !== $days && $days > $limit ) {
							$out[] = Candidate::item( (int) $entry['id'], Level::WORTH, array( 'name' => self::nameOf( $entry ), 'date' => $last, 'days' => $days ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					if ( '' === (string) ( $v['date'] ?? '' ) ) {
						/* translators: 1: system name, 2: number of days. */
						return sprintf( __( 'Access to %1$s has not been reviewed since it was added %2$d days ago.', 'estasoft-handover-passport' ), $v['name'], (int) $v['days'] );
					}
					/* translators: 1: system name, 2: date, 3: number of days. */
					return sprintf( __( 'Access to %1$s was last reviewed on %2$s, %3$d days ago.', 'estasoft-handover-passport' ), $v['name'], self::when( $v['date'] ), (int) $v['days'] );
				},
				'access_review_days'
			),
			new Rule(
				'HP-ACC-003',
				'access',
				Level::INFO,
				false,
				__( 'Critical system signs in with a password only', 'estasoft-handover-passport' ),
				self::people( 'access' ),
				static function ( Context $c ): array {
					$out = array();
					foreach ( $c->items( Kinds::ACCESS ) as $entry ) {
						if ( 'yes' === Context::field( $entry, 'critical' ) && in_array( Context::field( $entry, 'signin' ), array( '', 'password_only', 'unknown' ), true ) ) {
							$out[] = Candidate::item( (int) $entry['id'], Level::INFO, array( 'name' => self::nameOf( $entry ), 'unknown' => 'password_only' !== Context::field( $entry, 'signin' ) ) );
						}
					}
					return $out;
				},
				static function ( array $v ): string {
					if ( ! empty( $v['unknown'] ) ) {
						/* translators: %s: system name. */
						return sprintf( __( 'The sign-in method for the critical system %s is not recorded.', 'estasoft-handover-passport' ), $v['name'] );
					}
					/* translators: %s: system name. */
					return sprintf( __( 'The critical system %s is recorded as signing in with a password only.', 'estasoft-handover-passport' ), $v['name'] );
				}
			),
		);
	}
}
