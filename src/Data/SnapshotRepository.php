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
 * Only the acknowledgements column can change: it grows, and the privacy eraser may blank the personal details in an entry.
 */
class SnapshotRepository extends Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function key(): string {
		return 'snapshots';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function jsonColumns(): array {
		return array( 'acknowledgements' );
	}

	/**
	 * {@inheritDoc}
	 */
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
	 * Only the acknowledgements can change. Content, hash, name and time never do (HND-3).
	 *
	 * @param int                  $id  Snapshot ID.
	 * @param array<string, mixed> $row Columns to change.
	 * @throws \LogicException When a column other than the acknowledgements is named.
	 */
	public function update( int $id, array $row ): bool {
		if ( array() !== array_diff( array_keys( $row ), array( 'acknowledgements' ) ) ) {
			throw new \LogicException( 'A snapshot cannot be changed.' );
		}
		return parent::update( $id, $row );
	}

	/**
	 * Snapshots, newest first, without the bulky content.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function listAll(): array {
		$rows = $this->rows( "SELECT id, name, profile_key, note, hash, schema_version, plugin_version, created_at, created_by, acknowledgements FROM {$this->table()} ORDER BY created_at DESC, id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name, no variables.
		return $rows;
	}

	/**
	 * How many snapshots contain any of the strings in their saved content (privacy tools).
	 *
	 * @param array<int, string> $needles Texts to look for, such as an email address or a name.
	 */
	public function countMentioning( array $needles ): int {
		$needles = array_values( array_filter( array_unique( $needles ), static fn( string $text ): bool => '' !== $text ) );
		if ( array() === $needles ) {
			return 0;
		}
		$parts  = array();
		$params = array();
		foreach ( $needles as $text ) {
			$parts[]  = 'content LIKE %s';
			$params[] = '%' . $this->db()->esc_like( $text ) . '%';
		}
		return $this->count( implode( ' OR ', $parts ), $params );
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
