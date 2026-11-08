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
use Estasoft\HandoverPassport\Security\CopyGuard;
use Estasoft\HandoverPassport\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Safe to run more than once with the same result (DAT-1).
 */
final class Installer {

	public const DB_VERSION_OPTION = 'estasoft_hp_db_version';

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
		( new CopyGuard() )->check(); // Remember the host this Passport was set up on (IMP-4).

		Schedule::register();
		update_option( self::DB_VERSION_OPTION, Schema::VERSION, true );
	}
}
