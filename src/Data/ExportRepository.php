<?php
/**
 * Export log (EXP-9). It holds no file.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Access to the exports table.
 */
class ExportRepository extends Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function key(): string {
		return 'exports';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function columns(): array {
		return array( 'exported_at', 'user_id', 'snapshot_id', 'profile', 'format', 'size', 'hash', 'emailed' );
	}

	/**
	 * Remove log rows older than a moment.
	 *
	 * @param string $before UTC date-time.
	 */
	public function purgeBefore( string $before ): void {
		$this->db()->query( $this->db()->prepare( "DELETE FROM {$this->table()} WHERE exported_at < %s", $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed table name; retention task.
	}
}
