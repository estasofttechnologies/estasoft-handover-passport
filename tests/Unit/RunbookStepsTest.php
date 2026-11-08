<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Domain\RunbookSteps;
use PHPUnit\Framework\TestCase;

/**
 * The step editor posts loose rows; this turns them into an ordered list of clean steps (RUN-2).
 */
final class RunbookStepsTest extends TestCase {

	private const ROLES = array( 'site_owner' => 'Site owner', 'technical_maintainer' => 'Technical maintainer' );

	public function test_rows_are_ordered_by_position_then_by_row(): void {
		$steps = RunbookSteps::clean(
			array(
				array( 'text' => 'C', 'position' => '3' ),
				array( 'text' => 'A', 'position' => '1' ),
				array( 'text' => 'B', 'position' => '2' ),
				array( 'text' => 'B2', 'position' => '2' ),
			),
			self::ROLES
		);
		$this->assertSame( array( 'A', 'B', 'B2', 'C' ), array_column( $steps, 'text' ) );
	}

	public function test_a_row_without_a_position_keeps_its_place_in_the_form(): void {
		$steps = RunbookSteps::clean( array( array( 'text' => 'one' ), array( 'text' => 'two' ), array( 'text' => 'three' ) ), self::ROLES );
		$this->assertSame( array( 'one', 'two', 'three' ), array_column( $steps, 'text' ) );
	}

	public function test_empty_and_removed_rows_are_dropped(): void {
		$steps = RunbookSteps::clean( array( array( 'text' => '   ' ), array( 'text' => 'keep' ), array( 'text' => 'gone', 'remove' => '1' ), 'not a row' ), self::ROLES );
		$this->assertSame( array( 'keep' ), array_column( $steps, 'text' ) );
	}

	public function test_a_duplicate_is_followed_by_a_copy(): void {
		$steps = RunbookSteps::clean( array( array( 'text' => 'again', 'duplicate' => '1', 'minutes' => '4' ), array( 'text' => 'last' ) ), self::ROLES );
		$this->assertSame( array( 'again', 'again', 'last' ), array_column( $steps, 'text' ) );
		$this->assertSame( 4, $steps[1]['minutes'] );
	}

	public function test_role_link_minutes_and_warning_are_cleaned(): void {
		$steps = RunbookSteps::clean(
			array(
				array( 'text' => 'a', 'who' => 'site_owner', 'minutes' => '12', 'link' => 'https://example.com/x', 'warning' => '1' ),
				array( 'text' => 'b', 'who' => 'nobody', 'minutes' => '-5', 'link' => 'javascript:alert(1)', 'warning' => '0' ),
				array( 'text' => 'c', 'minutes' => '99999', 'link' => 'ftp://example.com/file' ),
			),
			self::ROLES
		);
		$this->assertSame( 'site_owner', $steps[0]['who'] );
		$this->assertSame( 12, $steps[0]['minutes'] );
		$this->assertSame( 'https://example.com/x', $steps[0]['link'] );
		$this->assertTrue( $steps[0]['warning'] );
		$this->assertSame( '', $steps[1]['who'] );
		$this->assertSame( 0, $steps[1]['minutes'] );
		$this->assertSame( '', $steps[1]['link'] );
		$this->assertFalse( $steps[1]['warning'] );
		$this->assertSame( 10000, $steps[2]['minutes'] );
		$this->assertSame( '', $steps[2]['link'] );
	}

	public function test_text_is_limited_and_non_scalar_cells_are_ignored(): void {
		$steps = RunbookSteps::clean( array( array( 'text' => str_repeat( 'x', 2000 ), 'who' => array( 'site_owner' ), 'minutes' => array( 5 ) ) ), self::ROLES );
		$this->assertSame( RunbookSteps::MAX_TEXT, mb_strlen( $steps[0]['text'] ) );
		$this->assertSame( '', $steps[0]['who'] );
		$this->assertSame( 0, $steps[0]['minutes'] );
	}

	public function test_the_list_is_capped(): void {
		$rows = array();
		for ( $i = 0; $i < RunbookSteps::MAX_STEPS + 10; $i++ ) {
			$rows[] = array( 'text' => 'step ' . $i );
		}
		$this->assertCount( RunbookSteps::MAX_STEPS, RunbookSteps::clean( $rows, self::ROLES ) );
	}

	public function test_minutes_are_summed(): void {
		$this->assertSame( 9, RunbookSteps::minutes( array( array( 'minutes' => 4 ), array( 'minutes' => 5 ), array( 'text' => 'no minutes' ) ) ) );
		$this->assertSame( 0, RunbookSteps::minutes( array() ) );
	}
}
