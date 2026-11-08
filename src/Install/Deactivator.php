<?php
/**
 * Deactivation. Never deletes data.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Removes scheduled events only. On a network-wide deactivation it does so on every site.
 */
final class Deactivator {

	/**
	 * Deactivation callback.
	 *
	 * @param bool $network_wide Whether the plugin is deactivated for the whole network.
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				Schedule::clear();
				restore_current_blog();
			}
			return;
		}
		Schedule::clear();
	}
}
