<?php
/**
 * Per-site installation steps shared by activation and the upgrade routine.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Install;

use Estasoft\HandoverPassport\Data\Schema;
use Estasoft\HandoverPassport\Passport\Passport;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Safe to run more than once with the same result (DAT-1).
 */
final class Installer {

	public const DB_VERSION_OPTION = 'estasoft_hp_db_version';
	public const SITE_HOST_OPTION  = 'estasoft_hp_site_host';

	/**
	 * Install everything one site needs.
	 */
	public static function installSite(): void {
		Schema::install();
		Capabilities::grantDefaults();

		( new Passport() )->ensure();
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
		if ( false === get_option( self::SITE_HOST_OPTION, false ) ) {
			add_option( self::SITE_HOST_OPTION, self::currentHost(), '', false );
		}

		Schedule::register();
		update_option( self::DB_VERSION_OPTION, Schema::VERSION, true );
	}

	/**
	 * Host of the site address, lower case.
	 */
	public static function currentHost(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}
}
