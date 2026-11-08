<?php
/**
 * Integration test for snapshots, the handover checklist and the status. Run on a WordPress test site:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/handover.php
 *
 * It writes records, snapshots and log rows to the site, so use a test site.
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Export\ExportOptions;
use Estasoft\HandoverPassport\Export\Profile;
use Estasoft\HandoverPassport\Findings\SnapshotIntegrity;
use Estasoft\HandoverPassport\Handover\HandoverChecklist;
use Estasoft\HandoverPassport\Passport\Passport;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\SecretFound;
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
$snaps = $plugin->snapshots();

// Remove what this test makes, now and at the end.
$cleanup = static function () use ( $plugin ): void {
	foreach ( $plugin->records()->everything() as $old ) {
		if ( 0 === stripos( (string) $old['title'], 'hndtest' ) ) {
			$plugin->records()->delete( (int) $old['id'] );
		}
	}
	foreach ( $plugin->snapshots()->all() as $old ) {
		if ( 0 === strpos( (string) $old['name'], 'HNDTEST' ) ) {
			$plugin->snapshots()->delete( (int) $old['id'] );
		}
	}
};
$cleanup();

// Data to put in the packs.
$plugin->passport()->update( array( 'site_name' => 'HNDTEST Site', 'client_name' => 'HNDTEST Client', 'status' => Passport::STATUS_ACTIVE ) );
$plain  = (int) $plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'hndtest-plain.example', 'expires_on' => '2027-03-01' ) )['id'];
$secret = (int) $plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'hndtest-secret.example', 'expires_on' => '2027-03-02', 'sensitive' => '1' ) )['id'];
$lic    = (int) $plugin->records()->create( Kinds::LICENCE, array( 'product' => 'HNDTEST Licence', 'renews_on' => '2027-04-01', 'account_email' => 'hnd-account@example.test', 'cost' => '12.50' ) )['id'];

$none  = new ExportOptions( false, array(), 1 );
$full  = new ExportOptions( true, array(), 1 );
$ticks = new ExportOptions( true, array( $secret ), 1 );

// Making a snapshot (HND-2).
$client_id = $snaps->create( Profile::CLIENT, 'HNDTEST client pack', 'First one', $none );
$check( 'a snapshot is made', $client_id > 0 );
$row = $snaps->get( $client_id );
$check( 'it stores canonical JSON and the SHA-256 of that exact text', null !== $row && hash( 'sha256', (string) $row['content'] ) === $row['hash'] && 64 === strlen( (string) $row['hash'] ) );
$check( 'it records the pack, the person and the plugin version', 'client' === $row['profile_key'] && 1 === (int) $row['created_by'] && '' !== (string) $row['plugin_version'] );
$check( 'the Client pack holds no Sensitive record and no Sensitive field', false === strpos( (string) $row['content'], 'hndtest-secret' ) && false === strpos( (string) $row['content'], 'hnd-account@example.test' ) );
$check( 'the Client pack holds the ordinary domain', false !== strpos( (string) $row['content'], 'hndtest-plain.example' ) );
$check( 'listing does not load the content', ! array_key_exists( 'content', $snaps->all()[0] ) );

$ver = $snaps->verify( $client_id );
$check( 'verify says the hash matches', true === $ver['ok'] && '' !== $ver['checked_at'] );
$check( 'verify keeps the result', true === ( (array) get_option( SnapshotIntegrity::OPTION, array() ) )[ $client_id ]['ok'] );
$check( 'verify of a missing snapshot gives null', null === $snaps->verify( 999999 ) );

// Names and notes are checked.
$e = $throws( fn() => $snaps->create( Profile::CLIENT, '   ', '', $none ) );
$check( 'a blank name is refused', $e instanceof ValidationError );
$e = $throws( fn() => $snaps->create( Profile::CLIENT, str_repeat( 'n', 191 ), '', $none ) );
$check( 'a name over 190 characters is refused', $e instanceof ValidationError );
$e = $throws( fn() => $snaps->create( 'nope', 'HNDTEST x', '', $none ) );
$check( 'an unknown pack is refused', $e instanceof ValidationError );
$before = count( $snaps->all() );
$e      = $throws( fn() => $snaps->create( Profile::CLIENT, 'HNDTEST bad', 'key AKIAIOSFODNN7EXAMPLE', $none ) );
$check( 'a secret in the note is refused', $e instanceof SecretFound );
$check( 'and the message does not repeat it', null !== $e && false === strpos( $e->getMessage(), 'AKIAIOSFODNN7EXAMPLE' ) );
$check( 'and nothing was stored', count( $snaps->all() ) === $before );

// Sensitive handling (EXP-4, SEC-5).
$tech_id = $snaps->create( Profile::TECHNICAL, 'HNDTEST technical, nothing ticked', '', $full );
$check( 'Technical pack without a tick leaves Sensitive records out', false === strpos( (string) $snaps->get( $tech_id )['content'], 'hndtest-secret' ) );
$ticked_id = $snaps->create( Profile::TECHNICAL, 'HNDTEST technical, ticked', '', $ticks );
$content   = $snaps->content( $ticked_id );
$check( 'Technical pack with a tick holds the record', false !== strpos( (string) $snaps->get( $ticked_id )['content'], 'hndtest-secret' ) );
$check( 'and lists it as included', in_array( 'item:' . $secret, array_column( (array) $content['sensitive_included'], 'ref' ), true ) );
$check( 'and the ticked pack still has no Sensitive field value', false === strpos( (string) $snaps->get( $ticked_id )['content'], 'hnd-account@example.test' ) );
$full_id = $snaps->create( Profile::FULL, 'HNDTEST full', '', $full );
$check( 'Full pack by someone who may holds the Sensitive field', false !== strpos( (string) $snaps->get( $full_id )['content'], 'hnd-account@example.test' ) );
$count = count( $snaps->all() );
$e     = $throws( fn() => $snaps->create( Profile::FULL, 'HNDTEST full, no right', '', $none ) );
$check( 'Full pack by someone who may not see Sensitive records is refused', $e instanceof \Estasoft\HandoverPassport\Export\ExportRefused && count( $snaps->all() ) === $count );
$tech_none = $snaps->create( Profile::TECHNICAL, 'HNDTEST technical, no right', '', $none );
$check( 'Technical pack by someone who may not holds no Sensitive value', false === strpos( (string) $snaps->get( $tech_none )['content'], 'hndtest-secret' ) && false === strpos( (string) $snaps->get( $tech_none )['content'], 'hnd-account@example.test' ) );
$e = $throws( fn() => $snaps->create( Profile::TECHNICAL, 'HNDTEST ticks ignored', '', new ExportOptions( false, array( $secret ), 1 ) ) );
$check( 'ticks from someone who may not are ignored', null === $e );

// No pack names an unticked Sensitive record anywhere, not even in a finding about it (AS-2).
$plugin->findings()->run( true );
foreach ( array( Profile::CLIENT, Profile::TECHNICAL, Profile::EMERGENCY, Profile::FULL ) as $key ) {
	$leak_id = $snaps->create( $key, 'HNDTEST leak check ' . $key, '', Profile::FULL === $key ? $full : $none );
	$text    = (string) $snaps->get( $leak_id )['content'];
	$check( "pack {$key}: the unticked Sensitive record is named nowhere", Profile::FULL === $key || false === strpos( $text, 'hndtest-secret' ) );
	$check( "pack {$key}: no Sensitive field value anywhere", Profile::FULL === $key || false === strpos( $text, 'hnd-account@example.test' ) );
}

// Immutable (HND-3).
$e = $throws( fn() => $snaps->repo()->update( $client_id, array( 'content' => '{}' ) ) );
$check( 'content cannot be changed through the repository', $e instanceof \LogicException );
$e = $throws( fn() => $snaps->repo()->update( $client_id, array( 'name' => 'renamed' ) ) );
$check( 'the name cannot be changed either', $e instanceof \LogicException );

// Tamper (AS-8, HP-HND-002).
$wpdb->update( $snaps->repo()->table(), array( 'content' => str_replace( 'hndtest-plain.example', 'hndtest-evil.example', (string) $snaps->get( $client_id )['content'] ) ), array( 'id' => $client_id ) );
$ver = $snaps->verify( $client_id );
$check( 'after tampering, verify says it does not match', false === $ver['ok'] && $ver['stored'] !== $ver['found'] );
$plugin->findings()->run( true );
$open = array_column( $plugin->findings()->list( array( 'state' => 'open' ) ), 'rule_id' );
$check( 'the integrity finding HP-HND-002 is raised', in_array( 'HP-HND-002', $open, true ) );
$check( 'the other snapshots still match', true === $snaps->verify( $tech_id )['ok'] && true === $snaps->verify( $full_id )['ok'] );

// Compare (HND-6).
$plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'hndtest-new.example', 'expires_on' => '2027-05-01' ) );
$plugin->records()->update( $plain, array( 'expires_on' => '2027-06-01' ) );
$diff = $snaps->compare( $tech_id, 'live', $full );
$check( 'compare with live lists the added record', null !== $diff && in_array( 'hndtest-new.example', array_column( $diff['added'], 'title' ), true ) );
$changed = null !== $diff ? array_column( $diff['changed'], 'title' ) : array();
$check( 'compare with live lists the changed record', in_array( 'hndtest-plain.example', $changed, true ), implode( ',', $changed ) );
$check( 'compare of a missing snapshot gives null', null === $snaps->compare( 999999, 'live', $full ) );
$both = $snaps->compare( $tech_id, $full_id, $full );
$check( 'compare of two snapshots works', null !== $both && array_key_exists( 'unchanged', $both ) );
$cut = $snaps->compare( $full_id, $tech_id, $none );
$check( 'someone without the Sensitive right sees no Sensitive value in a comparison', null !== $cut && false === strpos( wp_json_encode( $cut ), 'hnd-account@example.test' ) && false === strpos( wp_json_encode( $cut ), 'hndtest-secret' ) );
$check( 'and is told how many Sensitive records were skipped', null !== $cut && $cut['sensitive_skipped'] >= 1 );

// Acknowledge (HND-4, PRT-8).
$bad = array(
	'no name'     => array( 'name' => '', 'method' => 'email' ),
	'future date' => array( 'name' => 'Ann', 'method' => 'email', 'date' => gmdate( 'Y-m-d', time() + 86400 * 3 ) ),
	'bad date'    => array( 'name' => 'Ann', 'method' => 'email', 'date' => '2026-13-45' ),
	'bad method'  => array( 'name' => 'Ann', 'method' => 'carrier pigeon' ),
);
foreach ( $bad as $label => $input ) {
	$e = $throws( fn() => $snaps->acknowledge( $tech_id, $input ) );
	$check( "acknowledge refused: {$label}", $e instanceof ValidationError );
}
$e = $throws( fn() => $snaps->acknowledge( $tech_id, array( 'name' => 'Ann', 'method' => 'email', 'note' => 'token AKIAIOSFODNN7EXAMPLE' ) ) );
$check( 'a secret in the acknowledgement note is refused', $e instanceof SecretFound );
$e = $throws( fn() => $snaps->acknowledge( 999999, array( 'name' => 'Ann', 'method' => 'email' ) ) );
$check( 'acknowledging a missing snapshot is refused', $e instanceof ValidationError );
$check( 'still Active after refused attempts', Passport::STATUS_ACTIVE === $plugin->passport()->get( 'status' ) );

$snaps->acknowledge( $tech_id, array( 'name' => 'Ann Receiver', 'organisation' => 'Receiving Ltd', 'role' => 'Developer', 'date' => Clock::today(), 'method' => 'in_person', 'note' => 'Met in the office' ) );
$acks = $snaps->get( $tech_id )['acknowledgements'];
$check( 'the acknowledgement is stored with who recorded it', 1 === count( $acks ) && 'Ann Receiver' === $acks[0]['name'] && '' !== $acks[0]['recorded_by'] && '' !== $acks[0]['recorded_at'] );
$check( 'the Passport is now Handed over', Passport::STATUS_HANDED === $plugin->passport()->get( 'status' ) );
$check( 'content and hash are untouched by it', true === $snaps->verify( $tech_id )['ok'] );
$snaps->acknowledge( $tech_id, array( 'name' => 'Bo Second', 'method' => 'phone' ) );
$acks = $snaps->get( $tech_id )['acknowledgements'];
$check( 'a second acknowledgement is added, the first kept', 2 === count( $acks ) && 'Ann Receiver' === $acks[0]['name'] );

$log   = $plugin->changeLog()->repo()->query( array(), 'id', 'DESC', 60 );
$kinds = array_column( $log, 'kind' );
foreach ( array( 'snapshot_created', 'snapshot_acknowledged', 'status_changed' ) as $kind ) {
	$check( "change log has {$kind}", in_array( $kind, $kinds, true ) );
}

// Status (PRT-8).
$plugin->handoverStatus()->set( Passport::STATUS_ACTIVE, 'Back to work' );
$check( 'status can go back to Active', Passport::STATUS_ACTIVE === $plugin->passport()->get( 'status' ) );
$e = $throws( fn() => $plugin->handoverStatus()->set( 'finished' ) );
$check( 'an unknown status is refused', $e instanceof ValidationError );
$count = count( $plugin->changeLog()->repo()->query( array( 'kind' => 'status_changed' ), 'id', 'DESC', 100 ) );
$plugin->handoverStatus()->set( Passport::STATUS_ACTIVE );
$check( 'setting the same status writes no log row', $count === count( $plugin->changeLog()->repo()->query( array( 'kind' => 'status_changed' ), 'id', 'DESC', 100 ) ) );

// Delete (HND-3).
$hash = (string) $snaps->get( $tech_none )['hash'];
$snaps->delete( $tech_none );
$check( 'a snapshot can be deleted', null === $snaps->get( $tech_none ) );
$deleted = array_values( array_filter( $plugin->changeLog()->repo()->query( array( 'kind' => 'snapshot_deleted' ), 'id', 'DESC', 5 ), static fn( $r ) => 'HNDTEST technical, no right' === $r['subject_label'] ) );
$check( 'the log keeps its name, hash and time', 1 === count( $deleted ) && $hash === ( (array) $deleted[0]['before_data'] )['hash'] && '' !== (string) $deleted[0]['changed_at'] );
$e = $throws( fn() => $snaps->delete( $tech_none ) );
$check( 'deleting a missing snapshot is refused', $e instanceof ValidationError );

// Checklist (HND-1).
$list  = $plugin->handover()->items();
$check( 'the checklist has items', count( $list ) > 0 );
$keys = array_column( $list, 'key' );
$check( 'item keys are unique', count( $keys ) === count( array_unique( $keys ) ) );
$states = array_unique( array_column( $list, 'state' ) );
$check( 'every item has a known state', array() === array_diff( $states, array( HandoverChecklist::DONE, HandoverChecklist::NOT_DONE, HandoverChecklist::NA ) ) );
$custom = $plugin->handover()->addCustom( 'HNDTEST hand over the keys', 'Bob', '2027-01-01' );
$check( 'a custom item can be added', ! empty( $custom['id'] ) );
$plugin->handover()->setCustomState( (int) $custom['id'], 'done' );
$mine = array_values( array_filter( $plugin->handover()->items(), static fn( $i ) => false !== strpos( $i['label'], 'HNDTEST hand over the keys' ) ) );
$check( 'and marked Done', 1 === count( $mine ) && HandoverChecklist::DONE === $mine[0]['state'] );
$e = $throws( fn() => $plugin->handover()->setCustomState( (int) $custom['id'], 'maybe' ) );
$check( 'an unknown state is refused', $e instanceof ValidationError );
$rule_id = '';
foreach ( $plugin->handover()->items() as $item ) {
	if ( 0 === strpos( $item['key'], 'rule:' ) && HandoverChecklist::DONE !== $item['state'] ) {
		$rule_id = substr( $item['key'], 5 );
		break;
	}
}
if ( '' !== $rule_id ) {
	$e = $throws( fn() => $plugin->handover()->setRuleNotApplicable( $rule_id, true, '  ' ) );
	$check( 'Not applicable needs a reason', $e instanceof ValidationError );
	$plugin->handover()->setRuleNotApplicable( $rule_id, true, 'Not used on this site' );
	$na = array_values( array_filter( $plugin->handover()->items(), static fn( $i ) => 'rule:' . $rule_id === $i['key'] ) );
	$check( 'a rule item can be set Not applicable with a reason', 1 === count( $na ) && HandoverChecklist::NA === $na[0]['state'] );
	$before = $plugin->handover()->progress();
	$plugin->handover()->setRuleNotApplicable( $rule_id, false );
	$after = $plugin->handover()->progress();
	$check( 'and put back to automatic', $after['total'] === $before['total'] + 1 );
} else {
	$check( 'a rule item was available to test', false );
}

// Clean up.
$cleanup();
$plugin->passport()->update( array( 'status' => Passport::STATUS_DRAFT ) );
delete_option( SnapshotIntegrity::OPTION );
$plugin->findings()->run( true );

echo "\n{$checks} checks, {$failures} failed\n";
if ( $failures > 0 ) {
	exit( 1 );
}
