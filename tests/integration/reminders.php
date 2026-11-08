<?php
/**
 * Integration test for reminders (RMD-1 to RMD-5, LIC-7, IMP-4, AS-4, AS-5). Run on a test site with the plugin active:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/reminders.php
 *
 * The clock is frozen and mail is captured with the pre_wp_mail filter, so nothing is sent. It writes records and then
 * removes them again.
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Data\LinkRepository;
use Estasoft\HandoverPassport\Data\ReminderRepository;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\CopyGuard;
use Estasoft\HandoverPassport\Services\ReminderService;
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

$plugin = Plugin::instance();
wp_set_current_user( 1 );

// Mail capture: nothing leaves the site.
$mails     = array();
$mail_ok   = true;
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

$reminders = static fn(): ReminderService => $plugin->reminders();
$at        = static function ( string $day ): void {
	Clock::freeze( $day . ' 09:00:00' );
};
$mails_for = static function ( string $needle ) use ( &$mails ): array {
	return array_values( array_filter( $mails, static fn( array $m ): bool => false !== strpos( (string) $m['subject'], $needle ) ) );
};
$licence   = static function ( string $name, string $due, array $extra = array() ) use ( $plugin ): int {
	return (int) $plugin->records()->create( Kinds::LICENCE, array_merge( array( 'product' => $name, 'renews_on' => $due ), $extra ) )['id'];
};
$created   = array();
$people    = array();

// Start from a known state.
$original_settings = get_option( 'estasoft_hp_settings' );
$original_host     = get_option( CopyGuard::HOST_OPTION );
$plugin->settings()->update( array( 'reminders_enabled' => true, 'digest_enabled' => false ) );
$plugin->settings()->set( 'reminder_emails', array( 'ops@example.test' ) );
delete_option( ReminderService::HEALTH_OPTION );
delete_option( ReminderService::DIGEST_OPTION );
delete_option( CopyGuard::STATE_OPTION );
update_option( CopyGuard::HOST_OPTION, $plugin->copyGuard()->currentHost() );
( new ReminderRepository() );
$wpdb_reminders = ( new ReminderRepository() )->table();
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb_reminders}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Test site clean-up.

// ---- AS-4: a licence due in 14 days, a faked clock, three recipients -------------------------------------------------
$holder = $plugin->people()->create( array( 'name' => 'Holly Holder', 'email' => 'holder@example.test' ) );
$payer  = $plugin->people()->create( array( 'name' => 'Pat Payer', 'email' => 'payer@example.test' ) );
$gone   = $plugin->people()->create( array( 'name' => 'Lee Left', 'email' => 'left@example.test', 'left_on' => '2026-01-01' ) );
$people = array( $holder['id'], $payer['id'], $gone['id'] );

$a_id = $licence( 'RMDTEST Licence A', '2026-05-15', array( 'account_holder' => array( $holder['id'], $gone['id'] ), 'payer' => array( $payer['id'] ) ) );
$created[] = $a_id;

$at( '2026-04-20' );
$mails = array();
$at( '2026-05-01' );
$dry = $reminders()->run( true );
$check( 'dry run lists one reminder', 1 === count( array_filter( $dry['planned'], static fn( $p ) => (int) $p['id'] === $a_id ) ), wp_json_encode( $dry ) );
$check( 'dry run sends nothing and logs nothing', array() === $mails && 0 === count( ( new ReminderRepository() )->query( array( 'item_id' => $a_id ) ) ) );

$report = $reminders()->run();
$mine   = $mails_for( 'RMDTEST Licence A' );
$check( 'AS-4: one reminder for the 14-day lead', 1 === $report['sent'] && 3 === count( $mine ), wp_json_encode( $report ) . ' mails=' . count( $mine ) );
$tos = array_map( static fn( array $m ): string => is_array( $m['to'] ) ? implode( ',', $m['to'] ) : (string) $m['to'], $mine );
sort( $tos );
$check( 'recipients: list + holder + payer, not the person who left', array( 'holder@example.test', 'ops@example.test', 'payer@example.test' ) === $tos, implode( ' | ', $tos ) );
$check( 'each person is mailed on their own', 3 === count( array_unique( $tos ) ) );
$check( 'subject names the record and the time left', false !== strpos( (string) $mine[0]['subject'], 'Licence: RMDTEST Licence A, in 14 days' ), (string) $mine[0]['subject'] );
$check( 'body is plain text with a link', false !== strpos( (string) $mine[0]['message'], 'page=estasoft-passport' ) && false === strpos( (string) $mine[0]['message'], '<' ) );
$row = ( new ReminderRepository() )->forCycle( $a_id, '2026-05-15', 14 );
$check( 'log row: sent, 1 attempt, 3 recipients, no address kept', null !== $row && 'sent' === $row['status'] && 1 === (int) $row['attempts'] && 3 === (int) $row['recipient_count'] && false === strpos( wp_json_encode( $row ), '@' ), wp_json_encode( $row ) );

$count = count( $mails );
$again = $reminders()->run();
$check( 'AS-4: a second run sends nothing', 0 === $again['sent'] && $count === count( $mails ) );
$at( '2026-05-02' );
$next = $reminders()->run();
$check( 'the next day is still the 14-day lead: nothing', 0 === $next['sent'] && $count === count( $mails ) );

// ---- LIC-7: Renewed starts a new cycle -------------------------------------------------------------------------------
try {
	$plugin->records()->renew( $a_id, '2026-05-10' );
	$check( 'Renewed refuses a date that is not later', false );
} catch ( ValidationError $e ) {
	$check( 'Renewed refuses a date that is not later', true );
}
try {
	$plugin->records()->renew( $a_id, 'next year' );
	$check( 'Renewed refuses a date that is not a date', false );
} catch ( ValidationError $e ) {
	$check( 'Renewed refuses a date that is not a date', true );
}
$renewed = $plugin->records()->renew( $a_id, '2027-05-15' );
$check( 'Renewed stores the new date', '2027-05-15' === $renewed['due_date'] && '2027-05-15' === $renewed['fields']['renews_on'] );
$history = $plugin->changeLog()->repo()->search( array( 'kind' => 'record_renewed', 'subject_type' => 'item', 'subject_id' => $a_id ), 5 );
$check( 'History records the renewal', 1 === count( $history ) && '2026-05-15' === ( $history[0]['before_data']['value'] ?? '' ) && '2027-05-15' === ( $history[0]['after_data']['value'] ?? '' ), wp_json_encode( $history ) );
$at( '2026-05-03' );
$count = count( $mails );
$reminders()->run();
$check( 'after Renewed, nothing is sent while the new date is far off', $count === count( $mails ) );
$at( '2027-05-01' );
$reminders()->run();
$check( 'AS-4: a year later the new cycle sends one reminder again', 3 === count( array_filter( array_slice( $mails, $count ), static fn( array $m ): bool => false !== strpos( (string) $m['subject'], 'RMDTEST Licence A' ) ) ) );

// ---- RMD-5: failures, retries, health, HP-RMD-001 --------------------------------------------------------------------
$b_id      = $licence( 'RMDTEST Licence B', '2026-08-08' );
$created[] = $b_id;
$mail_ok   = false;
$at( '2026-08-01' );
$r1 = $reminders()->run();
$check( 'a failed send is logged as failed', 1 === $r1['failed'] && 0 === $r1['sent'], wp_json_encode( $r1 ) );
$row = ( new ReminderRepository() )->forCycle( $b_id, '2026-08-08', 7 );
$check( 'log row: failed, attempt 1', null !== $row && 'failed' === $row['status'] && 1 === (int) $row['attempts'] );
$check( 'health: one failed day', 1 === $reminders()->health()['failed_days'] );
$r1b = $reminders()->run();
$check( 'not retried on the same day', 0 === $r1b['failed'] && 1 === $reminders()->health()['failed_days'] );
$at( '2026-08-02' );
$r2 = $reminders()->run();
$check( 'retried the next day (attempt 2)', 1 === $r2['failed'] && 2 === (int) ( new ReminderRepository() )->forCycle( $b_id, '2026-08-08', 7 )['attempts'] );
$at( '2026-08-03' );
$reminders()->run();
$check( 'attempt 3 and three failed days in a row', 3 === (int) ( new ReminderRepository() )->forCycle( $b_id, '2026-08-08', 7 )['attempts'] && 3 === $reminders()->health()['failed_days'] );
$plugin->findings()->run();
$open = array_filter( $plugin->findings()->list( array( 'state' => 'open' ) ), static fn( array $f ): bool => 'HP-RMD-001' === $f['rule_id'] );
$check( 'HP-RMD-001 opens after three failed days', 1 === count( $open ) );
$at( '2026-08-04' );
$r4 = $reminders()->run();
$check( 'gives up after three attempts', 0 === $r4['failed'] && 3 === (int) ( new ReminderRepository() )->forCycle( $b_id, '2026-08-08', 7 )['attempts'] );
$check( 'the health records an error text without addresses', false === strpos( $reminders()->health()['last_error'], '@' ) );

$mail_ok   = true;
$c_id      = $licence( 'RMDTEST Licence C', '2026-08-05' );
$created[] = $c_id;
$r5 = $reminders()->run();
$check( 'one success clears the failed days', $r5['sent'] >= 1 && 0 === $reminders()->health()['failed_days'] );
$plugin->findings()->run();
$open = array_filter( $plugin->findings()->list( array( 'state' => 'open' ) ), static fn( array $f ): bool => 'HP-RMD-001' === $f['rule_id'] );
$check( 'HP-RMD-001 clears after a success', 0 === count( $open ) );

// ---- RMD-1: per-record days, cancelled, Sensitive -------------------------------------------------------------------
foreach ( array( 'x', '400', '3,,soon' ) as $bad ) {
	try {
		$plugin->records()->create( Kinds::LICENCE, array( 'product' => 'RMDTEST Bad', 'reminder_days' => $bad ) );
		$check( 'bad reminder days refused: ' . $bad, false );
	} catch ( ValidationError $e ) {
		$check( 'bad reminder days refused: ' . $bad, true );
	}
}
$d_id      = $licence( 'RMDTEST Licence D', '2026-09-10', array( 'reminder_days' => '3 10' ) );
$created[] = $d_id;
$check( 'per-record days are stored cleaned', '10, 3' === $plugin->records()->get( $d_id, true )['fields']['reminder_days'] );
$e_id      = $licence( 'RMDTEST Licence E', '2026-09-10', array( 'reminder_days' => 'OFF' ) );
$created[] = $e_id;
$check( 'off is stored as off', 'off' === $plugin->records()->get( $e_id, true )['fields']['reminder_days'] );
$f_id      = $licence( 'RMDTEST Licence F', '2026-09-10', array( 'cancelled' => 'yes' ) );
$created[] = $f_id;
$g_id      = $licence( 'RMDTEST Secret Vendor', '2026-09-10', array( 'sensitive' => '1' ) );
$created[] = $g_id;

$mails = array();
$at( '2026-08-31' ); // 10 days out.
$reminders()->run();
$check( 'per-record 10: sent at 10 days (not the Settings list)', 1 === count( $mails_for( 'RMDTEST Licence D' ) ) && 3 > count( $mails_for( 'RMDTEST Licence D' ) ) );
$check( 'off: nothing for that record', 0 === count( $mails_for( 'RMDTEST Licence E' ) ) );
$check( 'cancelled: nothing for that record', 0 === count( $mails_for( 'RMDTEST Licence F' ) ) );
$at( '2026-09-03' ); // 7 days out.
$mails = array();
$reminders()->run();
$secret = array_values( array_filter( $mails, static fn( array $m ): bool => false !== strpos( (string) $m['subject'], 'Sensitive' ) ) );
$check( 'Sensitive: mailed without its name', 1 === count( $secret ) && false === strpos( wp_json_encode( $mails ), 'Secret Vendor' ), (string) ( $secret[0]['subject'] ?? '' ) );
$check( 'record D is not mailed again at 7 days: only its own days count', 0 === count( $mails_for( 'RMDTEST Licence D' ) ) );

// ---- recipients: fallback to the site address ----------------------------------------------------------------------
$plugin->settings()->set( 'reminder_emails', array() );
$check( 'no list, no holder: the site administration address', array( strtolower( (string) get_option( 'admin_email' ) ) ) === array_map( 'strtolower', $reminders()->recipients( $b_id ) ) );
add_filter( 'estasoft_hp_reminder_recipients', $filter = static fn( $a ) => array_merge( (array) $a, array( 'extra@example.test', 'not an address' ) ) );
$check( 'filter adds recipients and bad ones are dropped', in_array( 'extra@example.test', $reminders()->recipients( $b_id ), true ) && ! in_array( 'not an address', $reminders()->recipients( $b_id ), true ) );
remove_filter( 'estasoft_hp_reminder_recipients', $filter );
$plugin->settings()->set( 'reminder_emails', array( 'ops@example.test' ) );

// ---- switched off ---------------------------------------------------------------------------------------------------
$plugin->settings()->update( array( 'reminders_enabled' => false ) );
$h_id      = $licence( 'RMDTEST Licence H', '2026-09-05' );
$created[] = $h_id;
$mails = array();
$off   = $reminders()->run();
$check( 'switched off: nothing is sent', 'disabled' === $off['state'] && array() === $mails );
$plugin->settings()->update( array( 'reminders_enabled' => true ) );

// ---- IMP-4 / AS-5: the copy guard ----------------------------------------------------------------------------------
$guard = $plugin->copyGuard();
$check( 'on the original host the guard is quiet', ! $guard->paused() && ! $guard->pending() );
update_option( CopyGuard::HOST_OPTION, 'original-site.example' );
$guard->check();
$check( 'a different host pauses the guard and asks the question', $guard->paused() && $guard->pending() );
$state = $guard->state();
$check( 'the question names both addresses', 'original-site.example' === $state['from'] && '' !== $state['to'] );
$mails = array();
$held  = $reminders()->run();
$check( 'AS-5: no reminder is sent from a copy', 'paused' === $held['state'] && array() === $mails );
$plugin->settings()->update( array( 'digest_enabled' => true ) );
$check( 'AS-5: no weekly summary either', 'paused' === $reminders()->digest()['state'] && array() === $mails );
$plugin->findings()->run();
$open = array_filter( $plugin->findings()->list( array( 'state' => 'open' ) ), static fn( array $f ): bool => 'HP-IMP-001' === $f['rule_id'] );
$check( 'HP-IMP-001 is open while the question is', 1 === count( $open ) );
$check( 'an unknown answer is refused', false === $guard->answer( 'maybe' ) && $guard->pending() );
$check( 'This is a copy is accepted', true === $guard->answer( CopyGuard::COPY ) );
$check( 'after "copy" the question is closed but reminders stay off', ! $guard->pending() && $guard->paused() && $guard->isMarkedCopy() );
$check( 'AS-5: choosing copy keeps reminders off', 'paused' === $reminders()->run()['state'] && array() === $mails );
$guard->check();
$check( 'checking again does not reopen the question', ! $guard->pending() );
$plugin->findings()->run();
$open = array_filter( $plugin->findings()->list( array( 'state' => 'open' ) ), static fn( array $f ): bool => 'HP-IMP-001' === $f['rule_id'] );
$check( 'HP-IMP-001 clears once answered', 0 === count( $open ) );
$check( 'This site moved is accepted', true === $guard->answer( CopyGuard::MOVED ) );
$check( 'after "moved" everything is back', ! $guard->paused() && ! $guard->isMarkedCopy() && $guard->currentHost() === $guard->storedHost() && array() === $guard->state() );

// ---- RMD-3: the weekly summary --------------------------------------------------------------------------------------
$mails = array();
$at( '2026-08-31' );
$plugin->settings()->update( array( 'digest_enabled' => false ) );
$check( 'summary off: nothing', 'disabled' === $reminders()->digest()['state'] && array() === $mails );
$plugin->settings()->update( array( 'digest_enabled' => true ) );
$dry = $reminders()->digest( true );
$check( 'summary dry run counts but sends nothing', 'dry_run' === $dry['state'] && $dry['recipients'] >= 1 && array() === $mails );
$sent = $reminders()->digest();
$check( 'summary sent', 'sent' === $sent['state'] && 1 === count( $mails ), wp_json_encode( $sent ) );
$summary = (string) ( $mails[0]['message'] ?? '' );
$check( 'summary lists a date inside 30 days', false !== strpos( $summary, 'RMDTEST Licence D' ), $summary );
$check( 'summary leaves out a cancelled record and a Sensitive name', false === strpos( $summary, 'RMDTEST Licence F' ) && false === strpos( $summary, 'Secret Vendor' ) );
$check( 'summary remembers when it went', '' !== (string) get_option( ReminderService::DIGEST_OPTION, '' ) );
$plugin->settings()->update( array( 'digest_enabled' => false ) );

// ---- RMD-5: the test email ------------------------------------------------------------------------------------------
$mails  = array();
$tester = 'tester-' . wp_rand( 100000, 999999 ) . '@example.test'; // A new address each run: the one-a-minute limit is per address.
$other  = 'other-' . wp_rand( 100000, 999999 ) . '@example.test';
$check( 'test email: an invalid address is refused', 'invalid' === $reminders()->sendTest( 'not-an-address' )['reason'] );
$ok = $reminders()->sendTest( $tester );
$check( 'test email: sent', $ok['ok'] && 1 === count( $mails ) );
$wait = $reminders()->sendTest( $tester );
$check( 'test email: rate limited with the time left', 'wait' === $wait['reason'] && $wait['wait'] >= 1 && $wait['wait'] <= 60, wp_json_encode( $wait ) );
$check( 'health keeps the test result', true === $reminders()->health()['test_ok'] );
$mail_ok = false;
$bad = $reminders()->sendTest( $other );
$check( 'test email: a failure is reported', ! $bad['ok'] && 'failed' === $bad['reason'] && false === $reminders()->health()['test_ok'] );
$mail_ok = true;

// ---- Upcoming dates leave out computed review dates ----------------------------------------------------------------
$backup = $plugin->records()->create( Kinds::BACKUP, array( 'name' => 'RMDTEST Backup', 'last_confirmed' => '2026-08-01' ) );
$created[] = $backup['id'];
$kinds = array_map( static fn( array $r ): string => $r['kind'], ( new \Estasoft\HandoverPassport\Reminders\UpcomingDates( $plugin->records() ) )->between( '2026-01-01', '2030-12-31' ) );
$check( 'backup review dates are not renewal dates', ! in_array( Kinds::BACKUP, $kinds, true ) && in_array( Kinds::LICENCE, $kinds, true ) );

// ---- clean up -------------------------------------------------------------------------------------------------------
Clock::freeze( null );
$links = new LinkRepository();
foreach ( $created as $id ) {
	$links->forget( 'item', (int) $id );
	$plugin->records()->items()->delete( (int) $id );
}
foreach ( $people as $id ) {
	$links->forget( 'person', (int) $id );
	$plugin->people()->repo()->delete( (int) $id );
}
$wpdb->query( "DELETE FROM {$wpdb_reminders}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Test site clean-up.
delete_option( ReminderService::HEALTH_OPTION );
delete_option( ReminderService::DIGEST_OPTION );
delete_option( CopyGuard::STATE_OPTION );
if ( false !== $original_host ) {
	update_option( CopyGuard::HOST_OPTION, $original_host );
}
if ( false !== $original_settings ) {
	update_option( 'estasoft_hp_settings', $original_settings );
}
$plugin->settings()->flush();
$plugin->findings()->run();

echo "\n" . ( $checks - $failures ) . ' of ' . $checks . " checks passed\n";
if ( $failures > 0 ) {
	exit( 1 );
}
