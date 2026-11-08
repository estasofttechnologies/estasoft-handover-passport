<?php
/**
 * Tests for the rule catalogue as a whole.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Admin\SettingsScreen;
use Estasoft\HandoverPassport\Findings\Candidate;
use Estasoft\HandoverPassport\Findings\Context;
use Estasoft\HandoverPassport\Findings\Level;
use Estasoft\HandoverPassport\Findings\Rule;
use Estasoft\HandoverPassport\Findings\RuleCatalog;
use Estasoft\HandoverPassport\Findings\SnapshotIntegrity;
use Estasoft\HandoverPassport\Settings\Settings;

/**
 * The catalogue is the public list of rule IDs, so its shape is tested.
 */
final class RuleCatalogTest extends UnitTestCase {

	protected function tearDown(): void {
		$GLOBALS['estasoft_hp_test_filters'] = array();
	}

	public function test_every_rule_has_a_stable_id_a_level_and_a_screen(): void {
		$rules = RuleCatalog::all();
		$this->assertGreaterThan( 50, count( $rules ), 'The shipped catalogue has more than fifty rules' );
		foreach ( $rules as $id => $rule ) {
			$this->assertSame( $id, $rule->id );
			$this->assertMatchesRegularExpression( '/^HP-[A-Z]{3}-\d{3}$/', $id );
			$this->assertContains( $rule->level, Level::all(), $id . ' level' );
			$this->assertNotSame( '', $rule->title, $id . ' title' );
			$this->assertNotSame( '', $rule->area, $id . ' area' );
			$this->assertArrayHasKey( 'page', $rule->screen, $id . ' screen' );
		}
	}

	public function test_titles_make_no_claim_of_safety(): void {
		foreach ( RuleCatalog::all() as $id => $rule ) {
			$this->assertSame( 0, preg_match( '/\b(safe|secure|compliant|compliance|protected|healthy)\b/i', $rule->title ), $id . ': ' . $rule->title );
		}
	}

	public function test_thresholds_named_by_rules_exist_in_settings(): void {
		$known  = array_keys( Settings::defaults()['thresholds'] );
		$fields = array_keys( SettingsScreen::thresholdFields() );
		foreach ( RuleCatalog::all() as $id => $rule ) {
			if ( null !== $rule->threshold ) {
				$this->assertContains( $rule->threshold, $known, $id . ' names a threshold Settings does not have' );
				$this->assertContains( $rule->threshold, $fields, $id . ' threshold has no field on the Findings tab' );
			}
		}
		foreach ( $fields as $field ) {
			$this->assertContains( $field, $known, $field . ' is on the Findings tab but has no default' );
		}
	}

	public function test_a_rule_can_be_added_but_not_replaced_through_the_filter(): void {
		$custom = new Rule( 'HP-XYZ-001', 'custom', Level::INFO, false, 'Custom', array( 'page' => 'x' ), static fn(): array => array( Candidate::site( Level::INFO ) ), static fn(): string => 'Custom' );
		$clash  = new Rule( 'HP-STK-001', 'stack', Level::INFO, false, 'Replacement', array( 'page' => 'x' ), static fn(): array => array(), static fn(): string => '' );
		$GLOBALS['estasoft_hp_test_filters'] = array( 'estasoft_hp_rules' => array( static fn( array $rules ): array => array_merge( $rules, array( $custom, $clash, 'not a rule' ) ) ) );
		$rules = RuleCatalog::all();
		$this->assertArrayHasKey( 'HP-XYZ-001', $rules );
		$this->assertNotSame( 'Replacement', $rules['HP-STK-001']->title );
		$this->assertCount( 1, $rules['HP-XYZ-001']->evaluate( new Context( '2026-11-08', '2026-11-08 10:00:00' ) ) );
	}

	public function test_snapshot_integrity_compares_the_stored_text_with_its_hash(): void {
		$text = '{"a":1}';
		$this->assertTrue( SnapshotIntegrity::matches( $text, hash( 'sha256', $text ) ) );
		$this->assertTrue( SnapshotIntegrity::matches( $text, strtoupper( hash( 'sha256', $text ) ) ) );
		$this->assertFalse( SnapshotIntegrity::matches( '{"a":2}', hash( 'sha256', $text ) ) );
		$this->assertFalse( SnapshotIntegrity::matches( $text, '' ) );
	}
}
