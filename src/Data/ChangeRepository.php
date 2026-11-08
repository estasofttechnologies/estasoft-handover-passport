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
	 * @param array{kind?: string, since?: string, subject_type?: string, subject_id?: int, batch_id?: string, kinds?: list<string>} $filters Filters.
	 * @param int                                                                                                                      $limit   Rows to return, 0 for all.
	 * @param int                                                                                                                      $offset  Rows to skip.
	 * @return list<array<string, mixed>>
	 */
	public function search( array $filters = array(), int $limit = 50, int $offset = 0 ): array {
		[ $extra, $params ] = $this->filterSql( $filters );
		return $this->query( array(), 'changed_at', 'DESC', $limit, $offset, $extra, $params );
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
		return array( implode( ' AND ', $parts ), $params );
	}
}
