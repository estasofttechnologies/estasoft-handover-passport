<?php
/**
 * Tests for the hosting and service rules (HP-HST, HP-SVC).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Findings\Level;

/**
 * Hosting rules.
 */
final class HostingRulesTest extends RuleTestCase {

	public function test_hst001_no_host_or_no_support_contact(): void {
		$none = $this->run( 'HP-HST-001', $this->ctx() );
		$this->assertCount( 1, $none );
		$this->assertSame( 'site', $none[0]->subject_type );
		$hosts = array(
			$this->record( 1, 'Acme Hosting', array( 'support_address' => '' ) ),
			$this->record( 2, 'Other Host', array( 'support_address' => 'https://support.example' ) ),
		);
		$c = $this->ctx( array( 'items' => array( Kinds::HOST => $hosts ) ) );
		$this->assertSame( array( 1 ), $this->subjects( 'HP-HST-001', $c ) );
		$this->assertStringContainsString( 'Acme Hosting', $this->sentence( 'HP-HST-001', $c ) );
		$this->assertQuiet( 'HP-HST-001', $this->ctx( array( 'items' => array( Kinds::HOST => array( $hosts[1] ) ) ) ) );
	}

	public function test_hst002_cron_off_without_server_cron(): void {
		$off = $this->facts( array( 'core' => array( 'cron_disabled' => true ) ) );
		$c   = $this->ctx( array( 'facts' => $off ) );
		$this->assertCount( 1, $this->run( 'HP-HST-002', $c ) );
		$this->assertStringContainsString( 'WordPress cron is switched off', $this->sentence( 'HP-HST-002', $c ) );
		$host = $this->record( 1, 'Host', array( 'cron_server' => 'yes' ) );
		$this->assertQuiet( 'HP-HST-002', $this->ctx( array( 'facts' => $off, 'items' => array( Kinds::HOST => array( $host ) ) ) ) );
		$this->assertQuiet( 'HP-HST-002', $this->ctx() );
		$this->assertNotChecked( 'HP-HST-002', $this->ctx( array( 'facts' => array() ) ) );
	}

	public function test_hst003_hosting_renewal(): void {
		$hosts = array(
			$this->record( 1, 'Late', array( 'renews_on' => '2026-11-01' ) ),
			$this->record( 2, 'Near', array( 'renews_on' => '2026-11-20' ) ),
			$this->record( 3, 'Far', array( 'renews_on' => '2027-05-01' ) ),
			$this->record( 4, 'Undated', array() ),
		);
		$c   = $this->ctx( array( 'items' => array( Kinds::HOST => $hosts ) ) );
		$out = $this->run( 'HP-HST-003', $c );
		$this->assertSame( array( 1, 2 ), $this->subjects( 'HP-HST-003', $c ) );
		$this->assertSame( Level::NEEDS, $out[0]->level );
		$this->assertSame( Level::WORTH, $out[1]->level );
		$this->assertStringContainsString( 'Late', $this->sentence( 'HP-HST-003', $c ) );
	}

	public function test_hst004_no_exit_note(): void {
		$host = $this->record( 1, 'Host', array( 'exit_notes' => '' ) );
		$c    = $this->ctx( array( 'items' => array( Kinds::HOST => array( $host ) ) ) );
		$this->assertSame( array( 1 ), $this->subjects( 'HP-HST-004', $c ) );
		$noted = $this->record( 1, 'Host', array( 'exit_notes' => 'Export from cPanel' ) );
		$this->assertQuiet( 'HP-HST-004', $this->ctx( array( 'items' => array( Kinds::HOST => array( $noted ) ) ) ) );
		$with_runbook = $this->ctx( array( 'items' => array( Kinds::HOST => array( $host ) ), 'runbooks' => array( $this->runbook( 1, array( 'template_key' => 'move_host' ) ) ) ) );
		$this->assertQuiet( 'HP-HST-004', $with_runbook );
	}

	public function test_svc001_plugin_that_implies_a_service(): void {
		$hints = array(
			array( 'slug' => 'wp-mail-smtp', 'kind' => 'plugin', 'service' => 'SMTP provider', 'plugin' => 'WP Mail SMTP' ),
			array( 'slug' => 'wordfence', 'kind' => 'plugin', 'service' => 'Wordfence', 'plugin' => 'Wordfence' ),
		);
		$have = $this->record( 1, 'Wordfence', array( 'service' => 'Wordfence' ) );
		$c    = $this->ctx( array( 'service_hints' => $hints, 'items' => array( Kinds::SERVICE => array( $have ) ) ) );
		$this->assertSame( array( 'wp-mail-smtp' ), $this->subjects( 'HP-SVC-001', $c ) );
		$this->assertStringContainsString( 'SMTP provider', $this->sentence( 'HP-SVC-001', $c ) );
		$this->assertQuiet( 'HP-SVC-001', $this->ctx() );
		$this->assertNotChecked( 'HP-SVC-001', $this->ctx( array( 'facts' => array() ) ) );
	}

	public function test_svc002_service_without_account_holder(): void {
		$none = $this->record( 1, 'Mailer' );
		$has  = $this->record( 2, 'CDN', array(), array( 'owners' => array( 'account_holder' => array( 3 ) ) ) );
		$c    = $this->ctx( array( 'items' => array( Kinds::SERVICE => array( $none, $has ) ) ) );
		$this->assertSame( array( 1 ), $this->subjects( 'HP-SVC-002', $c ) );
	}
}
