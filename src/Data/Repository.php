<?php
/**
 * Base class for table repositories.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Data;

use Estasoft\HandoverPassport\Support\Json;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Thin, prepared-statement access to one table. Table and column names are fixed in code.
 */
abstract class Repository {

	/**
	 * Short table key from Tables::KEYS.
	 */
	abstract protected function key(): string;

	/**
	 * Columns stored as JSON text.
	 *
	 * @return array<int, string>
	 */
	protected function jsonColumns(): array {
		return array();
	}

	/**
	 * Columns that may be written. Anything else is dropped.
	 *
	 * @return array<int, string>
	 */
	abstract protected function columns(): array;

	/**
	 * Full table name.
	 */
	public function table(): string {
		return Tables::name( $this->key() );
	}

	/**
	 * Database handle.
	 */
	protected function db(): wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Find one row by ID.
	 *
	 * @param int $id Row ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$row = $this->db()->get_row(
			$this->db()->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from a fixed list.
			ARRAY_A
		);
		return is_array( $row ) ? $this->decode( $row ) : null;
	}

	/**
	 * Insert a row and return its ID, or 0 on failure.
	 *
	 * @param array<string, mixed> $row Column values.
	 */
	public function insert( array $row ): int {
		$row = $this->encode( $this->only( $row ) );
		if ( false === $this->db()->insert( $this->table(), $row ) ) {
			return 0;
		}
		return (int) $this->db()->insert_id;
	}

	/**
	 * Update a row by ID.
	 *
	 * @param int                  $id  Row ID.
	 * @param array<string, mixed> $row Column values to change.
	 */
	public function update( int $id, array $row ): bool {
		$row = $this->encode( $this->only( $row ) );
		if ( array() === $row ) {
			return true;
		}
		return false !== $this->db()->update( $this->table(), $row, array( 'id' => $id ) );
	}

	/**
	 * Delete a row for good.
	 *
	 * @param int $id Row ID.
	 */
	public function delete( int $id ): bool {
		return false !== $this->db()->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * The IDs among the given ones that exist in the table, deleted or not.
	 *
	 * @param array<int, int> $ids IDs.
	 * @return array<int, int>
	 */
	public function existingIds( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql   = $this->db()->prepare( "SELECT id FROM {$this->table()} WHERE id IN ({$marks})", ...$ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name; one placeholder per ID.
		return array_map( 'intval', (array) $this->db()->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above.
	}

	/**
	 * Count rows with an optional prepared WHERE fragment.
	 *
	 * @param string            $where  Fragment such as "state = %s", already containing placeholders.
	 * @param array<int, mixed> $params Values for the placeholders.
	 */
	public function count( string $where = '', array $params = array() ): int {
		$sql = "SELECT COUNT(*) FROM {$this->table()}" . ( '' === $where ? '' : ' WHERE ' . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is fixed; the fragment is built in code with placeholders.
		if ( array() !== $params ) {
			$sql = $this->db()->prepare( $sql, ...$params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fragment is code-built with placeholders.
		}
		return (int) $this->db()->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * The greatest value of a column among rows with no deleted time, or null when there are none.
	 *
	 * @param string $column A column of this table (checked against the fixed list).
	 * @return string|null
	 */
	public function maxOf( string $column ): ?string {
		if ( ! in_array( $column, $this->columns(), true ) ) {
			return null;
		}
		$where = in_array( 'deleted_at', $this->columns(), true ) ? ' WHERE deleted_at IS NULL' : '';
		$value = $this->db()->get_var( "SELECT MAX({$column}) FROM {$this->table()}{$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table and column names come from fixed lists.
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Select rows matching equality filters. Column names are checked against the fixed list.
	 *
	 * @param array<string, mixed> $equals  Column => value. A null value means IS NULL.
	 * @param string               $orderby Column to sort by.
	 * @param string               $order   ASC or DESC.
	 * @param int                  $limit   Maximum rows, 0 for all.
	 * @param int                  $offset  Rows to skip.
	 * @param string               $extra   Extra code-built fragment with placeholders, joined with AND.
	 * @param array<int, mixed>    $params  Values for the extra fragment.
	 * @return array<int, array<string, mixed>>
	 */
	public function query( array $equals = array(), string $orderby = 'id', string $order = 'DESC', int $limit = 0, int $offset = 0, string $extra = '', array $params = array() ): array {
		$allowed = array_merge( $this->columns(), array( 'id' ) );
		$where   = array();
		$values  = array();
		foreach ( $equals as $column => $value ) {
			if ( ! in_array( $column, $allowed, true ) ) {
				continue;
			}
			if ( null === $value ) {
				$where[] = "`{$column}` IS NULL";
			} else {
				$where[]  = "`{$column}` = " . ( is_int( $value ) ? '%d' : '%s' );
				$values[] = $value;
			}
		}
		if ( '' !== $extra ) {
			$where[] = '(' . $extra . ')';
			$values  = array_merge( $values, $params );
		}
		$orderby = in_array( $orderby, $allowed, true ) ? $orderby : 'id';
		$order   = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$sql     = "SELECT * FROM {$this->table()}" . ( array() === $where ? '' : ' WHERE ' . implode( ' AND ', $where ) ) . " ORDER BY `{$orderby}` {$order}, id {$order}";
		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$values[] = $limit;
			$values[] = max( 0, $offset );
		}
		return $this->rows( $sql, $values );
	}

	/**
	 * Run a prepared SELECT and decode the rows.
	 *
	 * @param string            $sql    SQL with placeholders. Built in code from fixed pieces.
	 * @param array<int, mixed> $params Values for the placeholders.
	 * @return array<int, array<string, mixed>>
	 */
	protected function rows( string $sql, array $params = array() ): array {
		if ( array() !== $params ) {
			$sql = $this->db()->prepare( $sql, ...$params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is code-built with placeholders.
		}
		$rows = $this->db()->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_map( fn( array $row ): array => $this->decode( $row ), $rows );
	}

	/**
	 * Keep only writable columns.
	 *
	 * @param array<string, mixed> $row Candidate values.
	 * @return array<string, mixed>
	 */
	private function only( array $row ): array {
		return array_intersect_key( $row, array_flip( array_merge( $this->columns(), array( 'id' ) ) ) );
	}

	/**
	 * Turn JSON columns into arrays.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	protected function decode( array $row ): array {
		foreach ( $this->jsonColumns() as $column ) {
			if ( array_key_exists( $column, $row ) ) {
				$row[ $column ] = Json::decode( $row[ $column ] );
			}
		}
		return $row;
	}

	/**
	 * Turn arrays in JSON columns into text.
	 *
	 * @param array<string, mixed> $row Values.
	 * @return array<string, mixed>
	 */
	protected function encode( array $row ): array {
		foreach ( $this->jsonColumns() as $column ) {
			if ( array_key_exists( $column, $row ) && ! is_string( $row[ $column ] ) ) {
				$row[ $column ] = Json::encode( $row[ $column ] );
			}
		}
		return $row;
	}
}
