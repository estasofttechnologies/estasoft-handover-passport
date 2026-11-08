<?php
/**
 * Integration test for the weekly address check (URL-5). Run on a WordPress test site:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/weekly_urls.php
 *
 * It asks the home page of the test site a few questions over HTTP, so the site must answer on its own address. Mail is
 * captured with the pre_wp_mail filter and nothing is sent. It writes records and settings and puts them back.
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Checks\WeeklyUrlCheck;
use Estasoft\HandoverPassport\Data\Tables;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Install\Schedule;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\CopyGuard;

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

// Mail capture: nothing leaves the site.
$mails   = array();
$mail_ok = true;
add_filter(
	'pre_wp_mail',
	static function ( $pre, $atts ) use ( &$mails, &$mail_ok ) {
		if ( $mail_ok ) {
			$mails[] = $atts;
		}
		return $mail_ok;
	},
	10,
	2
);

$had_settings = get_option( 'estasoft_hp_settings', null );
$had_last     = get_option( WeeklyUrlCheck::OPTION, null );
$had_host     = get_option( CopyGuard::HOST_OPTION, null );
$had_state    = get_option( CopyGuard::STATE_OPTION, null );
$baseline     = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Tables::name( 'changes' ) );

// Put the addresses the site already holds aside, as if deleted, so the numbers below are the test's own.
$held = array_map( static fn( array $r ): int => (int) $r['id'], $plugin->records()->listKind( Kinds::URL, true ) );
foreach ( $held as $id ) {
	$plugin->records()->items()->softDelete( $id );
}
$plugin->settings()->set( 'reminder_emails', array( 'urltest-ops@example.test' ) );
update_option( CopyGuard::HOST_OPTION, $plugin->copyGuard()->currentHost() );
delete_option( CopyGuard::STATE_OPTION );
delete_option( WeeklyUrlCheck::OPTION );

$cleanup = static function () use ( $plugin, $wpdb, $baseline, $held, $had_settings, $had_last, $had_host, $had_state ): void {
	foreach ( $plugin->records()->listKind( Kinds::URL, true ) as $record ) {
		$plugin->records()->items()->delete( (int) $record['id'] );
	}
	foreach ( $held as $id ) {
		$plugin->records()->items()->restore( $id );
	}
	$wpdb->query( 'DELETE FROM ' . Tables::name( 'changes' ) . " WHERE id > {$baseline}" );
	foreach ( array( 'estasoft_hp_settings' => $had_settings, WeeklyUrlCheck::OPTION => $had_last, CopyGuard::HOST_OPTION => $had_host, CopyGuard::STATE_OPTION => $had_state ) as $name => $value ) {
		if ( null === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value, strpos( $name, 'settings' ) !== false );
		}
	}
	$plugin->reset();
};
// Each address may be asked once a minute; a test needs to ask again at once.
$reset_limits = static function () use ( $wpdb ): void {
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_estasoft\\_hp\\_chk\\_%' OR option_name LIKE '\\_transient\\_timeout\\_estasoft\\_hp\\_chk\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Test site clean-up.
};
$job   = static function () use ( $plugin ): WeeklyUrlCheck {
	$plugin->reset();
	return $plugin->weeklyUrls();
};
$url   = static function ( string $name, string $address, array $extra = array() ) use ( $plugin ): int {
	return (int) $plugin->records()->create( Kinds::URL, array_merge( array( 'name' => $name, 'address' => $address, 'expected_status' => 200 ), $extra ) )['id'];
};

// ---- off by default -----------------------------------------------------------------------------------------------
$plugin->settings()->set( 'url_check_weekly', false );
$check( 'the weekly check is off until someone switches it on', false === $job()->enabled() );
$r = $job()->run();
$check( 'while it is off it does nothing', 'disabled' === $r['state'] && 0 === $r['checked'] && array() === $mails );
$check( 'the cron event is bound to the job', false !== has_action( Schedule::URLS, array( $plugin, 'runWeeklyUrls' ) ) );

// ---- on, with nothing to check ------------------------------------------------------------------------------------
$plugin->settings()->set( 'url_check_weekly', true );
$check( 'it can be switched on', true === $job()->enabled() );
$r = $job()->run();
$check( 'with no addresses there is nothing to check and no email', 'none' === $r['state'] && array() === $mails );

// ---- an address that answers --------------------------------------------------------------------------------------
$good = $url( 'URLTEST home', home_url( '/' ) );
$r    = $job()->run();
$check( 'an address that answers is checked and not mailed', 'ok' === $r['state'] && 1 === $r['checked'] && 0 === $r['failed'] && array() === $mails, json_encode( $r ) );
$last = $job()->last();
$check( 'the run is remembered', 'ok' === $last['state'] && 1 === $last['checked'] && '' !== $last['at'] );
$stored = $plugin->records()->get( $good, true );
$check( 'the result is written to the address like a manual check', ! empty( $stored['fields']['last_check']['ok'] ) );

// ---- one that fails -----------------------------------------------------------------------------------------------
$bad = $url( 'URLTEST missing page', home_url( '/?p=999999' . wp_rand( 100, 999 ) . '&urltest=missing' ) );
$reset_limits();
$r = $job()->run( true );
$check( 'a dry run finds the failure but sends nothing', 'dry_run' === $r['state'] && 1 === $r['failed'] && 2 === $r['checked'] && array() === $mails, json_encode( $r ) );
$check( 'a dry run does not touch the remembered run', 'ok' === $job()->last()['state'] );

$reset_limits();
$r = $job()->run();
$check( 'a failing address is mailed once', 'sent' === $r['state'] && 1 === $r['failed'] && 1 === count( $mails ), json_encode( $r ) . ' mails=' . count( $mails ) );
$mail = $mails[0] ?? array( 'to' => '', 'subject' => '', 'message' => '' );
$check( 'to the reminder recipients', 'urltest-ops@example.test' === ( is_array( $mail['to'] ) ? $mail['to'][0] : $mail['to'] ) );
$check( 'the subject counts the addresses', false !== strpos( (string) $mail['subject'], '1 address did not answer as expected' ), (string) $mail['subject'] );
$check( 'the email names the failing address and its link', false !== strpos( (string) $mail['message'], 'URLTEST missing page' ) && false !== strpos( (string) $mail['message'], 'urltest=missing' ) );
$check( 'the email does not name the one that answered', false === strpos( (string) $mail['message'], 'URLTEST home' ) );
$check( 'the email says what was found', false !== strpos( (string) $mail['message'], '404' ) || false !== strpos( (string) $mail['message'], 'expected' ), (string) $mail['message'] );
$check( 'the email says it is not an uptime monitor', false !== strpos( (string) $mail['message'], 'not an uptime monitor' ) );
$check( 'the email holds no header-injection characters in the subject', 1 !== preg_match( '/[\r\n]/', (string) $mail['subject'] ) );
$last = $job()->last();
$check( 'the failed run is remembered', 'sent' === $last['state'] && 1 === $last['failed'] );

// ---- a Sensitive address is checked but not named -----------------------------------------------------------------
$secret = $url( 'URLTEST secret page', home_url( '/?p=999998' . wp_rand( 100, 999 ) . '&urltest=secret-missing' ), array( 'sensitive' => '1' ) );
$mails  = array();
$reset_limits();
$r = $job()->run();
$body = (string) ( $mails[0]['message'] ?? '' );
$check( 'both failures are counted', 2 === $r['failed'], json_encode( $r ) );
$check( 'the Sensitive address is not named in the email', false === strpos( $body, 'URLTEST secret' ) && false === strpos( $body, 'urltest=secret-missing' ) && false !== strpos( $body, 'marked Sensitive' ), $body );
$check( 'the plain one still is', false !== strpos( $body, 'URLTEST missing page' ) );

// ---- an address on another host is refused, not failed ------------------------------------------------------------
$plugin->records()->items()->delete( $bad );
$plugin->records()->items()->delete( $secret );
$other = $url( 'URLTEST elsewhere', 'https://not-this-site.example/' );
$mails = array();
$reset_limits();
$r = $job()->run();
$check( 'an address on another host is refused, neither checked nor failed, and nothing is mailed', 1 === $r['checked'] && 0 === $r['failed'] && array() === $mails, json_encode( $r ) );

// ---- mail that cannot be delivered --------------------------------------------------------------------------------
$bad2    = $url( 'URLTEST missing again', home_url( '/?p=999997' . wp_rand( 100, 999 ) . '&urltest=missing-again' ) );
$mail_ok = false;
$reset_limits();
$r = $job()->run();
$check( 'when no email can be delivered the run says so', 'mail_failed' === $r['state'] && 'mail_failed' === $job()->last()['state'], json_encode( $r ) );
$mail_ok = true;

// ---- a copy of the site -------------------------------------------------------------------------------------------
update_option( CopyGuard::HOST_OPTION, 'original-site.example' );
$plugin->copyGuard()->check();
$mails = array();
$reset_limits();
$r = $job()->run();
$check( 'on a copy of the site nothing is checked or mailed', 'paused' === $r['state'] && 0 === $r['checked'] && array() === $mails, json_encode( $r ) );

$cleanup();
$check( 'the addresses made by this test are gone', array() === array_filter( $plugin->records()->listKind( Kinds::URL, true ), static fn( array $r ): bool => 0 === strpos( (string) $r['title'], 'URLTEST' ) ) );
$check( 'the addresses the site had are back', count( $held ) === count( $plugin->records()->listKind( Kinds::URL, true ) ) );

echo "\n{$checks} checks, {$failures} failed.\n";
exit( 0 === $failures ? 0 : 1 );
