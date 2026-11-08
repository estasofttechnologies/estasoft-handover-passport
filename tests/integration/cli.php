<?php
/**
 * Integration test for the command line: status, findings and record. Run on a test site with the plugin active and
 * WP-CLI available:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/cli.php
 *
 * Each command runs in its own process, as it would in a terminal. The script writes test records and two test users and
 * removes them again.
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

$plugin = Plugin::instance();
wp_set_current_user( 1 );

/**
 * Run one command in its own process.
 *
 * @return array{out: string, err: string, code: int}
 */
$run = static function ( string $command ): array {
	$result = WP_CLI::runcommand(
		'estasoft-passport ' . $command,
		array(
			'return'     => 'all',
			'launch'     => true,
			'exit_error' => false,
			'parse'      => false,
		)
	);
	return array( 'out' => (string) $result->stdout, 'err' => (string) $result->stderr, 'code' => (int) $result->return_code );
};
$json = static function ( string $command ) use ( $run ): array {
	$r       = $run( $command . ' --format=json' );
	$decoded = json_decode( $r['out'], true );
	return is_array( $decoded ) ? $decoded : array();
};

// Test users: an editor who may change records but not see Sensitive ones, and a subscriber.
$admin_login = (string) get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->user_login;
foreach ( array( 'clitest_editor', 'clitest_sub' ) as $login ) {
	if ( username_exists( $login ) ) {
		wp_delete_user( (int) username_exists( $login ) );
	}
}
$editor = wp_insert_user( array( 'user_login' => 'clitest_editor', 'user_pass' => wp_generate_password(), 'user_email' => 'clitest_editor@example.test', 'role' => 'editor', 'display_name' => 'CLI Test Editor' ) );
$sub    = wp_insert_user( array( 'user_login' => 'clitest_sub', 'user_pass' => wp_generate_password(), 'user_email' => 'clitest_sub@example.test', 'role' => 'subscriber' ) );
Capabilities::applyGrants( array( 'editor' => array( Capabilities::VIEW, Capabilities::EDIT ) ) );

// Test data: one plain and one Sensitive domain, both already expired so that each has a finding.
$past   = Dates::addDays( Clock::today(), -10 );
$plain  = (int) $plugin->records()->create( 'domain', array( 'domain' => 'clitest-plain.example', 'expires_on' => $past ) )['id'];
$secret = (int) $plugin->records()->create( 'domain', array( 'domain' => 'clitest-secret.example', 'expires_on' => $past, 'sensitive' => '1' ) )['id'];
$plugin->findings()->run( true );

// ---- status ---------------------------------------------------------------------------------------------------------
$status = $json( 'status' );
$check( 'status: JSON holds findings, readiness, records, dates and last scan', isset( $status['findings'], $status['readiness'], $status['records'], $status['dates_next_30_days'], $status['last_scan'] ) );
$check( 'status: counts are numbers', is_int( $status['findings']['needs_fixing'] ?? null ) && is_int( $status['readiness']['total'] ?? null ) );
$table = $run( 'status' );
$check( 'status: the table says how ready the site is', 0 === $table['code'] && false !== strpos( $table['out'], 'Ready for a handover' ) );
$check( 'status: no record names in the output', false === strpos( $table['out'] . wp_json_encode( $status ), 'clitest-' ) );
$expect = ( (int) ( $status['findings']['needs_fixing'] ?? 0 ) ) > 0 ? 1 : 0;
$check( 'status: --fail-on=needs-fixing exits 1 when such findings are open', $expect === $run( 'status --fail-on=needs-fixing' )['code'] && 1 === $expect, (string) $expect );
$fail_worth = ( (int) ( $status['findings']['needs_fixing'] ?? 0 ) + (int) ( $status['findings']['worth_fixing'] ?? 0 ) ) > 0 ? 1 : 0;
$check( 'status: --fail-on=worth-fixing counts both levels', $fail_worth === $run( 'status --fail-on=worth-fixing' )['code'] );
$check( 'status: an unknown level is refused', 0 !== $run( 'status --fail-on=bogus' )['code'] );
$check( 'status: no --fail-on never fails', 0 === $run( 'status' )['code'] );

// ---- findings list ----------------------------------------------------------------------------------------------------
$all = $json( 'findings list' );
$text = static fn( array $rows ): string => (string) wp_json_encode( $rows );
$check( 'findings list: shows open findings with a level, a rule and a plain message', array() !== $all && isset( $all[0]['severity'], $all[0]['rule'], $all[0]['message'] ) );
$check( 'findings list: the plain record is named', false !== strpos( $text( $all ), 'clitest-plain.example' ) );
$check( 'findings list: no user, no Sensitive name', false === strpos( $text( $all ), 'clitest-secret.example' ) );
$check( 'findings list: a user without the Sensitive capability sees no name either', false === strpos( $text( $json( 'findings list --user=clitest_editor' ) ), 'clitest-secret.example' ) );
$check( 'findings list: a user with the capability sees it', false !== strpos( $text( $json( 'findings list --user=' . $admin_login ) ), 'clitest-secret.example' ) );
$needs = $json( 'findings list --severity=needs-fixing' );
$check( 'findings list: --severity keeps one level', array() !== $needs && array() === array_filter( $needs, static fn( array $f ): bool => 'needs-fixing' !== $f['severity'] ) );
$check( 'findings list: only open findings by default', array() === array_filter( $all, static fn( array $f ): bool => 'open' !== $f['state'] ) );
$check( 'findings list: a bad level is refused', 0 !== $run( 'findings list --severity=bogus' )['code'] );
$check( 'findings list: --format=count prints a number', ctype_digit( trim( $run( 'findings list --format=count' )['out'] ) ) );

$plain_finding = 0;
foreach ( $all as $finding ) {
	if ( false !== strpos( (string) $finding['message'], 'clitest-plain.example' ) ) {
		$plain_finding = (int) $finding['id'];
		break;
	}
}
$check( 'a finding about the plain test record exists', $plain_finding > 0 );

// ---- findings: snooze, accept, reopen ------------------------------------------------------------------------------
$r = $run( "findings snooze {$plain_finding}" );
$check( 'snooze: refused without a user, with a reason', 0 !== $r['code'] && false !== strpos( $r['err'], 'name a user' ), $r['err'] );
$check( 'snooze: refused for a subscriber', 0 !== $run( "findings snooze {$plain_finding} --user=clitest_sub" )['code'] );
$check( 'snooze: refused for a length that is not offered', 0 !== $run( "findings snooze {$plain_finding} --days=5 --user=clitest_editor" )['code'] );
$check( 'snooze: refused for a number that does not exist', 0 !== $run( 'findings snooze 99999999 --user=clitest_editor' )['code'] );
$check( 'snooze: refused for a word instead of a number', 0 !== $run( 'findings snooze abc --user=clitest_editor' )['code'] );
$r = $run( "findings snooze {$plain_finding} --days=30 --user=clitest_editor" );
$check( 'snooze: an editor may hide a finding', 0 === $r['code'], $r['err'] );
$snoozed = $json( 'findings list --state=snoozed' );
$check( 'snooze: it moves to the snoozed list', in_array( $plain_finding, array_column( $snoozed, 'id' ), true ) );
$check( 'snooze: it leaves the open list', ! in_array( $plain_finding, array_column( $json( 'findings list' ), 'id' ), true ) );
$check( 'reopen: refused without a user', 0 !== $run( "findings reopen {$plain_finding}" )['code'] );
$check( 'reopen: puts it back', 0 === $run( "findings reopen {$plain_finding} --user=clitest_editor" )['code'] && in_array( $plain_finding, array_column( $json( 'findings list' ), 'id' ), true ) );

$check( 'accept: a reason is required', 0 !== $run( "findings accept {$plain_finding} --user=clitest_editor" )['code'] );
$check( 'accept: an empty reason is refused', 0 !== $run( "findings accept {$plain_finding} --reason=\"\" --user=clitest_editor" )['code'] );
$secret_text = 'AKIAIOSFODNN7EXAMPLE';
$r           = $run( "findings accept {$plain_finding} --reason=\"key {$secret_text}\" --user=clitest_editor" );
$check( 'accept: a reason that looks like a secret is refused and not repeated', 0 !== $r['code'] && false === strpos( $r['out'] . $r['err'], $secret_text ), $r['err'] );
$check( 'accept: refused without a user', 0 !== $run( "findings accept {$plain_finding} --reason=\"Handled by the client\"" )['code'] );
$r = $run( "findings accept {$plain_finding} --reason=\"Handled by the client\" --user=clitest_editor" );
$check( 'accept: an editor may accept it with a reason', 0 === $r['code'], $r['err'] );
$check( 'accept: it is listed as accepted', in_array( $plain_finding, array_column( $json( 'findings list --state=accepted' ), 'id' ), true ) );
global $wpdb;
$last = (array) $wpdb->get_row( 'SELECT kind, source, actor_label, note FROM ' . Tables::name( 'changes' ) . " WHERE kind = 'finding_accepted' ORDER BY id DESC LIMIT 1", ARRAY_A );
$check( 'accept: History says it came from the command line and who did it', 'cli' === ( $last['source'] ?? '' ) && 'CLI Test Editor' === ( $last['actor_label'] ?? '' ) && 'Handled by the client' === ( $last['note'] ?? '' ), wp_json_encode( $last ) );
$run( "findings reopen {$plain_finding} --user=clitest_editor" );

// ---- record list ---------------------------------------------------------------------------------------------------
$domains = $json( 'record list --kind=domain' );
$check( 'record list: shows the plain record with its date', in_array( 'clitest-plain.example', array_column( $domains, 'title' ), true ) );
$check( 'record list: no user, the Sensitive record is hidden', ! in_array( 'clitest-secret.example', array_column( $domains, 'title' ), true ) && in_array( $secret, array_column( $domains, 'id' ), true ) );
$check( 'record list: a user without the capability sees it hidden', false === strpos( $text( $json( 'record list --kind=domain --user=clitest_editor' ) ), 'clitest-secret.example' ) );
$check( 'record list: a user with the capability sees it', in_array( 'clitest-secret.example', array_column( $json( 'record list --kind=domain --user=' . $admin_login ), 'title' ), true ) );
$check( 'record list: hidden records give no dates', '' === ( array_values( array_filter( $domains, static fn( array $d ): bool => $d['id'] === $secret ) )[0]['date'] ?? 'x' ) );
$check( 'record list: a bad kind is refused', 0 !== $run( 'record list --kind=bogus' )['code'] );
$check( 'record list: without --kind lists every register', count( $json( 'record list' ) ) >= count( $domains ) );

// ---- record verify and renew ----------------------------------------------------------------------------------------
$check( 'verify: refused without a user', 0 !== $run( "record verify {$plain}" )['code'] );
$check( 'verify: refused for a subscriber', 0 !== $run( "record verify {$plain} --user=clitest_sub" )['code'] );
$check( 'verify: refused for a number that does not exist', 0 !== $run( 'record verify 99999999 --user=clitest_editor' )['code'] );
$check( 'verify: an editor may mark a record as checked', 0 === $run( "record verify {$plain} --user=clitest_editor" )['code'] );
$row = $plugin->records()->items()->find( $plain );
$check( 'verify: the date and the person are stored', ! empty( $row['verified_at'] ) && (int) $editor === (int) $row['verified_by'] );
$last = (array) $wpdb->get_row( 'SELECT kind, source, actor_label FROM ' . Tables::name( 'changes' ) . " WHERE kind = 'record_verified' ORDER BY id DESC LIMIT 1", ARRAY_A );
$check( 'verify: History says it came from the command line', 'cli' === ( $last['source'] ?? '' ) && 'CLI Test Editor' === ( $last['actor_label'] ?? '' ), wp_json_encode( $last ) );
$r = $run( "record verify {$secret} --user=clitest_editor" );
$check( 'verify: a user who cannot see Sensitive records cannot touch one', 0 !== $r['code'] && empty( $plugin->records()->items()->find( $secret )['verified_at'] ), $r['err'] );
$check( 'verify: a user who can see it may', 0 === $run( "record verify {$secret} --user={$admin_login}" )['code'] && ! empty( $plugin->records()->items()->find( $secret )['verified_at'] ) );

$next = Dates::addDays( $past, 400 );
$check( 'renew: refused without a user', 0 !== $run( "record renew {$plain} {$next}" )['code'] );
$check( 'renew: an earlier date is refused', 0 !== $run( "record renew {$plain} " . Dates::addDays( $past, -5 ) . ' --user=clitest_editor' )['code'] );
$check( 'renew: a date that is not a date is refused', 0 !== $run( "record renew {$plain} tomorrow --user=clitest_editor" )['code'] );
$check( 'renew: an editor may enter the next date', 0 === $run( "record renew {$plain} {$next} --user=clitest_editor" )['code'] && $next === (string) $plugin->records()->items()->find( $plain )['due_date'] );
$last = (array) $wpdb->get_row( 'SELECT kind, source FROM ' . Tables::name( 'changes' ) . " WHERE kind = 'record_renewed' ORDER BY id DESC LIMIT 1", ARRAY_A );
$check( 'renew: History says it came from the command line', 'cli' === ( $last['source'] ?? '' ), wp_json_encode( $last ) );

// ---- clean up --------------------------------------------------------------------------------------------------------
$items = $plugin->records()->items();
foreach ( array( $plain, $secret ) as $id ) {
	$items->delete( $id );
}
$wpdb->query( 'DELETE FROM ' . Tables::name( 'changes' ) . " WHERE actor_label = 'CLI Test Editor'" );
wp_delete_user( (int) $editor );
wp_delete_user( (int) $sub );
Capabilities::applyGrants( array() );
$plugin->findings()->run( true );

echo "\n" . ( $checks - $failures ) . ' of ' . $checks . " checks passed\n";
if ( $failures > 0 ) {
	exit( 1 );
}
