<?php
/**
 * Tests for the licence rules (HP-LIC).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Findings\Level;

/**
 * Licence rules.
 */
final class LicenceRulesTest extends RuleTestCase {

	/**
	 * Context with installed plugins and licence records.
	 *
	 * @param list<array<string, mixed>> $licences Licence records.
	 * @param array<string, mixed>       $plugins  Plugins from the scan.
	 */
	private function with( array $licences, array $plugins = array() ): \Estasoft\HandoverPassport\Findings\Context {
		return $this->ctx( array( 'facts' => $this->facts( array( 'plugins' => $plugins ) ), 'items' => array( Kinds::LICENCE => $licences ) ) );
	}

	public function test_lic001_expired_licence_for_installed_plugin(): void {
		$expired = $this->record( 1, 'Forms Pro', array( 'slug' => 'forms-pro', 'renews_on' => '2026-10-01' ) );
		$c       = $this->with( array( $expired ), array( 'forms-pro' => array( 'name' => 'Forms Pro' ) ) );
		$out     = $this->run( 'HP-LIC-001', $c );
		$this->assertCount( 1, $out );
		$this->assertSame( Level::NEEDS, $out[0]->level );
		$this->assertStringContainsString( 'Forms Pro expired on', $this->sentence( 'HP-LIC-001', $c ) );
	}

	public function test_lic001_quiet_when_not_installed_not_expired_or_free(): void {
		$expired = $this->record( 1, 'Forms Pro', array( 'slug' => 'forms-pro', 'renews_on' => '2026-10-01' ) );
		$this->assertQuiet( 'HP-LIC-001', $this->with( array( $expired ) ) );
		$free = $this->record( 2, 'Free', array( 'slug' => 'free', 'renews_on' => '2026-10-01', 'free' => 'yes' ) );
		$this->assertQuiet( 'HP-LIC-001', $this->with( array( $free ), array( 'free' => array() ) ) );
		$live = $this->record( 3, 'Live', array( 'slug' => 'live', 'renews_on' => '2027-10-01' ) );
		$this->assertQuiet( 'HP-LIC-001', $this->with( array( $live ), array( 'live' => array() ) ) );
		$this->assertNotChecked( 'HP-LIC-001', $this->ctx( array( 'facts' => array() ) ) );
	}

	public function test_lic002_expires_soon_but_not_yet(): void {
		$records = array(
			$this->record( 1, 'Soon', array( 'renews_on' => '2026-12-20' ) ),
			$this->record( 2, 'Far', array( 'renews_on' => '2027-06-01' ) ),
			$this->record( 3, 'Gone', array( 'renews_on' => '2026-10-01' ) ),
			$this->record( 4, 'Free', array( 'renews_on' => '2026-12-01', 'free' => 'yes' ) ),
			$this->record( 5, 'Cancelled', array( 'renews_on' => '2026-12-01', 'cancelled' => 'yes' ) ),
			$this->record( 6, 'Undated', array() ),
		);
		$c = $this->with( $records );
		$this->assertSame( array( 1 ), $this->subjects( 'HP-LIC-002', $c ) );
		$this->assertStringContainsString( 'which is 42 days away', $this->sentence( 'HP-LIC-002', $c ) );
		$wide = $this->ctx( array( 'items' => array( Kinds::LICENCE => $records ), 'thresholds' => array( 'licence_warn_days' => 365 ) ) );
		$this->assertSame( array( 1, 2 ), $this->subjects( 'HP-LIC-002', $wide ) );
	}

	public function test_lic003_outside_wordpress_org_without_record(): void {
		$plugins = array(
			'premium'                    => array( 'name' => 'Premium', 'wporg' => false ),
			'listed'                     => array( 'name' => 'Listed', 'wporg' => true ),
			'unknown'                    => array( 'name' => 'Unknown' ),
			'covered'                    => array( 'name' => 'Covered', 'wporg' => false ),
			'estasoft-handover-passport' => array( 'name' => 'Passport', 'wporg' => false ),
		);
		$c = $this->with( array( $this->record( 1, 'Covered', array( 'slug' => 'Covered' ) ) ), $plugins );
		$this->assertSame( array( 'premium' ), $this->subjects( 'HP-LIC-003', $c ) );
		$this->assertStringContainsString( 'Premium does not come from WordPress.org', $this->sentence( 'HP-LIC-003', $c ) );
		$this->assertNotChecked( 'HP-LIC-003', $this->ctx( array( 'facts' => array() ) ) );
	}

	public function test_lic004_licence_for_removed_plugin(): void {
		$records = array(
			$this->record( 1, 'Gone', array( 'slug' => 'gone', 'kind' => 'plugin' ) ),
			$this->record( 2, 'Here', array( 'slug' => 'here', 'kind' => 'plugin' ) ),
			$this->record( 3, 'Service', array( 'slug' => 'svc', 'kind' => 'service' ) ),
		);
		$c = $this->with( $records, array( 'here' => array() ) );
		$this->assertSame( array( 1 ), $this->subjects( 'HP-LIC-004', $c ) );
		$this->assertNotChecked( 'HP-LIC-004', $this->ctx( array( 'facts' => array() ) ) );
	}

	public function test_lic005_missing_payer_or_account_holder(): void {
		$none   = $this->record( 1, 'None', array() );
		$payer  = $this->record( 2, 'Payer only', array(), array( 'owners' => array( 'payer' => array( 5 ) ) ) );
		$holder = $this->record( 3, 'Holder only', array(), array( 'owners' => array( 'account_holder' => array( 5 ) ) ) );
		$both   = $this->record( 4, 'Both', array(), array( 'owners' => array( 'payer' => array( 5 ), 'account_holder' => array( 6 ) ) ) );
		$free   = $this->record( 5, 'Free', array( 'free' => 'yes' ) );
		$c      = $this->with( array( $none, $payer, $holder, $both, $free ) );
		$this->assertSame( array( 1, 2, 3 ), $this->subjects( 'HP-LIC-005', $c ) );
		$out = $this->run( 'HP-LIC-005', $c );
		$rule = \Estasoft\HandoverPassport\Findings\RuleCatalog::all()['HP-LIC-005'];
		$this->assertStringContainsString( 'no payer and no account holder', $rule->message( $out[0]->values ) );
		$this->assertStringContainsString( 'has no account holder', $rule->message( $out[1]->values ) );
		$this->assertStringContainsString( 'has no payer', $rule->message( $out[2]->values ) );
	}
}
