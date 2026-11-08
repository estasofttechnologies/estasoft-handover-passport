<?php
/**
 * Change log, maintenance notes and incidents (CHG-1 to CHG-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only access to the changes table.
 */
class ChangeRepository extends Repository {

	protected function key(): string {
		return 'changes';
	}

	protected function jsonColumns(): array {
		return array( 'before_data', 'after_data' );
	}

	protected function columns(): array {
		return array( 'changed_at', 'kind', 'subject_type', 'subject_id', 'subject_label', 'before_data', 'after_data', 'actor_id', 'actor_label', 'source', 'batch_id', 'note', 'sensitive' );
	}

	/**
	 * Entries with optional filters, newest first.
	 *
	 * The filters are kind, kinds, since, subject_type, subject_id, batch_id, search (text in the label or note) and
	 * visible_only, which leaves out every entry that would be masked for someone without the Sensitive capability; with
	 * it, hidden_items lists the numbers of the records that are Sensitive now.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param int                  $limit   Rows to return, 0 for all.
	 * @param int                  $offset  Rows to skip.
	 * @param string               $order   DESC for newest first, ASC for oldest first.
	 * @return list<array<string, mixed>>
	 */
	public function search( array $filters = array(), int $limit = 50, int $offset = 0, string $order = 'DESC' ): array {
		[ $extra, $params ] = $this->filterSql( $filters );
		return $this->query( array(), 'changed_at', $order, $limit, $offset, $extra, $params );
	}

	/**
	 * Number of entries for the same filters.
	 *
	 * @param array<string, mixed> $filters Filters as in search().
	 */
	public function countFor( array $filters = array() ): int {
		[ $extra, $params ] = $this->filterSql( $filters );
		return $this->count( '' === $extra ? '' : $extra, $params );
	}

	/**
	 * Remove entries older than a moment (retention, CHG-6).
	 *
	 * @param string $before UTC date-time.
	 */
	public function purgeBefore( string $before ): void {
		$this->db()->query( $this->db()->prepare( "DELETE FROM {$this->table()} WHERE changed_at < %s", $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed table name; retention task.
	}

	/**
	 * Build the WHERE fragment for the filters.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function filterSql( array $filters ): array {
		$parts  = array();
		$params = array();
		if ( ! empty( $filters['kind'] ) ) {
			$parts[]  = 'kind = %s';
			$params[] = (string) $filters['kind'];
		}
		if ( ! empty( $filters['kinds'] ) && is_array( $filters['kinds'] ) ) {
			$parts[] = 'kind IN (' . implode( ',', array_fill( 0, count( $filters['kinds'] ), '%s' ) ) . ')';
			foreach ( $filters['kinds'] as $kind ) {
				$params[] = (string) $kind;
			}
		}
		if ( ! empty( $filters['since'] ) ) {
			$parts[]  = 'changed_at > %s';
			$params[] = (string) $filters['since'];
		}
		if ( ! empty( $filters['subject_type'] ) ) {
			$parts[]  = 'subject_type = %s';
			$params[] = (string) $filters['subject_type'];
		}
		if ( isset( $filters['subject_id'] ) ) {
			$parts[]  = 'subject_id = %d';
			$params[] = (int) $filters['subject_id'];
		}
		if ( ! empty( $filters['batch_id'] ) ) {
			$parts[]  = 'batch_id = %s';
			$params[] = (string) $filters['batch_id'];
		}
		if ( isset( $filters['search'] ) && '' !== (string) $filters['search'] ) {
			$like     = '%' . $this->db()->esc_like( (string) $filters['search'] ) . '%';
			$parts[]  = '(subject_label LIKE %s OR note LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $filters['visible_only'] ) ) {
			$parts[] = 'sensitive = 0';
			$hidden  = array_values( array_unique( array_map( 'intval', (array) ( $filters['hidden_items'] ?? array() ) ) ) );
			if ( array() !== $hidden ) {
				$parts[] = "NOT (subject_type = 'item' AND subject_id IN (" . implode( ',', array_fill( 0, count( $hidden ), '%d' ) ) . '))';
				foreach ( $hidden as $id ) {
					$params[] = $id;
				}
			}
		}
		return array( implode( ' AND ', $parts ), $params );
	}
}
