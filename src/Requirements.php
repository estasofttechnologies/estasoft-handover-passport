<?php
/**
 * Minimum requirements guard (ONB-1).
 *
 * This file is loaded before the autoloader and must avoid syntax newer than PHP 7.0.
 *
 * @package Estasoft\HandoverPassport
 */

namespace Estasoft\HandoverPassport;

defined( 'ABSPATH' ) || exit;

/**
 * Checks PHP, WordPress and extension requirements.
 */
final class Requirements {

	/**
	 * Return a plain sentence naming the first missing requirement, or null when all are met.
	 *
	 * @return string|null
	 */
	public static function problem() {
		if ( version_compare( PHP_VERSION, ESTASOFT_HP_MIN_PHP, '<' ) ) {
			return sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'It needs PHP %1$s or newer. This site runs PHP %2$s.', 'estasoft-handover-passport' ),
				ESTASOFT_HP_MIN_PHP,
				PHP_VERSION
			);
		}

		global $wp_version;
		if ( version_compare( (string) $wp_version, ESTASOFT_HP_MIN_WP, '<' ) ) {
			return sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version. */
				__( 'It needs WordPress %1$s or newer. This site runs WordPress %2$s.', 'estasoft-handover-passport' ),
				ESTASOFT_HP_MIN_WP,
				(string) $wp_version
			);
		}

		foreach ( array( 'json', 'mbstring' ) as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				return sprintf(
					/* translators: %s: PHP extension name. */
					__( 'It needs the PHP extension %s, which is not loaded.', 'estasoft-handover-passport' ),
					$extension
				);
			}
		}

		return null;
	}
}
