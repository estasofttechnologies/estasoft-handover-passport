<?php
/**
 * Sent-reminder log (RMD-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Access to the reminders table. Addresses are never kept, only a count.
 */
class ReminderRepository extends Repository {

	protected function key(): string {
		return 'reminders';
	}

	protected function columns(): array {
		return array( 'item_id', 'lead_days', 'cycle_key', 'sent_at', 'status', 'attempts', 'recipient_count' );
	}

	/**
	 * The log row for one item, cycle and lead time.
	 *
	 * @param int    $item_id Item ID.
	 * @param string $cycle   Cycle key.
	 * @param int    $lead    Lead days.
	 * @return array<string, mixed>|null
	 */
	public function forCycle( int $item_id, string $cycle, int $lead ): ?array {
		$rows = $this->query( array( 'item_id' => $item_id, 'cycle_key' => $cycle, 'lead_days' => $lead ), 'id', 'DESC', 1 );
		return $rows[0] ?? null;
	}

	/**
	 * Rows for failed sends that may be tried again.
	 *
	 * @param int $max_attempts Stop after this many attempts.
	 * @return list<array<string, mixed>>
	 */
	public function retryable( int $max_attempts ): array {
		return $this->query( array( 'status' => 'failed' ), 'sent_at', 'ASC', 0, 0, 'attempts < %d', array( $max_attempts ) );
	}

	/**
	 * Remove log rows older than a moment.
	 *
	 * @param string $before UTC date-time.
	 */
	public function purgeBefore( string $before ): void {
		$this->db()->query( $this->db()->prepare( "DELETE FROM {$this->table()} WHERE sent_at < %s", $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed table name; retention task.
	}
}
