<?php
/**
 * Plugin Name:       Estasoft Handover Passport
 * Plugin URI:        https://estasoft.com/
 * Description:       One living record of a site: stack, licences, domains, hosting, backups, restore steps and owners. Reminds the right person before anything expires and exports a client-ready handover pack.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            ESTASOFT TECHNOLOGIES
 * Author URI:        https://estasoft.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       estasoft-handover-passport
 * Domain Path:       /languages
 *
 * @package Estasoft\HandoverPassport
 */

// This file must stay parseable by old PHP versions so the requirement notice can show.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ESTASOFT_HP_VERSION', '1.0.0' );
define( 'ESTASOFT_HP_MIN_PHP', '8.1' );
define( 'ESTASOFT_HP_MIN_WP', '6.4' );
define( 'ESTASOFT_HP_FILE', __FILE__ );
define( 'ESTASOFT_HP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ESTASOFT_HP_URL', plugin_dir_url( __FILE__ ) );
define( 'ESTASOFT_HP_BASENAME', plugin_basename( __FILE__ ) );

require_once ESTASOFT_HP_DIR . 'src/Requirements.php';

$estasoft_hp_problem = \Estasoft\HandoverPassport\Requirements::problem();

if ( null !== $estasoft_hp_problem ) {
	// ONB-1: nothing else loads; an admin notice names the missing requirement.
	add_action(
		'admin_notices',
		static function () use ( $estasoft_hp_problem ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Estasoft Handover Passport is switched off.', 'estasoft-handover-passport' ),
				esc_html( $estasoft_hp_problem )
			);
		}
	);
	return;
}

require_once ESTASOFT_HP_DIR . 'src/Autoloader.php';

\Estasoft\HandoverPassport\Autoloader::register( 'Estasoft\\HandoverPassport\\', ESTASOFT_HP_DIR . 'src/' );

register_activation_hook( __FILE__, array( \Estasoft\HandoverPassport\Install\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Estasoft\HandoverPassport\Install\Deactivator::class, 'deactivate' ) );

\Estasoft\HandoverPassport\Plugin::instance()->boot();
