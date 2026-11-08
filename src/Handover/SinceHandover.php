<?php
/**
 * How much of the Passport changed since the last acknowledged handover (HND-8).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Handover;

use Estasoft\HandoverPassport\Export\ExportOptions;
use Estasoft\HandoverPassport\Export\ExportRefused;
use Estasoft\HandoverPassport\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the newest acknowledged snapshot with the live Passport. Sensitive records are always left out, so the
 * number is the same for everybody who can see the Overview and tells nobody anything about a record they may not see.
 * Building the live Passport is not free, so the answer is kept until the change log gets a new entry or ten minutes
 * pass, whichever comes first.
 */
final class SinceHandover {

	public const TRANSIENT = 'estasoft_hp_since_handover';
	public const KEEP      = 600;

	/**
	 * Chapters that are worked out from the records, not entered. They change whenever the findings are checked again,
	 * so they are not counted as records that changed.
	 */
	private const DERIVED = array( 'passport', 'checklist', 'findings' );

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * The newest snapshot that somebody acknowledged, or null when none was.
	 *
	 * @return array{id: int, name: string, acknowledged_on: string}|null
	 */
	public function snapshot(): ?array {
		foreach ( $this->plugin->snapshots()->all() as $row ) {
			$acks = is_array( $row['acknowledgements'] ?? null ) ? $row['acknowledgements'] : array();
			if ( array() === $acks ) {
				continue;
			}
			$last = end( $acks );
			return array(
				'id'              => (int) $row['id'],
				'name'            => (string) $row['name'],
				'acknowledged_on' => is_array( $last ) ? (string) ( $last['date'] ?? '' ) : '',
			);
		}
		return null;
	}

	/**
	 * Counts of what is new, gone or different since that snapshot. Null when there is no acknowledged snapshot or the
	 * pack it was made with no longer exists.
	 *
	 * @return array{id: int, name: string, acknowledged_on: string, added: int, removed: int, changed: int, notes_changed: bool, total: int}|null
	 */
	public function get(): ?array {
		$snapshot = $this->snapshot();
		if ( null === $snapshot ) {
			return null;
		}
		$marker = $this->plugin->changeLog()->repo()->latestId();
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && (int) ( $cached['id'] ?? 0 ) === $snapshot['id'] && (int) ( $cached['marker'] ?? -1 ) === $marker ) {
			unset( $cached['marker'] );
			return $cached;
		}
		try {
			$diff = $this->plugin->snapshots()->compare( $snapshot['id'], 'live', new ExportOptions( false, array(), 0 ), true );
		} catch ( ExportRefused ) {
			return null;
		}
		if ( null === $diff ) {
			return null;
		}
		$count           = static fn( array $entries ): int => count( array_filter( $entries, static fn( array $entry ): bool => ! in_array( (string) ( $entry['chapter'] ?? '' ), self::DERIVED, true ) ) );
		$result          = $snapshot + array(
			'added'         => $count( $diff['added'] ),
			'removed'       => $count( $diff['removed'] ),
			'changed'       => $count( $diff['changed'] ),
			'notes_changed' => (bool) $diff['notes_changed'],
		);
		$result['total'] = $result['added'] + $result['removed'] + $result['changed'];
		set_transient( self::TRANSIENT, $result + array( 'marker' => $marker ), self::KEEP );
		return $result;
	}

	/**
	 * Forget the kept answer.
	 */
	public static function forget(): void {
		delete_transient( self::TRANSIENT );
	}
}
