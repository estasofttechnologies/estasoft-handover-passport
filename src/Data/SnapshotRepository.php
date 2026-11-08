<?php
/**
 * Handover snapshots (HND-2, HND-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshots are immutable: this repository has no way to change stored content.
 * Only the append-only acknowledgements column can grow.
 */
class SnapshotRepository extends Repository {

	protected function key(): string {
		return 'snapshots';
	}

	protected function jsonColumns(): array {
		return array( 'acknowledgements' );
	}

	protected function columns(): array {
		return array( 'name', 'profile_key', 'note', 'content', 'hash', 'schema_version', 'plugin_version', 'created_at', 'created_by', 'acknowledgements' );
	}

	/**
	 * Create a snapshot. Content is stored as the exact canonical JSON text.
	 *
	 * @param array<string, mixed> $row Values including the canonical content text and its hash.
	 */
	public function create( array $row ): int {
		$row['acknowledgements'] = array();
		return $this->insert( $row );
	}

	/**
	 * Snapshots, newest first, without the bulky content.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listAll(): array {
		$rows = $this->rows( "SELECT id, name, profile_key, note, hash, schema_version, plugin_version, created_at, created_by, acknowledgements FROM {$this->table()} ORDER BY created_at DESC, id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, no variables.
		return $rows;
	}

	/**
	 * Add an acknowledgement. Existing ones are never changed.
	 *
	 * @param int                  $id  Snapshot ID.
	 * @param array<string, mixed> $ack Acknowledgement.
	 */
	public function appendAcknowledgement( int $id, array $ack ): bool {
		$row = $this->find( $id );
		if ( null === $row ) {
			return false;
		}
		$list   = is_array( $row['acknowledgements'] ) ? $row['acknowledgements'] : array();
		$list[] = $ack;
		return $this->update( $id, array( 'acknowledgements' => $list ) );
	}
}
