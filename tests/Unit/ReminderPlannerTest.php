<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Reminders\ReminderPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Each reminder goes once per record, date and lead time (RMD-1, RMD-4, RMD-5, AS-4).
 */
final class ReminderPlannerTest extends TestCase {

	private const LEADS = array( 60, 30, 14, 7, 1, 0 );

	/**
	 * A sent log that remembers what was "sent", like the table does.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $log = array();

	protected function setUp(): void {
		$this->log = array();
	}

	/**
	 * Run the planner against the fake log.
	 *
	 * @param list<array<string, mixed>> $records Records.
	 * @param string                     $today   Today.
	 * @return list<array<string, mixed>>
	 */
	private function plan( array $records, string $today ): array {
		return ReminderPlanner::plan( $records, $today, fn( int $id, string $cycle, int $lead ): ?array => $this->log[ "$id|$cycle|$lead" ] ?? null );
	}

	/**
	 * Pretend the step was sent.
	 *
	 * @param array<string, mixed> $step Planned step.
	 * @param string               $status sent or failed.
	 * @param string               $at     UTC date-time of the attempt.
	 */
	private function record( array $step, string $status = 'sent', string $at = '2026-05-01 07:00:00' ): void {
		$this->log[ "{$step['id']}|{$step['cycle']}|{$step['lead']}" ] = array(
			'id'       => 900 + (int) $step['id'],
			'status'   => $status,
			'attempts' => $step['attempt'],
			'sent_at'  => $at,
		);
	}

	/**
	 * A licence record.
	 *
	 * @param string $due Due date.
	 * @return array<string, mixed>
	 */
	private function licence( string $due, array $leads = self::LEADS, bool $skip = false ): array {
		return array( 'id' => 7, 'due' => $due, 'leads' => $leads, 'skip' => $skip );
	}

	public function test_a_licence_due_in_14_days_sends_one_reminder_and_a_second_run_sends_none(): void {
		$records = array( $this->licence( '2026-05-15' ) );
		$first   = $this->plan( $records, '2026-05-01' );
		$this->assertCount( 1, $first );
		$this->assertSame( 14, $first[0]['lead'] );
		$this->assertSame( 14, $first[0]['days_left'] );
		$this->assertSame( 1, $first[0]['attempt'] );

		$this->record( $first[0] );
		$this->assertSame( array(), $this->plan( $records, '2026-05-01' ), 'The same day, run again' );
		$this->assertSame( array(), $this->plan( $records, '2026-05-02' ), 'The next day is still the 14-day lead' );
	}

	public function test_each_lead_time_sends_once_as_the_date_comes_closer(): void {
		$records = array( $this->licence( '2026-07-01' ) );
		$sent    = array();
		$day     = new \DateTimeImmutable( '2026-04-01', new \DateTimeZone( 'UTC' ) );
		for ( $i = 0; $i < 100; $i++ ) {
			foreach ( $this->plan( $records, $day->format( 'Y-m-d' ) ) as $step ) {
				$sent[] = $step['lead'];
				$this->record( $step );
			}
			$day = $day->modify( '+1 day' );
		}
		$this->assertSame( array( 60, 30, 14, 7, 1, 0 ), $sent );
	}

	public function test_a_missed_run_sends_the_lead_the_date_has_reached_not_all_of_them(): void {
		$steps = $this->plan( array( $this->licence( '2026-05-21' ) ), '2026-05-01' );
		$this->assertCount( 1, $steps );
		$this->assertSame( 30, $steps[0]['lead'] );
	}

	public function test_the_day_itself_and_a_few_days_after_still_send_the_last_reminder_once(): void {
		$records = array( $this->licence( '2026-05-01' ) );
		$steps   = $this->plan( $records, '2026-05-03' );
		$this->assertCount( 1, $steps );
		$this->assertSame( 0, $steps[0]['lead'] );
		$this->record( $steps[0] );
		$this->assertSame( array(), $this->plan( $records, '2026-05-04' ) );
		$this->assertSame( array(), $this->plan( array( $this->licence( '2026-05-01' ) ), '2026-05-05' ), 'Four days late: nothing, even for a fresh log' );
	}

	public function test_a_new_date_starts_a_new_cycle(): void {
		$old = $this->plan( array( $this->licence( '2026-05-15' ) ), '2026-05-01' );
		$this->record( $old[0] );
		$this->assertSame( array(), $this->plan( array( $this->licence( '2026-05-15' ) ), '2026-05-01' ) );

		// Renewed: the date moves a year on, so a year later the same lead time can send again.
		$this->assertSame( array(), $this->plan( array( $this->licence( '2027-05-15' ) ), '2026-05-01' ), 'Far from the new date' );
		$next = $this->plan( array( $this->licence( '2027-05-15' ) ), '2027-05-01' );
		$this->assertCount( 1, $next );
		$this->assertSame( 14, $next[0]['lead'] );
		$this->assertNotSame( $old[0]['cycle'], $next[0]['cycle'] );
	}

	public function test_a_record_override_decides_the_days(): void {
		$records = array( $this->licence( '2026-05-15', array( 3 ) ) );
		$this->assertSame( array(), $this->plan( $records, '2026-05-01' ), 'Not yet 3 days away' );
		$steps = $this->plan( $records, '2026-05-12' );
		$this->assertCount( 1, $steps );
		$this->assertSame( 3, $steps[0]['lead'] );
		$this->assertSame( array(), $this->plan( array( $this->licence( '2026-05-15', array() ) ), '2026-05-14' ), 'Off for this record' );
	}

	public function test_skipped_and_dateless_records_are_ignored(): void {
		$this->assertSame( array(), $this->plan( array( $this->licence( '2026-05-15', self::LEADS, true ) ), '2026-05-15' ) );
		$this->assertSame( array(), $this->plan( array( $this->licence( 'soon' ) ), '2026-05-15' ) );
		$this->assertSame( array(), $this->plan( array( $this->licence( '' ) ), '2026-05-15' ) );
	}

	public function test_a_failed_send_is_tried_again_the_next_day_up_to_three_times(): void {
		$records = array( $this->licence( '2026-05-15' ) );

		$first = $this->plan( $records, '2026-05-01' );
		$this->assertSame( 1, $first[0]['attempt'] );
		$this->record( $first[0], 'failed', '2026-05-01 07:00:00' );
		$this->assertSame( array(), $this->plan( $records, '2026-05-01' ), 'Not twice on the same day' );

		$second = $this->plan( $records, '2026-05-02' );
		$this->assertCount( 1, $second );
		$this->assertSame( 2, $second[0]['attempt'] );
		$this->assertSame( 907, $second[0]['log_id'], 'The same log row is updated' );
		$this->record( $second[0], 'failed', '2026-05-02 07:00:00' );

		$third = $this->plan( $records, '2026-05-03' );
		$this->assertSame( 3, $third[0]['attempt'] );
		$this->record( $third[0], 'failed', '2026-05-03 07:00:00' );

		$this->assertSame( array(), $this->plan( $records, '2026-05-04' ), 'Gives up after three attempts' );
	}

	public function test_a_retry_that_works_ends_the_tries(): void {
		$records = array( $this->licence( '2026-05-15' ) );
		$first   = $this->plan( $records, '2026-05-01' );
		$this->record( $first[0], 'failed', '2026-05-01 07:00:00' );
		$second = $this->plan( $records, '2026-05-02' );
		$this->record( $second[0], 'sent', '2026-05-02 07:00:00' );
		$this->assertSame( array(), $this->plan( $records, '2026-05-03' ) );
	}

	public function test_two_records_are_planned_independently(): void {
		$records = array(
			array( 'id' => 1, 'due' => '2026-05-15', 'leads' => self::LEADS, 'skip' => false ),
			array( 'id' => 2, 'due' => '2026-05-08', 'leads' => self::LEADS, 'skip' => false ),
			array( 'id' => 3, 'due' => '2026-12-31', 'leads' => self::LEADS, 'skip' => false ),
		);
		$steps = $this->plan( $records, '2026-05-01' );
		$this->assertCount( 2, $steps );
		$this->assertSame( array( 1, 2 ), array_map( static fn( array $s ): int => (int) $s['id'], $steps ) );
		$this->assertSame( array( 14, 7 ), array_map( static fn( array $s ): int => (int) $s['lead'], $steps ) );
	}
}
