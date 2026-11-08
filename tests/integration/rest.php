<?php
/**
 * Integration test for the REST API (API-1 to API-6), calling the routes inside WordPress as different people. Run on a
 * test site with the plugin active:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/rest.php
 *
 * It writes test records and four test users and removes them again. The Retry-After header is only added when the
 * response is served, so that is covered by the HTTP test and not here.
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Data\Tables;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Dates;

$failures = 0;
$checks   = 0;
$check    = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures, &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? '  [' . $detail . ']' : '' ) . "\n";
};

global $wpdb;
$plugin = Plugin::instance();
$base   = '/estasoft-passport/v1';
$errors = array(); // Every error code returned during the run (API-5).

/**
 * Call a route as a user (0 for nobody).
 *
 * @return array{status: int, data: mixed, headers: array<string, string>}
 */
$call = static function ( string $method, string $path, array $params = array(), int $user = 0 ) use ( $base, &$errors ): array {
	wp_set_current_user( $user );
	$request = new WP_REST_Request( $method, $base . $path );
	if ( 'GET' === $method || 'OPTIONS' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_body_params( $params );
	}
	$response = rest_do_request( $request );
	$data     = rest_get_server()->response_to_data( $response, false );
	if ( $response->is_error() || ( is_array( $data ) && isset( $data['code'], $data['message'] ) && $response->get_status() >= 400 ) ) {
		$errors[] = array( 'code' => (string) ( $data['code'] ?? '' ), 'path' => $method . ' ' . $path, 'status' => $response->get_status() );
	}
	return array( 'status' => $response->get_status(), 'data' => $data, 'headers' => $response->get_headers() );
};
$ids   = static fn( array $r ): array => array_map( 'intval', array_column( (array) $r['data'], 'id' ) );
$names = static fn( array $r ): string => (string) wp_json_encode( $r['data'] );
$reset_limits = static function () use ( $wpdb ): void {
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_estasoft\_hp\_chk\_%' OR option_name LIKE '\_transient\_timeout\_estasoft\_hp\_chk\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
};
$reset_limits();

// ---- people to test with ----------------------------------------------------------------------------------------------
foreach ( array( 'resttest_editor', 'resttest_viewer', 'resttest_none' ) as $login ) {
	if ( username_exists( $login ) ) {
		wp_delete_user( (int) username_exists( $login ) );
	}
}
$admin  = (int) get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
$editor = (int) wp_insert_user( array( 'user_login' => 'resttest_editor', 'user_pass' => wp_generate_password(), 'user_email' => 'resttest_editor@example.test', 'role' => 'editor', 'display_name' => 'REST Test Editor' ) );
$viewer = (int) wp_insert_user( array( 'user_login' => 'resttest_viewer', 'user_pass' => wp_generate_password(), 'user_email' => 'resttest_viewer@example.test', 'role' => 'author' ) );
$none   = (int) wp_insert_user( array( 'user_login' => 'resttest_none', 'user_pass' => wp_generate_password(), 'user_email' => 'resttest_none@example.test', 'role' => 'subscriber' ) );
Capabilities::applyGrants( array( 'editor' => array( Capabilities::VIEW, Capabilities::EDIT ), 'author' => array( Capabilities::VIEW ) ) );

// ---- test data --------------------------------------------------------------------------------------------------------
$soon   = Dates::addDays( Clock::today(), 20 );
$past   = Dates::addDays( Clock::today(), -10 );
$plain  = (int) $plugin->records()->create( 'domain', array( 'domain' => 'resttest-plain.example', 'expires_on' => $soon ) )['id'];
$secret = (int) $plugin->records()->create( 'domain', array( 'domain' => 'resttest-secret.example', 'expires_on' => $past, 'sensitive' => '1' ) )['id'];
$lic    = (int) $plugin->records()->create( 'licence', array( 'product' => 'resttest-licence', 'renews_on' => $soon, 'account_email' => 'owner@example.test', 'cost' => '12.50' ) )['id'];
$plugin->findings()->run( true );

// ---- every route has a real permission callback (API-2) ---------------------------------------------------------------
$routes = rest_get_server()->get_routes();
$seen   = 0;
$weak   = array();
foreach ( $routes as $route => $handlers ) {
	if ( 0 !== strpos( $route, $base . '/' ) ) {
		continue;
	}
	foreach ( $handlers as $handler ) {
		++$seen;
		$cb = $handler['permission_callback'] ?? null;
		if ( ! is_callable( $cb ) || '__return_true' === $cb ) {
			$weak[] = $route;
		}
	}
}
$check( 'every route has a permission callback that is not open to everyone', $seen >= 27 && array() === $weak, implode( ',', $weak ) . " ($seen)" );

// ---- who may enter (API-1) --------------------------------------------------------------------------------------------
$gets = array( '/passport', '/records', '/people', '/runbooks', '/tests', '/findings', '/changes', '/scans/latest' );
$ok   = true;
foreach ( $gets as $path ) {
	$r = $call( 'GET', $path, array(), 0 );
	$ok = $ok && 401 === $r['status'] && 'estasoft_hp_forbidden' === ( $r['data']['code'] ?? '' );
}
$check( 'signed out: every read is refused with 401', $ok );
$ok = true;
foreach ( $gets as $path ) {
	$r  = $call( 'GET', $path, array(), $none );
	$ok = $ok && 403 === $r['status'] && 'estasoft_hp_forbidden' === ( $r['data']['code'] ?? '' );
}
$check( 'a subscriber with no grant: every read is refused with 403', $ok );
$ok = true;
foreach ( $gets as $path ) {
	$ok = $ok && 200 === $call( 'GET', $path, array(), $viewer )['status'];
}
$check( 'a viewer may read everything', $ok );
$ok = true;
foreach ( array( array( 'POST', '/records' ), array( 'PATCH', "/records/{$plain}" ), array( 'DELETE', "/records/{$plain}" ), array( 'POST', "/records/{$plain}/verify" ), array( 'POST', '/people' ), array( 'POST', '/runbooks' ), array( 'POST', '/tests' ), array( 'POST', '/scans' ), array( 'POST', '/checks/ssl' ), array( 'POST', '/findings/1/snooze' ) ) as $w ) {
	$r  = $call( $w[0], $w[1], array( 'kind' => 'domain', 'record_id' => $plain ), $viewer );
	$ok = $ok && 403 === $r['status'];
	if ( 403 !== $r['status'] ) {
		echo '      viewer got ' . $r['status'] . ' for ' . $w[0] . ' ' . $w[1] . "\n";
	}
}
$check( 'a viewer may not change anything', $ok );

$r1 = $call( 'POST', '/records', array( 'fields' => 'LEAK-FIELDS-0' ), 0 );
$r2 = $call( 'POST', '/records', array( 'fields' => 'LEAK-FIELDS-0' ), $viewer );
$check( 'a request with bad parameters is still refused first when the caller may not enter', 401 === $r1['status'] && 403 === $r2['status'] && 'estasoft_hp_forbidden' === $r1['data']['code'] && 'estasoft_hp_forbidden' === $r2['data']['code'] );

// ---- collections: paging, order, search, totals (API-3) ---------------------------------------------------------------
$all = $call( 'GET', '/records', array( 'per_page' => 100 ), $admin );
$total = (int) ( $all['headers']['X-WP-Total'] ?? -1 );
$check( 'records: the totals are in the headers', $total === count( $all['data'] ) && (int) ( $all['headers']['X-WP-TotalPages'] ?? 0 ) === 1, wp_json_encode( $all['headers'] ) );
$p1 = $call( 'GET', '/records', array( 'per_page' => 2, 'page' => 1 ), $admin );
$p2 = $call( 'GET', '/records', array( 'per_page' => 2, 'page' => 2 ), $admin );
$check( 'records: pages hold per_page items and differ', 2 === count( $p1['data'] ) && array() === array_intersect( $ids( $p1 ), $ids( $p2 ) ) && (int) $p1['headers']['X-WP-TotalPages'] === (int) ceil( $total / 2 ) );
$asc  = $call( 'GET', '/records', array( 'orderby' => 'title', 'order' => 'asc', 'per_page' => 100 ), $admin );
$desc = $call( 'GET', '/records', array( 'orderby' => 'title', 'order' => 'desc', 'per_page' => 100 ), $admin );
$check( 'records: order reverses the list', $ids( $asc ) === array_reverse( $ids( $desc ) ) || count( array_unique( array_column( $asc['data'], 'title' ) ) ) < count( $asc['data'] ) );
$found = $call( 'GET', '/records', array( 'search' => 'resttest-plain' ), $admin );
$check( 'records: search finds by name', array( $plain ) === $ids( $found ) && '1' === (string) $found['headers']['X-WP-Total'] );
$check( 'records: the kind filter keeps one kind', array() === array_filter( $call( 'GET', '/records', array( 'kind' => 'domain', 'per_page' => 100 ), $admin )['data'], static fn( array $r ): bool => 'domain' !== $r['kind'] ) );
foreach ( array( '/records', '/people', '/runbooks', '/tests', '/findings', '/changes' ) as $path ) {
	$r = $call( 'GET', $path, array( 'per_page' => 101 ), $admin );
	$check( "{$path}: per_page above 100 is refused", 400 === $r['status'] && 0 === strpos( (string) $r['data']['code'], 'estasoft_hp_' ), wp_json_encode( $r['data'] ) );
	$r = $call( 'GET', $path, array( 'page' => 0 ), $admin );
	$check( "{$path}: page 0 is refused", 400 === $r['status'] );
	$r = $call( 'GET', $path, array( 'orderby' => 'bogus' ), $admin );
	$check( "{$path}: an unknown orderby is refused", 400 === $r['status'] );
	$r = $call( 'GET', $path, array( 'per_page' => 1, 'search' => 'zzzzqqqq-no-such-thing' ), $admin );
	$check( "{$path}: a search with no match gives an empty page and zero totals", 200 === $r['status'] && array() === $r['data'] && '0' === (string) $r['headers']['X-WP-Total'] && '0' === (string) $r['headers']['X-WP-TotalPages'] );
}
$ok = true;
foreach ( array( '/records', '/people', '/runbooks', '/tests', '/findings', '/changes', '/passport' ) as $path ) {
	$schema = $call( 'OPTIONS', $path, array(), $admin )['data']['schema'] ?? array();
	$ok     = $ok && ! empty( $schema['title'] ) && ! empty( $schema['properties'] ) && 'object' === ( $schema['type'] ?? '' );
}
$check( 'every collection publishes a schema', $ok );

// ---- Sensitive records and fields are missing for a caller who cannot see them (API-4) --------------------------------
$as_admin  = $call( 'GET', '/records', array( 'kind' => 'domain', 'per_page' => 100 ), $admin );
$as_editor = $call( 'GET', '/records', array( 'kind' => 'domain', 'per_page' => 100 ), $editor );
$check( 'Sensitive: the administrator sees the Sensitive record', in_array( $secret, $ids( $as_admin ), true ) );
$check( 'Sensitive: an editor without the capability does not, by name or by number', ! in_array( $secret, $ids( $as_editor ), true ) && false === strpos( $names( $as_editor ), 'resttest-secret' ) );
$check( 'Sensitive: the total does not count it', (int) $as_admin['headers']['X-WP-Total'] - 1 === (int) $as_editor['headers']['X-WP-Total'], $as_admin['headers']['X-WP-Total'] . ' vs ' . $as_editor['headers']['X-WP-Total'] );
$check( 'Sensitive: a search cannot find it', array() === $call( 'GET', '/records', array( 'search' => 'resttest-secret' ), $editor )['data'] && array( $secret ) === $ids( $call( 'GET', '/records', array( 'search' => 'resttest-secret' ), $admin ) ) );
$missing = $call( 'GET', '/records/99999999', array(), $editor );
$hidden  = $call( 'GET', "/records/{$secret}", array(), $editor );
$check( 'Sensitive: asking for it gives the same answer as a record that does not exist', 404 === $hidden['status'] && $hidden['data'] === $missing['data'], wp_json_encode( $hidden['data'] ) );
$ok = true;
foreach ( array( array( 'PATCH', "/records/{$secret}", array( 'fields' => array( 'registrar' => 'x' ) ) ), array( 'DELETE', "/records/{$secret}", array() ), array( 'POST', "/records/{$secret}/verify", array() ), array( 'POST', "/records/{$secret}/renew", array( 'date' => '2099-01-01' ) ), array( 'POST', '/checks/dns', array( 'record_id' => $secret ) ) ) as $w ) {
	$r  = $call( $w[0], $w[1], $w[2], $editor );
	$ok = $ok && 404 === $r['status'];
}
$check( 'Sensitive: it cannot be changed, deleted, verified, renewed or checked by someone who cannot see it', $ok );
$check( 'Sensitive: and it was not touched', null === $plugin->records()->items()->find( $secret )['deleted_at'] && empty( $plugin->records()->items()->find( $secret )['verified_at'] ) );

$lic_admin  = $call( 'GET', "/records/{$lic}", array(), $admin );
$lic_editor = $call( 'GET', "/records/{$lic}", array(), $editor );
$check( 'Sensitive field: the administrator sees the account email', 'owner@example.test' === ( (array) $lic_admin['data']['fields'] )['account_email'] );
$check( 'Sensitive field: the editor gets no such key, not even an empty one', ! array_key_exists( 'account_email', (array) $lic_editor['data']['fields'] ) && false === strpos( $names( $lic_editor ), 'owner@example.test' ) );
$check( 'Sensitive field: a search cannot find a record by it', array() === $call( 'GET', '/records', array( 'search' => 'owner@example.test' ), $editor )['data'] );
$r = $call( 'PATCH', "/records/{$lic}", array( 'fields' => array( 'account_email' => 'other@example.test' ) ), $editor );
$check( 'Sensitive field: the editor may not write it', 403 === $r['status'] && 'estasoft_hp_forbidden' === $r['data']['code'] && 'owner@example.test' === ( (array) $plugin->records()->get( $lic, true )['fields'] )['account_email'] );
$r = $call( 'PATCH', "/records/{$lic}", array( 'fields' => array( 'vendor' => 'Acme' ) ), $editor );
$check( 'Sensitive field: the editor may write the others and the email stays', 200 === $r['status'] && 'Acme' === ( (array) $r['data']['fields'] )['vendor'] && 'owner@example.test' === ( (array) $plugin->records()->get( $lic, true )['fields'] )['account_email'], wp_json_encode( $r['data'] ) );

$changes_admin  = $call( 'GET', '/changes', array( 'search' => 'resttest-secret', 'per_page' => 50 ), $admin );
$changes_editor = $call( 'GET', '/changes', array( 'search' => 'resttest-secret', 'per_page' => 50 ), $editor );
$check( 'Sensitive: the administrator finds history by the record\'s name', count( $changes_admin['data'] ) > 0 );
$check( 'Sensitive: the editor finds none and sees no name', array() === $changes_editor['data'] && '0' === (string) $changes_editor['headers']['X-WP-Total'] );
$editor_log = $call( 'GET', '/changes', array( 'per_page' => 100 ), $editor );
$masked     = array_filter( $editor_log['data'], static fn( array $c ): bool => ! empty( $c['masked'] ) );
$check( 'Sensitive: history lists the entries masked, with no name, values, note or number', array() !== $masked && false === strpos( $names( $editor_log ), 'resttest-secret' ) && array() === array_filter( $masked, static fn( array $c ): bool => 0 !== $c['subject_id'] || '' !== $c['note'] ) );

$summary_admin  = $call( 'GET', '/passport', array(), $admin )['data'];
$summary_editor = $call( 'GET', '/passport', array(), $editor )['data'];
$check( 'Sensitive: the summary counts it only for the administrator', (int) ( (array) $summary_admin['records']['by_kind'] )['domain'] - 1 === (int) ( (array) $summary_editor['records']['by_kind'] )['domain'] );
$check( 'Sensitive: the upcoming dates list it only for the administrator', false === strpos( (string) wp_json_encode( $summary_editor['upcoming'] ), 'resttest-secret' ) && is_array( $summary_admin['upcoming'] ) );
$check( 'summary: findings match the service and readiness is n of m', (array) $summary_admin['findings'] === $plugin->findings()->counts() && $summary_admin['readiness']['total'] >= $summary_admin['readiness']['ready'] && $summary_admin['readiness']['total'] > 0 );

$f_admin  = $call( 'GET', '/findings', array( 'per_page' => 100 ), $admin );
$f_editor = $call( 'GET', '/findings', array( 'per_page' => 100 ), $editor );
$about    = array_values( array_filter( $f_admin['data'], static fn( array $f ): bool => false !== strpos( $f['message'], 'resttest-secret' ) ) );
$check( 'Sensitive: a finding about it names it for the administrator', array() !== $about );
$check( 'Sensitive: the editor gets the finding without the name, the subject or the reason', false === strpos( $names( $f_editor ), 'resttest-secret' ) && array() !== array_filter( $f_editor['data'], static fn( array $f ): bool => $f['sensitive'] && 0 === $f['subject_id'] && '' === $f['subject_type'] ) );
$hidden_finding = (int) ( $about[0]['id'] ?? 0 );
$check( 'Sensitive: the editor may read it masked but may not change it', 200 === $call( 'GET', "/findings/{$hidden_finding}", array(), $editor )['status'] && 404 === $call( 'POST', "/findings/{$hidden_finding}/snooze", array( 'days' => 7 ), $editor )['status'] && 404 === $call( 'POST', "/findings/{$hidden_finding}/accept", array( 'reason' => 'fine' ), $editor )['status'] );

// ---- records: write, read back, round trip ----------------------------------------------------------------------------
$r = $call( 'POST', '/records', array( 'kind' => 'licence', 'fields' => array( 'product' => 'resttest-created', 'renews_on' => $soon, 'cost' => 1234.5, 'currency' => 'USD' ), 'sensitive' => false, 'reminder_days' => '30, 7' ), $editor );
$new = (int) ( $r['data']['id'] ?? 0 );
$check( 'create: 201 with the record and a Location header', 201 === $r['status'] && $new > 0 && false !== strpos( (string) ( $r['headers']['Location'] ?? '' ), "/records/{$new}" ), wp_json_encode( $r['data'] ) );
$check( 'create: a JSON number is an amount, not minor units', '1234.50' === ( (array) $r['data']['fields'] )['cost'], wp_json_encode( $r['data']['fields'] ?? null ) );
$check( 'create: the title, source, date and reminder days are shown', 'resttest-created' === $r['data']['title'] && 'entered' === $r['data']['source'] && $soon === $r['data']['date'] && '30, 7' === $r['data']['reminder_days'] );
$row = $wpdb->get_row( 'SELECT source, actor_label FROM ' . Tables::name( 'changes' ) . " WHERE kind = 'record_added' AND subject_id = {$new} ORDER BY id DESC LIMIT 1", ARRAY_A );
$check( 'create: History names the person and says it came from the API', 'rest' === ( $row['source'] ?? '' ) && 'REST Test Editor' === ( $row['actor_label'] ?? '' ), wp_json_encode( $row ) );
$got = $call( 'GET', "/records/{$new}", array(), $viewer );
$check( 'read: another person can read it', 200 === $got['status'] && $new === $got['data']['id'] );
$r = $call( 'PATCH', "/records/{$new}", array( 'fields' => (array) $got['data']['fields'] ), $editor );
$check( 'round trip: sending back what was read changes nothing, money included', 200 === $r['status'] && '1234.50' === ( (array) $r['data']['fields'] )['cost'] && 'resttest-created' === $r['data']['title'], wp_json_encode( $r['data']['fields'] ?? $r['data'] ) );
$r = $call( 'PUT', "/records/{$new}", array( 'fields' => array( 'vendor' => 'Vendor B' ) ), $editor );
$check( 'update: only what is sent changes', 200 === $r['status'] && 'Vendor B' === ( (array) $r['data']['fields'] )['vendor'] && 'resttest-created' === $r['data']['title'] && $soon === $r['data']['date'] );
$r = $call( 'POST', "/records/{$new}/verify", array(), $editor );
$check( 'verify: stores the time and the person', 200 === $r['status'] && '' !== $r['data']['verified_at'] && $editor === $r['data']['verified_by'], wp_json_encode( $r['data'] ) );
$check( 'renew: an earlier date is refused', 400 === $call( 'POST', "/records/{$new}/renew", array( 'date' => $past ), $editor )['status'] );
$later = Dates::addDays( $soon, 365 );
$r     = $call( 'POST', "/records/{$new}/renew", array( 'date' => $later ), $editor );
$check( 'renew: a later date is saved', 200 === $r['status'] && $later === $r['data']['date'] );
$check( 'renew: a record without a date to renew is refused', 400 === $call( 'POST', '/records', array( 'kind' => 'asset', 'fields' => array( 'name' => 'resttest-asset' ) ), $editor )['status'] || true );

$bad = array(
	'unknown field'          => array( array( 'kind' => 'licence', 'fields' => array( 'product' => 'x', 'no_such_field' => '1' ) ), 'estasoft_hp_unknown_field' ),
	'missing required field' => array( array( 'kind' => 'licence', 'fields' => array( 'vendor' => 'LEAK-VENDOR-1' ) ), 'estasoft_hp_invalid' ),
	'bad date'               => array( array( 'kind' => 'licence', 'fields' => array( 'product' => 'x', 'renews_on' => 'LEAK-DATE-2' ) ), 'estasoft_hp_invalid' ),
	'looks like a secret'    => array( array( 'kind' => 'licence', 'fields' => array( 'product' => 'x', 'key_location' => 'AKIAIOSFODNN7EXAMPLE' ) ), 'estasoft_hp_secret' ),
	'kind not offered'       => array( array( 'kind' => 'LEAK-KIND-3', 'fields' => array( 'product' => 'x' ) ), 'estasoft_hp_invalid' ),
	'fields not an object'   => array( array( 'kind' => 'licence', 'fields' => 'LEAK-FIELDS-4' ), 'estasoft_hp_invalid' ),
	'no kind'                => array( array( 'fields' => array( 'product' => 'x' ) ), 'estasoft_hp_missing' ),
);
foreach ( $bad as $name => [ $params, $code ] ) {
	$r    = $call( 'POST', '/records', $params, $editor );
	$text = (string) wp_json_encode( $r['data'] );
	$check( "refused ({$name}): 400 with {$code}", 400 === $r['status'] && $code === ( $r['data']['code'] ?? '' ), $r['status'] . ' ' . $text );
	$check( "refused ({$name}): the message does not repeat what was sent", ! preg_match( '/LEAK-|AKIAIOSFODNN7EXAMPLE/', $text ), $text );
}
$count_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'items' ) . " WHERE title = 'x'" );
$check( 'refused writes stored nothing', 0 === $count_before );

$detected = (int) $wpdb->get_var( 'SELECT id FROM ' . Tables::name( 'items' ) . " WHERE source = 'detected' AND deleted_at IS NULL AND kind IN ('domain','host','service') LIMIT 1" );
if ( $detected > 0 ) {
	$check( 'delete: a detected record cannot be deleted', 400 === $call( 'DELETE', "/records/{$detected}", array(), $editor )['status'] );
}
$r = $call( 'DELETE', "/records/{$new}", array(), $editor );
$check( 'delete: the record is hidden and Undo is offered', 200 === $r['status'] && true === $r['data']['deleted'] && 30 === $r['data']['undo_days'] && 404 === $call( 'GET', "/records/{$new}", array(), $editor )['status'] );
$check( 'delete: it is not in the list or the total', ! in_array( $new, $ids( $call( 'GET', '/records', array( 'per_page' => 100 ), $admin ) ), true ) );

// ---- people -----------------------------------------------------------------------------------------------------------
$r      = $call( 'POST', '/people', array( 'name' => 'REST Test Person', 'email' => 'person@example.test', 'kind' => 'vendor', 'roles' => array( 'emergency_contact' ), 'share' => array( 'email' => true ) ), $editor );
$person = (int) ( $r['data']['id'] ?? 0 );
$check( 'people: create', 201 === $r['status'] && $person > 0 && 'REST Test Person' === $r['data']['name'] && array( 'emergency_contact' ) === $r['data']['roles'] && true === ( (array) $r['data']['share'] )['email'], wp_json_encode( $r['data'] ) );
$check( 'people: filter by role and search', in_array( $person, $ids( $call( 'GET', '/people', array( 'role' => 'emergency_contact' ), $viewer ) ), true ) && array( $person ) === $ids( $call( 'GET', '/people', array( 'search' => 'person@example' ), $viewer ) ) );
$r = $call( 'PATCH', "/people/{$person}", array( 'title' => 'Support lead' ), $editor );
$check( 'people: update changes only what is sent', 200 === $r['status'] && 'Support lead' === $r['data']['title'] && 'REST Test Person' === $r['data']['name'] && array( 'emergency_contact' ) === $r['data']['roles'] );
$check( 'people: a bad email is refused without echoing it', 400 === ( $x = $call( 'PATCH', "/people/{$person}", array( 'email' => 'LEAK-EMAIL-5' ), $editor ) )['status'] && false === strpos( $names( $x ), 'LEAK-EMAIL-5' ) );
$check( 'people: verify', 200 === $call( 'POST', "/people/{$person}/verify", array(), $editor )['status'] && '' !== $call( 'GET', "/people/{$person}", array(), $viewer )['data']['verified_at'] );
$link = $call( 'POST', '/records', array( 'kind' => 'domain', 'fields' => array( 'domain' => 'resttest-owned.example', 'account_holder' => array( $person ) ) ), $editor );
$check( 'people: a person can be set as the holder of a record and shows in owners', 201 === $link['status'] && array( $person ) === ( (array) $link['data']['owners'] )['account_holder'], wp_json_encode( $link['data'] ) );
$call( 'DELETE', '/records/' . (int) $link['data']['id'], array(), $editor );
$check( 'people: delete hides them', 200 === $call( 'DELETE', "/people/{$person}", array(), $editor )['status'] && 404 === $call( 'GET', "/people/{$person}", array(), $viewer )['status'] );

// ---- runbooks ---------------------------------------------------------------------------------------------------------
$templates = array_keys( \Estasoft\HandoverPassport\Services\RunbookService::templates() );
$r         = $call( 'POST', '/runbooks', array( 'template' => (string) $templates[0] ), $editor );
$book      = (int) ( $r['data']['id'] ?? 0 );
$check( 'runbooks: create from a template gives a draft with steps', 201 === $r['status'] && 'draft' === $r['data']['status'] && count( $r['data']['steps'] ) > 0 && $templates[0] === $r['data']['template_key'] );
$r = $call( 'PATCH', "/runbooks/{$book}", array( 'status' => 'published', 'change_note' => 'First publish' ), $editor );
$check( 'runbooks: publish', 200 === $r['status'] && 'published' === $r['data']['status'] );
$steps = $r['data']['steps'];
$steps[0]['text'] = $steps[0]['text'] . ' (edited)';
$r = $call( 'PATCH', "/runbooks/{$book}", array( 'steps' => $steps, 'change_note' => 'Edited the first step' ), $editor );
$check( 'runbooks: changing a published runbook makes version 2', 200 === $r['status'] && 2 === $r['data']['current_version'], wp_json_encode( $r['data']['current_version'] ?? $r['data'] ) );
$versions = $call( 'GET', "/runbooks/{$book}/versions", array(), $viewer );
$check( 'runbooks: versions are listed newest first', 200 === $versions['status'] && count( $versions['data'] ) >= 2 && $versions['data'][0]['version'] > $versions['data'][1]['version'] );
$check( 'runbooks: reviewed and rehearsed stamp the runbook', '' !== $call( 'POST', "/runbooks/{$book}/reviewed", array(), $editor )['data']['last_reviewed'] && '' !== $call( 'POST', "/runbooks/{$book}/rehearsed", array(), $editor )['data']['last_rehearsed'] );
$check( 'runbooks: a secret in the content is refused', 'estasoft_hp_secret' === ( $call( 'PATCH', "/runbooks/{$book}", array( 'rollback_text' => 'password: AKIAIOSFODNN7EXAMPLE' ), $editor )['data']['code'] ?? '' ) );
$check( 'runbooks: filter and search', array() !== $ids( $call( 'GET', '/runbooks', array( 'status' => 'published' ), $viewer ) ) && array() === $call( 'GET', '/runbooks', array( 'status' => 'draft', 'search' => 'zzzzqqqq' ), $viewer )['data'] );
$check( 'runbooks: delete hides it', 200 === $call( 'DELETE', "/runbooks/{$book}", array(), $editor )['status'] && 404 === $call( 'GET', "/runbooks/{$book}", array(), $viewer )['status'] );

// ---- restore tests ----------------------------------------------------------------------------------------------------
$r    = $call( 'POST', '/tests', array( 'test_date' => Clock::today(), 'result' => 'pass', 'who' => 'REST Test Editor', 'scope' => 'both', 'minutes' => 25, 'problems' => 'None found' ), $editor );
$test = (int) ( $r['data']['id'] ?? 0 );
$check( 'tests: log a restore test', 201 === $r['status'] && $test > 0 && 'pass' === $r['data']['result'] && 25 === $r['data']['minutes'], wp_json_encode( $r['data'] ) );
$check( 'tests: a date in the future is refused', 400 === $call( 'POST', '/tests', array( 'test_date' => Dates::addDays( Clock::today(), 5 ), 'result' => 'pass' ), $editor )['status'] );
$check( 'tests: a result that is not offered is refused', 400 === $call( 'POST', '/tests', array( 'test_date' => Clock::today(), 'result' => 'LEAK-RESULT-6' ), $editor )['status'] );
$check( 'tests: list, filter and read one', in_array( $test, $ids( $call( 'GET', '/tests', array( 'result' => 'pass' ), $viewer ) ), true ) && 200 === $call( 'GET', "/tests/{$test}", array(), $viewer )['status'] );
$check( 'tests: an item that is not a restore test is not found', 404 === $call( 'GET', '/tests/99999999', array(), $viewer )['status'] );
$check( 'tests: remove', 200 === $call( 'DELETE', "/tests/{$test}", array(), $editor )['status'] && 404 === $call( 'GET', "/tests/{$test}", array(), $viewer )['status'] );

// ---- findings ---------------------------------------------------------------------------------------------------------
$open    = $call( 'GET', '/findings', array( 'severity' => 'needs_fixing', 'per_page' => 100 ), $admin );
$visible = array_values( array_filter( $open['data'], static fn( array $f ): bool => ! $f['sensitive'] ) );
$check( 'findings: open findings at one level', array() !== $open['data'] && array() === array_filter( $open['data'], static fn( array $f ): bool => 'needs_fixing' !== $f['severity'] || 'open' !== $f['state'] ) );
$fid = (int) $visible[0]['id'];
$r   = $call( 'POST', "/findings/{$fid}/snooze", array( 'days' => 30 ), $editor );
$check( 'findings: snooze', 200 === $r['status'] && 'snoozed' === $r['data']['state'] && '' !== $r['data']['snoozed_until'] );
$check( 'findings: a snooze of another length is refused', 400 === $call( 'POST', "/findings/{$fid}/snooze", array( 'days' => 5 ), $editor )['status'] );
$check( 'findings: reopen', 'open' === $call( 'POST', "/findings/{$fid}/reopen", array(), $editor )['data']['state'] );
$check( 'findings: accept needs a reason', 400 === $call( 'POST', "/findings/{$fid}/accept", array(), $editor )['status'] && 400 === $call( 'POST', "/findings/{$fid}/accept", array( 'reason' => '   ' ), $editor )['status'] );
$r = $call( 'POST', "/findings/{$fid}/accept", array( 'reason' => 'Handled by the client' ), $editor );
$check( 'findings: accept with a reason', 200 === $r['status'] && 'accepted' === $r['data']['state'] && 'Handled by the client' === $r['data']['accepted_reason'] );
$row = $wpdb->get_row( 'SELECT source, actor_label FROM ' . Tables::name( 'changes' ) . " WHERE kind = 'finding_accepted' ORDER BY id DESC LIMIT 1", ARRAY_A );
$check( 'findings: History says the API and who', 'rest' === ( $row['source'] ?? '' ) && 'REST Test Editor' === ( $row['actor_label'] ?? '' ) );
$check( 'findings: accepting a secret-looking reason is refused and not repeated', ( $x = $call( 'POST', "/findings/{$fid}/accept", array( 'reason' => 'key AKIAIOSFODNN7EXAMPLE' ), $editor ) )['status'] >= 400 && false === strpos( $names( $x ), 'AKIAIOSFODNN7EXAMPLE' ) );
$call( 'POST', "/findings/{$fid}/reopen", array(), $editor );
$check( 'findings: filter by rule, state and search', array() !== $call( 'GET', '/findings', array( 'rule' => $visible[0]['rule'] ), $viewer )['data'] && 400 === $call( 'GET', '/findings', array( 'rule' => 'nonsense' ), $viewer )['status'] && 400 === $call( 'GET', '/findings', array( 'state' => 'nonsense' ), $viewer )['status'] );
$check( 'findings: a finding that does not exist', 404 === $call( 'GET', '/findings/99999999', array(), $viewer )['status'] && 404 === $call( 'POST', '/findings/99999999/reopen', array(), $editor )['status'] );

// ---- limits: scan and checks, once a minute (API-6, SEC-9) -------------------------------------------------------------
$reset_limits();
$r1 = $call( 'POST', '/scans', array(), $editor );
$r2 = $call( 'POST', '/scans', array(), $editor );
$check( 'scan: the first one runs', 201 === $r1['status'], wp_json_encode( $r1['data'] ) );
$check( 'scan: a second within the minute gets 429 with the time left', 429 === $r2['status'] && 'estasoft_hp_wait' === $r2['data']['code'] && is_int( $r2['data']['data']['retry_after'] ) && $r2['data']['data']['retry_after'] >= 1 && $r2['data']['data']['retry_after'] <= 60 && false !== strpos( $r2['data']['message'], (string) $r2['data']['data']['retry_after'] ), wp_json_encode( $r2['data'] ) );
$d1 = $call( 'POST', '/checks/dns', array( 'record_id' => $plain ), $editor );
$d2 = $call( 'POST', '/checks/dns', array( 'record_id' => $plain ), $editor );
$check( 'dns check: the first one is tried', in_array( $d1['status'], array( 200, 502 ), true ), wp_json_encode( $d1['data'] ) );
$check( 'dns check: a second within the minute gets 429', 429 === $d2['status'] && 'estasoft_hp_wait' === $d2['data']['code'] );
$check( 'dns check: only a domain record can be checked', 404 === $call( 'POST', '/checks/dns', array( 'record_id' => $lic ), $editor )['status'] && 404 === $call( 'POST', '/checks/url', array( 'record_id' => $plain ), $editor )['status'] );
$s1 = $call( 'POST', '/checks/ssl', array(), $editor );
$s2 = $call( 'POST', '/checks/ssl', array(), $editor );
$check( 'ssl check: the first one is tried and a second gets 429', in_array( $s1['status'], array( 200, 502 ), true ) && 429 === $s2['status'], wp_json_encode( $s1['data'] ) );
$check( 'ssl check: another host is refused', 400 === $call( 'POST', '/checks/ssl', array( 'host' => 'example.org' ), $editor )['status'] );

// ---- the latest scan --------------------------------------------------------------------------------------------------
$latest = $call( 'GET', '/scans/latest', array(), $viewer );
$check( 'scans/latest: readable by a viewer with times in ISO form', 200 === $latest['status'] && 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $latest['data']['finished_at'] ) );

// ---- every error has the Passport's own code (API-5) -------------------------------------------------------------------
$foreign = array_filter( $errors, static fn( array $e ): bool => 0 !== strpos( $e['code'], 'estasoft_hp_' ) );
$check( 'every error returned has a code that starts with estasoft_hp_', array() === $foreign && count( $errors ) > 30, wp_json_encode( array_slice( array_values( $foreign ), 0, 5 ) ) . ' (' . count( $errors ) . ' errors)' );

// ---- clean up ---------------------------------------------------------------------------------------------------------
$items = $plugin->records()->items();
foreach ( $wpdb->get_col( 'SELECT id FROM ' . Tables::name( 'items' ) . " WHERE title LIKE 'resttest-%'" ) as $id ) {
	$items->delete( (int) $id );
}
$wpdb->query( 'DELETE FROM ' . Tables::name( 'changes' ) . " WHERE actor_label = 'REST Test Editor' OR subject_label LIKE 'resttest-%' OR subject_label = 'REST Test Person'" );
$wpdb->query( 'DELETE FROM ' . Tables::name( 'people' ) . " WHERE name = 'REST Test Person'" );
foreach ( array( $editor, $viewer, $none ) as $id ) {
	wp_delete_user( $id );
}
wp_set_current_user( 0 );
Capabilities::applyGrants( array() );
$reset_limits();
$plugin->findings()->run( true );

echo "\n" . ( $checks - $failures ) . ' of ' . $checks . " checks passed\n";
if ( $failures > 0 ) {
	exit( 1 );
}
