<?php
/**
 * A note in the network admin: every site keeps its own Passport (WPI-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Shown to network administrators on the network Plugins and Dashboard screens while the plugin is active for the whole
 * network, so nobody looks in the network admin for a Passport that is not there.
 */
final class NetworkNotice {

	/**
	 * Screens that carry the note.
	 *
	 * @var array<int, string>
	 */
	private const SCREENS = array( 'plugins-network', 'dashboard-network' );

	/**
	 * Print the note when it applies.
	 */
	public function show(): void {
		if ( ! is_multisite() || ! current_user_can( 'manage_network_plugins' ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( null === $screen || ! in_array( $screen->id, self::SCREENS, true ) || ! is_plugin_active_for_network( ESTASOFT_HP_BASENAME ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html( self::text() ) . '</p></div>';
	}

	/**
	 * The words of the note.
	 */
	public static function text(): string {
		return __( 'Estasoft Handover Passport keeps a separate Passport for each site. A site gets its own the first time someone opens its dashboard. A view across the whole network is not part of this version.', 'estasoft-handover-passport' );
	}
}
