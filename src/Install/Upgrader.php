<?php
/**
 * Schema upgrades in small idempotent steps under a lock (WPI-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Install;

use Estasoft\HandoverPassport\Data\Schema;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the stored schema version with the shipped one and applies steps.
 */
final class Upgrader {

	public const LOCK_OPTION   = 'estasoft_hp_upgrade_lock';
	public const ERROR_OPTION  = 'estasoft_hp_upgrade_error';
	private const LOCK_SECONDS = 120;

	/**
	 * Whether this site still waits for somebody to open its dashboard before the Passport is set up (WPI-5).
	 *
	 * Network activation does not install anything. A site gets its own tables, settings and scheduled tasks on its first
	 * visit to the admin area, and nothing runs on the front end, in the REST API or in cron before that.
	 */
	public static function waitsForAdminVisit(): bool {
		return self::mustWait( (int) get_option( Installer::DB_VERSION_OPTION, 0 ), is_multisite(), is_admin(), defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * The rule behind waitsForAdminVisit(), apart from the request.
	 *
	 * @param int  $stored    Stored schema version, 0 when the site was never set up.
	 * @param bool $multisite Whether this is a network.
	 * @param bool $admin     Whether the request is for the admin area.
	 * @param bool $cli       Whether the request is a WP-CLI command, which stands for a person with access.
	 */
	public static function mustWait( int $stored, bool $multisite, bool $admin, bool $cli ): bool {
		return 0 === $stored && $multisite && ! $admin && ! $cli;
	}

	/**
	 * Install or upgrade this site when its stored version is behind.
	 */
	public static function maybeUpgrade(): void {
		$stored = (int) get_option( Installer::DB_VERSION_OPTION, 0 );
		if ( $stored >= Schema::VERSION ) {
			return;
		}
		if ( ! self::acquireLock() ) {
			return;
		}
		try {
			if ( 0 === $stored ) {
				Installer::installSite();
				Schedule::queueScan( 5 ); // A site set up on its first admin visit gets its baseline scan too (SCN-7).
			} else {
				foreach ( self::steps() as $version => $step ) {
					if ( $version > $stored && $version <= Schema::VERSION ) {
						$step();
						update_option( Installer::DB_VERSION_OPTION, $version, true );
					}
				}
				Installer::installSite();
			}
			delete_option( self::ERROR_OPTION );
		} catch ( Throwable $e ) {
			// Existing data stays untouched; a short code is shown, not the message (SEC-7).
			update_option( self::ERROR_OPTION, 'upgrade_' . ( $stored + 1 ), false );
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Upgrade steps keyed by the schema version they produce. Version 1 needs none.
	 *
	 * @return array<int, callable(): void>
	 */
	private static function steps(): array {
		return array();
	}

	/**
	 * Take the lock with an atomic add so two requests cannot run the upgrade twice.
	 */
	private static function acquireLock(): bool {
		$now = time();
		if ( add_option( self::LOCK_OPTION, $now, '', false ) ) {
			return true;
		}
		$held = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $held > 0 && ( $now - $held ) > self::LOCK_SECONDS ) {
			delete_option( self::LOCK_OPTION );
			return add_option( self::LOCK_OPTION, $now, '', false );
		}
		return false;
	}
}
