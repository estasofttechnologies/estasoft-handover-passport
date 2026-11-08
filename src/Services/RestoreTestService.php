<?php
/**
 * The restore-test log (BKP-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Services;

use Estasoft\HandoverPassport\Changes\ChangeLog;
use Estasoft\HandoverPassport\Data\RunbookRepository;
use Estasoft\HandoverPassport\Data\TestRepository;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Security\SecretGuard;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * A restore test is a fact: on this date, someone restored this backup here, it took this long, and this was the result.
 */
final class RestoreTestService {

	/**
	 * Constructor.
	 *
	 * @param TestRepository    $tests    Tests.
	 * @param RunbookRepository $runbooks Runbooks, to record the version used.
	 * @param SecretGuard       $guard    Secret guard.
	 * @param ChangeLog         $log      Change log.
	 */
	public function __construct( private TestRepository $tests, private RunbookRepository $runbooks, private SecretGuard $guard, private ChangeLog $log ) {}

	/**
	 * What was restored.
	 *
	 * @return array<string, string>
	 */
	public static function scopes(): array {
		return array(
			'files'    => __( 'Files', 'estasoft-handover-passport' ),
			'database' => __( 'Database', 'estasoft-handover-passport' ),
			'both'     => __( 'Files and database', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Where it was restored to.
	 *
	 * @return array<string, string>
	 */
	public static function places(): array {
		return array(
			'staging'    => __( 'Staging site', 'estasoft-handover-passport' ),
			'local'      => __( 'Local machine', 'estasoft-handover-passport' ),
			'spare_host' => __( 'Spare host', 'estasoft-handover-passport' ),
			'other'      => __( 'Somewhere else', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Results.
	 *
	 * @return array<string, string>
	 */
	public static function results(): array {
		return array(
			'pass'    => __( 'Pass', 'estasoft-handover-passport' ),
			'partial' => __( 'Partial', 'estasoft-handover-passport' ),
			'fail'    => __( 'Fail', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Restore tests, newest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		return $this->tests->restoreTests();
	}

	/**
	 * Date of the last Pass, or null.
	 */
	public function lastPass(): ?string {
		return $this->tests->lastPassDate();
	}

	/**
	 * Log a restore test.
	 *
	 * @param array<string, mixed> $input   Posted values.
	 * @param string               $channel Where it came from.
	 * @return array<string, mixed> The stored row.
	 * @throws ValidationError            When a value is invalid.
	 * @throws \Estasoft\HandoverPassport\Security\SecretFound When a value looks like a secret.
	 */
	public function log( array $input, string $channel = 'screen' ): array {
		$str = static fn( string $key, int $max ): string => mb_substr( trim( sanitize_text_field( is_scalar( $input[ $key ] ?? null ) ? (string) $input[ $key ] : '' ) ), 0, $max );

		$date = $str( 'test_date', 10 );
		if ( ! Dates::isDate( $date ) ) {
			throw new ValidationError( __( 'Date of the test', 'estasoft-handover-passport' ), __( 'Enter a date.', 'estasoft-handover-passport' ) );
		}
		if ( $date > Clock::today() ) {
			throw new ValidationError( __( 'Date of the test', 'estasoft-handover-passport' ), __( 'A test cannot be dated in the future.', 'estasoft-handover-passport' ) );
		}
		$result = $str( 'result', 10 );
		if ( ! array_key_exists( $result, self::results() ) ) {
			throw new ValidationError( __( 'Result', 'estasoft-handover-passport' ), __( 'Choose Pass, Partial or Fail.', 'estasoft-handover-passport' ) );
		}
		$scope = $str( 'scope', 10 );
		$place = $str( 'place', 20 );
		$backup_date = $str( 'backup_date', 10 );
		if ( '' !== $backup_date && ! Dates::isDate( $backup_date ) ) {
			throw new ValidationError( __( 'Date of the backup used', 'estasoft-handover-passport' ), __( 'Enter a date or leave it empty.', 'estasoft-handover-passport' ) );
		}

		$runbook_id      = is_scalar( $input['runbook_id'] ?? null ) ? absint( $input['runbook_id'] ) : 0;
		$runbook_version = 0;
		if ( $runbook_id > 0 ) {
			$runbook = $this->runbooks->find( $runbook_id );
			if ( null === $runbook || null !== $runbook['deleted_at'] ) {
				$runbook_id = 0;
			} else {
				$runbook_version = (int) $runbook['current_version'];
			}
		}

		$row = array(
			'kind'            => TestRepository::RESTORE_TEST,
			'runbook_id'      => $runbook_id,
			'runbook_version' => $runbook_version,
			'backup_item_id'  => is_scalar( $input['backup_item_id'] ?? null ) ? absint( $input['backup_item_id'] ) : 0,
			'test_date'       => $date,
			'who'             => $str( 'who', 190 ),
			'scope'           => array_key_exists( $scope, self::scopes() ) ? $scope : '',
			'place'           => array_key_exists( $place, self::places() ) ? $place : '',
			'backup_date'     => '' === $backup_date ? null : $backup_date,
			'minutes'         => is_scalar( $input['minutes'] ?? null ) ? max( 0, min( 100000, absint( $input['minutes'] ) ) ) : 0,
			'result'          => $result,
			'problems'        => mb_substr( trim( sanitize_textarea_field( is_scalar( $input['problems'] ?? null ) ? (string) $input['problems'] : '' ) ), 0, 3000 ),
			'step_log'        => array(),
			'created_at'      => Clock::sql(),
			'created_by'      => get_current_user_id(),
		);
		$this->guard->assertClean( $row['who'], __( 'Who did it', 'estasoft-handover-passport' ) );
		$this->guard->assertClean( $row['problems'], __( 'Problems found', 'estasoft-handover-passport' ) );

		$id = $this->tests->insert( $row );
		if ( 0 === $id ) {
			throw new \RuntimeException( 'estasoft_hp_db_insert' );
		}
		$label = Dates::displayDate( $date ) . ': ' . self::results()[ $result ];
		$this->log->add( 'restore_test_logged', 'test', $id, $label, array(), array( 'result' => $result, 'minutes' => $row['minutes'] ), '', $channel );
		do_action( 'estasoft_hp_record_saved', $id, 'test', array(), array( 'id' => $id, 'result' => $result ) );
		return $row + array( 'id' => $id );
	}

	/**
	 * Remove a test that was logged by mistake. The removal is written to the change log.
	 *
	 * @param int    $id      Test ID.
	 * @param string $channel Origin for the log.
	 */
	public function delete( int $id, string $channel = 'wordpress' ): void {
		$row = $this->tests->find( $id );
		if ( null === $row ) {
			return;
		}
		$this->tests->delete( $id );
		$this->log->add( 'restore_test_deleted', 'test', $id, Dates::displayDate( (string) $row['test_date'] ) . ': ' . ( self::results()[ (string) $row['result'] ] ?? '' ), source: $channel );
		do_action( 'estasoft_hp_record_saved', $id, 'test', array(), array() );
	}

	/**
	 * Underlying repository.
	 */
	public function repo(): TestRepository {
		return $this->tests;
	}
}
