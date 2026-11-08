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

	/**
	 * {@inheritDoc}
	 */
	protected function key(): string {
		return 'changes';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function jsonColumns(): array {
		return array( 'before_data', 'after_data' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function columns(): array {
		return array( 'changed_at', 'kind', 'subject_type', 'subject_id', 'subject_label', 'before_data', 'after_data', 'actor_id', 'actor_label', 'source', 'batch_id', 'note', 'sensitive' );
	}

	/**
	 * Entries with optional filters, newest first.
	 *
	 * The filters are kind, kinds, since, after_id (only entries logged after the one with this number), subject_type, subject_id, batch_id, search (text in the label or note) and
	 * visible_only, which leaves out every entry that would be masked for someone without the Sensitive capability; with
	 * it, hidden_items lists the numbers of the records that are Sensitive now.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param int                  $limit   Rows to return, 0 for all.
	 * @param int                  $offset  Rows to skip.
	 * @param string               $order   DESC for newest first, ASC for oldest first.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( array $filters = array(), int $limit = 50, int $offset = 0, string $order = 'DESC' ): array {
		[ $extra, $params ] = $this->filterSql( $filters );
		return $this->query( array(), 'changed_at', $order, $limit, $offset, $extra, $params );
	}

	/**
	 * The highest entry number. It only grows, so a different number means something was logged since.
	 */
	public function latestId(): int {
		return (int) $this->db()->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$this->table()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, no variables.
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
	 * Entries a WordPress user made (privacy export).
	 *
	 * @param array<int, int> $user_ids WordPress user numbers.
	 * @param int             $limit    Rows to return, 0 for all.
	 * @param int             $offset   Rows to skip.
	 * @return array<int, array<string, mixed>>
	 */
	public function byActors( array $user_ids, int $limit = 0, int $offset = 0 ): array {
		$user_ids = $this->numbers( $user_ids );
		if ( array() === $user_ids ) {
			return array();
		}
		return $this->query( array(), 'changed_at', 'ASC', $limit, $offset, 'actor_id IN (' . implode( ',', array_fill( 0, count( $user_ids ), '%d' ) ) . ')', $user_ids );
	}

	/**
	 * Replace the name of a WordPress user in the entries they made and the entries about them. This is one of two
	 * places that change stored entries (the other is relabel()), and it runs only when a person's personal-data erase
	 * request is processed.
	 *
	 * Entries about an account are found by the user number. A scan writes "administrator added" without a number, so
	 * those are found by the name the scan saw.
	 *
	 * @param array<int, int>    $user_ids WordPress user numbers.
	 * @param array<int, string> $names    Names the user went by in the scans.
	 * @param string             $label    Neutral name to write instead.
	 * @return int Entries changed.
	 */
	public function anonymiseUsers( array $user_ids, array $names, string $label ): int {
		$user_ids = $this->numbers( $user_ids );
		$names    = array_values( array_filter( array_unique( array_map( 'strval', $names ) ), static fn( string $name ): bool => '' !== $name ) );
		$changed  = 0;
		if ( array() !== $user_ids ) {
			$marks    = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
			$changed += (int) $this->db()->query( $this->db()->prepare( "UPDATE {$this->table()} SET actor_id = 0, actor_label = %s WHERE actor_id IN ({$marks})", $label, ...$user_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed table name; one placeholder per ID.
			$changed += (int) $this->db()->query( $this->db()->prepare( "UPDATE {$this->table()} SET subject_label = %s WHERE subject_type = 'user' AND subject_id IN ({$marks})", $label, ...$user_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed table name; one placeholder per ID.
		}
		if ( array() !== $names ) {
			$marks    = implode( ',', array_fill( 0, count( $names ), '%s' ) );
			$changed += (int) $this->db()->query( $this->db()->prepare( "UPDATE {$this->table()} SET subject_label = %s WHERE subject_type = 'user' AND subject_id = 0 AND subject_label IN ({$marks})", $label, ...$names ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed table name; one placeholder per name.
		}
		return $changed;
	}

	/**
	 * Replace the name of a subject, such as a person in the directory, in its entries (privacy erase).
	 *
	 * @param string $subject_type Subject type.
	 * @param int    $subject_id   Subject number.
	 * @param string $label        Neutral name to write instead.
	 * @return int Entries changed.
	 */
	public function relabel( string $subject_type, int $subject_id, string $label ): int {
		$done = $this->db()->update( $this->table(), array( 'subject_label' => $label ), array( 'subject_type' => $subject_type, 'subject_id' => $subject_id ) );
		return false === $done ? 0 : (int) $done;
	}

	/**
	 * Whole numbers above zero, without repeats.
	 *
	 * @param array<mixed> $values Candidate values.
	 * @return array<int, int>
	 */
	private function numbers( array $values ): array {
		return array_values( array_unique( array_filter( array_map( 'intval', $values ), static fn( int $n ): bool => $n > 0 ) ) );
	}

	/**
	 * Build the WHERE fragment for the filters.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array{0: string, 1: array<int, mixed>}
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
		if ( ! empty( $filters['after_id'] ) ) {
			$parts[]  = 'id > %d';
			$params[] = (int) $filters['after_id'];
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
			$parts[] = '`sensitive` = 0';
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
