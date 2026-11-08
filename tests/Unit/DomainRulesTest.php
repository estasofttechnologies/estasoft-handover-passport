<?php
/**
 * Tests for the domain, DNS and certificate rules (HP-DOM).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Findings\Level;

/**
 * Domain rules.
 */
final class DomainRulesTest extends RuleTestCase {

	/**
	 * A DNS snapshot record.
	 *
	 * @param int                  $id     ID.
	 * @param string               $domain Domain.
	 * @param array<string, mixed> $fields Fields.
	 * @return array<string, mixed>
	 */
	private function dns( int $id, string $domain, array $fields ): array {
		return $this->record( $id, $domain, $fields, array( 'subject_key' => $domain ) );
	}

	public function test_dom001_expired_or_expiring_domain(): void {
		$records = array(
			$this->record( 1, 'gone.com', array( 'expires_on' => '2026-10-20' ) ),
			$this->record( 2, 'soon.com', array( 'expires_on' => '2026-11-30' ) ),
			$this->record( 3, 'far.com', array( 'expires_on' => '2028-01-01' ) ),
			$this->record( 4, 'undated.com', array() ),
		);
		$c   = $this->ctx( array( 'items' => array( Kinds::DOMAIN => $records ) ) );
		$out = $this->run( 'HP-DOM-001', $c );
		$this->assertSame( array( 1, 2 ), $this->subjects( 'HP-DOM-001', $c ) );
		$this->assertSame( Level::NEEDS, $out[0]->level );
		$this->assertStringContainsString( 'gone.com', $this->sentence( 'HP-DOM-001', $c ) );
		$tight = $this->ctx( array( 'items' => array( Kinds::DOMAIN => $records ), 'thresholds' => array( 'domain_warn_days' => 10 ) ) );
		$this->assertSame( array( 1 ), $this->subjects( 'HP-DOM-001', $tight ) );
	}

	public function test_dom002_primary_domain_without_expiry(): void {
		$records = array(
			$this->record( 1, 'main.com', array( 'role' => 'primary' ) ),
			$this->record( 2, 'dated.com', array( 'role' => 'primary', 'expires_on' => '2027-01-01' ) ),
			$this->record( 3, 'alias.com', array( 'role' => 'alias' ) ),
		);
		$this->assertSame( array( 1 ), $this->subjects( 'HP-DOM-002', $this->ctx( array( 'items' => array( Kinds::DOMAIN => $records ) ) ) ) );
		$this->assertQuiet( 'HP-DOM-002', $this->ctx() );
	}

	public function test_dom003_certificate_expiring(): void {
		$records = array(
			$this->record( 1, 'a', array( 'valid_to' => '2026-11-20' ), array( 'subject_key' => 'example.com' ) ),
			$this->record( 2, 'b', array( 'valid_to' => '2027-03-01' ), array( 'subject_key' => 'other.com' ) ),
			$this->record( 3, 'c', array( 'valid_to' => '2026-11-01' ), array( 'subject_key' => 'old.com' ) ),
		);
		$c = $this->ctx( array( 'items' => array( Kinds::SSL => $records ) ) );
		$this->assertSame( array( 1, 3 ), $this->subjects( 'HP-DOM-003', $c ) );
		$this->assertStringContainsString( 'example.com', $this->sentence( 'HP-DOM-003', $c ) );
		$this->assertNotChecked( 'HP-DOM-003', $this->ctx() );
	}

	public function test_dom004_site_address_without_https(): void {
		$plain = $this->ctx( array( 'facts' => $this->facts( array( 'core' => array( 'https' => false, 'site_url' => 'http://example.com' ) ) ) ) );
		$out   = $this->run( 'HP-DOM-004', $plain );
		$this->assertCount( 1, $out );
		$this->assertSame( 'site', $out[0]->subject_type );
		$this->assertStringContainsString( 'http://example.com does not use HTTPS', $this->sentence( 'HP-DOM-004', $plain ) );
		$this->assertQuiet( 'HP-DOM-004', $this->ctx() );
		$this->assertNotChecked( 'HP-DOM-004', $this->ctx( array( 'facts' => array() ) ) );
	}

	public function test_dom005_no_spf_or_dmarc_on_a_domain_with_mail(): void {
		$full    = $this->dns( 1, 'full.com', array( 'mx' => array( 'mx.full.com' ), 'spf' => 'v=spf1 -all', 'dmarc' => 'v=DMARC1; p=none' ) );
		$bare    = $this->dns( 2, 'bare.com', array( 'mx' => array( 'mx.bare.com' ), 'spf' => '', 'dmarc' => '' ) );
		$nomail  = $this->dns( 3, 'nomail.com', array( 'mx' => array() ) );
		$failed  = $this->dns( 4, 'failed.com', array( 'mx' => array( 'x' ), 'errors' => array( 'timeout' ) ) );
		$onlyspf = $this->dns( 5, 'spf.com', array( 'mx' => array( 'x' ), 'spf' => 'v=spf1 -all', 'dmarc' => '' ) );
		$c       = $this->ctx( array( 'items' => array( Kinds::DNS => array( $full, $bare, $nomail, $failed, $onlyspf ) ) ) );
		$out     = $this->run( 'HP-DOM-005', $c );
		$this->assertSame( array( 2, 5 ), $this->subjects( 'HP-DOM-005', $c ) );
		$this->assertSame( array( 'SPF', 'DMARC' ), $out[0]->values['missing'] );
		$this->assertSame( array( 'DMARC' ), $out[1]->values['missing'] );
		$this->assertNotChecked( 'HP-DOM-005', $this->ctx() );
	}

	public function test_dom006_records_changed_between_checks(): void {
		$changed = $this->dns( 1, 'a.com', array( 'changed' => array( 'mx' ), 'checked_at' => '2026-11-07 03:00:00' ) );
		$cname   = $this->dns( 2, 'b.com', array( 'changed' => array( 'txt' ) ) );
		$same    = $this->dns( 3, 'c.com', array( 'changed' => array() ) );
		$c       = $this->ctx( array( 'items' => array( Kinds::DNS => array( $changed, $cname, $same ) ) ) );
		$this->assertSame( array( 1 ), $this->subjects( 'HP-DOM-006', $c ) );
		$this->assertStringContainsString( 'a.com', $this->sentence( 'HP-DOM-006', $c ) );
		$this->assertNotChecked( 'HP-DOM-006', $this->ctx() );
	}

	public function test_dom007_intended_zone_differs_from_last_check(): void {
		$snapshot = $this->dns( 9, 'example.com', array( 'a' => array( '203.0.113.9' ), 'mx' => array( '10 mail.example.com' ) ) );
		$wrong    = $this->record( 1, 'Zone', array( 'domain' => 'example.com', 'rows' => "A,@,203.0.113.7\nMX,@,10 mail.example.com" ) );
		$right    = $this->record( 2, 'Zone', array( 'domain' => 'example.com', 'rows' => "A,@,203.0.113.9" ) );
		$c        = $this->ctx( array( 'items' => array( Kinds::DNS => array( $snapshot ), Kinds::ZONE => array( $wrong ) ) ) );
		$this->assertSame( array( 1 ), $this->subjects( 'HP-DOM-007', $c ) );
		$ok = $this->ctx( array( 'items' => array( Kinds::DNS => array( $snapshot ), Kinds::ZONE => array( $right ) ) ) );
		$this->assertQuiet( 'HP-DOM-007', $ok );
		$this->assertQuiet( 'HP-DOM-007', $this->ctx() );
		$unchecked = $this->ctx( array( 'items' => array( Kinds::ZONE => array( $wrong ) ) ) );
		$this->assertNotChecked( 'HP-DOM-007', $unchecked );
	}
}
