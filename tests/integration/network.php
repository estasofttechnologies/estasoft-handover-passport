<?php
/**
 * Integration test for what a single site can show of the network handling (WPI-5). Run on a WordPress test site:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/network.php
 *
 * A real network is not needed for these checks: they cover the first-time setup that a site gets from its first admin
 * visit, the network note staying silent on a single site, and the clean-up of scheduled tasks.
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Admin\NetworkNotice;
use Estasoft\HandoverPassport\Install\Deactivator;
use Estasoft\HandoverPassport\Install\Installer;
use Estasoft\HandoverPassport\Install\Schedule;
use Estasoft\HandoverPassport\Install\Upgrader;

$failures = 0;
$checks   = 0;
$check    = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures, &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? '  [' . $detail . ']' : '' ) . "\n";
};

$had_version = get_option( Installer::DB_VERSION_OPTION, null );
$had_scan    = wp_next_scheduled( Schedule::EVENT_SCAN );

$check( 'a single site never waits for an admin visit', false === Upgrader::waitsForAdminVisit() );
$check( 'the network note says what it must', false !== strpos( NetworkNotice::text(), 'separate Passport for each site' ) && false !== strpos( NetworkNotice::text(), 'not part of this version' ) );
ob_start();
( new NetworkNotice() )->show();
$printed = ob_get_clean();
$check( 'the network note stays silent on a single site', '' === $printed, $printed );

// A site that was never set up is set up by the upgrade routine, baseline scan included.
wp_clear_scheduled_hook( Schedule::EVENT_SCAN );
delete_option( Installer::DB_VERSION_OPTION );
Upgrader::maybeUpgrade();
$check( 'the first admin visit sets the site up', (int) get_option( Installer::DB_VERSION_OPTION, 0 ) >= 1 );
$check( 'and queues the baseline scan', false !== wp_next_scheduled( Schedule::EVENT_SCAN ) );
$check( 'the daily tasks are scheduled', false !== wp_next_scheduled( Schedule::REMINDERS ) );
Upgrader::maybeUpgrade();
$check( 'a second call changes nothing', false !== wp_next_scheduled( Schedule::EVENT_SCAN ) && (int) get_option( Installer::DB_VERSION_OPTION, 0 ) >= 1 );

// Deactivation clears the tasks, also when it is told "network wide" on a single site.
Deactivator::deactivate( true );
$check( 'deactivation clears the scheduled tasks', false === wp_next_scheduled( Schedule::REMINDERS ) && false === wp_next_scheduled( Schedule::EVENT_SCAN ) );

// Put the site back.
Schedule::register();
if ( false !== $had_scan ) {
	Schedule::queueScan( 60 );
} else {
	wp_clear_scheduled_hook( Schedule::EVENT_SCAN );
}
if ( null === $had_version ) {
	delete_option( Installer::DB_VERSION_OPTION );
} else {
	update_option( Installer::DB_VERSION_OPTION, $had_version, true );
}
$check( 'the site is back as it was', false !== wp_next_scheduled( Schedule::REMINDERS ) && (string) get_option( Installer::DB_VERSION_OPTION, '' ) === (string) ( $had_version ?? '' ) );

echo "\n{$checks} checks, {$failures} failed.\n";
if ( $failures > 0 ) {
	exit( 1 );
}
