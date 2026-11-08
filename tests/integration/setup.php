<?php
/**
 * Integration test for the setup guide (ONB-2, ONB-3, ONB-4). Run on a WordPress test site:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/setup.php
 *
 * It writes runbooks, records and log entries to the site and puts the Passport back as it was, so use a test site.
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Data\Tables;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Passport\Passport;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Setup\SetupService;

$failures = 0;
$checks   = 0;
$check    = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures, &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? '  [' . $detail . ']' : '' ) . "\n";
};
$throws = static function ( callable $fn, string $class ): bool {
	try {
		$fn();
	} catch ( Throwable $e ) {
		return $e instanceof $class;
	}
	return false;
};

global $wpdb;
$plugin = Plugin::instance();
$plugin->reset();
wp_set_current_user( 1 );

$had_option = get_option( Passport::OPTION, null );
$baseline   = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Tables::name( 'changes' ) );
$live_books = array_map( static fn( array $b ): int => (int) $b['id'], $plugin->runbooks()->all() );
$live_urls  = array_map( static fn( array $r ): int => (int) $r['id'], $plugin->records()->listKind( Kinds::URL, true ) );

// Put what the site already holds aside (as if deleted) so the suggestions have room, and bring it back at the end.
foreach ( $live_books as $id ) {
	$plugin->runbooks()->repo()->softDelete( $id );
}
foreach ( $live_urls as $id ) {
	$plugin->records()->items()->softDelete( $id );
}

$fresh = static function () use ( $plugin ): SetupService {
	$plugin->reset();
	return $plugin->setup();
};
$wipe  = static function () use ( $plugin ): void {
	foreach ( $plugin->runbooks()->all() as $book ) {
		$plugin->runbooks()->repo()->purge( (int) $book['id'] );
	}
	foreach ( $plugin->records()->listKind( Kinds::URL, true ) as $url ) {
		$plugin->records()->items()->delete( (int) $url['id'] );
	}
};

$cleanup = static function () use ( $plugin, $wpdb, $baseline, $had_option, $live_books, $live_urls ): void {
	foreach ( $plugin->runbooks()->all() as $book ) {
		$plugin->runbooks()->repo()->purge( (int) $book['id'] );
	}
	foreach ( $plugin->records()->listKind( Kinds::URL, true ) as $url ) {
		$plugin->records()->items()->delete( (int) $url['id'] );
	}
	foreach ( $live_books as $id ) {
		$plugin->runbooks()->repo()->restore( $id );
	}
	foreach ( $live_urls as $id ) {
		$plugin->records()->items()->restore( $id );
	}
	$wpdb->query( 'DELETE FROM ' . Tables::name( 'changes' ) . " WHERE id > {$baseline}" );
	if ( null === $had_option ) {
		delete_option( Passport::OPTION );
	} else {
		update_option( Passport::OPTION, $had_option, false );
	}
	$plugin->reset();
};

// Start from a Passport that has never been through the guide, on a site with none of the suggested records.
update_option( Passport::OPTION, array_replace( Passport::defaults(), array( 'client_name' => '' ) ), false );
$wipe();
$setup = $fresh();

// ---- the steps ----------------------------------------------------------------------------------------------------
$steps = $setup->steps();
$check( 'there are seven steps in a fixed order', array_keys( $steps ) === SetupService::STEPS && 7 === count( $steps ) );
$check( 'every step has a state, an auto flag and a detail', array() === array_filter( $steps, static fn( $s ): bool => ! isset( $s['state'], $s['auto'], $s['detail'] ) ) );
$check( 'the about step is open while no client or owner is named', SetupService::TODO === $steps['about']['state'] && false === $steps['about']['auto'] );
$check( 'the review step never completes by itself', false === $steps['review']['auto'] );
$check( 'the guide is not complete', ! $setup->complete() && in_array( 'review', $setup->remaining(), true ) );

// ---- done, skipped and picked up again ----------------------------------------------------------------------------
$setup->skip( 'review' );
$setup = $fresh();
$check( 'a skipped step is marked skipped', SetupService::SKIPPED === $setup->steps()['review']['state'] );
$check( 'a skipped step stays on the list of things to do', in_array( 'review', $setup->remaining(), true ) );
$setup->reopen( 'review' );
$setup = $fresh();
$check( 'a reopened step is open again', SetupService::TODO === $setup->steps()['review']['state'] );
$setup->markDone( 'review' );
$setup = $fresh();
$check( 'a step marked done counts as done', SetupService::DONE === $setup->steps()['review']['state'] && ! in_array( 'review', $setup->remaining(), true ) );
$setup->skip( 'review' );
$setup = $fresh();
$check( 'skipping takes a step off the done list', SetupService::SKIPPED === $setup->steps()['review']['state'] );
$setup->skip( 'no_such_step' );
$setup->markDone( 'no_such_step' );
$stored = (array) $plugin->passport()->get( 'setup', array() );
$check( 'an unknown step key is ignored', ! in_array( 'no_such_step', (array) ( $stored['done'] ?? array() ), true ) && ! in_array( 'no_such_step', (array) ( $stored['skipped'] ?? array() ), true ) );
$setup->reopen( 'review' );
$setup->skip( 'scan' );
$setup->markDone( 'scan' );
$setup = $fresh();
$check( 'a step cannot be on both lists', array() === array_intersect( (array) $plugin->passport()->get( 'setup' )['done'], (array) $plugin->passport()->get( 'setup' )['skipped'] ) );
$setup->reopen( 'scan' );

// ---- the details of the Passport ----------------------------------------------------------------------------------
$before_rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'changes' ) . " WHERE id > {$baseline}" );
$setup->saveAbout(
	array(
		'site_name'   => 'Setuptest Bakery',
		'client_name' => 'Setuptest Ltd',
		'purpose'     => 'Sells bread online.',
		'type'        => 'store',
		'role'        => 'agency',
		'brand_color' => '#123abc',
		'logo_id'     => 0,
	)
);
$setup    = $fresh();
$passport = $plugin->passport();
$check( 'the names and purpose are saved', 'Setuptest Bakery' === $passport->get( 'site_name' ) && 'Setuptest Ltd' === $passport->get( 'client_name' ) && 'Sells bread online.' === $passport->get( 'purpose' ) );
$check( 'the colour is saved', '#123abc' === $passport->get( 'brand_color' ) );
$check( 'the kind of site and the role are kept', array( 'type' => 'store', 'role' => 'agency' ) === $setup->choices() );
$check( 'the about step is done once a client or owner is named', SetupService::DONE === $setup->steps()['about']['state'] && true === $setup->steps()['about']['auto'] );
$rows = $wpdb->get_results( 'SELECT * FROM ' . Tables::name( 'changes' ) . " WHERE id > {$baseline} AND kind = 'passport_details_changed'", ARRAY_A );
$check( 'one change log entry records the edit', 1 === count( $rows ) && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'changes' ) . " WHERE id > {$baseline}" ) === $before_rows + 1, (string) count( $rows ) );
$check( 'the log entry holds the new client name', isset( $rows[0] ) && false !== strpos( (string) $rows[0]['after_data'], 'Setuptest Ltd' ) );
$check( 'the log entry holds the old colour as well as the new one', isset( $rows[0] ) && false !== strpos( (string) $rows[0]['before_data'], '#2f4858' ) && false !== strpos( (string) $rows[0]['after_data'], '#123abc' ) );
$check( 'the log entry leaves out what did not change', isset( $rows[0] ) && false === strpos( (string) $rows[0]['after_data'], 'logo_id' ) );

$setup->saveAbout(
	array(
		'site_name'   => 'Setuptest Bakery',
		'client_name' => 'Setuptest Ltd',
		'purpose'     => 'Sells bread online.',
		'type'        => 'store',
		'role'        => 'agency',
		'brand_color' => '#123abc',
		'logo_id'     => 0,
	)
);
$same = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'changes' ) . " WHERE id > {$baseline} AND kind = 'passport_details_changed'" );
$check( 'saving the same values again adds nothing to the log', 1 === $same, (string) $same );

$setup->saveAbout( array( 'site_name' => '', 'client_name' => 'Setuptest Ltd', 'type' => 'store', 'role' => 'agency' ) );
$check( 'an empty site name falls back to the site title', (string) get_option( 'blogname', '' ) === $plugin->passport()->get( 'site_name' ) );
$check( 'an empty colour keeps the colour that was set', '#123abc' === $plugin->passport()->get( 'brand_color' ) );

// ---- what is refused ----------------------------------------------------------------------------------------------
$good = array( 'site_name' => 'Setuptest', 'client_name' => 'Setuptest Ltd', 'type' => 'store', 'role' => 'agency' );
$check( 'a kind of site that is not listed is refused', $throws( static fn() => $setup->saveAbout( array_replace( $good, array( 'type' => 'spaceship' ) ) ), ValidationError::class ) );
$check( 'a role that is not listed is refused', $throws( static fn() => $setup->saveAbout( array_replace( $good, array( 'role' => 'emperor' ) ) ), ValidationError::class ) );
$check( 'a colour that is not a colour is refused', $throws( static fn() => $setup->saveAbout( array_replace( $good, array( 'brand_color' => 'blue' ) ) ), ValidationError::class ) );
$check( 'a colour with markup is refused', $throws( static fn() => $setup->saveAbout( array_replace( $good, array( 'brand_color' => '#fff;}</style>' ) ) ), ValidationError::class ) );
$check( 'an attachment that is not an image is refused', $throws( static fn() => $setup->saveAbout( array_replace( $good, array( 'logo_id' => 99999999 ) ) ), ValidationError::class ) );
$check( 'a password in the purpose is refused', $throws( static fn() => $setup->saveAbout( array_replace( $good, array( 'purpose' => 'Admin password: Tr0ub4dor&3xyz!' ) ) ), SecretFound::class ) );
$check( 'a key in the client name is refused', $throws( static fn() => $setup->saveAbout( array_replace( $good, array( 'client_name' => 'sk_' . 'live_' . '4eC39HqLyjWDarjtT1zdp7dc' ) ) ), SecretFound::class ) );
$plugin->reset();
$check( 'a refused save changes nothing', 'Setuptest Ltd' === $plugin->passport()->get( 'client_name' ) && '#123abc' === $plugin->passport()->get( 'brand_color' ) && 'store' === $fresh()->choices()['type'] );

// ---- the logo -----------------------------------------------------------------------------------------------------
$logo = wp_insert_attachment(
	array(
		'post_title'     => 'setuptest logo',
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
	),
	'setuptest-logo.png'
);
update_attached_file( $logo, 'setuptest-logo.png' );
$setup->saveAbout( array_replace( $good, array( 'logo_id' => $logo ) ) );
$plugin->reset();
$check( 'an image from the library is accepted as the logo', $logo > 0 && $logo === (int) $plugin->passport()->get( 'logo_id' ) );
$setup = $fresh();
$setup->saveAbout( array_replace( $good, array( 'logo_id' => 0 ) ) );
$check( 'the logo can be removed', 0 === (int) $plugin->passport()->get( 'logo_id' ) );
wp_delete_attachment( $logo, true );

// ---- suggestions --------------------------------------------------------------------------------------------------
$setup  = $fresh();
$none   = $setup->suggestions( 'spaceship' );
$check( 'an unknown kind of site suggests nothing', array() === $none['runbooks'] && array() === $none['urls'] );
$templates = Estasoft\HandoverPassport\Services\RunbookService::templates();
$all_exist = true;
foreach ( SetupService::TYPES as $type ) {
	$s = $setup->suggestions( $type );
	foreach ( $s['runbooks'] as $key ) {
		$all_exist = $all_exist && isset( $templates[ $key ] );
	}
	$check( "'{$type}' suggests the four basics", array() === array_diff( array( 'restore_site', 'restore_database', 'rollback_update', 'contact_tree' ), $s['runbooks'] ) );
	$check( "'{$type}' suggests the home page", home_url( '/' ) === $s['urls'][0]['address'] );
}
$check( 'every suggested runbook is a template that exists', $all_exist );
$check( 'a membership site gets the log in page', in_array( wp_login_url(), array_column( $setup->suggestions( 'membership' )['urls'], 'address' ), true ) );
$check( 'a blog gets the feed', in_array( (string) get_bloginfo( 'rss2_url' ), array_column( $setup->suggestions( 'blog' )['urls'], 'address' ), true ) );
$check( 'a brochure site gets only the home page', 1 === count( $setup->suggestions( 'brochure' )['urls'] ) );

// ---- applying them ------------------------------------------------------------------------------------------------
$wipe();
$setup = $fresh();
$setup->saveAbout( array_replace( $good, array( 'type' => '' ) ) );
$check( 'nothing is added until a kind of site is chosen', $throws( static fn() => $setup->applySuggestions(), ValidationError::class ) );
$setup->saveAbout( array_replace( $good, array( 'type' => 'membership' ) ) );
$setup  = $fresh();
$first  = $setup->applySuggestions();
$wanted = $setup->suggestions( 'membership' );
$check( 'the suggested runbooks are created', count( $wanted['runbooks'] ) === $first['runbooks'], json_encode( $first ) );
$check( 'the suggested addresses are created', count( $wanted['urls'] ) === $first['urls'], json_encode( $first ) );
$keys = array_column( $plugin->runbooks()->all(), 'template_key' );
$check( 'each runbook remembers its template', array() === array_diff( $wanted['runbooks'], $keys ) );
$addresses = array();
foreach ( $plugin->records()->listKind( Kinds::URL, true ) as $record ) {
	$addresses[] = (string) $record['fields']['address'];
}
$check( 'the addresses are stored as critical URLs', array() === array_diff( array_column( $wanted['urls'], 'address' ), $addresses ) );
$again = $fresh()->applySuggestions();
$check( 'applying twice adds nothing the second time', 0 === $again['runbooks'] && 0 === $again['urls'], json_encode( $again ) );
$count_books = count( $plugin->runbooks()->all() );
$fresh()->applySuggestions();
$check( 'the number of runbooks does not grow', count( $plugin->runbooks()->all() ) === $count_books );
$plugin->runbooks()->repo()->purge( (int) $plugin->runbooks()->all()[0]['id'] );
$third = $fresh()->applySuggestions();
$check( 'a runbook that was deleted comes back, nothing else does', 1 === $third['runbooks'] && 0 === $third['urls'], json_encode( $third ) );

// A critical URL added by hand, with a trailing slash the other way, is not added a second time.
$plugin->records()->items()->delete( (int) $plugin->records()->listKind( Kinds::URL, true )[0]['id'] );
foreach ( $plugin->records()->listKind( Kinds::URL, true ) as $url ) {
	$plugin->records()->items()->delete( (int) $url['id'] );
}
$plugin->records()->create( Kinds::URL, array( 'name' => 'My own home', 'address' => untrailingslashit( home_url( '/' ) ), 'expected_status' => 200 ) );
$fourth = $fresh()->applySuggestions();
$check( 'an address that is already recorded is not added again, with or without the slash', $fourth['urls'] === count( $wanted['urls'] ) - 1, json_encode( $fourth ) );

// ---- steps that complete themselves -------------------------------------------------------------------------------
$setup = $fresh();
$check( 'the backups step reports the runbooks it sees', '' !== $setup->steps()['backups']['detail'] && false !== strpos( $setup->steps()['backups']['detail'], (string) count( $plugin->runbooks()->all() ) ) );

$cleanup();
$check( 'the Passport is put back as it was', ( null === $had_option ? false === get_option( Passport::OPTION, false ) : $had_option === get_option( Passport::OPTION ) ) );
$check( 'the runbooks are as they were', array_map( static fn( array $b ): int => (int) $b['id'], $plugin->runbooks()->all() ) === $live_books );

echo "\n{$checks} checks, {$failures} failed.\n";
exit( 0 === $failures ? 0 : 1 );
