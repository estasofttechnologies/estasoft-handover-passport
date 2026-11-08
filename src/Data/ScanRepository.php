<?php
/**
 * Scan results (SCN-1 to SCN-9).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Access to the scans table.
 */
class ScanRepository extends Repository {

	protected function key(): string {
		return 'scans';
	}

	protected function jsonColumns(): array {
		return array( 'facts', 'differences' );
	}

	protected function columns(): array {
		return array( 'trigger_kind', 'status', 'started_at', 'finished_at', 'facts', 'differences', 'error_code' );
	}

	/**
	 * Latest finished scan.
	 *
	 * @return array<string, mixed>|null
	 */
	public function latestDone(): ?array {
		$rows = $this->query( array( 'status' => 'done' ), 'started_at', 'DESC', 1 );
		return $rows[0] ?? null;
	}

	/**
	 * Latest scan of any state.
	 *
	 * @return array<string, mixed>|null
	 */
	public function latest(): ?array {
		$rows = $this->query( array(), 'started_at', 'DESC', 1 );
		return $rows[0] ?? null;
	}

	/**
	 * Keep only the newest scans so facts do not pile up.
	 *
	 * @param int $keep Number of scans to keep.
	 */
	public function prune( int $keep = 30 ): void {
		$rows = $this->query( array(), 'started_at', 'DESC' );
		foreach ( array_slice( $rows, $keep ) as $row ) {
			$this->delete( (int) $row['id'] );
		}
	}
}
