<?php
/**
 * Integration smoke test. Run on a WordPress site with the plugin active:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/smoke.php
 *
 * It checks the pieces that need a real WordPress and database: tables, capabilities, schedule,
 * a scan, the change log and the REST permission matrix. It writes to the site, so use a test site.
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Data\Tables;
use Estasoft\HandoverPassport\Install\Schedule;
use Estasoft\HandoverPassport\Security\Capabilities;

$failures = 0;
$checks   = 0;

$check = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures, &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? '  [' . $detail . ']' : '' ) . "\n";
};

global $wpdb;
$plugin = Plugin::instance();

// Tables (DAT-1).
foreach ( Tables::all() as $key => $name ) {
	$check( 'table exists: ' . $key, $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) || (bool) $wpdb->get_var( "SELECT name FROM sqlite_master WHERE type='table' AND name='" . esc_sql( $name ) . "'" ) );
}

// Capabilities (CAP-1).
$admin = get_role( 'administrator' );
foreach ( array_keys( Capabilities::all() ) as $cap ) {
	$check( 'administrator holds ' . $cap, $admin && $admin->has_cap( $cap ) );
}
$editor = get_role( 'editor' );
$check( 'editor holds no Passport capability', $editor && ! $editor->has_cap( Capabilities::VIEW ) );

// Schedule (WPI-3).
foreach ( array( Schedule::SCAN, Schedule::REMINDERS, Schedule::RETENTION ) as $hook ) {
	$check( 'event scheduled: ' . $hook, false !== wp_next_scheduled( $hook ) );
}

// Scan and change log (SCN-1, SCN-7, SCN-8).
$http_calls = 0;
add_filter(
	'pre_http_request',
	static function ( $pre ) use ( &$http_calls ) {
		++$http_calls;
		return $pre;
	}
);
wp_set_current_user( 1 );
$result = $plugin->scanService()->run( 'cli' );
$check( 'scan finishes', 'done' === $result['status'], wp_json_encode( $result ) );
$check( 'scan makes no outside request', 0 === $http_calls, (string) $http_calls );
$facts = $plugin->scanService()->latestFacts();
$check( 'facts hold the WordPress version', ! empty( $facts['core']['wp_version'] ) );
$check( 'facts never hold database credentials', false === strpos( wp_json_encode( $facts ), DB_PASSWORD ) || '' === DB_PASSWORD );
$check( 'detected domain record exists', null !== $plugin->records()->items()->bySubject( 'domain', strtolower( (string) wp_parse_url( get_option( 'siteurl' ), PHP_URL_HOST ) ) ) );

// A second scan right away finds nothing new (SCN-7).
$again = $plugin->scanService()->run( 'cli' );
$check( 'second scan finds no differences', 0 === $again['found'], wp_json_encode( $again ) );

// Redaction (CAP-4).
$redacted = Estasoft\HandoverPassport\Scan\ScanService::redact( $facts );
$check( 'redaction removes sensitive facts', ! isset( $redacted['sensitive'] ) );

// REST permission matrix (API-1, API-2).
$route = '/estasoft-passport/v1/scans/latest';
wp_set_current_user( 0 );
$status = rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status();
$check( 'anonymous is refused', in_array( $status, array( 401, 403 ), true ), (string) $status );

$subscriber = wp_insert_user( array( 'user_login' => 'hp_smoke_sub_' . wp_generate_password( 4, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( (int) $subscriber );
$status = rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status();
$check( 'subscriber is refused', 403 === $status, (string) $status );
$status = rest_do_request( new WP_REST_Request( 'POST', '/estasoft-passport/v1/scans' ) )->get_status();
$check( 'subscriber cannot start a scan', 403 === $status, (string) $status );

wp_set_current_user( 1 );
$status = rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status();
$check( 'administrator can read', 200 === $status, (string) $status );
wp_delete_user( (int) $subscriber );

echo "\n" . $checks . ' checks, ' . $failures . " failed\n";
exit( $failures > 0 ? 1 : 0 );
