<?php
/**
 * Tests for the findings engine: opening, keeping, snoozing, accepting, resolving (FND-1..5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Data\FindingRepository;
use Estasoft\HandoverPassport\Findings\Candidate;
use Estasoft\HandoverPassport\Findings\Context;
use Estasoft\HandoverPassport\Findings\Engine;
use Estasoft\HandoverPassport\Findings\Level;
use Estasoft\HandoverPassport\Findings\Rule;
use Estasoft\HandoverPassport\Support\Clock;

/**
 * One test rule whose answer the test controls.
 */
final class EngineTest extends UnitTestCase {

	/**
	 * Subjects the test rule reports now, or null for "not checked".
	 *
	 * @var list<string>|null
	 */
	private ?array $subjects = array();

	/**
	 * Level the test rule gives.
	 *
	 * @var string
	 */
	private string $level = Level::WORTH;

	/**
	 * Whether the test rule throws.
	 *
	 * @var bool
	 */
	private bool $throws = false;

	private MemoryFindingRepository $repo;
	private Engine $engine;

	protected function setUp(): void {
		Clock::freeze( '2026-11-08 10:00:00' );
		$GLOBALS['estasoft_hp_test_actions'] = array();
		$this->subjects                       = array();
		$this->level                          = Level::WORTH;
		$this->throws                         = false;
		$this->repo                           = new MemoryFindingRepository();
		$this->engine                         = new Engine( $this->repo );
		$rule                                 = new Rule(
			'HP-TST-001',
			'test',
			Level::WORTH,
			false,
			'A test rule',
			array( 'page' => 'x', 'tab' => '' ),
			function ( Context $c ): ?array {
				if ( $this->throws ) {
					throw new \RuntimeException( 'boom' );
				}
				if ( null === $this->subjects ) {
					return null;
				}
				return array_map( fn( string $key ): Candidate => Candidate::keyed( 'thing', $key, $this->level, array( 'key' => $key ) ), $this->subjects );
			},
			static fn( array $v ): string => 'Thing ' . $v['key']
		);
		$GLOBALS['estasoft_hp_test_filters'] = array( 'estasoft_hp_rules' => array( static fn() => array( $rule ) ) );
	}

	protected function tearDown(): void {
		Clock::freeze( null );
		$GLOBALS['estasoft_hp_test_filters'] = array();
	}

	/**
	 * Run the engine at a moment.
	 *
	 * @param string               $at       UTC date-time.
	 * @param array<string,string> $disabled Disabled rules.
	 * @return array<string, mixed>
	 */
	private function run_at( string $at, array $disabled = array() ): array {
		Clock::freeze( $at );
		return $this->engine->run( new Context( substr( $at, 0, 10 ), $at ), $disabled );
	}

	/**
	 * Names of the actions fired so far.
	 *
	 * @return list<string>
	 */
	private function fired(): array {
		return array_map( static fn( array $a ): string => $a[0], $GLOBALS['estasoft_hp_test_actions'] );
	}

	public function test_a_true_fact_opens_a_finding_once(): void {
		$this->subjects = array( 'a' );
		$sum            = $this->run_at( '2026-11-08 10:00:00' );
		$this->assertSame( 1, $sum['opened'] );
		$rows = $this->repo->forRule( 'HP-TST-001' );
		$this->assertCount( 1, $rows );
		$this->assertSame( FindingRepository::OPEN, $rows[0]['state'] );
		$this->assertSame( '2026-11-08 10:00:00', $rows[0]['first_seen'] );
		$this->assertSame( array( 'estasoft_hp_finding_opened' ), $this->fired() );

		$again = $this->run_at( '2026-11-09 10:00:00' );
		$this->assertSame( 0, $again['opened'] );
		$this->assertSame( 1, $again['updated'] );
		$rows = $this->repo->forRule( 'HP-TST-001' );
		$this->assertCount( 1, $rows, 'No duplicate on the second run (FND-2)' );
		$this->assertSame( '2026-11-08 10:00:00', $rows[0]['first_seen'], 'First seen is kept' );
		$this->assertSame( '2026-11-09 10:00:00', $rows[0]['last_seen'] );
	}

	public function test_it_resolves_by_itself_when_the_fact_stops_being_true(): void {
		$this->subjects = array( 'a', 'b' );
		$this->run_at( '2026-11-08 10:00:00' );
		$this->subjects = array( 'b' );
		$sum            = $this->run_at( '2026-11-09 10:00:00' );
		$this->assertSame( 1, $sum['resolved'] );
		$by = array();
		foreach ( $this->repo->forRule( 'HP-TST-001' ) as $row ) {
			$by[ $row['subject_key'] ] = $row;
		}
		$this->assertSame( FindingRepository::RESOLVED, $by['a']['state'] );
		$this->assertSame( '2026-11-09 10:00:00', $by['a']['resolved_at'] );
		$this->assertSame( FindingRepository::OPEN, $by['b']['state'] );
		$this->assertContains( 'estasoft_hp_finding_resolved', $this->fired() );
	}

	public function test_a_snoozed_finding_stays_hidden_then_reopens(): void {
		$this->subjects = array( 'a' );
		$this->run_at( '2026-11-08 10:00:00' );
		$id = array_key_first( $this->repo->rows );
		$this->repo->update( $id, array( 'state' => FindingRepository::SNOOZED, 'snoozed_until' => '2026-11-15 10:00:00' ) );

		$this->run_at( '2026-11-10 10:00:00' );
		$this->assertSame( FindingRepository::SNOOZED, $this->repo->find( $id )['state'], 'Still snoozed before the date' );

		$this->run_at( '2026-11-15 10:00:00' );
		$row = $this->repo->find( $id );
		$this->assertSame( FindingRepository::OPEN, $row['state'], 'Reopens when the snooze ends' );
		$this->assertNull( $row['snoozed_until'] );
	}

	public function test_a_snoozed_finding_that_clears_is_resolved(): void {
		$this->subjects = array( 'a' );
		$this->run_at( '2026-11-08 10:00:00' );
		$id = array_key_first( $this->repo->rows );
		$this->repo->update( $id, array( 'state' => FindingRepository::SNOOZED, 'snoozed_until' => '2026-12-01 00:00:00' ) );
		$this->subjects = array();
		$this->run_at( '2026-11-09 10:00:00' );
		$this->assertSame( FindingRepository::RESOLVED, $this->repo->find( $id )['state'] );
		$this->assertNull( $this->repo->find( $id )['snoozed_until'] );
	}

	public function test_an_accepted_finding_keeps_its_reason_and_is_not_reopened(): void {
		$this->subjects = array( 'a' );
		$this->run_at( '2026-11-08 10:00:00' );
		$id = array_key_first( $this->repo->rows );
		$this->repo->update( $id, array( 'state' => FindingRepository::ACCEPTED, 'accepted_reason' => 'Agreed with the client', 'accepted_by' => 7 ) );

		$this->run_at( '2027-03-01 10:00:00' );
		$row = $this->repo->find( $id );
		$this->assertSame( FindingRepository::ACCEPTED, $row['state'] );
		$this->assertSame( 'Agreed with the client', $row['accepted_reason'] );
		$this->assertSame( 7, $row['accepted_by'] );

		$this->subjects = array();
		$this->run_at( '2027-03-02 10:00:00' );
		$this->assertSame( FindingRepository::RESOLVED, $this->repo->find( $id )['state'], 'Accepted findings still clear when the fact stops' );

		$this->subjects = array( 'a' );
		$sum            = $this->run_at( '2027-04-01 10:00:00' );
		$this->assertSame( 1, $sum['opened'], 'A returning fact is a new finding' );
		$this->assertCount( 2, $this->repo->forRule( 'HP-TST-001' ) );
	}

	public function test_a_rule_that_cannot_check_leaves_findings_alone(): void {
		$this->subjects = array( 'a' );
		$this->run_at( '2026-11-08 10:00:00' );
		$this->subjects = null;
		$sum            = $this->run_at( '2026-11-09 10:00:00' );
		$this->assertSame( array( 'HP-TST-001' ), $sum['not_checked'] );
		$this->assertSame( 0, $sum['resolved'] );
		$this->assertSame( FindingRepository::OPEN, $this->repo->forRule( 'HP-TST-001' )[0]['state'], 'Not checked is not passed' );
	}

	public function test_a_rule_that_throws_counts_as_not_checked(): void {
		$this->subjects = array( 'a' );
		$this->run_at( '2026-11-08 10:00:00' );
		$this->throws = true;
		$sum          = $this->run_at( '2026-11-09 10:00:00' );
		$this->assertSame( array( 'HP-TST-001' ), $sum['not_checked'] );
		$this->assertSame( FindingRepository::OPEN, $this->repo->forRule( 'HP-TST-001' )[0]['state'] );
	}

	public function test_a_disabled_rule_resolves_its_findings(): void {
		$this->subjects = array( 'a' );
		$this->run_at( '2026-11-08 10:00:00' );
		$sum = $this->run_at( '2026-11-09 10:00:00', array( 'HP-TST-001' => 'Not wanted' ) );
		$this->assertSame( 1, $sum['resolved'] );
		$this->assertSame( array(), $sum['not_checked'], 'A switched-off rule is not "not checked"' );
		$this->assertSame( FindingRepository::RESOLVED, $this->repo->forRule( 'HP-TST-001' )[0]['state'] );
		$back = $this->run_at( '2026-11-10 10:00:00' );
		$this->assertSame( 1, $back['opened'], 'Switching it back on opens the finding again' );
	}

	public function test_severity_follows_the_latest_candidate(): void {
		$this->subjects = array( 'a' );
		$this->run_at( '2026-11-08 10:00:00' );
		$this->level = Level::NEEDS;
		$this->run_at( '2026-11-09 10:00:00' );
		$this->assertSame( Level::NEEDS, $this->repo->forRule( 'HP-TST-001' )[0]['severity'] );
		$this->assertSame( array( 'key' => 'a' ), $this->repo->forRule( 'HP-TST-001' )[0]['message_values'] );
	}

	public function test_identity_is_rule_subject_type_id_and_key(): void {
		$this->assertSame( 'R|item|5|', Candidate::item( 5, Level::INFO )->identity( 'R' ) );
		$this->assertSame( 'R|site|0|', Candidate::site( Level::INFO )->identity( 'R' ) );
		$this->assertSame( 'R|plugin|0|akismet', Candidate::keyed( 'plugin', 'akismet', Level::INFO )->identity( 'R' ) );
		$this->assertSame( 'R|person|3|', Candidate::row( 'person', 3, Level::INFO )->identity( 'R' ) );
		$this->assertNotSame( Candidate::item( 5, Level::INFO )->identity( 'R' ), Candidate::item( 6, Level::INFO )->identity( 'R' ) );
	}
}
