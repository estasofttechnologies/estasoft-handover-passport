<?php
/**
 * Runbooks and their immutable versions (RUN-1, RUN-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Data;

use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Access to the runbooks and runbook_versions tables.
 */
class RunbookRepository extends Repository {

	protected function key(): string {
		return 'runbooks';
	}

	protected function columns(): array {
		return array( 'title', 'purpose', 'when_to_use', 'owner_role', 'estimated_minutes', 'current_version', 'status', 'template_key', 'restore_scope', 'critical', 'review_days', 'last_reviewed', 'last_rehearsed', 'created_at', 'updated_at', 'deleted_at' );
	}

	/**
	 * Live runbooks sorted by title.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function allLive(): array {
		return $this->query( array( 'deleted_at' => null ), 'title', 'ASC' );
	}

	/**
	 * Mark deleted.
	 *
	 * @param int $id Runbook ID.
	 */
	public function softDelete( int $id ): bool {
		return $this->update( $id, array( 'deleted_at' => Clock::sql() ) );
	}

	/**
	 * Bring back a deleted runbook.
	 *
	 * @param int $id Runbook ID.
	 */
	public function restore( int $id ): bool {
		return $this->update( $id, array( 'deleted_at' => null ) );
	}

	/**
	 * A stored version of a runbook.
	 *
	 * @param int $runbook_id Runbook ID.
	 * @param int $version    Version number.
	 * @return array<string, mixed>|null
	 */
	public function version( int $runbook_id, int $version ): ?array {
		$rows = $this->versionRows( "runbook_id = %d AND version = %d", array( $runbook_id, $version ) );
		return $rows[0] ?? null;
	}

	/**
	 * Every version of a runbook, newest first.
	 *
	 * @param int $runbook_id Runbook ID.
	 * @return list<array<string, mixed>>
	 */
	public function versions( int $runbook_id ): array {
		return $this->versionRows( 'runbook_id = %d', array( $runbook_id ), 'version DESC' );
	}

	/**
	 * Add a new immutable version.
	 *
	 * @param array<string, mixed> $row Version values.
	 */
	public function addVersion( array $row ): int {
		$allowed = array( 'runbook_id', 'version', 'steps', 'prerequisites', 'verification', 'rollback_text', 'change_note', 'created_at', 'created_by' );
		$row     = array_intersect_key( $row, array_flip( $allowed ) );
		foreach ( array( 'steps', 'prerequisites', 'verification' ) as $json ) {
			if ( isset( $row[ $json ] ) && ! is_string( $row[ $json ] ) ) {
				$row[ $json ] = \Estasoft\HandoverPassport\Support\Json::encode( $row[ $json ] );
			}
		}
		if ( false === $this->db()->insert( Tables::name( 'runbook_versions' ), $row ) ) {
			return 0;
		}
		return (int) $this->db()->insert_id;
	}

	/**
	 * Replace the content of a version in place (a draft being edited).
	 *
	 * @param int                  $runbook_id Runbook ID.
	 * @param int                  $version    Version number.
	 * @param array<string, mixed> $row        New content columns.
	 */
	public function updateVersion( int $runbook_id, int $version, array $row ): bool {
		$allowed = array( 'steps', 'prerequisites', 'verification', 'rollback_text', 'change_note' );
		$row     = array_intersect_key( $row, array_flip( $allowed ) );
		foreach ( array( 'steps', 'prerequisites', 'verification' ) as $json ) {
			if ( isset( $row[ $json ] ) && ! is_string( $row[ $json ] ) ) {
				$row[ $json ] = \Estasoft\HandoverPassport\Support\Json::encode( $row[ $json ] );
			}
		}
		return false !== $this->db()->update( Tables::name( 'runbook_versions' ), $row, array( 'runbook_id' => $runbook_id, 'version' => $version ) );
	}

	/**
	 * Remove a runbook and its versions for good.
	 *
	 * @param int $id Runbook ID.
	 */
	public function purge( int $id ): void {
		$this->db()->delete( Tables::name( 'runbook_versions' ), array( 'runbook_id' => $id ), array( '%d' ) );
		$this->delete( $id );
	}

	/**
	 * Select version rows.
	 *
	 * @param string      $where  Fragment with placeholders.
	 * @param list<mixed> $params Values.
	 * @param string      $order  Fixed ORDER BY text.
	 * @return list<array<string, mixed>>
	 */
	private function versionRows( string $where, array $params, string $order = 'version DESC' ): array {
		$table = Tables::name( 'runbook_versions' );
		$sql   = $this->db()->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY {$order}", ...$params ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Fixed table and order text; placeholders in the fragment.
		$rows  = $this->db()->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out   = array();
		foreach ( (array) $rows as $row ) {
			foreach ( array( 'steps', 'prerequisites', 'verification' ) as $json ) {
				$row[ $json ] = \Estasoft\HandoverPassport\Support\Json::decode( $row[ $json ] ?? '' );
			}
			$out[] = $row;
		}
		return $out;
	}
}
