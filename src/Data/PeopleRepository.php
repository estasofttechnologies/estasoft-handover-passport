<?php
/**
 * The people directory (PPL-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Data;

use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Access to the people table.
 */
class PeopleRepository extends Repository {

	protected function key(): string {
		return 'people';
	}

	protected function jsonColumns(): array {
		return array( 'share_flags' );
	}

	protected function columns(): array {
		return array(
			'name',
			'organisation',
			'kind',
			'title',
			'email',
			'phone',
			'language',
			'timezone',
			'urgent_method',
			'active',
			'left_on',
			'share_flags',
			'note',
			'review_days',
			'verified_at',
			'verified_by',
			'created_at',
			'updated_at',
			'deleted_at',
		);
	}

	/**
	 * Live people sorted by name.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function allLive(): array {
		return $this->query( array( 'deleted_at' => null ), 'name', 'ASC' );
	}

	/**
	 * Live people by email, case-insensitive (privacy tools, ACC-2).
	 *
	 * @param string $email Email address.
	 * @return list<array<string, mixed>>
	 */
	public function byEmail( string $email ): array {
		return $this->query( array(), 'id', 'ASC', 0, 0, 'LOWER(email) = %s', array( strtolower( $email ) ) );
	}

	/**
	 * Mark deleted.
	 *
	 * @param int $id Person ID.
	 */
	public function softDelete( int $id ): bool {
		return $this->update( $id, array( 'deleted_at' => Clock::sql() ) );
	}

	/**
	 * Bring back a deleted person.
	 *
	 * @param int $id Person ID.
	 */
	public function restore( int $id ): bool {
		return $this->update( $id, array( 'deleted_at' => null ) );
	}

	/**
	 * Remove people deleted before a moment.
	 *
	 * @param string $before UTC date-time.
	 * @return list<int> IDs removed.
	 */
	public function purgeDeletedBefore( string $before ): array {
		$ids = array();
		foreach ( $this->query( array(), 'id', 'ASC', 0, 0, 'deleted_at IS NOT NULL AND deleted_at < %s', array( $before ) ) as $row ) {
			if ( $this->delete( (int) $row['id'] ) ) {
				$ids[] = (int) $row['id'];
			}
		}
		return $ids;
	}
}
