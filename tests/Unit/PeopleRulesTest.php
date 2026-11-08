<?php
/**
 * Tests for the people, ownership and access rules (HP-PPL, HP-OWN, HP-ACC).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Domain\Kinds;

/**
 * People rules.
 */
final class PeopleRulesTest extends RuleTestCase {

	public function test_ppl001_no_site_owner_or_emergency_contact(): void {
		$this->assertSame( array( 'site_owner', 'emergency_contact' ), $this->subjects( 'HP-PPL-001', $this->ctx() ) );
		$c = $this->ctx( array( 'role_holders' => array( 'site_owner' => array( 1 ) ) ) );
		$this->assertSame( array( 'emergency_contact' ), $this->subjects( 'HP-PPL-001', $c ) );
		$this->assertStringContainsString( 'emergency', strtolower( $this->sentence( 'HP-PPL-001', $c ) ) );
		$this->assertQuiet( 'HP-PPL-001', $this->ctx( array( 'role_holders' => array( 'site_owner' => array( 1 ), 'emergency_contact' => array( 2 ) ) ) ) );
	}

	public function test_ppl002_emergency_contact_without_urgent_method(): void {
		$people = array( $this->person( 1, 'Ann', array( 'urgent_method' => '' ) ), $this->person( 2, 'Bob' ) );
		$c      = $this->ctx( array( 'people' => $people, 'role_holders' => array( 'emergency_contact' => array( 1, 2 ) ) ) );
		$this->assertSame( array( 1 ), $this->subjects( 'HP-PPL-002', $c ) );
		$this->assertStringContainsString( 'Ann', $this->sentence( 'HP-PPL-002', $c ) );
		$this->assertQuiet( 'HP-PPL-002', $this->ctx() );
	}

	public function test_ppl003_former_person_still_holds_something(): void {
		$people = array(
			$this->person( 1, 'Left', array( 'left_on' => '2026-10-01', 'roles' => array( 'site_owner' ) ) ),
			$this->person( 2, 'Gone', array( 'active' => 0 ) ),
			$this->person( 3, 'Nothing', array( 'left_on' => '2026-09-01' ) ),
			$this->person( 4, 'Staying', array( 'roles' => array( 'site_owner' ) ) ),
			$this->person( 5, 'Future', array( 'left_on' => '2027-01-01', 'roles' => array( 'site_owner' ) ) ),
		);
		$c   = $this->ctx( array( 'people' => $people, 'assets_by_person' => array( 2 => array( array( 'item_id' => 9, 'relation' => 'payer' ) ) ) ) );
		$out = $this->run( 'HP-PPL-003', $c );
		$this->assertSame( array( 1, 2 ), $this->subjects( 'HP-PPL-003', $c ) );
		$this->assertSame( 1, $out[0]->values['roles'] );
		$this->assertSame( 1, $out[1]->values['assets'] );
		$this->assertStringContainsString( 'Left', $this->sentence( 'HP-PPL-003', $c ) );
	}

	public function test_ppl004_single_administrator(): void {
		$one   = $this->record( 1, 'Backup', array(), array( 'owners' => array( 'administrators' => array( 5 ), 'account_holder' => array( 5 ) ) ) );
		$two   = $this->record( 2, 'Domain', array(), array( 'owners' => array( 'administrators' => array( 5, 6 ) ) ) );
		$dns   = $this->record( 3, 'DNS', array( 'layer' => 'dns' ), array( 'owners' => array( 'account_holder' => array( 6 ) ) ) );
		$cdn   = $this->record( 4, 'CDN', array( 'layer' => 'cdn' ), array( 'owners' => array( 'account_holder' => array( 6 ) ) ) );
		$people = array( $this->person( 5, 'Ann' ), $this->person( 6, 'Bob' ) );
		$c     = $this->ctx( array( 'people' => $people, 'items' => array( Kinds::BACKUP => array( $one ), Kinds::DOMAIN => array( $two ), Kinds::LAYER => array( $dns, $cdn ) ) ) );
		$this->assertSame( array( 1, 3 ), $this->subjects( 'HP-PPL-004', $c ) );
		$this->assertStringContainsString( 'Ann', $this->sentence( 'HP-PPL-004', $c ) );
	}

	public function test_own001_legal_owner_unknown(): void {
		$records = array(
			$this->record( 1, 'blank', array(), array( 'legal_owner' => '' ) ),
			$this->record( 2, 'unknown', array(), array( 'legal_owner' => 'unknown' ) ),
			$this->record( 3, 'client', array(), array( 'legal_owner' => 'client' ) ),
		);
		$c = $this->ctx( array( 'items' => array( Kinds::DOMAIN => $records ) ) );
		$this->assertSame( array( 1, 2 ), $this->subjects( 'HP-OWN-001', $c ) );
	}

	public function test_own002_transfer_to_client_not_done(): void {
		$records = array(
			$this->record( 1, 'open', array( 'transfer_status' => 'planned' ), array( 'legal_owner' => 'agency' ) ),
			$this->record( 2, 'blank', array(), array( 'legal_owner' => 'third_party' ) ),
			$this->record( 3, 'done', array( 'transfer_status' => 'done' ), array( 'legal_owner' => 'agency' ) ),
			$this->record( 4, 'not needed', array( 'transfer_status' => 'not_needed' ), array( 'legal_owner' => 'agency' ) ),
			$this->record( 5, 'client', array(), array( 'legal_owner' => 'client' ) ),
		);
		$c = $this->ctx( array( 'items' => array( Kinds::LICENCE => $records ) ) );
		$this->assertSame( array( 1, 2 ), $this->subjects( 'HP-OWN-002', $c ) );
	}

	public function test_acc001_administrator_missing_from_directory(): void {
		$facts = $this->facts( array( 'users' => array( 'admins' => array(
			array( 'id' => 1, 'name' => 'ann', 'email' => 'ANN@example.com' ),
			array( 'id' => 2, 'name' => 'stranger', 'email' => 'x@elsewhere.test' ),
			array( 'id' => 3, 'name' => 'noemail', 'email' => '' ),
		) ) ) );
		$c = $this->ctx( array( 'facts' => $facts, 'people' => array( $this->person( 1, 'Ann' ) ) ) );
		$this->assertSame( array( '2', '3' ), $this->subjects( 'HP-ACC-001', $c ) );
		$this->assertStringContainsString( 'stranger', $this->sentence( 'HP-ACC-001', $c ) );
		$this->assertNotChecked( 'HP-ACC-001', $this->ctx( array( 'facts' => array() ) ) );
	}

	public function test_acc002_access_not_reviewed(): void {
		$records = array(
			$this->record( 1, 'Old', array( 'last_reviewed' => '2026-06-01' ) ),
			$this->record( 2, 'New', array( 'last_reviewed' => '2026-10-15' ) ),
			$this->record( 3, 'Never old', array(), array( 'created_at' => '2026-01-01 00:00:00' ) ),
			$this->record( 4, 'Never new', array(), array( 'created_at' => '2026-10-01 00:00:00' ) ),
		);
		$c = $this->ctx( array( 'items' => array( Kinds::ACCESS => $records ) ) );
		$this->assertSame( array( 1, 3 ), $this->subjects( 'HP-ACC-002', $c ) );
		$wide = $this->ctx( array( 'items' => array( Kinds::ACCESS => $records ), 'thresholds' => array( 'access_review_days' => 200 ) ) );
		$this->assertSame( array( 3 ), $this->subjects( 'HP-ACC-002', $wide ) );
	}

	public function test_acc003_critical_system_password_only(): void {
		$records = array(
			$this->record( 1, 'Password', array( 'critical' => 'yes', 'signin' => 'password_only' ) ),
			$this->record( 2, 'Unrecorded', array( 'critical' => 'yes', 'signin' => '' ) ),
			$this->record( 3, 'Strong', array( 'critical' => 'yes', 'signin' => 'two_factor' ) ),
			$this->record( 4, 'Minor', array( 'critical' => 'no', 'signin' => 'password_only' ) ),
		);
		$c   = $this->ctx( array( 'items' => array( Kinds::ACCESS => $records ) ) );
		$out = $this->run( 'HP-ACC-003', $c );
		$this->assertSame( array( 1, 2 ), $this->subjects( 'HP-ACC-003', $c ) );
		$this->assertFalse( $out[0]->values['unknown'] );
		$this->assertTrue( $out[1]->values['unknown'] );
	}
}
