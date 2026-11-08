<?php
/**
 * Findings produced by the rules (HLT-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Access to the findings table.
 */
class FindingRepository extends Repository {

	public const OPEN     = 'open';
	public const SNOOZED  = 'snoozed';
	public const ACCEPTED = 'accepted';
	public const RESOLVED = 'resolved';

	/**
	 * {@inheritDoc}
	 */
	protected function key(): string {
		return 'findings';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function jsonColumns(): array {
		return array( 'message_values' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function columns(): array {
		return array( 'rule_id', 'subject_type', 'subject_id', 'subject_key', 'severity', 'state', 'message_values', 'first_seen', 'last_seen', 'snoozed_until', 'accepted_reason', 'accepted_by', 'resolved_at' );
	}

	/**
	 * Findings that are not resolved.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function active(): array {
		return $this->query( array(), 'severity', 'ASC', 0, 0, 'state <> %s', array( self::RESOLVED ) );
	}

	/**
	 * Findings in a state.
	 *
	 * @param string $state One of the constants.
	 * @return array<int, array<string, mixed>>
	 */
	public function inState( string $state ): array {
		return $this->query( array( 'state' => $state ), 'last_seen', 'DESC' );
	}

	/**
	 * Remove resolved findings older than a moment (retention).
	 *
	 * @param string $before UTC date-time.
	 */
	public function purgeResolvedBefore( string $before ): void {
		$this->db()->query( $this->db()->prepare( "DELETE FROM {$this->table()} WHERE state = %s AND resolved_at < %s", self::RESOLVED, $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed table name; retention task.
	}
}
