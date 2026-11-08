<?php
/**
 * Table names.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Data;

defined( 'ABSPATH' ) || exit;

/**
 * The twelve custom tables (spec section 14.1).
 */
final class Tables {

	public const KEYS = array(
		'scans',
		'items',
		'people',
		'links',
		'runbooks',
		'runbook_versions',
		'tests',
		'changes',
		'findings',
		'reminders',
		'snapshots',
		'exports',
	);

	/**
	 * Full table name including the WordPress prefix.
	 *
	 * @param string $key One of self::KEYS.
	 */
	public static function name( string $key ): string {
		global $wpdb;
		return $wpdb->prefix . 'estasoft_hp_' . $key;
	}

	/**
	 * Every full table name, keyed by short key.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		$names = array();
		foreach ( self::KEYS as $key ) {
			$names[ $key ] = self::name( $key );
		}
		return $names;
	}
}
