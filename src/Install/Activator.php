<?php
/**
 * Activation.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on activation. Network activation installs lazily on each site's first admin visit (WPI-5).
 */
final class Activator {

	public const REDIRECT_TRANSIENT = 'estasoft_hp_welcome';

	/**
	 * Activation callback.
	 *
	 * @param bool $network_wide Whether activated for the whole network.
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			return;
		}
		Installer::installSite();
		Schedule::queueScan( 5 ); // Baseline scan, so the first screen has facts (SCN-7).

		// ONB-2: one welcome redirect for a single-site activation by a person in a browser.
		$bulk = isset( $_GET['activate-multi'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of a core flag.
		if ( ! $bulk && ! ( defined( 'WP_CLI' ) && WP_CLI ) && current_user_can( 'activate_plugins' ) ) {
			set_transient( self::REDIRECT_TRANSIENT, '1', 60 );
		}
	}
}
