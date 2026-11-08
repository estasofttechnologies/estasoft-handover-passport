<?php
/**
 * Integration test for the command line: status, findings, record, snapshot, export and restore-test. Run on a test site
 * with the plugin active and WP-CLI available:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/cli.php
 *
 * Each command runs in its own process, as it would in a terminal. The script writes test records, snapshots and three test users and
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
foreach ( array( 'clitest_editor', 'clitest_sub', 'clitest_exporter' ) as $login ) {
	if ( username_exists( $login ) ) {
		wp_delete_user( (int) username_exists( $login ) );
	}
}
$editor = wp_insert_user( array( 'user_login' => 'clitest_editor', 'user_pass' => wp_generate_password(), 'user_email' => 'clitest_editor@example.test', 'role' => 'editor', 'display_name' => 'CLI Test Editor' ) );
$sub    = wp_insert_user( array( 'user_login' => 'clitest_sub', 'user_pass' => wp_generate_password(), 'user_email' => 'clitest_sub@example.test', 'role' => 'subscriber' ) );
$exporter = wp_insert_user( array( 'user_login' => 'clitest_exporter', 'user_pass' => wp_generate_password(), 'user_email' => 'clitest_exporter@example.test', 'role' => 'author', 'display_name' => 'CLI Test Exporter' ) );
Capabilities::applyGrants(
	array(
		'editor' => array( Capabilities::VIEW, Capabilities::EDIT ),
		'author' => array( Capabilities::VIEW, Capabilities::EXPORT ),
	)
);

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


// ---- snapshot, export and restore-test -------------------------------------------------------------------------------
global $wpdb;
$baseline_change = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Tables::name( 'changes' ) );
$baseline_export = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Tables::name( 'exports' ) );
$made_snapshots  = array();
$made_tests      = array();
$files           = array();
$tmp             = sys_get_temp_dir() . '/estasoft-hp-cli-' . getmypid();
mkdir( $tmp, 0700 );

$snap_before = count( $plugin->snapshots()->all() );

$r = $run( 'snapshot create --name="CLI snapshot"' );
$check( 'snapshot create: refused without a user', 0 !== $r['code'] && false !== strpos( $r['err'], 'name a user' ), $r['err'] );
$check( 'snapshot create: refused for an editor who may not export', 0 !== $run( 'snapshot create --name="CLI snapshot" --user=clitest_editor' )['code'] );
$check( 'snapshot create: refused for a subscriber', 0 !== $run( 'snapshot create --name="CLI snapshot" --user=clitest_sub' )['code'] );
$check( 'snapshot create: nothing was stored by the refusals', count( $plugin->snapshots()->all() ) === $snap_before );
$check( 'snapshot create: a name is required', 0 !== $run( 'snapshot create --user=clitest_exporter' )['code'] );
$check( 'snapshot create: an empty name is refused', 0 !== $run( 'snapshot create --name="  " --user=clitest_exporter' )['code'] );
$check( 'snapshot create: an unknown pack is refused', 0 !== $run( 'snapshot create --name="CLI snapshot" --pack=bogus --user=clitest_exporter' )['code'] );
$r = $run( "snapshot create --name=\"CLI snapshot\" --note=\"key {$secret_text}\" --user=clitest_exporter" );
$check( 'snapshot create: a note that looks like a secret is refused and not repeated', 0 !== $r['code'] && false === strpos( $r['out'] . $r['err'], $secret_text ), $r['err'] );
$check( 'snapshot create: --include-sensitive is refused for someone who may not see Sensitive records', 0 !== $run( 'snapshot create --name="CLI snapshot" --include-sensitive --user=clitest_exporter' )['code'] );
$check( 'snapshot create: --include-sensitive is refused without a user', 0 !== $run( 'snapshot create --name="CLI snapshot" --include-sensitive' )['code'] );
$check( 'snapshot create: none of the refusals stored a snapshot', count( $plugin->snapshots()->all() ) === $snap_before );

$r = $run( 'snapshot create --name="CLI snapshot A" --pack=technical --note="made by the test" --user=clitest_exporter' );
$check( 'snapshot create: an exporter may make one', 0 === $r['code'] && 1 === preg_match( '/Snapshot (\d+) stored\. SHA-256: ([0-9a-f]{64})/', $r['out'], $m ), $r['out'] . $r['err'] );
$snap_a = isset( $m[1] ) ? (int) $m[1] : 0;
$made_snapshots[] = $snap_a;
$row_a = $plugin->snapshots()->get( $snap_a ) ?? array();
$check( 'snapshot create: the stored hash is the one printed', ( $m[2] ?? '' ) === ( $row_a['hash'] ?? 'x' ) && hash( 'sha256', (string) ( $row_a['content'] ?? '' ) ) === ( $row_a['hash'] ?? 'y' ) );
$check( 'snapshot create: name, pack and note are stored, and the maker is the named user', 'CLI snapshot A' === ( $row_a['name'] ?? '' ) && 'technical' === ( $row_a['profile_key'] ?? '' ) && 'made by the test' === ( $row_a['note'] ?? '' ) && (int) $exporter === (int) ( $row_a['created_by'] ?? 0 ) );
$check( 'snapshot create: no Sensitive record without the flag', false === strpos( (string) ( $row_a['content'] ?? '' ), 'clitest-secret.example' ) && false !== strpos( (string) ( $row_a['content'] ?? '' ), 'clitest-plain.example' ) );
$last = (array) $wpdb->get_row( 'SELECT kind, source, actor_label FROM ' . Tables::name( 'changes' ) . " WHERE kind = 'snapshot_created' AND id > {$baseline_change} ORDER BY id DESC LIMIT 1", ARRAY_A );
$check( 'snapshot create: History names the person', 'CLI Test Exporter' === ( $last['actor_label'] ?? '' ), wp_json_encode( $last ) );

$r      = $run( 'snapshot create --name="CLI snapshot B" --pack=technical --include-sensitive --user=' . $admin_login );
$check( 'snapshot create: --include-sensitive works for someone who may see Sensitive records', 0 === $r['code'], $r['out'] . $r['err'] );
$snap_b = 1 === preg_match( '/Snapshot (\d+) stored/', $r['out'], $m2 ) ? (int) $m2[1] : 0;
$made_snapshots[] = $snap_b;
$check( 'snapshot create: the Sensitive record is in it and listed as included', false !== strpos( (string) ( $plugin->snapshots()->get( $snap_b )['content'] ?? '' ), 'sensitive_included' ) && false !== strpos( (string) ( $plugin->snapshots()->get( $snap_b )['content'] ?? '' ), 'clitest-secret.example' ) );

$list = $json( 'snapshot list' );
$ids = array_map( 'intval', array_column( $list, 'id' ) );
$check( 'snapshot list: shows both snapshots, newest first', in_array( $snap_a, $ids, true ) && in_array( $snap_b, $ids, true ) && array_search( $snap_b, $ids, true ) < array_search( $snap_a, $ids, true ) );
$listed = array_values( array_filter( $list, static fn( array $x ): bool => (int) $x['id'] === $snap_a ) )[0] ?? array();
$check( 'snapshot list: name, pack, maker and hash are shown, with no content', 'CLI snapshot A' === ( $listed['name'] ?? '' ) && 'technical' === ( $listed['pack'] ?? '' ) && 'clitest_exporter' === ( $listed['by'] ?? '' ) && 64 === strlen( (string) ( $listed['hash'] ?? '' ) ) && false === strpos( $text( $list ), 'clitest-plain.example' ) );
$check( 'snapshot list: works without a user', 0 === $run( 'snapshot list' )['code'] );
$check( 'snapshot list: --format=count prints a number', ctype_digit( trim( $run( 'snapshot list --format=count' )['out'] ) ) );

$r = $run( "snapshot verify {$snap_a}" );
$check( 'snapshot verify: a snapshot that is intact passes', 0 === $r['code'] && false !== strpos( $r['out'], 'matches its stored hash' ), $r['out'] . $r['err'] );
$check( 'snapshot verify: the result is kept for the list', 'ok' === ( array_values( array_filter( $json( 'snapshot list' ), static fn( array $x ): bool => (int) $x['id'] === $snap_a ) )[0]['integrity'] ?? '' ) );
$check( 'snapshot verify: a missing snapshot is an error', 0 !== $run( 'snapshot verify 99999999' )['code'] );
$check( 'snapshot verify: a word instead of a number is an error', 0 !== $run( 'snapshot verify abc' )['code'] );
$check( 'snapshot verify: no number is an error', 0 !== $run( 'snapshot verify' )['code'] );

// Compare: a new record appears in the live Passport and a Sensitive one changes.
$added = (int) $plugin->records()->create( 'domain', array( 'domain' => 'clitest-added.example', 'expires_on' => Dates::addDays( Clock::today(), 400 ) ) )['id'];
$plugin->records()->update( $secret, array( 'domain' => 'clitest-secret.example', 'expires_on' => Dates::addDays( $past, 200 ), 'sensitive' => '1' ) );
$diff = $json( 'snapshot compare ' . $snap_a . ' live' );
$check( 'snapshot compare: against live shows the new record', array() !== $diff && array() !== array_filter( (array) ( $diff['added'] ?? array() ), static fn( array $x ): bool => 'clitest-added.example' === $x['title'] ) );
$check( 'snapshot compare: the counts add up', isset( $diff['unchanged'] ) && is_int( $diff['unchanged'] ) && isset( $diff['sensitive_skipped'] ) );
$out = $run( 'snapshot compare ' . $snap_a . ' live' );
$check( 'snapshot compare: the text form says what was added', 0 === $out['code'] && 1 === preg_match( '/^\d+ added, \d+ removed, \d+ changed, \d+ unchanged\./m', $out['out'] ) && false !== strpos( $out['out'], 'clitest-added.example' ), $out['out'] . $out['err'] );
$check( 'snapshot compare: the Sensitive record is not named for a run without a user', false === strpos( $out['out'] . $text( $diff ), 'clitest-secret.example' ) );
$with_sensitive = $run( 'snapshot compare ' . $snap_b . ' live --user=' . $admin_login );
$without        = $run( 'snapshot compare ' . $snap_b . ' live --user=clitest_exporter' );
$check( 'snapshot compare: even a user who may see Sensitive records sees only that one changed and which field, never the name or value', 0 === $with_sensitive['code'] && false !== strpos( $with_sensitive['out'], '(Sensitive record) [Expires on]' ) && false === strpos( $with_sensitive['out'], 'clitest-secret.example' ), $with_sensitive['out'] . $with_sensitive['err'] );
$check( 'snapshot compare: a user who may not, does not, and is told some were left out', 0 === $without['code'] && false === strpos( $without['out'], 'clitest-secret.example' ) && false !== strpos( $without['out'], 'Sensitive record(s) were left out' ), $without['out'] . $without['err'] );
$between = $run( "snapshot compare {$snap_a} {$snap_b} --format=json" );
$check( 'snapshot compare: two snapshots can be compared', 0 === $between['code'] && is_array( json_decode( $between['out'], true ) ), $between['out'] . $between['err'] );
$check( 'snapshot compare: a missing snapshot is an error', 0 !== $run( 'snapshot compare 99999999 live' )['code'] );
$check( 'snapshot compare: a missing second snapshot is an error', 0 !== $run( "snapshot compare {$snap_a} 99999999" )['code'] );
$check( 'snapshot compare: the second argument must be a number or live', 0 !== $run( "snapshot compare {$snap_a} later" )['code'] );
$check( 'snapshot compare: the second argument is required', 0 !== $run( "snapshot compare {$snap_a}" )['code'] );

// ---- export ----------------------------------------------------------------------------------------------------------
$r = $run( 'export' );
$check( 'export: a pack or a snapshot must be named', 0 !== $r['code'] && false !== strpos( $r['err'], 'which pack' ), $r['err'] );
$check( 'export: an unknown pack is refused', 0 !== $run( 'export bogus --format=md' )['code'] );
$check( 'export: an unknown format is refused', 0 !== $run( 'export client --format=docx' )['code'] );
$r = $run( 'export client --format=pdf' );
$check( 'export: pdf needs --file', 0 !== $r['code'] && false !== strpos( $r['err'], '--file' ) && false === strpos( $r['out'], '%PDF' ), $r['err'] );
$check( 'export: csv needs --file', 0 !== $run( 'export client --format=csv' )['code'] );
$r = $run( 'export full --format=json' );
$check( 'export: the Full pack is refused without --include-sensitive', 0 !== $r['code'] && '' === trim( $r['out'] ) && false !== strpos( $r['err'], '--include-sensitive' ), $r['err'] );
$r = $run( 'export full --format=json --include-sensitive' );
$check( 'export: --include-sensitive is refused without a user', 0 !== $r['code'] && '' === trim( $r['out'] ), $r['err'] );
$r = $run( 'export full --format=json --include-sensitive --user=clitest_exporter' );
$check( 'export: --include-sensitive is refused for a user who may not see Sensitive records', 0 !== $r['code'] && false === strpos( $r['out'], 'clitest-secret.example' ), $r['err'] );
$r = $run( 'export technical --format=md --user=clitest_sub' );
$check( 'export: a user who may not export is refused', 0 !== $r['code'] && '' === trim( $r['out'] ), $r['err'] );
$check( 'export: an editor who may not export is refused', 0 !== $run( 'export technical --format=md --user=clitest_editor' )['code'] );

$r = $run( 'export technical --format=md' );
$check( 'export: a text format prints to standard output', 0 === $r['code'] && 0 === strpos( $r['out'], '# ' ) && false !== strpos( $r['out'], 'clitest-plain.example' ), substr( $r['out'], 0, 80 ) . $r['err'] );
$check( 'export: no Sensitive record in a run without --include-sensitive', false === strpos( $r['out'], 'clitest-secret.example' ) );
$r    = $run( 'export technical --format=json' );
$data = json_decode( $r['out'], true );
$check( 'export: json on standard output is valid and has the schema', 0 === $r['code'] && is_array( $data ) && 'estasoft-handover-passport' === ( $data['schema'] ?? '' ), substr( $r['out'], 0, 80 ) );
$check( 'export: standard output holds nothing but the file', null !== $data );
$r = $run( 'export full --format=json --include-sensitive --user=' . $admin_login );
$check( 'export: the Full pack with --include-sensitive and a permitted user holds the Sensitive record', 0 === $r['code'] && false !== strpos( $r['out'], 'clitest-secret.example' ), $r['err'] );
$r = $run( 'export technical --format=ics' );
$check( 'export: an ics calendar prints to standard output', 0 === $r['code'] && 0 === strpos( $r['out'], 'BEGIN:VCALENDAR' ), substr( $r['out'], 0, 40 ) );
$r = $run( 'export technical --format=html' );
$check( 'export: html prints to standard output', 0 === $r['code'] && false !== stripos( $r['out'], '<html' ) );

// Files.
$pdf = $tmp . '/client.pdf';
$r   = $run( "export client --format=pdf --file={$pdf}" );
$check( 'export: a pdf is written to the file', 0 === $r['code'] && is_file( $pdf ) && 0 === strpos( (string) file_get_contents( $pdf ), '%PDF' ), $r['out'] . $r['err'] );
$check( 'export: the message gives the size and a hash that matches the file', 1 === preg_match( '/SHA-256 ([0-9a-f]{64})/', $r['out'], $hm ) && hash_file( 'sha256', $pdf ) === ( $hm[1] ?? '' ) );
$check( 'export: the file is readable by its owner only', '0600' === substr( sprintf( '%o', fileperms( $pdf ) ), -4 ) );
$before = hash_file( 'sha256', $pdf );
$r      = $run( "export client --format=pdf --file={$pdf}" );
$check( 'export: an existing file is not replaced without --force', 0 !== $r['code'] && hash_file( 'sha256', $pdf ) === $before && false !== strpos( $r['err'], '--force' ), $r['err'] );
file_put_contents( $pdf, 'old' );
$r = $run( "export client --format=pdf --file={$pdf} --force" );
$check( 'export: --force replaces it', 0 === $r['code'] && 0 === strpos( (string) file_get_contents( $pdf ), '%PDF' ), $r['err'] );
$check( 'export: a folder that does not exist is refused', 0 !== $run( "export client --format=md --file={$tmp}/nope/x.md" )['code'] );
$check( 'export: a folder instead of a file name is refused', 0 !== $run( "export client --format=md --file={$tmp}" )['code'] );
$zip = $tmp . '/technical.zip';
$r   = $run( "export technical --format=csv --file={$zip}" );
$check( 'export: csv is written as a zip', 0 === $r['code'] && 'PK' === substr( (string) file_get_contents( $zip ), 0, 2 ), $r['err'] );
$md = $tmp . '/tech.md';
$r  = $run( "export technical --format=md --file={$md}" );
$check( 'export: a text format can go to a file too, with nothing on standard output', 0 === $r['code'] && false !== strpos( (string) file_get_contents( $md ), 'clitest-plain.example' ) && false === strpos( $r['out'], '# ' ), $r['err'] );
$full = $tmp . '/full.json';
$r    = $run( "export full --format=json --file={$full} --include-sensitive --user={$admin_login}" );
$check( 'export: the file with Sensitive records is written and owner-only', 0 === $r['code'] && false !== strpos( (string) file_get_contents( $full ), 'clitest-secret.example' ) && '0600' === substr( sprintf( '%o', fileperms( $full ) ), -4 ), $r['err'] );
$inside = rtrim( ABSPATH, '/' ) . '/estasoft-hp-cli-test-' . getmypid() . '.md';
$files[] = $inside;
$r = $run( "export technical --format=md --file={$inside}" );
$check( 'export: a file written inside the WordPress folder comes with a warning', 0 === $r['code'] && false !== strpos( $r['err'], 'inside the WordPress folder' ), $r['err'] );
$r = $run( "export technical --format=md --file={$tmp}/outside.md" );
$check( 'export: no warning for a file outside it', 0 === $r['code'] && false === strpos( $r['err'], 'inside the WordPress folder' ), $r['err'] );

// From a snapshot.
$r = $run( "export --snapshot={$snap_a} --format=md" );
$check( 'export --snapshot: makes the file from the stored snapshot, not from the live Passport', 0 === $r['code'] && false !== strpos( $r['out'], 'clitest-plain.example' ) && false === strpos( $r['out'], 'clitest-added.example' ), $r['err'] );
$check( 'export --snapshot: a pack name as well is refused', 0 !== $run( "export client --snapshot={$snap_a} --format=md" )['code'] );
$check( 'export --snapshot: a word instead of a number is refused', 0 !== $run( 'export --snapshot=abc --format=md' )['code'] );
$check( 'export --snapshot: a missing snapshot is refused', 0 !== $run( 'export --snapshot=99999999 --format=md' )['code'] );
$r = $run( "export --snapshot={$snap_b} --format=md" );
$check( 'export --snapshot: a snapshot with Sensitive records is refused without the flag and says why', 0 !== $r['code'] && '' === trim( $r['out'] ) && false !== strpos( $r['err'], '--include-sensitive' ), $r['err'] );
$r = $run( "export --snapshot={$snap_b} --format=md --include-sensitive" );
$check( 'export --snapshot: or without a user', 0 !== $r['code'] && '' === trim( $r['out'] ), $r['err'] );
$r = $run( "export --snapshot={$snap_b} --format=md --include-sensitive --user=clitest_exporter" );
$check( 'export --snapshot: or for a user who may not see Sensitive records', 0 !== $r['code'] && '' === trim( $r['out'] ), $r['err'] );
$r = $run( "export --snapshot={$snap_b} --format=md --include-sensitive --user={$admin_login}" );
$check( 'export --snapshot: and made with it', 0 === $r['code'] && false !== strpos( $r['out'], 'clitest-secret.example' ), $r['err'] );

// A tampered snapshot is never turned into a file (AS-8).
$wpdb->update( $plugin->snapshots()->repo()->table(), array( 'content' => str_replace( 'clitest-plain.example', 'clitest-evil.example', (string) $plugin->snapshots()->get( $snap_a )['content'] ) ), array( 'id' => $snap_a ) );
$r = $run( "snapshot verify {$snap_a}" );
$check( 'snapshot verify: a tampered snapshot exits 1 and names both hashes', 1 === $r['code'] && 2 === preg_match_all( '/[0-9a-f]{64}/', $r['err'], $hashes ) && $hashes[0][0] !== $hashes[0][1], $r['err'] );
$check( 'snapshot verify: the list now says MISMATCH', 'MISMATCH' === ( array_values( array_filter( $json( 'snapshot list' ), static fn( array $x ): bool => (int) $x['id'] === $snap_a ) )[0]['integrity'] ?? '' ) );
$r = $run( "export --snapshot={$snap_a} --format=md" );
$check( 'export --snapshot: no file is made from a tampered snapshot', 0 !== $r['code'] && '' === trim( $r['out'] ) && false === strpos( $r['err'] . $r['out'], 'clitest-evil.example' ), $r['err'] );
$wpdb->update( $plugin->snapshots()->repo()->table(), array( 'content' => str_replace( 'clitest-evil.example', 'clitest-plain.example', (string) $plugin->snapshots()->get( $snap_a )['content'] ) ), array( 'id' => $snap_a ) );
$check( 'snapshot verify: once restored it passes again', 0 === $run( "snapshot verify {$snap_a}" )['code'] );

// The export log records who and what.
$logged = $wpdb->get_results( 'SELECT user_id, snapshot_id, profile, format FROM ' . Tables::name( 'exports' ) . " WHERE id > {$baseline_export} ORDER BY id", ARRAY_A );
$check( 'export: each file made was logged', count( $logged ) >= 12 );
$check( 'export: a run without a user is logged as a task (user 0)', array() !== array_filter( $logged, static fn( array $x ): bool => 0 === (int) $x['user_id'] && 'technical' === $x['profile'] && 'md' === $x['format'] ) );
$check( 'export: a run with a user is logged with that user', array() !== array_filter( $logged, static fn( array $x ): bool => 1 === (int) $x['user_id'] || (int) get_user_by( 'login', $admin_login )->ID === (int) $x['user_id'] ) );
$check( 'export: a file made from a snapshot is logged with its number', array() !== array_filter( $logged, static fn( array $x ): bool => $snap_a === (int) $x['snapshot_id'] ) );
$check( 'export: the refusals were not logged', 0 === count( array_filter( $logged, static fn( array $x ): bool => 'bogus' === $x['profile'] ) ) );

// ---- restore-test ----------------------------------------------------------------------------------------------------
$tests_before = count( $plugin->restoreTests()->all() );
$r            = $run( 'restore-test add --result=pass --duration=12' );
$check( 'restore-test add: refused without a user', 0 !== $r['code'] && false !== strpos( $r['err'], 'name a user' ), $r['err'] );
$check( 'restore-test add: refused for a subscriber', 0 !== $run( 'restore-test add --result=pass --user=clitest_sub' )['code'] );
$check( 'restore-test add: refused for an exporter who may not edit', 0 !== $run( 'restore-test add --result=pass --user=clitest_exporter' )['code'] );
$check( 'restore-test add: a result is required', 0 !== $run( 'restore-test add --user=clitest_editor' )['code'] );
$check( 'restore-test add: an unknown result is refused', 0 !== $run( 'restore-test add --result=great --user=clitest_editor' )['code'] );
$check( 'restore-test add: a date in the future is refused', 0 !== $run( 'restore-test add --result=pass --date=' . Dates::addDays( Clock::today(), 3 ) . ' --user=clitest_editor' )['code'] );
$check( 'restore-test add: a date that is not a date is refused', 0 !== $run( 'restore-test add --result=pass --date=yesterday --user=clitest_editor' )['code'] );
$check( 'restore-test add: a duration that is not a number is refused', 0 !== $run( 'restore-test add --result=pass --duration=soon --user=clitest_editor' )['code'] );
$check( 'restore-test add: an unknown scope is refused', 0 !== $run( 'restore-test add --result=pass --scope=everything --user=clitest_editor' )['code'] );
$check( 'restore-test add: an unknown place is refused', 0 !== $run( 'restore-test add --result=pass --place=moon --user=clitest_editor' )['code'] );
$check( 'restore-test add: a backup date that is not a date is refused', 0 !== $run( 'restore-test add --result=pass --backup-date=lastweek --user=clitest_editor' )['code'] );
$r = $run( "restore-test add --result=fail --problems=\"password {$secret_text}\" --user=clitest_editor" );
$check( 'restore-test add: a problem text that looks like a secret is refused and not repeated', 0 !== $r['code'] && false === strpos( $r['out'] . $r['err'], $secret_text ), $r['err'] );
$check( 'restore-test add: none of the refusals logged a test', count( $plugin->restoreTests()->all() ) === $tests_before );

$day = Dates::addDays( Clock::today(), -2 );
$r   = $run( "restore-test add --date={$day} --result=partial --duration=95 --scope=database --place=staging --backup-date=" . Dates::addDays( $day, -1 ) . ' --problems="Media folder missing" --user=clitest_editor' );
$check( 'restore-test add: an editor may log a test', 0 === $r['code'] && 1 === preg_match( '/Restore test (\d+) logged: Partial on ' . preg_quote( $day, '/' ) . '/', $r['out'], $tm ), $r['out'] . $r['err'] );
$test_id = isset( $tm[1] ) ? (int) $tm[1] : 0;
$made_tests[] = $test_id;
$stored = (array) $plugin->restoreTests()->repo()->find( $test_id );
$check( 'restore-test add: every value is stored', 'partial' === ( $stored['result'] ?? '' ) && 95 === (int) ( $stored['minutes'] ?? 0 ) && 'database' === ( $stored['scope'] ?? '' ) && 'staging' === ( $stored['place'] ?? '' ) && $day === ( $stored['test_date'] ?? '' ) && 'Media folder missing' === ( $stored['problems'] ?? '' ) && Dates::addDays( $day, -1 ) === ( $stored['backup_date'] ?? '' ), wp_json_encode( $stored ) );
$check( 'restore-test add: "who" defaults to the name of the user', 'CLI Test Editor' === ( $stored['who'] ?? '' ) );
$last = (array) $wpdb->get_row( 'SELECT kind, source, actor_label FROM ' . Tables::name( 'changes' ) . " WHERE kind = 'restore_test_logged' AND id > {$baseline_change} ORDER BY id DESC LIMIT 1", ARRAY_A );
$check( 'restore-test add: History says it came from the command line and who did it', 'cli' === ( $last['source'] ?? '' ) && 'CLI Test Editor' === ( $last['actor_label'] ?? '' ), wp_json_encode( $last ) );

$r = $run( 'restore-test add --result=pass --who="Nightly CI job" --user=clitest_editor' );
$check( 'restore-test add: --who and a default date of today', 0 === $r['code'] && 1 === preg_match( '/Restore test (\d+) logged: Pass on ' . preg_quote( Clock::today(), '/' ) . '/', $r['out'], $tm2 ), $r['out'] . $r['err'] );
$second = isset( $tm2[1] ) ? (int) $tm2[1] : 0;
$made_tests[] = $second;
$check( 'restore-test add: the name given is stored', 'Nightly CI job' === ( $plugin->restoreTests()->repo()->find( $second )['who'] ?? '' ) );
$check( 'restore-test add: a passing test moves the date of the last Pass', Clock::today() === $plugin->restoreTests()->lastPass() );

$tests = $json( 'restore-test list' );
$order = array_map( 'intval', array_column( $tests, 'id' ) );
$check( 'restore-test list: shows both, the newer date first', in_array( $second, $order, true ) && in_array( $test_id, $order, true ) && array_search( $second, $order, true ) < array_search( $test_id, $order, true ) );
$check( 'restore-test list: has date, result, minutes and who', 'partial' === ( array_values( array_filter( $tests, static fn( array $x ): bool => (int) $x['id'] === $test_id ) )[0]['result'] ?? '' ) && 95 === (int) ( array_values( array_filter( $tests, static fn( array $x ): bool => (int) $x['id'] === $test_id ) )[0]['minutes'] ?? 0 ) );
$check( 'restore-test list: works without a user', 0 === $run( 'restore-test list' )['code'] );

// Clean up what this part made.
foreach ( $made_snapshots as $id ) {
	if ( $id > 0 && null !== $plugin->snapshots()->get( $id ) ) {
		$plugin->snapshots()->delete( $id );
	}
}
foreach ( $made_tests as $id ) {
	if ( $id > 0 ) {
		$plugin->restoreTests()->delete( $id, 'cli' );
	}
}
$plugin->records()->items()->delete( $added );
foreach ( glob( $tmp . '/*' ) ?: array() as $f ) {
	unlink( $f );
}
rmdir( $tmp );
foreach ( $files as $f ) {
	if ( is_file( $f ) ) {
		unlink( $f );
	}
}
$wpdb->query( 'DELETE FROM ' . Tables::name( 'changes' ) . " WHERE id > {$baseline_change} AND kind IN ('snapshot_created','snapshot_deleted','restore_test_logged','restore_test_deleted')" );
$wpdb->query( 'DELETE FROM ' . Tables::name( 'exports' ) . " WHERE id > {$baseline_export}" );
delete_option( \Estasoft\HandoverPassport\Findings\SnapshotIntegrity::OPTION );

// ---- clean up --------------------------------------------------------------------------------------------------------
$items = $plugin->records()->items();
foreach ( array( $plain, $secret ) as $id ) {
	$items->delete( $id );
}
$wpdb->query( 'DELETE FROM ' . Tables::name( 'changes' ) . " WHERE actor_label IN ('CLI Test Editor','CLI Test Exporter')" );
wp_delete_user( (int) $editor );
wp_delete_user( (int) $sub );
wp_delete_user( (int) $exporter );
Capabilities::applyGrants( array() );
$plugin->findings()->run( true );

echo "\n" . ( $checks - $failures ) . ' of ' . $checks . " checks passed\n";
if ( $failures > 0 ) {
	exit( 1 );
}
