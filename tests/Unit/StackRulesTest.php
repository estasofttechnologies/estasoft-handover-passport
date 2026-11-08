<?php
/**
 * Tests for the stack rules (HP-STK).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Findings\Level;

/**
 * Each rule fires, stays quiet, and says Not checked when it cannot look.
 */
final class StackRulesTest extends RuleTestCase {

	public function test_stk001_past_support_needs_fixing(): void {
		$c   = $this->ctx( array( 'facts' => $this->facts( array( 'core' => array( 'php_version' => '8.1.20', 'wp_version' => '6.8.1' ) ) ) ) );
		$out = $this->run( 'HP-STK-001', $c );
		$this->assertCount( 1, $out );
		$this->assertSame( Level::NEEDS, $out[0]->level );
		$this->assertSame( 'php', $out[0]->subject_key );
		$this->assertStringContainsString( 'PHP 8.1 security support ended on', $this->sentence( 'HP-STK-001', $c ) );
	}

	public function test_stk001_support_ending_soon_is_worth_fixing(): void {
		$c   = $this->ctx( array( 'facts' => $this->facts( array( 'core' => array( 'php_version' => '8.2.5', 'wp_version' => '6.8.1' ) ) ) ) );
		$out = $this->run( 'HP-STK-001', $c );
		$this->assertCount( 1, $out );
		$this->assertSame( Level::WORTH, $out[0]->level );
		$this->assertStringContainsString( 'ends on', $this->sentence( 'HP-STK-001', $c ) );
	}

	public function test_stk001_quiet_when_support_is_far_off_or_unknown(): void {
		$this->assertQuiet( 'HP-STK-001', $this->ctx() );
		$c = $this->ctx( array( 'facts' => $this->facts( array( 'core' => array( 'php_version' => '5.4.1', 'wp_version' => '0.1' ) ) ) ) );
		$this->assertQuiet( 'HP-STK-001', $c );
	}

	public function test_stk001_follows_the_threshold(): void {
		$php = $this->facts( array( 'core' => array( 'php_version' => '8.2.5', 'wp_version' => '6.8.1' ) ) );
		$this->assertQuiet( 'HP-STK-001', $this->ctx( array( 'facts' => $php, 'thresholds' => array( 'eol_warn_days' => 30 ) ) ) );
		$wp = $this->facts( array( 'core' => array( 'php_version' => '8.3.1', 'wp_version' => '6.8.1' ) ) );
		$this->assertSame( array( 'wordpress' ), $this->subjects( 'HP-STK-001', $this->ctx( array( 'facts' => $wp, 'thresholds' => array( 'eol_warn_days' => 300 ) ) ) ) );
	}

	public function test_stk001_not_checked_without_a_scan(): void {
		$this->assertNotChecked( 'HP-STK-001', $this->ctx( array( 'facts' => array() ) ) );
	}

	public function test_stk002_update_waiting_long(): void {
		$facts = $this->facts( array(
			'plugins' => array(
				'old-one' => array( 'name' => 'Old One', 'update' => '2.0', 'update_since' => '2026-09-01 00:00:00' ),
				'fresh'   => array( 'name' => 'Fresh', 'update' => '3.0', 'update_since' => '2026-11-01 00:00:00' ),
				'current' => array( 'name' => 'Current', 'update' => '' ),
			),
			'themes'  => array( 'twenty' => array( 'name' => 'Twenty', 'update' => '1.1', 'update_since' => '2026-01-01' ) ),
		) );
		$c = $this->ctx( array( 'facts' => $facts ) );
		$this->assertSame( array( 'old-one', 'twenty' ), $this->subjects( 'HP-STK-002', $c ) );
		$this->assertStringContainsString( 'Old One to version 2.0 has been available for 68 days', $this->sentence( 'HP-STK-002', $c ) );
		$this->assertSame( array( 'old-one', 'fresh', 'twenty' ), $this->subjects( 'HP-STK-002', $this->ctx( array( 'facts' => $facts, 'thresholds' => array( 'update_age_days' => 5 ) ) ) ) );
		$this->assertNotChecked( 'HP-STK-002', $this->ctx( array( 'facts' => array() ) ) );
	}

	public function test_stk003_inactive_plugins_and_unused_themes(): void {
		$facts = $this->facts( array(
			'plugins' => array(
				'on'  => array( 'name' => 'On', 'active' => true ),
				'off' => array( 'name' => 'Off', 'active' => false ),
			),
			'themes'  => array(
				'child'  => array( 'name' => 'Child', 'active' => true, 'parent' => 'parent' ),
				'parent' => array( 'name' => 'Parent', 'active' => false ),
				'spare'  => array( 'name' => 'Spare', 'active' => false ),
			),
		) );
		$c = $this->ctx( array( 'facts' => $facts ) );
		$this->assertSame( array( 'off', 'spare' ), $this->subjects( 'HP-STK-003', $c ) );
		$this->assertQuiet( 'HP-STK-003', $this->ctx() );
		$this->assertNotChecked( 'HP-STK-003', $this->ctx( array( 'facts' => array() ) ) );
	}

	public function test_stk004_custom_code_without_source(): void {
		$notes = array(
			$this->record( 1, 'Mine', array( 'custom_code' => 'yes', 'repo_address' => '' ) ),
			$this->record( 2, 'Kept', array( 'custom_code' => 'yes', 'repo_address' => 'https://git.example/x' ) ),
			$this->record( 3, 'Other', array( 'custom_code' => 'no', 'repo_address' => '' ) ),
		);
		$c = $this->ctx( array( 'items' => array( Kinds::STACKNOTE => $notes ) ) );
		$this->assertSame( array( 1 ), $this->subjects( 'HP-STK-004', $c ) );
		$this->assertStringContainsString( 'Mine is marked as custom code', $this->sentence( 'HP-STK-004', $c ) );
		$this->assertQuiet( 'HP-STK-004', $this->ctx() );
	}

	public function test_stk005_active_plugin_without_purpose(): void {
		$facts = $this->facts( array(
			'plugins' => array(
				'known'                      => array( 'name' => 'Known', 'active' => true ),
				'unknown'                    => array( 'name' => 'Unknown', 'active' => true ),
				'off'                        => array( 'name' => 'Off', 'active' => false ),
				'estasoft-handover-passport' => array( 'name' => 'Passport', 'active' => true ),
			),
		) );
		$notes = array( $this->record( 1, 'Known', array( 'ref' => 'plugin:Known', 'what_it_does' => 'Forms' ) ) );
		$c     = $this->ctx( array( 'facts' => $facts, 'items' => array( Kinds::STACKNOTE => $notes ) ) );
		$this->assertSame( array( 'unknown' ), $this->subjects( 'HP-STK-005', $c ) );
		$this->assertNotChecked( 'HP-STK-005', $this->ctx( array( 'facts' => array() ) ) );
	}
}
