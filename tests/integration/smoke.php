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

// Registers (PRT-1 to PRT-9, PRT-12).
$records = $plugin->records();
$lic     = $records->create(
	'licence',
	array(
		'product'   => 'Smoke Forms Pro',
		'vendor'    => 'Acme',
		'renews_on' => '2020-01-01',
		'cost'      => '1,234.50',
		'currency'  => 'usd',
	)
);
$check( 'a licence can be created', ! empty( $lic['id'] ) && 'Smoke Forms Pro' === $lic['title'] );
$status_of = static fn( array $record ): string => Estasoft\HandoverPassport\Domain\LicenceStatus::compute( (array) $record['fields'], Estasoft\HandoverPassport\Support\Clock::today(), 30 );
$check( 'an old renewal date computes as expired', 'expired' === $status_of( $lic ), $status_of( $lic ) );
$check( 'money is stored in minor units', 123450 === (int) ( $lic['fields']['cost'] ?? 0 ), wp_json_encode( $lic['fields'] ?? array() ) );

$refused = false;
try {
	$records->create( 'licence', array( 'product' => 'Leaky', 'note' => 'password: Tr0ub4dor&3xyz' ) );
} catch ( Estasoft\HandoverPassport\Security\SecretFound $e ) {
	$refused = true;
}
$check( 'a secret-looking value is refused', $refused );
$check( 'a refused record is not stored', array() === array_filter( $records->listKind( 'licence', true ), static fn( $r ) => 'Leaky' === $r['title'] ) );

$bad_date = false;
try {
	$records->create( 'licence', array( 'product' => 'Bad date', 'renews_on' => '2026-02-30' ) );
} catch ( Estasoft\HandoverPassport\Domain\ValidationError $e ) {
	$bad_date = true;
}
$check( 'an impossible date is refused', $bad_date );

$records->update( (int) $lic['id'], array( 'renews_on' => '2035-01-01' ) );
$check( 'editing a date changes the computed status', 'active' === $status_of( $records->get( (int) $lic['id'], true ) ) );

$records->verify( (int) $lic['id'] );
$check( 'verify stamps the record', 'verified' === $records->get( (int) $lic['id'], true )['freshness'] );

$records->delete( (int) $lic['id'] );
$check( 'delete hides the record', null === $records->get( (int) $lic['id'], true ) );
$check( 'a deleted record waits in the Undo list', in_array( (int) $lic['id'], array_map( 'intval', array_column( $records->items()->deleted(), 'id' ) ), true ) );
$records->undelete( (int) $lic['id'] );
$check( 'undo restores the record', null !== $records->get( (int) $lic['id'], true ) );

$sensitive = $records->create( 'licence', array( 'product' => 'Smoke Secret Licence', 'sensitive' => '1' ) );
$check( 'a Sensitive record is hidden from a viewer without the capability', ! empty( $records->get( (int) $sensitive['id'], false )['hidden'] ) );

// Change log carries the actor and no secret text (CHG-1, CHG-2).
$kinds = array_column( $plugin->changeLog()->repo()->search( array(), 50, 0 ), 'kind' );
$check( 'record changes are logged', in_array( 'record_added', $kinds, true ) && in_array( 'record_edited', $kinds, true ) );

// Retention purges only what is past the window (PRV-8).
$plugin->settings()->update( array( 'retention' => array( 'deleted_days' => 1 ) ) );
$records->delete( (int) $sensitive['id'] );
global $wpdb;
$table = Tables::all()['items'];
$wpdb->update( $table, array( 'deleted_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ), array( 'id' => (int) $sensitive['id'] ) );
( new Estasoft\HandoverPassport\Services\Retention( $plugin->settings(), $records->items(), $plugin->people()->repo(), $records->links(), $plugin->changeLog()->repo() ) )->run();
$check( 'retention purges a record deleted before the window', null === $records->items()->find( (int) $sensitive['id'] ) );
$check( 'retention keeps live records', null !== $records->get( (int) $lic['id'], true ) );
$plugin->settings()->update( array( 'retention' => array( 'deleted_days' => 30 ) ) );

// People and roles (PPL-1 to PPL-3).
$people = $plugin->people();
$dana   = $people->create( array( 'name' => 'Smoke Dana', 'email' => 'dana@example.com', 'roles' => array( 'emergency_contact', 'site_owner' ) ) );
$sam    = $people->create( array( 'name' => 'Smoke Sam', 'roles' => array( 'emergency_contact' ) ) );
$mine = array( (int) $dana['id'], (int) $sam['id'] );
$only = static fn( array $ids ): array => array_values( array_intersect( $ids, $mine ) );
$check( 'people hold roles', $mine === $only( $people->inRole( 'emergency_contact' ) ), wp_json_encode( $people->inRole( 'emergency_contact' ) ) );
$others = array_values( array_diff( $people->inRole( 'emergency_contact' ), $mine ) );
$people->setRole( 'emergency_contact', array_merge( $others, array( (int) $sam['id'], (int) $dana['id'] ) ) );
$check( 'the emergency order can be changed', array( (int) $sam['id'], (int) $dana['id'] ) === $only( $people->inRole( 'emergency_contact' ) ) );
$bad_mail = false;
try {
	$people->create( array( 'name' => 'Smoke Bad', 'email' => 'not-an-email' ) );
} catch ( Estasoft\HandoverPassport\Domain\ValidationError $e ) {
	$bad_mail = true;
}
$check( 'a bad email is refused', $bad_mail );
$people->delete( (int) $dana['id'] );
$people->delete( (int) $sam['id'] );
$people->setRole( 'emergency_contact', $others );

// Capability grants (CAP-2).
Capabilities::applyGrants( array( 'editor' => array( Capabilities::VIEW ) ) );
$editor = get_role( 'editor' );
$check( 'a grant gives the role the capability', $editor && $editor->has_cap( Capabilities::VIEW ) && ! $editor->has_cap( Capabilities::EDIT ) );
Capabilities::applyGrants( array() );
$editor = get_role( 'editor' );
$check( 'removing a grant takes it away', $editor && ! $editor->has_cap( Capabilities::VIEW ) );

// Clean up what this run created.
foreach ( $records->items()->ofKind( 'licence' ) as $row ) {
	if ( 0 === strpos( (string) $row['title'], 'Smoke ' ) ) {
		$wpdb->delete( $table, array( 'id' => (int) $row['id'] ) );
	}
}

echo "\n" . $checks . ' checks, ' . $failures . " failed\n";
exit( $failures > 0 ? 1 : 0 );
