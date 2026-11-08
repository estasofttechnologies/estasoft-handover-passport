<?php
/**
 * Restore tests and drills (BKP-5, RUN-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Access to the tests table.
 */
class TestRepository extends Repository {

	public const RESTORE_TEST = 'restore_test';
	public const DRILL        = 'drill';

	/**
	 * {@inheritDoc}
	 */
	protected function key(): string {
		return 'tests';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function jsonColumns(): array {
		return array( 'step_log' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function columns(): array {
		return array( 'kind', 'runbook_id', 'runbook_version', 'backup_item_id', 'test_date', 'who', 'scope', 'place', 'backup_date', 'minutes', 'result', 'problems', 'step_log', 'created_at', 'created_by' );
	}

	/**
	 * Restore tests (not drills), newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function restoreTests(): array {
		return $this->query( array( 'kind' => self::RESTORE_TEST ), 'test_date', 'DESC' );
	}

	/**
	 * Date of the latest restore test with a passing result, or null.
	 */
	public function lastPassDate(): ?string {
		$rows = $this->query( array( 'kind' => self::RESTORE_TEST, 'result' => 'pass' ), 'test_date', 'DESC', 1 );
		return isset( $rows[0] ) ? (string) $rows[0]['test_date'] : null;
	}

	/**
	 * Latest drill or test for a runbook.
	 *
	 * @param int $runbook_id Runbook ID.
	 * @return array<string, mixed>|null
	 */
	public function lastForRunbook( int $runbook_id ): ?array {
		$rows = $this->query( array( 'runbook_id' => $runbook_id ), 'test_date', 'DESC', 1 );
		return $rows[0] ?? null;
	}
}
