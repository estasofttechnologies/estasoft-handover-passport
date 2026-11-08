<?php
/**
 * Integration test for uninstall (PRV-8, AS-13). Run on a WordPress test site:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/uninstall.php
 *
 * The first part only reads. The second part runs uninstall.php for real, which drops the plugin's tables, so it runs only
 * on a throw-away copy of a test site, and only when this is set:
 *
 *     ESTASOFT_HP_TEST_UNINSTALL=1 wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/uninstall.php
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Data\Tables;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Support\TempFiles;

$failures = 0;
$checks   = 0;
$check    = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures, &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? '  [' . $detail . ']' : '' ) . "\n";
};

$file = ESTASOFT_HP_DIR . 'uninstall.php';
$text = (string) file_get_contents( $file );

// ---- the file, read-only -----------------------------------------------------------------------------------------------------------
$check( 'uninstall.php exits when it is not run by WordPress', 1 === preg_match( '/defined\(\s*\'WP_UNINSTALL_PLUGIN\'\s*\)\s*\)\s*\{\s*exit;/', $text ) );
preg_match( '/\$tables\s*=\s*array\(([^)]*)\)/', $text, $m );
preg_match_all( "/'([a-z_]+)'/", $m[1] ?? '', $found );
$listed = $found[1] ?? array();
$real   = array_keys( Tables::all() );
sort( $listed );
sort( $real );
$check( 'it drops exactly the tables the plugin creates', $listed === $real, implode( ',', array_diff( $real, $listed ) ) . ' | ' . implode( ',', array_diff( $listed, $real ) ) );
$check( 'it keeps data unless the setting is on', 1 === preg_match( '/empty\(\s*\$settings\[\s*\'delete_on_uninstall\'\s*\]\s*\)\s*\)\s*\{\s*return;/', $text ) );
$names = array();
preg_match_all( "/'(estasoft_hp_[a-z_]+)'/", $text, $in_file );
$src = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ESTASOFT_HP_DIR . 'src', FilesystemIterator::SKIP_DOTS ) ) as $f ) {
	if ( 'php' === $f->getExtension() ) {
		$src .= (string) file_get_contents( $f->getPathname() );
	}
}
$src .= (string) file_get_contents( ESTASOFT_HP_DIR . 'estasoft-handover-passport.php' );
$unused = array();
foreach ( array_unique( $in_file[1] ) as $name ) {
	if ( false === strpos( $src, "'" . $name . "'" ) ) {
		$unused[] = $name;
	}
}
$check( 'every name it deletes is one the plugin really uses', array() === $unused, implode( ',', $unused ) );
preg_match_all( "/const [A-Z_]+\s*=\s*'(estasoft_hp_[a-z_]+)'/", $src, $consts );
$used_options = array_filter(
	array_unique( $consts[1] ),
	static fn( string $name ): bool => 1 === preg_match( '/_(?:option|lock|meta)$|^estasoft_hp_(?:settings|passport|site_host|db_version|update_seen|copy_guard|snapshot_check|findings_meta|digest_last|reminder_health|upgrade_error|upgrade_lock|scan_lock)$/', $name )
);
$missed = array();
foreach ( $used_options as $name ) {
	if ( false === strpos( $text, "'" . $name . "'" ) ) {
		$missed[] = $name;
	}
}
$check( 'every option the plugin keeps is named in the uninstall list', array() === $missed, implode( ',', $missed ) );

if ( '1' !== (string) getenv( 'ESTASOFT_HP_TEST_UNINSTALL' ) ) {
	echo "\nThe real uninstall run was skipped. Set ESTASOFT_HP_TEST_UNINSTALL=1 on a throw-away copy of a test site to run it.\n";
	echo "\n" . ( $checks - $failures ) . ' of ' . $checks . " checks passed\n";
	exit( $failures > 0 ? 1 : 0 );
}

// ---- the real run (destructive) ---------------------------------------------------------------------------------------------------
global $wpdb;
$plugin = Plugin::instance();
$plugin->reset();
wp_set_current_user( 1 );

$prefix = $wpdb->prefix . 'estasoft_hp_';
$tables = static function () use ( $wpdb, $prefix ): array {
	$out = array();
	foreach ( (array) $wpdb->get_col( 'SHOW TABLES' ) as $name ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 0 === strpos( (string) $name, $prefix ) ) {
			$out[] = (string) $name;
		}
	}
	return $out;
};
$leftovers = static function () use ( $wpdb ): int {
	$like = $wpdb->esc_like( 'estasoft_hp_' ) . '%';
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $like, '\_transient\_' . $like, '\_transient\_timeout\_' . $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
};

// The older SQLite driver accepts DROP TABLE and does nothing. On such a site, run that one statement on a second
// connection so the test still sees the table go. The current SQLite driver, MySQL and MariaDB run it as it is.
$drops = array();
add_filter(
	'query',
	static function ( $sql ) use ( &$drops ) {
		if ( 1 === preg_match( '/^\s*DROP TABLE IF EXISTS\s+`?([A-Za-z0-9_]+)`?\s*$/i', (string) $sql, $hit ) ) {
			$drops[] = $hit[1];
			if ( defined( 'FQDB' ) && ! class_exists( 'WP_MySQL_On_SQLite' ) ) {
				( new PDO( 'sqlite:' . FQDB ) )->exec( 'DROP TABLE IF EXISTS "' . $hit[1] . '"' );
				return 'SELECT 1';
			}
		}
		return $sql;
	}
);

// Data of every kind that uninstall has to find.
$plugin->scanService()->run( 'cli' );
$plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'unitest.example', 'expires_on' => '2027-03-01' ) );
$plugin->people()->create( array( 'name' => 'Unitest Una', 'email' => 'unitest@example.org', 'roles' => array( 'site_owner' ) ) );
$plugin->snapshots()->create( \Estasoft\HandoverPassport\Export\Profile::CLIENT, 'UNITEST snapshot', '', new \Estasoft\HandoverPassport\Export\ExportOptions( false, array(), 1 ) );
set_transient( 'estasoft_hp_said_1', array( 'text' => 'x', 'error' => false ), 300 );
set_transient( 'estasoft_hp_form_1', array( 'scope' => 'x' ), 300 );
set_transient( 'estasoft_hp_chk_0123456789abcdef0123', time() + 60, 60 );
update_option( 'estasoft_hp_unlisted_future_option', 'x', false );
update_user_meta( 1, 'estasoft_hp_dismissed', array( 'welcome' ) );
$tmp = TempFiles::createDir();
file_put_contents( $tmp . '/work.txt', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$other_option = 'unrelated_plugin_option';
update_option( $other_option, 'keep me', false );
set_transient( 'unrelated_transient', 'keep me', 300 );
update_user_meta( 1, 'unrelated_meta', 'keep me' );
wp_schedule_event( time() + 3600, 'daily', 'estasoft_hp_daily_scan' );
$rows_before = array();
foreach ( array_keys( Tables::all() ) as $key ) {
	$rows_before[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}{$key}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
}
$check( 'before: the tables exist and hold rows', count( $tables() ) === count( Tables::all() ) && $rows_before['items'] > 0 && $rows_before['people'] > 0 && $rows_before['snapshots'] > 0 && $rows_before['scans'] > 0, json_encode( $rows_before ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
$check( 'before: the capabilities are on the administrator role', get_role( 'administrator' )->has_cap( 'estasoft_hp_view' ) );

// Setting off: nothing is touched.
$settings                        = (array) get_option( 'estasoft_hp_settings', array() );
$settings['delete_on_uninstall'] = false;
update_option( 'estasoft_hp_settings', $settings, false );
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', ESTASOFT_HP_BASENAME );
}
require $file;
$after_off = array();
foreach ( array_keys( Tables::all() ) as $key ) {
	$after_off[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}{$key}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
}
$check( 'setting off: every table and every row is still there', $after_off === $rows_before, json_encode( $after_off ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
$check( 'setting off: the options, the user meta and the folder are still there', false !== get_option( 'estasoft_hp_passport', false ) && array( 'welcome' ) === get_user_meta( 1, 'estasoft_hp_dismissed', true ) && is_dir( $tmp ) );
$check( 'setting off: the capabilities and the schedule are still there', get_role( 'administrator' )->has_cap( 'estasoft_hp_view' ) && false !== wp_next_scheduled( 'estasoft_hp_daily_scan' ) );

// Setting on: everything of ours goes, nothing else does. When the plugin is deleted from the Plugins screen it is not
// loaded, so none of its work is queued for the end of the request; this test runs with it loaded, so drop that queue.
remove_all_actions( 'shutdown' );
$settings['delete_on_uninstall'] = true;
update_option( 'estasoft_hp_settings', $settings, false );
estasoft_hp_uninstall_site();
$expected = array_map( static fn( string $key ): string => $prefix . $key, array_keys( Tables::all() ) );
sort( $expected );
sort( $drops );
$check( 'setting on: it asked to drop exactly the plugin\'s tables, once each', $expected === $drops, implode( ',', $drops ) );
$check( 'setting on: every table is gone', array() === $tables(), implode( ',', $tables() ) );
$check( 'setting on: no option or transient with our prefix is left, even one nobody listed', 0 === $leftovers(), (string) $leftovers() );
$check( 'setting on: the dismissed notices are gone', '' === get_user_meta( 1, 'estasoft_hp_dismissed', true ) );
$check( 'setting on: the working folder is gone', ! is_dir( $tmp ) && ! is_dir( dirname( $tmp ) ) );
$check( 'setting on: the schedule is cleared', false === wp_next_scheduled( 'estasoft_hp_daily_scan' ) );
wp_roles()->for_site();
$caps_left = array();
foreach ( wp_roles()->role_objects as $role ) {
	foreach ( array( 'estasoft_hp_view', 'estasoft_hp_view_sensitive', 'estasoft_hp_edit', 'estasoft_hp_export', 'estasoft_hp_configure' ) as $cap ) {
		if ( isset( $role->capabilities[ $cap ] ) ) {
			$caps_left[] = $role->name . ':' . $cap;
		}
	}
}
$check( 'setting on: the capabilities are removed from every role', array() === $caps_left, implode( ',', $caps_left ) );
$check( 'another plugin\'s option, transient and user meta are untouched', 'keep me' === get_option( $other_option ) && 'keep me' === get_transient( 'unrelated_transient' ) && 'keep me' === get_user_meta( 1, 'unrelated_meta', true ) );
estasoft_hp_uninstall_site();
$check( 'running it a second time finds nothing and does no harm', array() === $tables() && 0 === $leftovers() && 'keep me' === get_option( $other_option ) );

echo "\n" . ( $checks - $failures ) . ' of ' . $checks . " checks passed\n";
if ( $failures > 0 ) {
	exit( 1 );
}
