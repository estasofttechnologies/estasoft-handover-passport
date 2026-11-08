<?php
/**
 * Relations between rows (roles, account holders, payers, administrators, access).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Access to the links table. A link always runs from a person to something.
 */
class LinkRepository extends Repository {

	public const ROLE_PREFIX = 'role:';
	public const HOLDER      = 'account_holder';
	public const PAYER       = 'payer';
	public const ADMIN       = 'administrator';
	public const ACCESS      = 'has_access';

	protected function key(): string {
		return 'links';
	}

	protected function columns(): array {
		return array( 'from_type', 'from_id', 'to_type', 'to_id', 'relation', 'position' );
	}

	/**
	 * People linked to a target by one relation, in order.
	 *
	 * @param string $relation Relation name.
	 * @param string $to_type  Target type, for example "item" or "passport".
	 * @param int    $to_id    Target ID.
	 * @return list<int> Person IDs.
	 */
	public function people( string $relation, string $to_type, int $to_id ): array {
		$rows = $this->query(
			array(
				'relation' => $relation,
				'to_type'  => $to_type,
				'to_id'    => $to_id,
			),
			'position',
			'ASC'
		);
		return array_map( static fn( array $row ): int => (int) $row['from_id'], $rows );
	}

	/**
	 * Replace the people linked to a target by one relation.
	 *
	 * @param string    $relation   Relation name.
	 * @param string    $to_type    Target type.
	 * @param int       $to_id      Target ID.
	 * @param list<int> $person_ids Person IDs in order.
	 */
	public function setPeople( string $relation, string $to_type, int $to_id, array $person_ids ): void {
		foreach ( $this->query( array( 'relation' => $relation, 'to_type' => $to_type, 'to_id' => $to_id ) ) as $row ) {
			$this->delete( (int) $row['id'] );
		}
		$position = 0;
		foreach ( array_values( array_unique( array_map( 'intval', $person_ids ) ) ) as $person_id ) {
			if ( $person_id > 0 ) {
				$this->insert(
					array(
						'from_type' => 'person',
						'from_id'   => $person_id,
						'to_type'   => $to_type,
						'to_id'     => $to_id,
						'relation'  => $relation,
						'position'  => $position++,
					)
				);
			}
		}
	}

	/**
	 * Every link from one person.
	 *
	 * @param int $person_id Person ID.
	 * @return list<array<string, mixed>>
	 */
	public function forPerson( int $person_id ): array {
		return $this->query( array( 'from_type' => 'person', 'from_id' => $person_id ), 'position', 'ASC' );
	}

	/**
	 * Every link with a relation.
	 *
	 * @param string $relation Relation name.
	 * @return list<array<string, mixed>>
	 */
	public function byRelation( string $relation ): array {
		return $this->query( array( 'relation' => $relation ), 'position', 'ASC' );
	}

	/**
	 * Every link, for building maps in memory.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function everything(): array {
		return $this->query( array(), 'id', 'ASC' );
	}

	/**
	 * Remove every link that points to, or starts at, a row.
	 *
	 * @param string $type Row type ("item", "person", "passport").
	 * @param int    $id   Row ID.
	 */
	public function forget( string $type, int $id ): void {
		$rows = array_merge(
			$this->query( array( 'to_type' => $type, 'to_id' => $id ) ),
			$this->query( array( 'from_type' => $type, 'from_id' => $id ) )
		);
		foreach ( $rows as $row ) {
			$this->delete( (int) $row['id'] );
		}
	}

	/**
	 * Delete a link by ID (used by the repair routine, DAT-8).
	 *
	 * @param int $id Link ID.
	 */
	public function remove( int $id ): bool {
		return $this->delete( $id );
	}
}
