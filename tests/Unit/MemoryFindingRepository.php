<?php
/**
 * A findings store that lives in memory, so the engine can be tested without a database.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Data\FindingRepository;

/**
 * In-memory stand-in for the findings table.
 */
final class MemoryFindingRepository extends FindingRepository {

	/**
	 * Rows by ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = array();

	/**
	 * Next ID.
	 *
	 * @var int
	 */
	private int $next = 1;

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $row Row.
	 */
	public function insert( array $row ): int {
		$id                 = $this->next++;
		$this->rows[ $id ] = array_merge( array( 'id' => $id ), $row );
		return $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                  $id  ID.
	 * @param array<string, mixed> $row Changes.
	 */
	public function update( int $id, array $row ): bool {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return false;
		}
		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $row );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id ID.
	 */
	public function find( int $id ): ?array {
		return $this->rows[ $id ] ?? null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $equals  Equality filters.
	 * @param string               $orderby Ignored: rows come in ID order.
	 * @param string               $order   Ignored.
	 * @param int                  $limit   Ignored.
	 * @param int                  $offset  Ignored.
	 * @param string               $extra   Ignored.
	 * @param array<mixed>         $params  Ignored.
	 */
	public function query( array $equals = array(), string $orderby = 'id', string $order = 'DESC', int $limit = 0, int $offset = 0, string $extra = '', array $params = array() ): array {
		$out = array();
		foreach ( $this->rows as $row ) {
			foreach ( $equals as $column => $value ) {
				if ( ( $row[ $column ] ?? null ) !== $value ) {
					continue 2;
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Rows of one rule.
	 *
	 * @param string $rule_id Rule ID.
	 * @return list<array<string, mixed>>
	 */
	public function forRule( string $rule_id ): array {
		return array_values( array_filter( $this->rows, static fn( array $row ): bool => $row['rule_id'] === $rule_id ) );
	}
}
