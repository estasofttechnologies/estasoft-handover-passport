<?php
/**
 * Compares two scans (SCN-7). Pure logic, no WordPress calls.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the difference between two fact sets into change-log entries.
 */
final class Differ {

	/**
	 * List the differences. An empty list means nothing changed.
	 *
	 * Each entry has: kind, subject_type, subject_id (always 0), label, before, after.
	 *
	 * @param array<string, mixed> $older Previous facts.
	 * @param array<string, mixed> $newer New facts.
	 * @return array<int, array{kind: string, subject_type: string, label: string, before: array<string, mixed>, after: array<string, mixed>}>
	 */
	public static function diff( array $older, array $newer ): array {
		$out = array();
		self::core( $older, $newer, $out );
		self::extensions( 'plugin', (array) ( $older['plugins'] ?? array() ), (array) ( $newer['plugins'] ?? array() ), $out );
		self::themes( (array) ( $older['themes'] ?? array() ), (array) ( $newer['themes'] ?? array() ), $out );
		self::admins( $older, $newer, $out );
		return $out;
	}

	/**
	 * Core, PHP and address changes.
	 *
	 * @param array<string, mixed>             $older Old facts.
	 * @param array<string, mixed>             $newer New facts.
	 * @param array<int, array<string, mixed>> $out Output list.
	 */
	private static function core( array $older, array $newer, array &$out ): void {
		$o      = (array) ( $older['core'] ?? array() );
		$n      = (array) ( $newer['core'] ?? array() );
		$checks = array(
			'wp_version'  => array( 'wordpress_version_changed', 'WordPress' ),
			'php_version' => array( 'php_version_changed', 'PHP' ),
			'site_url'    => array( 'site_address_changed', 'Site address' ),
			'home_url'    => array( 'site_address_changed', 'Home address' ),
		);
		foreach ( $checks as $key => $meta ) {
			if ( isset( $o[ $key ], $n[ $key ] ) && $o[ $key ] !== $n[ $key ] ) {
				$out[] = array(
					'kind'         => $meta[0],
					'subject_type' => 'core',
					'label'        => $meta[1],
					'before'       => array( 'value' => $o[ $key ] ),
					'after'        => array( 'value' => $n[ $key ] ),
				);
			}
		}
	}

	/**
	 * Plugin changes.
	 *
	 * @param string                           $type Subject type.
	 * @param array<string, mixed>             $older  Old list keyed by slug.
	 * @param array<string, mixed>             $newer  New list keyed by slug.
	 * @param array<int, array<string, mixed>> $out  Output list.
	 */
	private static function extensions( string $type, array $older, array $newer, array &$out ): void {
		foreach ( $newer as $slug => $item ) {
			$name = (string) ( $item['name'] ?? $slug );
			if ( ! isset( $older[ $slug ] ) ) {
				$out[] = self::entry( $type . '_added', $type, $name, array(), array( 'version' => $item['version'] ?? '', 'active' => ! empty( $item['active'] ) ) );
				continue;
			}
			$was = $older[ $slug ];
			if ( ( $was['version'] ?? '' ) !== ( $item['version'] ?? '' ) ) {
				$out[] = self::entry( $type . '_updated', $type, $name, array( 'version' => $was['version'] ?? '' ), array( 'version' => $item['version'] ?? '' ) );
			}
			if ( empty( $was['active'] ) && ! empty( $item['active'] ) ) {
				$out[] = self::entry( $type . '_activated', $type, $name, array( 'active' => false ), array( 'active' => true ) );
			} elseif ( ! empty( $was['active'] ) && empty( $item['active'] ) ) {
				$out[] = self::entry( $type . '_deactivated', $type, $name, array( 'active' => true ), array( 'active' => false ) );
			}
		}
		foreach ( $older as $slug => $item ) {
			if ( ! isset( $newer[ $slug ] ) ) {
				$out[] = self::entry( $type . '_removed', $type, (string) ( $item['name'] ?? $slug ), array( 'version' => $item['version'] ?? '' ), array() );
			}
		}
	}

	/**
	 * Theme changes, including a switch of the active theme.
	 *
	 * @param array<string, mixed>             $older Old themes.
	 * @param array<string, mixed>             $newer New themes.
	 * @param array<int, array<string, mixed>> $out Output list.
	 */
	private static function themes( array $older, array $newer, array &$out ): void {
		$active_old = self::activeSlug( $older );
		$active_new = self::activeSlug( $newer );
		foreach ( $newer as $slug => $item ) {
			$name = (string) ( $item['name'] ?? $slug );
			if ( isset( $older[ $slug ] ) && ( $older[ $slug ]['version'] ?? '' ) !== ( $item['version'] ?? '' ) ) {
				$out[] = self::entry( 'theme_updated', 'theme', $name, array( 'version' => $older[ $slug ]['version'] ?? '' ), array( 'version' => $item['version'] ?? '' ) );
			} elseif ( ! isset( $older[ $slug ] ) ) {
				$out[] = self::entry( 'theme_added', 'theme', $name, array(), array( 'version' => $item['version'] ?? '' ) );
			}
		}
		foreach ( $older as $slug => $item ) {
			if ( ! isset( $newer[ $slug ] ) ) {
				$out[] = self::entry( 'theme_removed', 'theme', (string) ( $item['name'] ?? $slug ), array(), array() );
			}
		}
		if ( '' !== $active_old && '' !== $active_new && $active_old !== $active_new ) {
			$out[] = self::entry( 'theme_switched', 'theme', (string) ( $newer[ $active_new ]['name'] ?? $active_new ), array( 'theme' => $active_old ), array( 'theme' => $active_new ) );
		}
	}

	/**
	 * New administrators and role changes of known ones.
	 *
	 * @param array<string, mixed>             $older Old facts.
	 * @param array<string, mixed>             $newer New facts.
	 * @param array<int, array<string, mixed>> $out Output list.
	 */
	private static function admins( array $older, array $newer, array &$out ): void {
		if ( ! isset( $older['users'] ) ) {
			return;
		}
		$known = array();
		foreach ( (array) ( $older['users']['admins'] ?? array() ) as $admin ) {
			$known[ (int) ( $admin['id'] ?? 0 ) ] = true;
		}
		foreach ( (array) ( $newer['users']['admins'] ?? array() ) as $admin ) {
			if ( ! isset( $known[ (int) ( $admin['id'] ?? 0 ) ] ) ) {
				$out[] = self::entry( 'administrator_added', 'user', (string) ( $admin['name'] ?? '' ), array(), array() );
			}
		}
	}

	/**
	 * Slug of the active theme.
	 *
	 * @param array<string, mixed> $themes Themes keyed by slug.
	 */
	private static function activeSlug( array $themes ): string {
		foreach ( $themes as $slug => $theme ) {
			if ( ! empty( $theme['active'] ) ) {
				return (string) $slug;
			}
		}
		return '';
	}

	/**
	 * Build one entry.
	 *
	 * @param string               $kind   Kind.
	 * @param string               $type   Subject type.
	 * @param string               $label  Label.
	 * @param array<string, mixed> $before Before.
	 * @param array<string, mixed> $after  After.
	 * @return array{kind: string, subject_type: string, label: string, before: array<string, mixed>, after: array<string, mixed>}
	 */
	private static function entry( string $kind, string $type, string $label, array $before, array $after ): array {
		return array(
			'kind'         => $kind,
			'subject_type' => $type,
			'label'        => $label,
			'before'       => $before,
			'after'        => $after,
		);
	}
}
