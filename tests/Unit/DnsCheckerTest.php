<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Checks\DnsChecker;
use PHPUnit\Framework\TestCase;

/**
 * The DNS reader turns resolver answers into a stable snapshot and reports what changed between two (DOM-2).
 */
final class DnsCheckerTest extends TestCase {

	/**
	 * A resolver answering from a table of "host|TYPE" => rows; a missing key means the lookup failed.
	 *
	 * @param array<string, list<array<string, mixed>>|false> $table Answers.
	 */
	private function checker( array $table ): DnsChecker {
		return new DnsChecker( static fn( string $host, string $type ) => array_key_exists( $host . '|' . $type, $table ) ? $table[ $host . '|' . $type ] : array() );
	}

	public function test_a_domain_is_accepted_only_in_plain_form(): void {
		foreach ( array( 'example.com', 'sub.example.co.uk', 'EXAMPLE.com.', 'xn--bcher-kva.example' ) as $ok ) {
			$this->assertTrue( DnsChecker::isDomain( $ok ), $ok );
		}
		foreach ( array( 'localhost', 'a b.com', 'example', 'http://example.com', 'example.com/path', '-bad.com', '', '1.2.3.4' ) as $bad ) {
			$this->assertFalse( DnsChecker::isDomain( $bad ), $bad );
		}
	}

	public function test_snapshot_sorts_and_normalises_values(): void {
		$snap = $this->checker(
			array(
				'example.com|A'            => array( array( 'ip' => '203.0.113.9' ), array( 'ip' => '203.0.113.5' ) ),
				'example.com|NS'           => array( array( 'target' => 'NS2.Example.net.' ), array( 'target' => 'ns1.example.net' ) ),
				'example.com|MX'           => array( array( 'pri' => 20, 'target' => 'Mail2.example.com.' ), array( 'pri' => 10, 'target' => 'mail.example.com' ) ),
				'example.com|TXT'          => array( array( 'entries' => array( 'v=spf1 include:', 'mail.example -all' ) ), array( 'txt' => 'google-site-verification=abc' ) ),
				'example.com|CAA'          => array( array( 'flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org' ) ),
				'www.example.com|CNAME'    => array( array( 'target' => 'Example.com.' ) ),
				'_dmarc.example.com|TXT'   => array( array( 'txt' => 'v=DMARC1; p=none' ) ),
			)
		)->snapshot( 'Example.com.' );

		$this->assertSame( array( '203.0.113.5', '203.0.113.9' ), $snap['a'] );
		$this->assertSame( array( 'ns1.example.net', 'ns2.example.net' ), $snap['ns'] );
		$this->assertSame( array( '10 mail.example.com', '20 mail2.example.com' ), $snap['mx'] );
		$this->assertContains( 'v=spf1 include:mail.example -all', $snap['txt'], 'Split TXT strings are joined.' );
		$this->assertSame( 'v=spf1 include:mail.example -all', $snap['spf'] );
		$this->assertSame( 'v=DMARC1; p=none', $snap['dmarc'] );
		$this->assertSame( 'example.com', $snap['cname_www'] );
		$this->assertSame( array( '0 issue letsencrypt.org' ), $snap['caa'] );
		$this->assertSame( array(), $snap['errors'] );
		$this->assertNotSame( '', $snap['checked_at'] );
	}

	public function test_mail_records_are_ordered_by_priority_as_numbers(): void {
		$snap = $this->checker( array( 'example.com|MX' => array( array( 'pri' => 10, 'target' => 'b.example.com' ), array( 'pri' => 5, 'target' => 'z.example.com' ), array( 'pri' => 10, 'target' => 'a.example.com' ), array( 'pri' => 10, 'target' => 'a.example.com.' ) ) ) )->snapshot( 'example.com' );
		$this->assertSame( array( '5 z.example.com', '10 a.example.com', '10 b.example.com' ), $snap['mx'] );
	}

	public function test_failed_lookups_are_listed_not_hidden(): void {
		$snap = $this->checker( array( 'example.com|A' => array( array( 'ip' => '203.0.113.5' ) ), 'example.com|NS' => false, 'www.example.com|CNAME' => false ) )->snapshot( 'example.com' );
		$this->assertSame( array( 'NS example.com', 'CNAME www.example.com' ), $snap['errors'] );
		$this->assertSame( array(), $snap['ns'] );
		$this->assertFalse( DnsChecker::isEmptyFailure( $snap ), 'One answer came back, so this is not an empty failure.' );
	}

	public function test_a_snapshot_with_only_failures_is_an_empty_failure(): void {
		$snap = $this->checker( array( 'example.com|A' => false, 'example.com|NS' => false, 'example.com|MX' => false ) )->snapshot( 'example.com' );
		$this->assertTrue( DnsChecker::isEmptyFailure( $snap ) );
	}

	public function test_a_domain_with_no_records_and_no_errors_is_not_a_failure(): void {
		$snap = $this->checker( array() )->snapshot( 'example.com' );
		$this->assertFalse( DnsChecker::isEmptyFailure( $snap ) );
	}

	public function test_changes_lists_added_and_removed_values(): void {
		$before = array( 'a' => array( '203.0.113.5' ), 'ns' => array( 'ns1.old.net' ), 'mx' => array( '10 mail.example.com' ), 'txt' => array(), 'caa' => array(), 'aaaa' => array(), 'cname_www' => '', 'errors' => array() );
		$after  = array( 'a' => array( '203.0.113.7' ), 'ns' => array( 'ns1.old.net' ), 'mx' => array( '10 mail.example.com', '20 backup.example.com' ), 'txt' => array(), 'caa' => array(), 'aaaa' => array(), 'cname_www' => 'example.com', 'errors' => array() );
		$diff   = DnsChecker::changes( $before, $after );
		$this->assertSame( array( 'a', 'mx', 'cname_www' ), array_keys( $diff ) );
		$this->assertSame( array( 'added' => array( '203.0.113.7' ), 'removed' => array( '203.0.113.5' ) ), $diff['a'] );
		$this->assertSame( array( 'added' => array( '20 backup.example.com' ), 'removed' => array() ), $diff['mx'] );
		$this->assertSame( array( 'added' => array( 'example.com' ), 'removed' => array() ), $diff['cname_www'] );
	}

	public function test_identical_snapshots_report_no_change(): void {
		$snap = array( 'a' => array( '203.0.113.5' ), 'ns' => array( 'ns1.example.net' ), 'mx' => array(), 'txt' => array( 'x' ), 'caa' => array(), 'aaaa' => array(), 'cname_www' => '', 'errors' => array() );
		$this->assertSame( array(), DnsChecker::changes( $snap, $snap ) );
	}

	public function test_a_lookup_that_failed_on_either_side_is_not_called_a_change(): void {
		$before = array( 'a' => array( '203.0.113.5' ), 'ns' => array( 'ns1.example.net' ), 'mx' => array(), 'txt' => array(), 'caa' => array(), 'aaaa' => array(), 'cname_www' => '', 'errors' => array() );
		$after  = array( 'a' => array(), 'ns' => array( 'ns1.example.net' ), 'mx' => array(), 'txt' => array(), 'caa' => array(), 'aaaa' => array(), 'cname_www' => '', 'errors' => array( 'A example.com' ) );
		$this->assertSame( array(), DnsChecker::changes( $before, $after ), 'A failed lookup must not look like the records were removed.' );
	}

	public function test_a_failed_dmarc_lookup_does_not_block_the_apex_text_comparison(): void {
		$before = array( 'txt' => array( 'v=spf1 -all' ), 'errors' => array() );
		$after  = array( 'txt' => array( 'v=spf1 +all' ), 'errors' => array( 'TXT _dmarc.example.com' ) );
		$diff   = DnsChecker::changes( $before, $after );
		$this->assertArrayHasKey( 'txt', $diff );
	}

	public function test_the_snapshot_never_contains_a_secret_looking_field_beyond_public_dns_text(): void {
		$snap = $this->checker( array( 'example.com|TXT' => array( array( 'txt' => str_repeat( 'a', 300 ) ) ) ) )->snapshot( 'example.com' );
		$this->assertSame( array( str_repeat( 'a', 300 ) ), $snap['txt'], 'Public DNS text is stored as measured.' );
	}
}
