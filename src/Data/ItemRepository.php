<?php
/**
 * Register records (stack notes, licences, domains, hosts, services, backups, access, URLs, assets).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Data;

use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Access to the items table.
 */
class ItemRepository extends Repository {

	protected function key(): string {
		return 'items';
	}

	protected function jsonColumns(): array {
		return array( 'data' );
	}

	protected function columns(): array {
		return array(
			'kind',
			'subject_key',
			'title',
			'data',
			'source',
			'vis_client',
			'vis_emergency',
			'sensitive',
			'legal_owner',
			'due_date',
			'status',
			'review_days',
			'verified_at',
			'verified_by',
			'created_at',
			'created_by',
			'updated_at',
			'deleted_at',
		);
	}

	/**
	 * Live (not deleted) records of one kind.
	 *
	 * @param string $kind Kind key.
	 * @return list<array<string, mixed>>
	 */
	public function ofKind( string $kind ): array {
		return $this->query( array( 'kind' => $kind, 'deleted_at' => null ), 'title', 'ASC' );
	}

	/**
	 * Every live record.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function allLive(): array {
		return $this->query( array( 'deleted_at' => null ), 'kind', 'ASC' );
	}

	/**
	 * One live record by kind and subject key.
	 *
	 * @param string $kind    Kind key.
	 * @param string $subject Subject key.
	 * @return array<string, mixed>|null
	 */
	public function bySubject( string $kind, string $subject ): ?array {
		$rows = $this->query( array( 'kind' => $kind, 'subject_key' => $subject, 'deleted_at' => null ), 'id', 'ASC', 1 );
		return $rows[0] ?? null;
	}

	/**
	 * Records with a due date inside a window.
	 *
	 * @param string $from First date, Y-m-d.
	 * @param string $to   Last date, Y-m-d.
	 * @return list<array<string, mixed>>
	 */
	public function dueBetween( string $from, string $to ): array {
		return $this->query( array( 'deleted_at' => null ), 'due_date', 'ASC', 0, 0, 'due_date IS NOT NULL AND due_date >= %s AND due_date <= %s', array( $from, $to ) );
	}

	/**
	 * IDs among the given ones that are marked Sensitive, deleted or not.
	 *
	 * @param list<int> $ids Record IDs.
	 * @return list<int>
	 */
	public function sensitiveIds( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql   = $this->db()->prepare( "SELECT id FROM {$this->table()} WHERE sensitive = 1 AND id IN ({$marks})", ...$ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name; one placeholder per ID.
		return array_map( 'intval', (array) $this->db()->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above.
	}

	/**
	 * Live records whose title or text contains a string (privacy tools).
	 *
	 * @param string $needle Text to look for, such as an email address.
	 * @return list<array<string, mixed>>
	 */
	public function mentioning( string $needle ): array {
		if ( '' === $needle ) {
			return array();
		}
		$like = '%' . $this->db()->esc_like( $needle ) . '%';
		return $this->query( array( 'deleted_at' => null ), 'id', 'ASC', 0, 0, 'title LIKE %s OR data LIKE %s', array( $like, $like ) );
	}

	/**
	 * The numbers of every record marked Sensitive, deleted ones included.
	 *
	 * @return list<int>
	 */
	public function allSensitiveIds(): array {
		$sql = "SELECT id FROM {$this->table()} WHERE sensitive = 1";
		return array_map( 'intval', (array) $this->db()->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed table name, no input.
	}

	/**
	 * Records deleted but not yet purged (for Undo).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function deleted(): array {
		return $this->query( array(), 'deleted_at', 'DESC', 0, 0, 'deleted_at IS NOT NULL' );
	}

	/**
	 * Mark a record deleted (PRT-9, DAT-6).
	 *
	 * @param int $id Record ID.
	 */
	public function softDelete( int $id ): bool {
		return $this->update( $id, array( 'deleted_at' => Clock::sql() ) );
	}

	/**
	 * Bring a deleted record back.
	 *
	 * @param int $id Record ID.
	 */
	public function restore( int $id ): bool {
		return $this->update( $id, array( 'deleted_at' => null ) );
	}

	/**
	 * Remove records deleted before a moment. Returns the IDs removed so links can follow.
	 *
	 * @param string $before UTC date-time.
	 * @return list<int>
	 */
	public function purgeDeletedBefore( string $before ): array {
		$rows = $this->query( array(), 'id', 'ASC', 0, 0, 'deleted_at IS NOT NULL AND deleted_at < %s', array( $before ) );
		$ids  = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			if ( $this->delete( $id ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Live record counts by kind.
	 *
	 * @return array<string, int>
	 */
	public function countsByKind(): array {
		$rows = $this->db()->get_results( "SELECT kind, COUNT(*) AS n FROM {$this->table()} WHERE deleted_at IS NULL GROUP BY kind", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, no variables.
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['kind'] ] = (int) $row['n'];
		}
		return $out;
	}
}
