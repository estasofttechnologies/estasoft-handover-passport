<?php
/**
 * Checks that stored snapshots still match their hash (HND-5, HP-HND-002).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Findings;

use Estasoft\HandoverPassport\Data\SnapshotRepository;
use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshots never change, so each one is checked once and again on the daily task. Results are kept in a small option.
 */
final class SnapshotIntegrity {

	public const OPTION = 'estasoft_hp_snapshot_check';

	/**
	 * Constructor.
	 *
	 * @param SnapshotRepository $snapshots Snapshots table.
	 */
	public function __construct( private SnapshotRepository $snapshots ) {}

	/**
	 * Whether stored content hashes to the stored value.
	 *
	 * @param string $content Stored canonical JSON text.
	 * @param string $hash    Stored hash.
	 */
	public static function matches( string $content, string $hash ): bool {
		return hash_equals( strtolower( $hash ), hash( 'sha256', $content ) );
	}

	/**
	 * Snapshots that do not match, using stored results for those already checked.
	 *
	 * @param bool $force Check every snapshot again (the daily task).
	 * @return array<int, array{id: int, name: string}>
	 */
	public function mismatches( bool $force = false ): array {
		$known  = $force ? array() : (array) get_option( self::OPTION, array() );
		$result = array();
		$out    = array();
		foreach ( $this->snapshots->listAll() as $row ) {
			$id = (int) $row['id'];
			if ( isset( $known[ $id ] ) && is_array( $known[ $id ] ) && isset( $known[ $id ]['ok'] ) ) {
				$result[ $id ] = $known[ $id ];
			} else {
				$full          = $this->snapshots->find( $id );
				$ok            = null !== $full && self::matches( (string) $full['content'], (string) $full['hash'] );
				$result[ $id ] = array( 'ok' => $ok, 'at' => Clock::sql() );
			}
			if ( ! $result[ $id ]['ok'] ) {
				$out[] = array( 'id' => $id, 'name' => (string) $row['name'] );
			}
		}
		if ( $result !== $known ) {
			update_option( self::OPTION, $result, false );
		}
		return $out;
	}
}
