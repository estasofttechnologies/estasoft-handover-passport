<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Domain\LeadTimes;
use PHPUnit\Framework\TestCase;

/**
 * Which reminder a date has reached decides whether an email goes today (RMD-1, RMD-4, AS-4).
 */
final class LeadTimesTest extends TestCase {

	public function test_text_is_read_into_unique_days_longest_first(): void {
		$this->assertSame( array( 30, 14, 7, 0 ), LeadTimes::parse( '7, 30,14 , 7;0' ) );
	}

	public function test_junk_and_out_of_range_numbers_are_dropped(): void {
		$this->assertSame( array( 365, 1 ), LeadTimes::parse( '1, -5, abc, 366, 365, 2.5, ' ) );
		$this->assertSame( array(), LeadTimes::parse( '' ) );
	}

	public function test_the_list_is_capped(): void {
		$this->assertCount( LeadTimes::MAX_LEN, LeadTimes::parse( implode( ',', range( 1, 40 ) ) ) );
	}

	public function test_clean_accepts_a_list_from_settings(): void {
		$this->assertSame( array( 14, 1 ), LeadTimes::clean( array( '1', 14, 'x', array( 5 ), 1 ) ) );
		$this->assertSame( array(), LeadTimes::clean( 'not a list' ) );
	}

	public function test_the_lead_a_date_has_reached(): void {
		$cases = array(
			'far away, nothing yet'     => array( 120, null ),
			'61 days is not yet 60'     => array( 61, null ),
			'exactly 60'                => array( 60, 60 ),
			'45 days has reached 60'    => array( 45, 60 ),
			'30 exactly'                => array( 30, 30 ),
			'20 days has reached 30'    => array( 20, 30 ),
			'14 exactly (AS-4)'         => array( 14, 14 ),
			'10 days has reached 14'    => array( 10, 14 ),
			'7 exactly'                 => array( 7, 7 ),
			'3 days has reached 7'      => array( 3, 7 ),
			'tomorrow'                  => array( 1, 1 ),
			'the day itself'            => array( 0, 0 ),
			'one day late still sends'  => array( -1, 0 ),
			'three days late'           => array( -3, 0 ),
			'four days late is dropped' => array( -4, null ),
		);
		foreach ( $cases as $label => list( $days_left, $expected ) ) {
			$this->assertSame( $expected, LeadTimes::reached( $days_left, LeadTimes::DEFAULT ), $label );
		}
	}

	public function test_a_custom_list_and_the_override_are_respected(): void {
		$this->assertSame( 5, LeadTimes::reached( 5, array( 5, 2 ) ) );
		$this->assertSame( 5, LeadTimes::reached( 4, array( 5, 2 ) ) );
		$this->assertNull( LeadTimes::reached( 6, array( 5, 2 ) ) );
		$this->assertNull( LeadTimes::reached( 0, array() ) );
	}

	public function test_a_new_date_is_a_new_cycle(): void {
		$this->assertNotSame( LeadTimes::cycleKey( '2026-12-01' ), LeadTimes::cycleKey( '2027-12-01' ) );
		$this->assertSame( LeadTimes::cycleKey( '2026-12-01' ), LeadTimes::cycleKey( '2026-12-01' ) );
	}

	public function test_a_record_setting_is_empty_off_or_a_clean_list(): void {
		$this->assertSame( '', LeadTimes::recordSetting( '  ' ) );
		$this->assertSame( 'off', LeadTimes::recordSetting( 'OFF' ) );
		$this->assertSame( 'off', LeadTimes::recordSetting( 'none' ) );
		$this->assertSame( '30, 7, 0', LeadTimes::recordSetting( '7 0,30' ) );
	}

	public function test_a_record_setting_with_anything_else_is_refused(): void {
		$this->assertNull( LeadTimes::recordSetting( '30, soon' ) );
		$this->assertNull( LeadTimes::recordSetting( '400' ) );
		$this->assertNull( LeadTimes::recordSetting( '-1' ) );
	}

	public function test_a_record_uses_its_own_list_or_falls_back(): void {
		$default = array( 30, 7 );
		$this->assertSame( array( 14, 2 ), LeadTimes::forRecord( '2, 14', $default ) );
		$this->assertSame( $default, LeadTimes::forRecord( '', $default ) );
		$this->assertSame( array(), LeadTimes::forRecord( 'off', $default ) );
	}
}
