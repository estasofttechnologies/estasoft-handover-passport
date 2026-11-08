<?php
/**
 * Integration test for the "since the handover" count (HND-8). Run on a WordPress test site:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/since_handover.php
 *
 * It writes records, snapshots and log entries to the site and removes them again, so use a test site.
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Data\Tables;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Export\ExportOptions;
use Estasoft\HandoverPassport\Export\Profile;
use Estasoft\HandoverPassport\Handover\SinceHandover;
use Estasoft\HandoverPassport\Passport\Passport;
use Estasoft\HandoverPassport\Plugin;

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
$plugin->reset();
wp_set_current_user( 1 );
$snaps      = $plugin->snapshots();
$had_option = get_option( Passport::OPTION, null );
$baseline   = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Tables::name( 'changes' ) );

$cleanup = static function () use ( $plugin, $wpdb, $baseline, $had_option ): void {
	foreach ( $plugin->records()->everything() as $old ) {
		if ( 0 === stripos( (string) $old['title'], 'sincetest' ) ) {
			$plugin->records()->items()->delete( (int) $old['id'] );
		}
	}
	foreach ( $plugin->records()->items()->deleted() as $old ) {
		if ( 0 === stripos( (string) $old['title'], 'sincetest' ) ) {
			$plugin->records()->items()->delete( (int) $old['id'] );
		}
	}
	foreach ( $plugin->snapshots()->all() as $old ) {
		if ( 0 === strpos( (string) $old['name'], 'SINCETEST' ) ) {
			$plugin->snapshots()->delete( (int) $old['id'] );
		}
	}
	$wpdb->query( 'DELETE FROM ' . Tables::name( 'changes' ) . " WHERE id > {$baseline}" );
	if ( null === $had_option ) {
		delete_option( Passport::OPTION );
	} else {
		update_option( Passport::OPTION, $had_option, false );
	}
	SinceHandover::forget();
	$plugin->reset();
};
$cleanup();
SinceHandover::forget();

$since = static fn(): SinceHandover => new SinceHandover( Plugin::instance() );
$full  = new ExportOptions( true, array(), 1 );

// ---- before anything is acknowledged ------------------------------------------------------------------------------
$already = $since()->snapshot();
if ( null === $already ) {
	$check( 'with no acknowledged snapshot there is nothing to count', null === $since()->get() );
} else {
	echo "SKIP  the site already holds an acknowledged snapshot ({$already['id']}), so the empty case is not checked\n";
}

// ---- a snapshot that nobody acknowledged is not a handover --------------------------------------------------------
$kept   = (int) $plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'sincetest-kept.example', 'expires_on' => '2028-03-01' ) )['id'];
$edited = (int) $plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'sincetest-edited.example', 'expires_on' => '2028-03-02' ) )['id'];
$gone   = (int) $plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'sincetest-gone.example', 'expires_on' => '2028-03-03' ) )['id'];
$first  = $snaps->create( Profile::FULL, 'SINCETEST unacknowledged', '', $full );
$plugin->reset();
$now = $since()->snapshot();
$check( 'a snapshot nobody acknowledged is not counted as a handover', null === $now || $now['id'] !== $first );

// ---- an acknowledged one ------------------------------------------------------------------------------------------
$acked = $snaps->create( Profile::FULL, 'SINCETEST handed over', '', $full );
$snaps->acknowledge( $acked, array( 'name' => 'Sam Receiver', 'method' => 'email', 'date' => gmdate( 'Y-m-d' ) ) );
$plugin->reset();
$snap = $since()->snapshot();
$check( 'the acknowledged snapshot is found', null !== $snap && $acked === $snap['id'] && 'SINCETEST handed over' === $snap['name'], json_encode( $snap ) );
$check( 'with the date it was acknowledged', null !== $snap && gmdate( 'Y-m-d' ) === $snap['acknowledged_on'] );

$later = $snaps->create( Profile::FULL, 'SINCETEST made later, not acknowledged', '', $full );
$plugin->reset();
$snap = $since()->snapshot();
$check( 'a newer snapshot without an acknowledgement does not take its place', null !== $snap && $acked === $snap['id'] );

$r = $since()->get();
$check( 'right after the handover nothing differs', null !== $r && 0 === $r['total'] && 0 === $r['added'] && 0 === $r['removed'] && 0 === $r['changed'] && false === $r['notes_changed'], json_encode( $r ) );
$check( 'the answer is kept for a while', is_array( get_transient( SinceHandover::TRANSIENT ) ) );

// Findings and the checklist are worked out from the records; checking them again is not a change to a record.
$plugin->findings()->run( true );
SinceHandover::forget();
$plugin->reset();
$r = $since()->get();
$check( 'checking the findings again does not count as a change', null !== $r && 0 === $r['total'], json_encode( $r ) );

// ---- drift --------------------------------------------------------------------------------------------------------
$plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'sincetest-new.example', 'expires_on' => '2028-04-01' ) );
$plugin->reset();
$r = $since()->get();
$check( 'a new record is counted as added at once', null !== $r && 1 === $r['added'] && 1 === $r['total'], json_encode( $r ) );

$plugin->records()->update( $edited, array( 'domain' => 'sincetest-edited.example', 'expires_on' => '2028-09-09' ) );
$plugin->reset();
$r = $since()->get();
$check( 'an edited record is counted as changed', null !== $r && 1 === $r['changed'] && 2 === $r['total'], json_encode( $r ) );

$plugin->records()->delete( $gone );
$plugin->reset();
$r = $since()->get();
$check( 'a deleted record is counted as removed', null !== $r && 1 === $r['removed'] && 3 === $r['total'], json_encode( $r ) );

$plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'sincetest-secret.example', 'expires_on' => '2028-05-01', 'sensitive' => '1' ) );
$plugin->reset();
$r = $since()->get();
$check( 'a Sensitive record is never counted', null !== $r && 3 === $r['total'] && 1 === $r['added'], json_encode( $r ) );

$again = $since()->get();
$check( 'asking again gives the same answer', $again === $r );

$check( 'the count holds only numbers and the snapshot, no record names', false === strpos( (string) json_encode( $r ), 'sincetest-new' ) && false === strpos( (string) json_encode( $r ), 'sincetest-secret' ) );

// ---- a snapshot that is deleted -----------------------------------------------------------------------------------
$snaps->delete( $acked );
$plugin->reset();
$gone_snap = $since()->snapshot();
$check( 'once the acknowledged snapshot is deleted it is no longer the reference', null === $gone_snap || $gone_snap['id'] !== $acked );

$cleanup();
$check( 'the records made by this test are gone', array() === array_filter( $plugin->records()->everything(), static fn( array $row ): bool => 0 === stripos( (string) $row['title'], 'sincetest' ) ) );
$check( 'the snapshots made by this test are gone', array() === array_filter( $plugin->snapshots()->all(), static fn( array $row ): bool => 0 === strpos( (string) $row['name'], 'SINCETEST' ) ) );
$check( 'the kept answer is forgotten', false === get_transient( SinceHandover::TRANSIENT ) );

echo "\n{$checks} checks, {$failures} failed.\n";
exit( 0 === $failures ? 0 : 1 );
