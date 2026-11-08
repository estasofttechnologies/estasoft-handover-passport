<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Support\Dates;
use PHPUnit\Framework\TestCase;

final class DatesTest extends TestCase {

	public function test_only_real_calendar_dates_are_accepted(): void {
		$this->assertTrue( Dates::isDate( '2026-02-28' ) );
		$this->assertTrue( Dates::isDate( '2028-02-29' ) );
		$this->assertFalse( Dates::isDate( '2026-02-29' ) );
		$this->assertFalse( Dates::isDate( '2026-13-01' ) );
		$this->assertFalse( Dates::isDate( '26-01-01' ) );
		$this->assertFalse( Dates::isDate( '' ) );
		$this->assertFalse( Dates::isDate( null ) );
	}

	public function test_days_between_counts_across_month_ends_and_signs(): void {
		$this->assertSame( 31, Dates::daysBetween( '2026-01-01', '2026-02-01' ) );
		$this->assertSame( -1, Dates::daysBetween( '2026-03-01', '2026-02-28' ) );
		$this->assertSame( 0, Dates::daysBetween( '2026-05-05', '2026-05-05' ) );
	}

	public function test_add_days_crosses_a_year_boundary(): void {
		$this->assertSame( '2027-01-01', Dates::addDays( '2026-12-31', 1 ) );
		$this->assertSame( '2026-12-30', Dates::addDays( '2026-12-31', -1 ) );
	}

	public function test_add_months_clamps_to_the_last_day_of_a_short_month(): void {
		$this->assertSame( '2026-02-28', Dates::addMonths( '2026-01-31', 1 ) );
		$this->assertSame( '2028-02-29', Dates::addMonths( '2028-01-31', 1 ) );
		$this->assertSame( '2027-01-31', Dates::addMonths( '2026-01-31', 12 ) );
	}

	public function test_relative_dates_read_in_words(): void {
		$this->assertSame( 'in 25 days', Dates::relative( '2026-12-03', '2026-11-08' ) );
		$this->assertSame( '98 days ago', Dates::relative( '2026-08-02', '2026-11-08' ) );
		$this->assertSame( 'today', Dates::relative( '2026-11-08', '2026-11-08' ) );
		$this->assertSame( 'tomorrow', Dates::relative( '2026-11-09', '2026-11-08' ) );
		$this->assertSame( 'yesterday', Dates::relative( '2026-11-07', '2026-11-08' ) );
		$this->assertSame( '', Dates::relative( '', '2026-11-08' ) );
	}
}
