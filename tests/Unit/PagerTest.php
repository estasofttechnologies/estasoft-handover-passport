<?php
/**
 * Tests for the REST pager (API-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Rest\Pager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Estasoft\HandoverPassport\Rest\Pager
 */
final class PagerTest extends TestCase {

	/**
	 * Twelve rows named item 1 to item 12, with a date that runs the other way.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(): array {
		$rows = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$rows[] = array( 'id' => $i, 'title' => 'Item ' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ), 'date' => 2000 - $i );
		}
		return $rows;
	}

	/**
	 * Run the pager over the sample rows.
	 *
	 * @param array<string, mixed> $args Request values.
	 * @return array<string, mixed>
	 */
	private function run( array $args ): array {
		return Pager::apply(
			$this->rows(),
			$args,
			static fn( array $row ): string => $row['title'],
			array(
				'title' => static fn( array $row ): string => $row['title'],
				'date'  => static fn( array $row ): int => $row['date'],
				'id'    => static fn( array $row ): int => $row['id'],
			),
			'title'
		);
	}

	public function test_defaults_to_the_first_page_in_the_default_order(): void {
		$out = $this->run( array() );
		$this->assertSame( 12, $out['total'] );
		$this->assertSame( 1, $out['pages'] );
		$this->assertSame( 1, $out['items'][0]['id'] );
		$this->assertCount( 12, $out['items'] );
	}

	public function test_pages_and_counts(): void {
		$out = $this->run( array( 'per_page' => 5, 'page' => 3 ) );
		$this->assertSame( 12, $out['total'] );
		$this->assertSame( 3, $out['pages'] );
		$this->assertSame( array( 11, 12 ), array_column( $out['items'], 'id' ) );
	}

	public function test_a_page_past_the_end_is_empty_but_keeps_the_totals(): void {
		$out = $this->run( array( 'per_page' => 5, 'page' => 9 ) );
		$this->assertSame( array(), $out['items'] );
		$this->assertSame( 12, $out['total'] );
		$this->assertSame( 3, $out['pages'] );
	}

	public function test_per_page_is_held_between_one_and_one_hundred(): void {
		$this->assertSame( 100, $this->run( array( 'per_page' => 5000 ) )['per_page'] );
		$this->assertSame( 1, $this->run( array( 'per_page' => 0 ) )['per_page'] );
		$this->assertSame( 1, $this->run( array( 'per_page' => -4 ) )['per_page'] );
	}

	public function test_order_by_a_number_in_both_directions(): void {
		$up   = $this->run( array( 'orderby' => 'date', 'order' => 'asc', 'per_page' => 3 ) );
		$down = $this->run( array( 'orderby' => 'date', 'order' => 'desc', 'per_page' => 3 ) );
		$this->assertSame( array( 12, 11, 10 ), array_column( $up['items'], 'id' ) );
		$this->assertSame( array( 1, 2, 3 ), array_column( $down['items'], 'id' ) );
	}

	public function test_text_is_ordered_without_regard_to_case(): void {
		$rows = array( array( 'title' => 'beta' ), array( 'title' => 'Alpha' ), array( 'title' => 'charlie' ) );
		$out  = Pager::apply( $rows, array(), static fn( array $r ): string => $r['title'], array( 'title' => static fn( array $r ): string => $r['title'] ), 'title' );
		$this->assertSame( array( 'Alpha', 'beta', 'charlie' ), array_column( $out['items'], 'title' ) );
	}

	public function test_an_unknown_orderby_falls_back_to_the_default(): void {
		$out = $this->run( array( 'orderby' => 'nonsense; DROP TABLE' ) );
		$this->assertSame( 1, $out['items'][0]['id'] );
	}

	public function test_search_ignores_case_and_counts_only_matches(): void {
		$out = $this->run( array( 'search' => 'ITEM 1' ) );
		$this->assertSame( 3, $out['total'] ); // Item 10, 11 and 12.
		$this->assertSame( 0, $this->run( array( 'search' => 'nothing like this' ) )['total'] );
	}

	public function test_no_rows_means_no_pages(): void {
		$out = Pager::apply( array(), array(), static fn( array $r ): string => '', array( 'id' => static fn( array $r ): int => 0 ), 'id' );
		$this->assertSame( 0, $out['total'] );
		$this->assertSame( 0, $out['pages'] );
		$this->assertSame( array(), $out['items'] );
	}

	public function test_search_with_unicode_text(): void {
		$rows = array( array( 'title' => 'Ünïcode Dömain' ), array( 'title' => 'plain' ) );
		$out  = Pager::apply( $rows, array( 'search' => 'dömain' ), static fn( array $r ): string => $r['title'], array( 'title' => static fn( array $r ): string => $r['title'] ), 'title' );
		$this->assertSame( 1, $out['total'] );
	}
}
