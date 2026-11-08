<?php
/**
 * Tests for the backup, restore, runbook and URL rules (HP-BKP, HP-RUN, HP-URL).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Domain\Kinds;

/**
 * Safety-net rules.
 */
final class SafetyRulesTest extends RuleTestCase {

	/**
	 * A restore test row.
	 *
	 * @param string $result  pass, partial or fail.
	 * @param string $date    Test date.
	 * @param int    $minutes Minutes the restore took.
	 * @return array<string, mixed>
	 */
	private function test_row( string $result, string $date, int $minutes = 0 ): array {
		return array( 'result' => $result, 'test_date' => $date, 'minutes' => $minutes );
	}

	/**
	 * Context holding backups.
	 *
	 * @param list<array<string, mixed>> $backups Backup records.
	 * @param array<string, mixed>       $over    Other arguments.
	 */
	private function backups( array $backups, array $over = array() ): \Estasoft\HandoverPassport\Findings\Context {
		return $this->ctx( array_merge( array( 'items' => array( Kinds::BACKUP => $backups ) ), $over ) );
	}

	public function test_bkp001_no_backup_record(): void {
		$this->assertCount( 1, $this->run( 'HP-BKP-001', $this->ctx() ) );
		$this->assertStringContainsString( 'No backup is recorded', $this->sentence( 'HP-BKP-001', $this->ctx() ) );
		$this->assertQuiet( 'HP-BKP-001', $this->backups( array( $this->record( 1, 'Nightly' ) ) ) );
	}

	public function test_bkp002_no_offsite_copy(): void {
		$this->assertCount( 1, $this->run( 'HP-BKP-002', $this->backups( array( $this->record( 1, 'Local', array( 'offsite' => 'no' ) ) ) ) ) );
		$this->assertQuiet( 'HP-BKP-002', $this->backups( array( $this->record( 1, 'Local', array( 'offsite' => 'no' ) ), $this->record( 2, 'S3', array( 'offsite' => 'yes' ) ) ) ) );
	}

	public function test_bkp003_no_restore_test(): void {
		$this->assertCount( 1, $this->run( 'HP-BKP-003', $this->ctx() ) );
		$this->assertQuiet( 'HP-BKP-003', $this->ctx( array( 'tests' => array( $this->test_row( 'fail', '2026-10-01' ) ) ) ) );
	}

	public function test_bkp004_restore_test_is_old(): void {
		$this->assertQuiet( 'HP-BKP-004', $this->ctx() );
		$this->assertQuiet( 'HP-BKP-004', $this->ctx( array( 'tests' => array( $this->test_row( 'pass', '2026-09-01' ) ) ) ) );
		$old = $this->ctx( array( 'tests' => array( $this->test_row( 'pass', '2026-01-01' ) ) ) );
		$out = $this->run( 'HP-BKP-004', $old );
		$this->assertCount( 1, $out );
		$this->assertSame( 311, $out[0]->values['days'] );
		$this->assertStringContainsString( 'passed', $this->sentence( 'HP-BKP-004', $old ) );
		$only_fail = $this->ctx( array( 'tests' => array( $this->test_row( 'fail', '2026-10-01' ) ) ) );
		$this->assertCount( 1, $this->run( 'HP-BKP-004', $only_fail ) );
		$this->assertQuiet( 'HP-BKP-004', $this->ctx( array( 'tests' => array( $this->test_row( 'pass', '2026-01-01' ) ), 'thresholds' => array( 'restore_test_days' => 400 ) ) ) );
	}

	public function test_bkp005_backup_not_confirmed(): void {
		$records = array(
			$this->record( 1, 'Stale', array( 'last_confirmed' => '2026-08-01' ) ),
			$this->record( 2, 'Fresh', array( 'last_confirmed' => '2026-10-20' ) ),
			$this->record( 3, 'Never old', array(), array( 'created_at' => '2026-01-01 00:00:00' ) ),
			$this->record( 4, 'Never new', array(), array( 'created_at' => '2026-11-01 00:00:00' ) ),
		);
		$c = $this->backups( $records );
		$this->assertSame( array( 1, 3 ), $this->subjects( 'HP-BKP-005', $c ) );
		$this->assertStringContainsString( 'Stale', $this->sentence( 'HP-BKP-005', $c ) );
		$this->assertSame( array( 3 ), $this->subjects( 'HP-BKP-005', $this->backups( $records, array( 'thresholds' => array( 'backup_confirm_days' => 200 ) ) ) ) );
	}

	public function test_bkp006_backups_less_frequent_than_target(): void {
		$weekly = $this->record( 1, 'Weekly', array( 'frequency_hours' => '168' ) );
		$daily  = $this->record( 2, 'Daily', array( 'frequency_hours' => '24' ) );
		$this->assertNotChecked( 'HP-BKP-006', $this->backups( array( $weekly ) ) );
		$this->assertNotChecked( 'HP-BKP-006', $this->backups( array( $this->record( 1, 'No frequency' ) ), array( 'recovery' => array( 'data_loss_hours' => 24 ) ) ) );
		$c = $this->backups( array( $weekly ), array( 'recovery' => array( 'data_loss_hours' => 24 ) ) );
		$this->assertCount( 1, $this->run( 'HP-BKP-006', $c ) );
		$this->assertStringContainsString( '168', $this->sentence( 'HP-BKP-006', $c ) );
		$this->assertQuiet( 'HP-BKP-006', $this->backups( array( $weekly, $daily ), array( 'recovery' => array( 'data_loss_hours' => 24 ) ) ) );
	}

	public function test_bkp007_restore_took_longer_than_target(): void {
		$slow = array( $this->test_row( 'pass', '2026-10-01', 180 ) );
		$this->assertNotChecked( 'HP-BKP-007', $this->ctx( array( 'tests' => $slow ) ) );
		$this->assertNotChecked( 'HP-BKP-007', $this->ctx( array( 'tests' => array( $this->test_row( 'fail', '2026-10-01', 30 ) ), 'recovery' => array( 'downtime_hours' => 2 ) ) ) );
		$c = $this->ctx( array( 'tests' => $slow, 'recovery' => array( 'downtime_hours' => 2 ) ) );
		$this->assertCount( 1, $this->run( 'HP-BKP-007', $c ) );
		$this->assertStringContainsString( '180', $this->sentence( 'HP-BKP-007', $c ) );
		$this->assertQuiet( 'HP-BKP-007', $this->ctx( array( 'tests' => array( $this->test_row( 'pass', '2026-10-01', 60 ) ), 'recovery' => array( 'downtime_hours' => 2 ) ) ) );
	}

	public function test_bkp008_encryption_not_known(): void {
		$records = array(
			$this->record( 1, 'Unknown', array( 'encrypted' => '' ) ),
			$this->record( 2, 'Plain', array( 'encrypted' => 'no' ) ),
			$this->record( 3, 'Encrypted', array( 'encrypted' => 'yes' ) ),
		);
		$c   = $this->backups( $records );
		$out = $this->run( 'HP-BKP-008', $c );
		$this->assertSame( array( 1, 2 ), $this->subjects( 'HP-BKP-008', $c ) );
		$this->assertFalse( $out[0]->values['known'] );
		$this->assertTrue( $out[1]->values['known'] );
	}

	public function test_run001_no_runbook_for_whole_site(): void {
		$this->assertCount( 1, $this->run( 'HP-RUN-001', $this->ctx() ) );
		$this->assertCount( 1, $this->run( 'HP-RUN-001', $this->ctx( array( 'runbooks' => array( $this->runbook( 1, array( 'restore_scope' => 'database' ) ) ) ) ) ) );
		$this->assertQuiet( 'HP-RUN-001', $this->ctx( array( 'runbooks' => array( $this->runbook( 1, array( 'restore_scope' => 'site' ) ) ) ) ) );
	}

	public function test_run002_runbook_not_reviewed(): void {
		$stale = $this->runbook( 1, array( 'last_reviewed' => '2025-01-01 00:00:00' ) );
		$fresh = $this->runbook( 2, array( 'last_reviewed' => '2026-11-01 00:00:00' ) );
		$due   = $this->runbook( 3, array( 'last_reviewed' => '2026-05-10 00:00:00' ) );
		$c     = $this->ctx( array( 'runbooks' => array( $stale, $fresh, $due ), 'review_days' => array( 'runbooks' => 180 ), 'due_days' => 14 ) );
		$this->assertSame( array( 1 ), $this->subjects( 'HP-RUN-002', $c ) );
		$own = $this->runbook( 4, array( 'review_days' => 30, 'last_reviewed' => '2026-08-01 00:00:00' ) );
		$this->assertSame( array( 4 ), $this->subjects( 'HP-RUN-002', $this->ctx( array( 'runbooks' => array( $own ) ) ) ) );
		$never = $this->runbook( 5, array( 'last_reviewed' => '', 'created_at' => '2025-01-01 00:00:00' ) );
		$this->assertSame( array( 5 ), $this->subjects( 'HP-RUN-002', $this->ctx( array( 'runbooks' => array( $never ) ) ) ) );
	}

	public function test_run003_runbook_never_rehearsed(): void {
		$this->assertSame( array( 7 ), $this->subjects( 'HP-RUN-003', $this->ctx( array( 'runbooks' => array( $this->runbook( 7, array( 'last_rehearsed' => '' ) ), $this->runbook( 8 ) ) ) ) ) );
	}

	public function test_url001_critical_url_failed(): void {
		$bad  = $this->record( 1, 'Checkout', array( 'last_check' => array( 'ok' => false, 'status' => 500, 'at' => '2026-11-08 03:00:00', 'reason' => '' ) ) );
		$good = $this->record( 2, 'Home', array( 'last_check' => array( 'ok' => true, 'status' => 200, 'at' => '2026-11-08 03:00:00' ) ) );
		$new  = $this->record( 3, 'New', array() );
		$c    = $this->ctx( array( 'items' => array( Kinds::URL => array( $bad, $good, $new ) ) ) );
		$this->assertSame( array( 1 ), $this->subjects( 'HP-URL-001', $c ) );
		$this->assertStringContainsString( 'Checkout', $this->sentence( 'HP-URL-001', $c ) );
	}

	public function test_url002_no_critical_urls(): void {
		$this->assertCount( 1, $this->run( 'HP-URL-002', $this->ctx() ) );
		$this->assertQuiet( 'HP-URL-002', $this->ctx( array( 'items' => array( Kinds::URL => array( $this->record( 1, 'Home' ) ) ) ) ) );
	}
}
