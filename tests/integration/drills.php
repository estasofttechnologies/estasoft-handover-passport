<?php
/**
 * Integration test for drills, the after-restore checklist and runbooks as text. Run on a WordPress test site:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/drills.php
 *
 * It writes runbooks, records, drills and restore tests to the site and removes them again, so use a test site.
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Data\Tables;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Services\DrillService;
use Estasoft\HandoverPassport\Services\RestoreChecklist;
use Estasoft\HandoverPassport\Support\Clock;

$failures = 0;
$checks   = 0;
$check    = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures, &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? '  [' . $detail . ']' : '' ) . "\n";
};
$throws = static function ( callable $fn ): ?\Throwable {
	try {
		$fn();
	} catch ( \Throwable $e ) {
		return $e;
	}
	return null;
};

global $wpdb;
$plugin = Plugin::instance();
$plugin->reset();
wp_set_current_user( 1 );
$admin_name = (string) wp_get_current_user()->display_name;
$baseline   = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Tables::name( 'changes' ) );

$cleanup = static function () use ( $plugin, $wpdb, $baseline ): void {
	$mine = array();
	foreach ( array_merge( $plugin->runbooks()->all(), $plugin->runbooks()->deleted() ) as $book ) {
		if ( 0 === strpos( (string) $book['title'], 'DRLTEST' ) ) {
			$mine[] = (int) $book['id'];
		}
	}
	foreach ( $plugin->restoreTests()->all() as $test ) {
		if ( in_array( (int) $test['runbook_id'], $mine, true ) ) {
			$plugin->restoreTests()->repo()->delete( (int) $test['id'] );
		}
	}
	foreach ( $plugin->drills()->all( 0, 500 ) as $drill ) {
		if ( in_array( (int) $drill['runbook_id'], $mine, true ) ) {
			$plugin->restoreTests()->repo()->delete( (int) $drill['id'] );
		}
	}
	foreach ( $plugin->runbooks()->all() as $book ) {
		if ( 0 === strpos( (string) $book['title'], 'DRLTEST' ) ) {
			$plugin->runbooks()->delete( (int) $book['id'] );
		}
	}
	foreach ( $plugin->runbooks()->deleted() as $book ) {
		if ( 0 === strpos( (string) $book['title'], 'DRLTEST' ) ) {
			$plugin->runbooks()->repo()->purge( (int) $book['id'] );
		}
	}
	foreach ( $plugin->records()->everything() as $old ) {
		if ( 0 === stripos( (string) $old['title'], 'drltest' ) ) {
			$plugin->records()->items()->delete( (int) $old['id'] );
		}
	}
	$plugin->settings()->set( RestoreChecklist::SETTING, array() );
	$wpdb->query( 'DELETE FROM ' . Tables::name( 'changes' ) . " WHERE id > {$baseline} AND (kind LIKE 'drill_%' OR kind LIKE 'restore_test_%' OR kind LIKE 'runbook_%' OR kind = 'settings_changed' OR subject_label LIKE 'DRLTEST%' OR subject_label LIKE 'drltest%')" );
};
$cleanup();
$tests_at_start = count( $plugin->restoreTests()->all() );

// ---- data ---------------------------------------------------------------------------------------------------------
$plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'drltest-main.example', 'expires_on' => '2027-03-01', 'registrar' => 'DRLTEST Registrar', 'role' => 'primary' ) );
$plugin->records()->create( Kinds::URL, array( 'name' => 'DRLTEST Home', 'address' => 'https://drltest.example/', 'expected_status' => '200', 'expected_text' => 'Welcome' ) );
$plugin->records()->create( Kinds::URL, array( 'name' => 'DRLTEST Secret page', 'address' => 'https://drltest.example/secret', 'sensitive' => '1' ) );

$step   = static fn( string $text, int $min = 0, bool $warn = false ): array => array( 'text' => $text, 'who' => '', 'minutes' => (string) $min, 'warning' => $warn ? '1' : '' );
$steps  = array( $step( 'Open {registrar} and check {domain}', 5 ), $step( 'Do not delete the live site', 0, true ), $step( 'Restore into staging at {backup_location}', 20 ) );
$book   = $plugin->runbooks()->create( array( 'title' => 'DRLTEST Restore', 'status' => 'published', 'restore_scope' => 'site', 'steps' => $steps, 'purpose' => 'For {site_name}' ) );
$other  = $plugin->runbooks()->create( array( 'title' => 'DRLTEST Plain', 'status' => 'published', 'restore_scope' => '', 'steps' => array( $step( 'Only step' ) ) ) );
$draft  = $plugin->runbooks()->create( array( 'title' => 'DRLTEST Draft', 'status' => 'draft', 'steps' => array( $step( 'Draft step' ) ) ) );
$empty  = $plugin->runbooks()->create( array( 'title' => 'DRLTEST Empty', 'status' => 'published', 'steps' => array() ) );
$rid    = (int) $book['id'];
$drills = $plugin->drills();

// ---- runbooks as text (RUN-4, RUN-6) ----------------------------------------------------------------------------------
$read = $plugin->runbookPresenter()->present( $rid );
$check( 'present: a runbook is returned', is_array( $read ) && 'DRLTEST Restore' === $read['title'] );
$check( 'present: a placeholder is filled from the Passport', false !== strpos( (string) $read['steps'][0]['text'], 'DRLTEST Registrar' ) );
$check( 'present: a missing detail shows as not recorded and is listed', false !== strpos( (string) $read['steps'][2]['text'], '[backup_location: not recorded]' ) && in_array( 'backup_location', $read['gaps'], true ) );
$check( 'present: text outside the steps is filled as well', false !== strpos( (string) $read['purpose'], 'For ' ) && false === strpos( (string) $read['purpose'], '{site_name}' ) );
$check( 'present: a warning step stays a warning', true === $read['steps'][1]['warning'] );
$check( 'present: the time is the sum of the steps', 25 === $read['estimated_minutes'] );
$check( 'present: the current version is flagged', true === $read['is_current'] && 1 === $read['version'] );
$check( 'present: a version that does not exist gives null', null === $plugin->runbookPresenter()->present( $rid, 9 ) );
$check( 'present: a runbook that does not exist gives null', null === $plugin->runbookPresenter()->present( 999999 ) );
$plugin->runbooks()->update( $rid, array( 'steps' => array_merge( $steps, array( $step( 'Added later' ) ) ), 'change_note' => 'More' ) );
$old = $plugin->runbookPresenter()->present( $rid, 1 );
$new = $plugin->runbookPresenter()->present( $rid );
$check( 'present: an earlier version reads as it was', is_array( $old ) && 3 === count( $old['steps'] ) && false === $old['is_current'] );
$check( 'present: the current version has the new step', is_array( $new ) && 4 === count( $new['steps'] ) && 2 === $new['version'] );

// ---- the after-restore checklist (URL-4) ------------------------------------------------------------------------------
$list    = $plugin->restoreChecklist()->items();
$labels  = array_column( $list, 'label' );
$kinds   = array_column( $list, 'kind' );
$check( 'checklist: a critical address is listed with what to expect', in_array( 'Open: DRLTEST Home', $labels, true ) && false !== strpos( implode( ' ', array_column( $list, 'detail' ) ), 'and the words "Welcome"' ) );
$check( 'checklist: a Sensitive address is left out', ! in_array( 'Open: DRLTEST Secret page', $labels, true ) && false === strpos( wp_json_encode( $list ), 'drltest.example/secret' ) );
$check( 'checklist: the shipped hand checks follow the addresses', 'url' === $kinds[0] && 'manual' === end( $kinds ) && count( RestoreChecklist::defaults() ) === count( array_keys( $kinds, 'manual', true ) ) );
$check( 'checklist: it starts out as the shipped list', false === $plugin->restoreChecklist()->isCustom() );
$plugin->restoreChecklist()->saveManual( "Check the shop\n\n  Look at the footer  \n" );
$check( 'checklist: the agency can set its own hand checks', array( 'Check the shop', 'Look at the footer' ) === $plugin->restoreChecklist()->manual() && true === $plugin->restoreChecklist()->isCustom() );
$plugin->restoreChecklist()->saveManual( '' );
$check( 'checklist: an empty list goes back to the shipped one', false === $plugin->restoreChecklist()->isCustom() && RestoreChecklist::defaults() === $plugin->restoreChecklist()->manual() );
$check( 'checklist: too many lines are refused', $throws( fn() => $plugin->restoreChecklist()->saveManual( implode( "\n", array_fill( 0, 21, 'x' ) ) ) ) instanceof ValidationError );
$check( 'checklist: a very long line is refused', $throws( fn() => $plugin->restoreChecklist()->saveManual( str_repeat( 'x', 301 ) ) ) instanceof ValidationError );
$secret_text = 'AKIAIOSFODNN7EXAMPLE';
$e           = $throws( fn() => $plugin->restoreChecklist()->saveManual( "Use key {$secret_text}" ) );
$check( 'checklist: a line that looks like a secret is refused and not repeated', $e instanceof SecretFound && false === strpos( $e->getMessage(), $secret_text ) );
$check( 'checklist: and nothing was saved by the refusals', false === $plugin->restoreChecklist()->isCustom() );

// ---- starting a drill -----------------------------------------------------------------------------------------------
$check( 'start: a draft cannot be rehearsed', $throws( fn() => $drills->start( (int) $draft['id'] ) ) instanceof ValidationError );
$check( 'start: a runbook without steps cannot be rehearsed', $throws( fn() => $drills->start( (int) $empty['id'] ) ) instanceof ValidationError );
$check( 'start: a runbook that does not exist cannot be rehearsed', $throws( fn() => $drills->start( 999999 ) ) instanceof ValidationError );
$drill = $drills->start( $rid );
$id    = (int) $drill['id'];
$check( 'start: a drill is made and is running', $id > 0 && DrillService::isRunning( $drill ) && 'drill' === $drill['kind'] );
$check( 'start: it follows the current version', 2 === (int) $drill['runbook_version'] && 4 === count( $drill['step_log']['steps'] ) );
$check( 'start: the steps are kept as they read, placeholders filled', false !== strpos( (string) $drill['step_log']['steps'][0]['text'], 'DRLTEST Registrar' ) && true === $drill['step_log']['steps'][1]['warning'] && 20 === $drill['step_log']['steps'][2]['minutes'] );
$check( 'start: a runbook that restores the site carries the after-restore checks', count( $drill['step_log']['checks'] ) === count( $plugin->restoreChecklist()->items() ) && 'both' === $drill['scope'] );
$check( 'start: nothing is ticked yet', array() === array_filter( array_merge( $drill['step_log']['steps'], $drill['step_log']['checks'] ), static fn( array $x ): bool => '' !== $x['done_at'] ) );
$check( 'start: starting again gives the same drill', $id === (int) $drills->start( $rid )['id'] && 1 === count( array_filter( $drills->all( $rid ), 'Estasoft\HandoverPassport\Services\DrillService::isRunning' ) ) );
$check( 'start: a drill is not a restore test', ! in_array( $id, array_map( 'intval', array_column( $plugin->restoreTests()->all(), 'id' ) ), true ) );
$plain = $drills->start( (int) $other['id'] );
$check( 'start: a runbook that restores nothing has no after-restore checks and no scope', array() === $plain['step_log']['checks'] && '' === $plain['scope'] );

// ---- ticking and notes ----------------------------------------------------------------------------------------------
$d = $drills->apply( $id, array( 'steps' => array( 0 => 'Took two tries' ) ), array( 'section' => 'steps', 'index' => 0, 'done' => true ) );
$check( 'tick: a step records the time and the name', '' !== $d['step_log']['steps'][0]['done_at'] && $admin_name === $d['step_log']['steps'][0]['by'] );
$check( 'tick: the note is saved with it', 'Took two tries' === $d['step_log']['steps'][0]['note'] );
$first = $d['step_log']['steps'][0]['done_at'];
sleep( 1 );
$d = $drills->apply( $id, array(), array( 'section' => 'steps', 'index' => 0, 'done' => true ) );
$check( 'tick: ticking a step again keeps the first time', $first === $d['step_log']['steps'][0]['done_at'] );
$d = $drills->apply( $id, array(), array( 'section' => 'steps', 'index' => 0, 'done' => false ) );
$check( 'untick: Undo clears the time and the name but keeps the note', '' === $d['step_log']['steps'][0]['done_at'] && '' === $d['step_log']['steps'][0]['by'] && 'Took two tries' === $d['step_log']['steps'][0]['note'] );
$check( 'tick: a step that does not exist is refused', $throws( fn() => $drills->apply( $id, array(), array( 'section' => 'steps', 'index' => 99, 'done' => true ) ) ) instanceof ValidationError );
$check( 'tick: a check can be ticked too', '' !== $drills->apply( $id, array(), array( 'section' => 'checks', 'index' => 0, 'done' => true ) )['step_log']['checks'][0]['done_at'] );
$d = $drills->apply( $id, array( 'steps' => array( 99 => 'ghost' ) ) );
$check( 'notes: a note for a step that does not exist is ignored', 4 === count( $d['step_log']['steps'] ) && false === strpos( wp_json_encode( $d ), 'ghost' ) );
$check( 'notes: a note over 500 characters is refused', $throws( fn() => $drills->apply( $id, array( 'steps' => array( 1 => str_repeat( 'n', 501 ) ) ) ) ) instanceof ValidationError );
$e = $throws( fn() => $drills->apply( $id, array( 'steps' => array( 1 => "token {$secret_text}" ) ) ) );
$check( 'notes: a note that looks like a secret is refused and not repeated', $e instanceof SecretFound && false === strpos( $e->getMessage(), $secret_text ) );
$check( 'notes: and it was not stored', false === strpos( wp_json_encode( $drills->find( $id ) ), $secret_text ) );
$check( 'notes: a drill that does not exist is refused', $throws( fn() => $drills->apply( 999999, array() ) ) instanceof ValidationError );

// ---- finishing ----------------------------------------------------------------------------------------------------------
$check( 'finish: Pass with steps left undone is refused and says how many', ( $e = $throws( fn() => $drills->finish( $id, 'pass', '' ) ) ) instanceof ValidationError && false !== strpos( $e->getMessage(), 'not ticked' ) );
$check( 'finish: an unknown result is refused', $throws( fn() => $drills->finish( $id, 'great', '' ) ) instanceof ValidationError );
$e = $throws( fn() => $drills->finish( $id, 'partial', "key {$secret_text}" ) );
$check( 'finish: a problem text that looks like a secret is refused and not repeated', $e instanceof SecretFound && false === strpos( $e->getMessage(), $secret_text ) );
$check( 'finish: the drill is still running after the refusals', DrillService::isRunning( (array) $drills->find( $id ) ) );
$before = $plugin->runbooks()->get( $rid )['last_rehearsed'];
$done   = $drills->finish( $id, 'partial', 'The staging host was slow' );
$check( 'finish: a partial result is recorded with the problems', 'partial' === $done['result'] && 'The staging host was slow' === $done['problems'] );
$check( 'finish: the total time is at least a minute', (int) $done['minutes'] >= 1 );
$check( 'finish: the people who ticked are named', false !== strpos( (string) $done['who'], $admin_name ) );
$check( 'finish: the finish time is kept', '' !== $done['step_log']['finished_at'] );
$check( 'finish: a partial drill counts as a rehearsal', null === $before && null !== $plugin->runbooks()->get( $rid )['last_rehearsed'] );
$check( 'finish: a finished drill cannot be changed', $throws( fn() => $drills->apply( $id, array(), array( 'section' => 'steps', 'index' => 1, 'done' => true ) ) ) instanceof ValidationError );
$check( 'finish: nor finished twice', $throws( fn() => $drills->finish( $id, 'pass', '' ) ) instanceof ValidationError );
$check( 'finish: nor thrown away', $throws( fn() => $drills->abandon( $id ) ) instanceof ValidationError );

// A passing drill needs every step ticked; a failing one does not count as a rehearsal.
$pid  = (int) $drills->start( (int) $other['id'] )['id'];
$ticked = $drills->apply( $pid, array(), array( 'section' => 'steps', 'index' => 0, 'done' => true ) );
$check( 'finish: the only step of a short runbook can be ticked', '' !== $ticked['step_log']['steps'][0]['done_at'] );
$done_plain = $drills->finish( $pid, 'pass', '' );
$check( 'finish: so Pass is recorded', 'pass' === $done_plain['result'] );
$other_before = $plugin->runbooks()->get( (int) $draft['id'] );
$plugin->runbooks()->update( (int) $draft['id'], array( 'status' => 'published' ) );
$fid = (int) $drills->start( (int) $draft['id'] )['id'];
$drills->finish( $fid, 'fail', 'Nothing worked' );
$check( 'finish: a failed drill does not count as a rehearsal', null === $plugin->runbooks()->get( (int) $draft['id'] )['last_rehearsed'] );

// ---- saving as a restore test (BKP-5) ---------------------------------------------------------------------------------
$check( 'save as test: a drill of a runbook that restores nothing cannot be saved', $throws( fn() => $drills->saveAsRestoreTest( $pid, array() ) ) instanceof ValidationError );
$run2 = $drills->start( $rid );
$check( 'save as test: a drill still going cannot be saved', $throws( fn() => $drills->saveAsRestoreTest( (int) $run2['id'], array() ) ) instanceof ValidationError );
$tests_before = count( $plugin->restoreTests()->all() );
$pass_before  = $plugin->restoreTests()->lastPass();
$test = $drills->saveAsRestoreTest( $id, array( 'place' => 'staging', 'backup_date' => Clock::today() ) );
$check( 'save as test: a restore test is made', (int) $test['id'] > 0 && $tests_before + 1 === count( $plugin->restoreTests()->all() ) );
$row = (array) $plugin->restoreTests()->repo()->find( (int) $test['id'] );
$check( 'save as test: it carries the result, time, people and problems of the drill', 'partial' === $row['result'] && (int) $done['minutes'] === (int) $row['minutes'] && $done['who'] === $row['who'] && 'The staging host was slow' === $row['problems'] );
$check( 'save as test: scope, place and backup date are set', 'both' === $row['scope'] && 'staging' === $row['place'] && Clock::today() === $row['backup_date'] );
$check( 'save as test: it names the runbook and the version the drill followed', $rid === (int) $row['runbook_id'] && 2 === (int) $row['runbook_version'] );
$check( 'save as test: the step log goes with it', is_array( $row['step_log'] ) && 4 === count( $row['step_log']['steps'] ) );
$check( 'save as test: the drill remembers the test', (int) $test['id'] === (int) $drills->find( $id )['step_log']['saved_test_id'] );
$check( 'save as test: it cannot be saved twice', $throws( fn() => $drills->saveAsRestoreTest( $id, array() ) ) instanceof ValidationError && $tests_before + 1 === count( $plugin->restoreTests()->all() ) );
$last = (array) $wpdb->get_row( 'SELECT source FROM ' . Tables::name( 'changes' ) . " WHERE kind = 'restore_test_logged' ORDER BY id DESC LIMIT 1", ARRAY_A );
$check( 'save as test: History says it came from a drill', 'drill' === ( $last['source'] ?? '' ) );
$check( 'save as test: a partial result does not move the date of the last Pass', $pass_before === $plugin->restoreTests()->lastPass() );

// ---- throwing a drill away -------------------------------------------------------------------------------------------
$gone = (int) $run2['id'];
$drills->abandon( $gone );
$check( 'abandon: a drill that is going can be thrown away', null === $drills->find( $gone ) );
$check( 'abandon: a second try is refused', $throws( fn() => $drills->abandon( $gone ) ) instanceof ValidationError );

// ---- listing and the log -------------------------------------------------------------------------------------------------
$all = $drills->all();
$check( 'list: drills are listed newest first', count( $all ) >= 3 && (int) $all[0]['id'] > (int) $all[1]['id'] );
$check( 'list: only the drills of one runbook can be asked for', array() === array_filter( $drills->all( $rid ), static fn( array $x ): bool => (int) $x['runbook_id'] !== $rid ) );
$logged = array_column( $plugin->changeLog()->repo()->query( array(), 'id', 'DESC', 80 ), 'kind' );
foreach ( array( 'drill_started', 'drill_finished', 'drill_abandoned', 'restore_test_logged' ) as $kind ) {
	$check( "log: {$kind} is written", in_array( $kind, $logged, true ) );
}

$cleanup();
$check( 'cleanup: no drill is left behind', array() === $drills->all( 0, 500 ) );
$check( 'cleanup: no restore test made from a drill is left behind', $tests_at_start === count( $plugin->restoreTests()->all() ) );
echo "\n" . ( $checks - $failures ) . ' of ' . $checks . " checks passed\n";
if ( $failures > 0 ) {
	exit( 1 );
}
