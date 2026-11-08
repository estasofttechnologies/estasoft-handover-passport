<?php
/**
 * Maps plugin folders to third-party services (SCN-5, SVC-2, BKP-2).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Suggests Services and Backups records from detected plugins.
 */
class ServiceMap {

	/**
	 * The map, filterable.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function map(): array {
		$file = ESTASOFT_HP_DIR . 'data/service-map.php';
		$map  = is_readable( $file ) ? (array) include $file : array();
		$map  = apply_filters( 'estasoft_hp_service_map', $map );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Suggestions for the active plugins in a scan.
	 *
	 * @param array<string, array<string, mixed>> $plugins Plugins from the scan facts, keyed by slug.
	 * @return list<array{slug: string, kind: string, service: string, plugin: string}>
	 */
	public function suggest( array $plugins ): array {
		$out = array();
		foreach ( $this->map() as $slug => $entry ) {
			if ( isset( $plugins[ $slug ] ) && ! empty( $plugins[ $slug ]['active'] ) && is_array( $entry ) && count( $entry ) >= 2 ) {
				$out[] = array(
					'slug'    => (string) $slug,
					'kind'    => (string) $entry[0],
					'service' => (string) $entry[1],
					'plugin'  => (string) ( $plugins[ $slug ]['name'] ?? $slug ),
				);
			}
		}
		return $out;
	}
}
