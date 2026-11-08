<?php
/**
 * Tests for the intended-zone comparison.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Checks\ZoneCompare;

/**
 * ZoneCompare is pure, so these tests need no WordPress.
 */
final class ZoneCompareTest extends UnitTestCase {

	public function test_parses_delimited_and_bind_lines_and_ignores_comments(): void {
		$rows = ZoneCompare::parse( "; comment\n# other\nA,@,203.0.113.5,300,web\nexample.com. 3600 IN MX 10 mail.example.com.\nwww IN CNAME example.com.\nSOA ignored\n\n" );
		$this->assertCount( 3, $rows );
		$this->assertSame( 'A', $rows[0]['type'] );
		$this->assertSame( 'web', $rows[0]['purpose'] );
		$this->assertSame( 'MX', $rows[1]['type'] );
		$this->assertSame( '3600', $rows[1]['ttl'] );
		$this->assertSame( '10 mail.example.com.', $rows[1]['value'] );
		$this->assertSame( 'www', $rows[2]['name'] );
	}

	public function test_matches_ignores_case_order_and_trailing_dots(): void {
		$rows = ZoneCompare::parse( "NS,@,NS2.Example.com.\nNS,@,ns1.example.com" );
		$res  = ZoneCompare::compare( 'example.com', $rows, array( 'ns' => array( 'ns1.example.com', 'ns2.example.com' ) ) );
		$this->assertCount( 1, $res );
		$this->assertSame( ZoneCompare::MATCHES, $res[0]['status'] );
	}

	public function test_differs_missing_and_skipped(): void {
		$rows = ZoneCompare::parse( "A,@,1.1.1.1\nAAAA,@,2001:db8::1\nCNAME,blog,example.net\nCNAME,www,example.com" );
		$res  = ZoneCompare::compare( 'example.com', $rows, array( 'a' => array( '9.9.9.9' ), 'aaaa' => array(), 'cname_www' => 'example.com' ) );
		$by   = array();
		foreach ( $res as $r ) {
			$by[ $r['scope'] . '|' . $r['type'] ] = $r['status'];
		}
		$this->assertSame( ZoneCompare::DIFFERS, $by['apex|A'] );
		$this->assertSame( ZoneCompare::MISSING, $by['apex|AAAA'] );
		$this->assertSame( ZoneCompare::SKIPPED, $by['other|CNAME'] );
		$this->assertSame( ZoneCompare::MATCHES, $by['www|CNAME'] );
		$sum = ZoneCompare::summary( $res );
		$this->assertSame( array( 1, 1, 1, 1 ), array_values( $sum ) );
	}

	public function test_txt_matches_when_every_expected_value_is_present(): void {
		$rows = ZoneCompare::parse( "TXT,@,v=spf1 include:mail.example -all" );
		$res  = ZoneCompare::compare( 'example.com', $rows, array( 'txt' => array( 'google-site-verification=abc', 'v=spf1 include:mail.example -all' ) ) );
		$this->assertSame( ZoneCompare::MATCHES, $res[0]['status'] );
		$res = ZoneCompare::compare( 'example.com', $rows, array( 'txt' => array( 'something else' ) ) );
		$this->assertSame( ZoneCompare::DIFFERS, $res[0]['status'] );
	}

	public function test_dmarc_row_is_read_from_the_dmarc_value(): void {
		$rows = ZoneCompare::parse( "TXT,_dmarc,v=DMARC1; p=none" );
		$res  = ZoneCompare::compare( 'example.com', $rows, array( 'dmarc' => 'v=DMARC1; p=none' ) );
		$this->assertSame( 'dmarc', $res[0]['scope'] );
		$this->assertSame( ZoneCompare::MATCHES, $res[0]['status'] );
	}
}
