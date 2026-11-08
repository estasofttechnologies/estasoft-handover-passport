<?php
/**
 * Drills: following a runbook for real or on a copy, with a record of it (RUN-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Services;

use Estasoft\HandoverPassport\Changes\ChangeLog;
use Estasoft\HandoverPassport\Data\TestRepository;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Security\SecretGuard;
use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * A drill ticks off the steps of one published runbook, as it was when the drill began. Each tick keeps the time and the
 * name of the person, and each step can take a note. Finishing records the outcome and the total time. A drill of a
 * runbook that restores the site also carries the after-restore checks and can be saved as a restore test.
 */
final class DrillService {

	public const RUNNING = 'running';

	/**
	 * Constructor.
	 *
	 * @param TestRepository     $tests     Tests table, where drills are kept with restore tests.
	 * @param RunbookService     $runbooks  Runbooks.
	 * @param RunbookPresenter   $presenter Runbooks as text, so the steps are kept as they read when the drill began.
	 * @param RestoreChecklist   $checklist The after-restore checks.
	 * @param RestoreTestService $restore   Restore tests.
	 * @param SecretGuard        $guard     Secret guard.
	 * @param ChangeLog          $log       Change log.
	 */
	public function __construct(
		private TestRepository $tests,
		private RunbookService $runbooks,
		private RunbookPresenter $presenter,
		private RestoreChecklist $checklist,
		private RestoreTestService $restore,
		private SecretGuard $guard,
		private ChangeLog $log
	) {}

	/**
	 * Drills, newest first.
	 *
	 * @param int $runbook_id Only this runbook's drills, or 0 for all.
	 * @param int $limit      How many to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( int $runbook_id = 0, int $limit = 50 ): array {
		$equals = array( 'kind' => TestRepository::DRILL );
		if ( $runbook_id > 0 ) {
			$equals['runbook_id'] = $runbook_id;
		}
		return array_map( fn( array $row ): array => $this->shape( $row ), $this->tests->query( $equals, 'id', 'DESC', $limit ) );
	}

	/**
	 * One drill, or null.
	 *
	 * @param int $id Drill ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$row = $this->tests->find( $id );
		return null !== $row && TestRepository::DRILL === (string) $row['kind'] ? $this->shape( $row ) : null;
	}

	/**
	 * Whether a drill is still going.
	 *
	 * @param array<string, mixed> $drill A drill from find().
	 */
	public static function isRunning( array $drill ): bool {
		return self::RUNNING === (string) $drill['result'];
	}

	/**
	 * Begin a drill of a published runbook. If one is already going for that runbook, it is returned instead, so the same
	 * drill is never started twice.
	 *
	 * @param int $runbook_id Runbook ID.
	 * @return array<string, mixed> The drill.
	 * @throws ValidationError When the runbook is missing, not published or has no steps.
	 */
	public function start( int $runbook_id ): array {
		$runbook = $this->runbooks->get( $runbook_id );
		if ( null === $runbook ) {
			throw new ValidationError( __( 'Runbook', 'estasoft-handover-passport' ), __( 'That runbook no longer exists.', 'estasoft-handover-passport' ) );
		}
		if ( 'published' !== (string) $runbook['status'] ) {
			throw new ValidationError( __( 'Runbook', 'estasoft-handover-passport' ), __( 'Only a published runbook can be rehearsed. Publish it first.', 'estasoft-handover-passport' ) );
		}
		if ( array() === (array) $runbook['steps'] ) {
			throw new ValidationError( __( 'Runbook', 'estasoft-handover-passport' ), __( 'This runbook has no steps to rehearse.', 'estasoft-handover-passport' ) );
		}
		foreach ( $this->all( $runbook_id, 20 ) as $drill ) {
			if ( self::isRunning( $drill ) ) {
				return $drill;
			}
		}
		$now   = Clock::sql();
		$name  = $this->person();
		$read  = (array) $this->presenter->present( $runbook_id );
		$steps = array();
		foreach ( (array) ( $read['steps'] ?? array() ) as $step ) {
			$steps[] = array(
				'text'    => (string) $step['text'],
				'who'     => (string) $step['who'],
				'minutes' => (int) $step['minutes'],
				'link'    => (string) $step['link'],
				'warning' => (bool) $step['warning'],
				'done_at' => '',
				'by'      => '',
				'note'    => '',
			);
		}
		$checks = array();
		if ( '' !== (string) $runbook['restore_scope'] ) {
			foreach ( $this->checklist->items() as $item ) {
				$checks[] = $item + array( 'done_at' => '', 'by' => '', 'note' => '' );
			}
		}
		$id = $this->tests->insert(
			array(
				'kind'            => TestRepository::DRILL,
				'runbook_id'      => $runbook_id,
				'runbook_version' => (int) $runbook['current_version'],
				'backup_item_id'  => 0,
				'test_date'       => Clock::today(),
				'who'             => '',
				'scope'           => $this->scopeOf( (string) $runbook['restore_scope'] ),
				'place'           => '',
				'backup_date'     => null,
				'minutes'         => 0,
				'result'          => self::RUNNING,
				'problems'        => '',
				'step_log'        => array(
					'started_at'    => $now,
					'started_by'    => $name,
					'finished_at'   => '',
					'steps'         => $steps,
					'checks'        => $checks,
					'saved_test_id' => 0,
				),
				'created_at'      => $now,
				'created_by'      => get_current_user_id(),
			)
		);
		if ( 0 === $id ) {
			throw new \RuntimeException( 'estasoft_hp_db_insert' );
		}
		$this->log->add( 'drill_started', 'drill', $id, (string) $runbook['title'], array(), array( 'version' => (int) $runbook['current_version'] ) );
		return (array) $this->find( $id );
	}

	/**
	 * Save the notes, and tick or untick one step or check, in one go.
	 *
	 * @param int                       $id    Drill ID.
	 * @param array<string, mixed>      $notes steps and checks: row number to note.
	 * @param array<string, mixed>|null $tick  section (steps or checks), index and done, or null to only save the notes.
	 * @return array<string, mixed> The drill.
	 * @throws ValidationError When the drill is missing or finished, or a note is too long.
	 * @throws \Estasoft\HandoverPassport\Security\SecretFound When a note looks like a secret.
	 */
	public function apply( int $id, array $notes, ?array $tick = null ): array {
		$drill = $this->running( $id );
		$log   = $drill['step_log'];

		foreach ( array( 'steps', 'checks' ) as $section ) {
			foreach ( (array) ( $notes[ $section ] ?? array() ) as $index => $note ) {
				$index = (int) $index;
				if ( ! isset( $log[ $section ][ $index ] ) || ! is_scalar( $note ) ) {
					continue;
				}
				$note = trim( sanitize_textarea_field( (string) $note ) );
				if ( mb_strlen( $note ) > 500 ) {
					throw new ValidationError( __( 'Note', 'estasoft-handover-passport' ), __( 'Keep each note under 500 characters.', 'estasoft-handover-passport' ) );
				}
				$log[ $section ][ $index ]['note'] = $note;
			}
		}
		$this->guard->assertClean( array_merge( array_column( $log['steps'], 'note' ), array_column( $log['checks'], 'note' ) ), __( 'Note', 'estasoft-handover-passport' ) );

		if ( null !== $tick ) {
			$section = 'checks' === (string) ( $tick['section'] ?? '' ) ? 'checks' : 'steps';
			$index   = (int) ( $tick['index'] ?? -1 );
			if ( ! isset( $log[ $section ][ $index ] ) ) {
				throw new ValidationError( __( 'Step', 'estasoft-handover-passport' ), __( 'That step is not part of this drill.', 'estasoft-handover-passport' ) );
			}
			if ( ! empty( $tick['done'] ) ) {
				if ( '' === (string) $log[ $section ][ $index ]['done_at'] ) {
					$log[ $section ][ $index ]['done_at'] = Clock::sql();
					$log[ $section ][ $index ]['by']      = $this->person();
				}
			} else {
				$log[ $section ][ $index ]['done_at'] = '';
				$log[ $section ][ $index ]['by']      = '';
			}
		}
		$this->tests->update( $id, array( 'step_log' => $log ) );
		return (array) $this->find( $id );
	}

	/**
	 * Finish a drill: record the outcome and the total time.
	 *
	 * @param int    $id       Drill ID.
	 * @param string $result   pass, partial or fail.
	 * @param string $problems What went wrong, if anything.
	 * @return array<string, mixed> The drill.
	 * @throws ValidationError When the outcome is not valid, or Pass is chosen with steps left undone.
	 * @throws \Estasoft\HandoverPassport\Security\SecretFound When the text looks like a secret.
	 */
	public function finish( int $id, string $result, string $problems ): array {
		$drill = $this->running( $id );
		if ( ! array_key_exists( $result, RestoreTestService::results() ) ) {
			throw new ValidationError( __( 'Result', 'estasoft-handover-passport' ), __( 'Choose Pass, Partial or Fail.', 'estasoft-handover-passport' ) );
		}
		$log   = $drill['step_log'];
		$open  = count( array_filter( $log['steps'], static fn( array $step ): bool => '' === (string) $step['done_at'] ) );
		$open += count( array_filter( $log['checks'], static fn( array $step ): bool => '' === (string) $step['done_at'] ) );
		if ( 'pass' === $result && $open > 0 ) {
			throw new ValidationError( __( 'Result', 'estasoft-handover-passport' ), sprintf( /* translators: %d: number of steps not ticked. */ _n( '%d step is not ticked. Tick it, or choose Partial.', '%d steps are not ticked. Tick them, or choose Partial.', $open, 'estasoft-handover-passport' ), $open ) );
		}
		$problems = mb_substr( trim( sanitize_textarea_field( $problems ) ), 0, 3000 );
		$this->guard->assertClean( $problems, __( 'Problems found', 'estasoft-handover-passport' ) );

		$now     = Clock::sql();
		$seconds = max( 0, (int) strtotime( $now . ' UTC' ) - (int) strtotime( (string) $log['started_at'] . ' UTC' ) );
		$minutes = max( 1, (int) ceil( $seconds / 60 ) );
		$names   = array();
		foreach ( array_merge( $log['steps'], $log['checks'] ) as $step ) {
			if ( '' !== (string) $step['by'] ) {
				$names[ (string) $step['by'] ] = true;
			}
		}
		if ( array() === $names ) {
			$names[ $this->person() ] = true;
		}
		$log['finished_at'] = $now;
		$this->tests->update(
			$id,
			array(
				'result'   => $result,
				'minutes'  => $minutes,
				'who'      => mb_substr( implode( ', ', array_keys( $names ) ), 0, 190 ),
				'problems' => $problems,
				'step_log' => $log,
			)
		);
		$title = $this->runbookTitle( (int) $drill['runbook_id'] );
		if ( 'fail' !== $result && null !== $this->runbooks->get( (int) $drill['runbook_id'] ) ) {
			$this->runbooks->markRehearsed( (int) $drill['runbook_id'], 'drill' );
		}
		$this->log->add( 'drill_finished', 'drill', $id, $title, array(), array( 'result' => RestoreTestService::results()[ $result ], 'minutes' => $minutes ), $problems );
		return (array) $this->find( $id );
	}

	/**
	 * Throw away a drill that is still going.
	 *
	 * @param int $id Drill ID.
	 * @throws ValidationError When the drill is missing or already finished.
	 */
	public function abandon( int $id ): void {
		$drill = $this->running( $id );
		$this->tests->delete( $id );
		$this->log->add( 'drill_abandoned', 'drill', $id, $this->runbookTitle( (int) $drill['runbook_id'] ) );
	}

	/**
	 * Keep a finished drill of a restoring runbook as a restore test (BKP-5).
	 *
	 * @param int                  $id    Drill ID.
	 * @param array<string, mixed> $input place and backup_date.
	 * @return array<string, mixed> The restore test.
	 * @throws ValidationError When the drill is not finished, does not restore anything, or was already saved.
	 * @throws \Estasoft\HandoverPassport\Security\SecretFound When a value looks like a secret.
	 */
	public function saveAsRestoreTest( int $id, array $input ): array {
		$drill = $this->find( $id );
		if ( null === $drill ) {
			throw new ValidationError( __( 'Drill', 'estasoft-handover-passport' ), __( 'That drill no longer exists.', 'estasoft-handover-passport' ) );
		}
		if ( self::isRunning( $drill ) ) {
			throw new ValidationError( __( 'Drill', 'estasoft-handover-passport' ), __( 'Finish the drill first.', 'estasoft-handover-passport' ) );
		}
		if ( '' === (string) $drill['scope'] ) {
			throw new ValidationError( __( 'Drill', 'estasoft-handover-passport' ), __( 'This runbook does not restore anything, so its drill is not a restore test.', 'estasoft-handover-passport' ) );
		}
		if ( (int) $drill['step_log']['saved_test_id'] > 0 ) {
			throw new ValidationError( __( 'Drill', 'estasoft-handover-passport' ), __( 'This drill was already saved as a restore test.', 'estasoft-handover-passport' ) );
		}
		$test                 = $this->restore->log(
			array(
				'test_date'      => (string) $drill['test_date'],
				'result'         => (string) $drill['result'],
				'scope'          => (string) $drill['scope'],
				'place'          => $input['place'] ?? '',
				'backup_date'    => $input['backup_date'] ?? '',
				'minutes'        => (int) $drill['minutes'],
				'who'            => (string) $drill['who'],
				'problems'       => (string) $drill['problems'],
				'runbook_id'     => (int) $drill['runbook_id'],
				'backup_item_id' => $input['backup_item_id'] ?? 0,
			),
			'drill',
			array(
				'step_log'        => $drill['step_log'],
				'runbook_version' => (int) $drill['runbook_version'],
			)
		);
		$log                  = $drill['step_log'];
		$log['saved_test_id'] = (int) $test['id'];
		$this->tests->update( $id, array( 'step_log' => $log ) );
		return $test;
	}

	/**
	 * The drill if it is still going, or an error.
	 *
	 * @param int $id Drill ID.
	 * @return array<string, mixed>
	 * @throws ValidationError When the drill is missing or finished.
	 */
	private function running( int $id ): array {
		$drill = $this->find( $id );
		if ( null === $drill ) {
			throw new ValidationError( __( 'Drill', 'estasoft-handover-passport' ), __( 'That drill no longer exists.', 'estasoft-handover-passport' ) );
		}
		if ( ! self::isRunning( $drill ) ) {
			throw new ValidationError( __( 'Drill', 'estasoft-handover-passport' ), __( 'This drill is finished and cannot be changed.', 'estasoft-handover-passport' ) );
		}
		return $drill;
	}

	/**
	 * A stored row with every part of its log present.
	 *
	 * @param array<string, mixed> $row Stored row.
	 * @return array<string, mixed>
	 */
	private function shape( array $row ): array {
		$log             = is_array( $row['step_log'] ) ? $row['step_log'] : array();
		$fix             = static fn( $steps_in ): array => array_values( array_map( static fn( $step ): array => (array) $step + array( 'text' => '', 'label' => '', 'detail' => '', 'kind' => '', 'who' => '', 'minutes' => 0, 'link' => '', 'warning' => false, 'done_at' => '', 'by' => '', 'note' => '' ), is_array( $steps_in ) ? $steps_in : array() ) );
		$row['step_log'] = array(
			'started_at'    => (string) ( $log['started_at'] ?? $row['created_at'] ),
			'started_by'    => (string) ( $log['started_by'] ?? '' ),
			'finished_at'   => (string) ( $log['finished_at'] ?? '' ),
			'steps'         => $fix( $log['steps'] ?? array() ),
			'checks'        => $fix( $log['checks'] ?? array() ),
			'saved_test_id' => (int) ( $log['saved_test_id'] ?? 0 ),
		);
		return $row;
	}

	/**
	 * A restore test scope for what a runbook restores.
	 *
	 * @param string $restore_scope site, database, files or empty.
	 */
	private function scopeOf( string $restore_scope ): string {
		return match ( $restore_scope ) {
			'site'     => 'both',
			'database' => 'database',
			'files'    => 'files',
			default    => '',
		};
	}

	/**
	 * The title of a runbook, also after it was deleted.
	 *
	 * @param int $runbook_id Runbook ID.
	 */
	private function runbookTitle( int $runbook_id ): string {
		$runbook = $this->runbooks->get( $runbook_id, true );
		return null === $runbook ? '' : (string) $runbook['title'];
	}

	/**
	 * The name of the person at the keyboard.
	 */
	private function person(): string {
		$name = (string) wp_get_current_user()->display_name;
		return '' !== $name ? mb_substr( $name, 0, 120 ) : __( 'Someone', 'estasoft-handover-passport' );
	}
}
