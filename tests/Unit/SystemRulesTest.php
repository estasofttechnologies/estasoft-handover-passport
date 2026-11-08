<?php
/**
 * Tests for the record, scan, reminder, import and snapshot rules.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Domain\Kinds;

/**
 * System rules.
 */
final class SystemRulesTest extends RuleTestCase {

	public function test_prt001_stale_records_and_people(): void {
		$stale = $this->record( 1, 'Old host', array(), array( 'freshness' => 'stale', 'verified_at' => '2025-01-01 00:00:00' ) );
		$fresh = $this->record( 2, 'New host' );
		$c     = $this->ctx( array(
			'items'  => array( Kinds::HOST => array( $stale, $fresh ), Kinds::CHECKLIST => array( $this->record( 9, 'List', array(), array( 'freshness' => 'stale' ) ) ) ),
			'people' => array( $this->person( 4, 'Ann', array( 'freshness' => 'stale', 'verified_at' => '' ) ), $this->person( 5, 'Bob' ) ),
		) );
		$this->assertSame( array( 1, 4 ), $this->subjects( 'HP-PRT-001', $c ) );
		$this->assertQuiet( 'HP-PRT-001', $this->ctx() );
	}

	public function test_prt002_entered_address_disagrees_with_detected(): void {
		$wrong = $this->record( 1, 'Production', array( 'name' => 'production', 'address' => 'https://other.example/path' ) );
		$right = $this->record( 2, 'Production', array( 'name' => 'production', 'address' => 'https://example.com' ) );
		$stage = $this->record( 3, 'Staging', array( 'name' => 'staging', 'address' => 'https://staging.other.example' ) );
		$c     = $this->ctx( array( 'items' => array( Kinds::ENV => array( $wrong, $right, $stage ) ) ) );
		$this->assertSame( array( 1 ), $this->subjects( 'HP-PRT-002', $c ) );
		$this->assertStringContainsString( 'other.example', $this->sentence( 'HP-PRT-002', $c ) );
		$this->assertNotChecked( 'HP-PRT-002', $this->ctx( array( 'facts' => array() ) ) );
	}

	public function test_scn001_scan_failed_or_late(): void {
		$this->assertQuiet( 'HP-SCN-001', $this->ctx() );
		$this->assertNotChecked( 'HP-SCN-001', $this->ctx( array( 'last_scan' => null ) ) );
		$failed = $this->ctx( array( 'last_scan' => array( 'status' => 'failed' ), 'scan_age_hours' => null ) );
		$this->assertTrue( $this->run( 'HP-SCN-001', $failed )[0]->values['failed'] );
		$late = $this->ctx( array( 'scan_age_hours' => 72 ) );
		$out  = $this->run( 'HP-SCN-001', $late );
		$this->assertFalse( $out[0]->values['failed'] );
		$this->assertStringContainsString( '72', $this->sentence( 'HP-SCN-001', $late ) );
		$this->assertQuiet( 'HP-SCN-001', $this->ctx( array( 'scan_age_hours' => 72, 'thresholds' => array( 'scan_stale_hours' => 100 ) ) ) );
	}

	public function test_scn002_missing_server_ability(): void {
		$c = $this->ctx( array( 'facts' => $this->facts( array( 'gaps' => array( 'db_size' => 'The database does not report its size.' ) ) ) ) );
		$this->assertSame( array( 'db_size' ), $this->subjects( 'HP-SCN-002', $c ) );
		$this->assertQuiet( 'HP-SCN-002', $this->ctx() );
		$this->assertNotChecked( 'HP-SCN-002', $this->ctx( array( 'facts' => array() ) ) );
	}

	public function test_rmd001_reminder_mail_failing(): void {
		$this->assertQuiet( 'HP-RMD-001', $this->ctx() );
		$this->assertQuiet( 'HP-RMD-001', $this->ctx( array( 'state' => array( 'reminder_health' => array( 'failed_days' => 2 ) ) ) ) );
		$c = $this->ctx( array( 'state' => array( 'reminder_health' => array( 'failed_days' => 3 ) ) ) );
		$this->assertCount( 1, $this->run( 'HP-RMD-001', $c ) );
		$this->assertStringContainsString( '3', $this->sentence( 'HP-RMD-001', $c ) );
	}

	public function test_imp001_copy_question_open(): void {
		$this->assertQuiet( 'HP-IMP-001', $this->ctx() );
		$c = $this->ctx( array( 'state' => array( 'copy_guard' => array( 'pending' => true, 'from' => 'https://live.example', 'to' => 'https://copy.example' ) ) ) );
		$this->assertCount( 1, $this->run( 'HP-IMP-001', $c ) );
		$this->assertStringContainsString( 'copy.example', $this->sentence( 'HP-IMP-001', $c ) );
	}

	public function test_hnd001_no_snapshot(): void {
		$this->assertCount( 1, $this->run( 'HP-HND-001', $this->ctx() ) );
		$this->assertQuiet( 'HP-HND-001', $this->ctx( array( 'snapshot_count' => 1 ) ) );
	}

	public function test_hnd002_snapshot_does_not_match_hash(): void {
		$this->assertQuiet( 'HP-HND-002', $this->ctx() );
		$c = $this->ctx( array( 'snapshot_mismatches' => array( array( 'id' => 3, 'name' => 'Handover 1' ) ) ) );
		$this->assertSame( array( 3 ), $this->subjects( 'HP-HND-002', $c ) );
		$this->assertStringContainsString( 'Handover 1', $this->sentence( 'HP-HND-002', $c ) );
	}
}
