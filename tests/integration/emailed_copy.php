<?php
/**
 * Integration test for the emailed copy of the Emergency sheet (EXP-10). Run on a WordPress test site:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/emailed_copy.php
 *
 * Mail is captured with the pre_wp_mail filter, together with the bytes of each attachment, and nothing is sent. It writes
 * records, snapshots and settings and puts them back.
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Data\Tables;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Export\EmailedCopy;
use Estasoft\HandoverPassport\Export\ExportOptions;
use Estasoft\HandoverPassport\Export\Profile;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\CopyGuard;
use Estasoft\HandoverPassport\Support\TempFiles;

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

// Mail capture: nothing leaves the site, and an attachment is read before the plugin deletes it.
$mails   = array();
$mail_ok = true;
add_filter(
	'pre_wp_mail',
	static function ( $pre, $atts ) use ( &$mails, &$mail_ok ) {
		if ( ! $mail_ok ) {
			return false;
		}
		$atts['files'] = array();
		foreach ( (array) ( $atts['attachments'] ?? array() ) as $path ) {
			$atts['files'][] = array( 'path' => $path, 'bytes' => is_file( $path ) ? (string) file_get_contents( $path ) : '' );
		}
		$mails[] = $atts;
		return true;
	},
	10,
	2
);

$had_settings = get_option( 'estasoft_hp_settings', null );
$had_state    = get_option( EmailedCopy::OPTION, null );
$had_host     = get_option( CopyGuard::HOST_OPTION, null );
$had_guard    = get_option( CopyGuard::STATE_OPTION, null );
$baseline     = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Tables::name( 'changes' ) );
$log_before   = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Tables::name( 'exports' ) );

// Put the hosting records the site holds aside, so the sheet below shows only the test's own.
$held = array_map( static fn( array $r ): int => (int) $r['id'], $plugin->records()->listKind( Kinds::HOST, true ) );
foreach ( $held as $id ) {
	$plugin->records()->items()->softDelete( $id );
}
update_option( CopyGuard::HOST_OPTION, $plugin->copyGuard()->currentHost() );
delete_option( CopyGuard::STATE_OPTION );
delete_option( EmailedCopy::OPTION );
$snapshots = array();

$cleanup = static function () use ( $plugin, $wpdb, $baseline, $log_before, $held, &$snapshots, $had_settings, $had_state, $had_host, $had_guard ): void {
	foreach ( $snapshots as $id ) {
		$plugin->snapshots()->delete( (int) $id );
	}
	foreach ( $plugin->records()->listKind( Kinds::HOST, true ) as $record ) {
		if ( 0 === strpos( (string) $record['title'], 'COPYTEST' ) ) {
			$plugin->records()->items()->delete( (int) $record['id'] );
		}
	}
	foreach ( $held as $id ) {
		$plugin->records()->items()->restore( $id );
	}
	$wpdb->query( 'DELETE FROM ' . Tables::name( 'changes' ) . " WHERE id > {$baseline}" );
	$wpdb->query( 'DELETE FROM ' . Tables::name( 'exports' ) . " WHERE id > {$log_before}" );
	foreach ( array( 'estasoft_hp_settings' => $had_settings, EmailedCopy::OPTION => $had_state, CopyGuard::HOST_OPTION => $had_host, CopyGuard::STATE_OPTION => $had_guard ) as $name => $value ) {
		if ( null === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value, false !== strpos( $name, 'settings' ) );
		}
	}
	$plugin->reset();
};

$copy = static function () use ( $plugin ): EmailedCopy {
	$plugin->reset();
	return $plugin->emailedCopy();
};
$set  = static function ( array $values ) use ( $plugin ): void {
	$plugin->settings()->set( 'emailed_copy', array_merge( array( 'enabled' => true, 'addresses' => array( 'copytest-a@example.test', 'copytest-b@example.test' ), 'on_snapshot' => true, 'monthly' => true ), $values ) );
};
$set_state = static function ( array $values ): void {
	update_option( EmailedCopy::OPTION, array_merge( (array) get_option( EmailedCopy::OPTION, array() ), $values ), false );
};
$yesterday = gmdate( 'Y-m-d H:i:s', time() - 86400 );
$tmp_files = static function (): array {
	$dir = TempFiles::dir();
	return '' === $dir ? array() : array_values( array_filter( (array) scandir( $dir ), static fn( $f ): bool => '.' !== $f && '..' !== $f && '.htaccess' !== $f && 'index.php' !== $f && 'index.html' !== $f && 'web.config' !== $f ) );
};
$before_tmp = $tmp_files();
// A button press may be repeated only after a minute; a test needs to press again at once.
$reset_limit = static function (): void {
	delete_transient( 'estasoft_hp_chk_' . substr( md5( 'copy|sheet' ), 0, 20 ) );
};

// ---- off by default -----------------------------------------------------------------------------------------------
$plugin->settings()->set( 'emailed_copy', array( 'enabled' => false, 'addresses' => array( 'copytest-a@example.test' ), 'on_snapshot' => true, 'monthly' => true ) );
$check( 'it is off until someone switches it on', false === $copy()->config()['enabled'] && false === $copy()->active() );
$r = $copy()->send( 'manual' );
$check( 'while off nothing is sent', 'disabled' === $r['state'] && array() === $mails );
$copy()->daily();
$check( 'the daily run sends nothing while it is off', array() === $mails );
$check( 'the hook for a new snapshot is bound', false !== has_action( 'estasoft_hp_snapshot_created', array( $plugin, 'onSnapshotCreated' ) ) );

// ---- on, with nobody listed ---------------------------------------------------------------------------------------
$set( array( 'addresses' => array() ) );
$check( 'with nobody listed it is not active', false === $copy()->active() );
$r = $copy()->send( 'manual' );
$check( 'with nobody listed nothing is sent', 'nobody' === $r['state'] && array() === $mails );

// ---- the list is cleaned ------------------------------------------------------------------------------------------
$set( array( 'addresses' => array( 'Copytest-A@example.test', 'copytest-a@example.test', 'not an address', '', 'b@example.test', 'c@example.test', 'd@example.test', 'e@example.test', 'f@example.test', 'g@example.test' ) ) );
$config = $copy()->config();
$check( 'a repeated address is kept once', 1 === count( array_filter( $config['addresses'], static fn( string $a ): bool => 'copytest-a@example.test' === strtolower( $a ) ) ) );
$check( 'text that is not an address is dropped', ! in_array( 'not an address', $config['addresses'], true ) && ! in_array( '', $config['addresses'], true ) );
$check( 'no more than five are kept', 5 === count( $config['addresses'] ), (string) count( $config['addresses'] ) );

// ---- a sheet to send: one plain and one Sensitive hosting record --------------------------------------------------
$plugin->records()->create( Kinds::HOST, array( 'provider' => 'COPYTEST-PLAIN-HOST', 'support_address' => 'help@plain-host.example' ) );
$plugin->records()->create( Kinds::HOST, array( 'provider' => 'COPYTEST-SECRET-HOST', 'support_address' => 'help@secret-host.example', 'sensitive' => '1' ) );

// ---- pressing the button ------------------------------------------------------------------------------------------
$set( array() );
$check( 'with addresses listed it is active', true === $copy()->active() );
$reset_limit();
$r = $copy()->send( 'manual' );
$check( 'the button sends', 'sent' === $r['state'] && 2 === $r['sent'], json_encode( $r ) );
$check( 'one email goes to each address', 2 === count( $mails ), (string) count( $mails ) );
$to = array_map( static fn( array $m ): string => is_array( $m['to'] ) ? (string) $m['to'][0] : (string) $m['to'], $mails );
sort( $to );
$check( 'to exactly the listed addresses, each on its own', array( 'copytest-a@example.test', 'copytest-b@example.test' ) === $to, implode( ',', $to ) );
$mail = $mails[0];
$pdf  = (string) ( $mail['files'][0]['bytes'] ?? '' );
$check( 'each carries one attachment', 1 === count( $mail['files'] ) );
$check( 'the attachment is a PDF', 0 === strpos( $pdf, '%PDF-' ) && strlen( $pdf ) > 1000, substr( $pdf, 0, 12 ) );
$check( 'the attachment is named .pdf', '.pdf' === substr( (string) $mail['files'][0]['path'], -4 ) );
$check( 'the subject names the site and the date', false !== strpos( (string) $mail['subject'], 'Emergency sheet' ) && false !== strpos( (string) $mail['subject'], gmdate( 'Y' ) ), (string) $mail['subject'] );
$check( 'the subject has no line break', 1 !== preg_match( '/[\r\n]/', (string) $mail['subject'] ) );
$body = (string) $mail['message'];
$check( 'the message says email is not encrypted', false !== strpos( $body, 'Email is not encrypted' ) );
$check( 'the message says no Sensitive record is in it', false !== strpos( $body, 'holds no record marked Sensitive' ) );
$check( 'the message says why it came', false !== strpos( $body, 'someone pressed Send a copy now' ) );
$check( 'the message links to the settings', false !== strpos( $body, 'estasoft-passport-settings' ) && false !== strpos( $body, 'tab=privacy' ) );
$check( 'the message holds no password-like word list', false === stripos( $body, 'password:' ) );
$check( 'the attachment file is deleted after sending', ! is_file( (string) $mail['files'][0]['path'] ) );
$check( 'no file is left in the working folder', $tmp_files() === $before_tmp, implode( ',', array_diff( $tmp_files(), $before_tmp ) ) );
$state = $copy()->state();
$check( 'the success is remembered and nothing is pending', ! $state['pending'] && '' !== $state['sent_at'] && '' === $state['error'] && '' === $copy()->warning() );

// What is on the sheet.
$tool = trim( (string) shell_exec( 'command -v pdftotext 2>/dev/null' ) );
if ( '' !== $tool ) {
	$file = tempnam( sys_get_temp_dir(), 'ehp' );
	file_put_contents( $file, $pdf );
	$text = (string) shell_exec( escapeshellarg( $tool ) . ' ' . escapeshellarg( $file ) . ' - 2>/dev/null' );
	unlink( $file );
	$check( 'the sheet is readable text and shows the plain record', false !== strpos( $text, 'COPYTEST-PLAIN-HOST' ), substr( $text, 0, 200 ) );
	$check( 'the Sensitive record is not on the sheet', false === strpos( $text, 'COPYTEST-SECRET-HOST' ) && false === strpos( $text, 'secret-host.example' ) );
} else {
	echo "SKIP  pdftotext is not installed, so the text on the sheet was not read\n";
}

// The export log.
$row = $wpdb->get_row( 'SELECT * FROM ' . Tables::name( 'exports' ) . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
$check( 'the export log notes a PDF of the Emergency sheet, emailed, by the person who pressed the button', is_array( $row ) && 'emergency' === $row['profile'] && 'pdf' === $row['format'] && 1 === (int) $row['emailed'] && 1 === (int) $row['user_id'], json_encode( $row ) );
$check( 'its fingerprint is that of the attached file', is_array( $row ) && hash( 'sha256', $pdf ) === $row['hash'] );

// Pressing again at once.
$count = count( $mails );
$r     = $copy()->send( 'manual' );
$check( 'pressing again at once is refused and sends nothing', 'wait' === $r['state'] && $count === count( $mails ), json_encode( $r ) );

// ---- a new snapshot -----------------------------------------------------------------------------------------------
$mails = array();
$id    = $plugin->snapshots()->create( Profile::TECHNICAL, 'COPYTEST snapshot', '', new ExportOptions( false, array(), 1 ) );
$snapshots[] = $id;
$check( 'a new snapshot sends the sheet to each address', 2 === count( $mails ), (string) count( $mails ) );
$check( 'and says why', isset( $mails[0] ) && false !== strpos( (string) $mails[0]['message'], 'a new snapshot of the Passport was made' ) );
$row = $wpdb->get_row( 'SELECT * FROM ' . Tables::name( 'exports' ) . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
$check( 'the export log shows an automatic copy as made by no person', is_array( $row ) && 1 === (int) $row['emailed'] && 0 === (int) $row['user_id'], json_encode( $row ) );
$mails = array();
$set( array( 'on_snapshot' => false ) );
$snapshots[] = $plugin->snapshots()->create( Profile::TECHNICAL, 'COPYTEST snapshot 2', '', new ExportOptions( false, array(), 1 ) );
$check( 'with that option off a snapshot sends nothing', array() === $mails );

// ---- the monthly copy ---------------------------------------------------------------------------------------------
$mails = array();
$set( array( 'on_snapshot' => false, 'monthly' => true ) );
$set_state( array( 'month' => '2000-01', 'pending' => false ) );
$copy()->daily();
$check( 'the first daily run of a new month sends the monthly copy', 2 === count( $mails ), (string) count( $mails ) );
$check( 'and says why', isset( $mails[0] ) && false !== strpos( (string) $mails[0]['message'], 'the monthly copy is on' ) );
$check( 'the month is remembered', substr( wp_date( 'Y-m-d' ), 0, 7 ) === $copy()->state()['month'] );
$mails = array();
$copy()->daily();
$check( 'a second run in the same month sends nothing', array() === $mails );
$set( array( 'monthly' => false ) );
$set_state( array( 'month' => '2000-01' ) );
$copy()->daily();
$check( 'with the monthly option off nothing is sent', array() === $mails );
$set( array( 'monthly' => true ) );
$set_state( array( 'month' => '' ) );
$copy()->startMonth();
$copy()->daily();
$check( 'switching copies on does not send one at once', array() === $mails );

// ---- a failure, shown and retried ---------------------------------------------------------------------------------
$set( array( 'on_snapshot' => true ) );
$set_state( array( 'pending' => false, 'retries' => 0, 'failed_at' => '', 'error' => '', 'reason' => '' ) );
$mails   = array();
$mail_ok = false;
$r       = $copy()->send( 'snapshot' );
$check( 'when the site cannot send email the copy fails', 'failed' === $r['state'] && 0 === $r['sent'] && '' !== $r['error'], json_encode( $r ) );
$state = $copy()->state();
$check( 'the failure is remembered as pending, with no tries used yet', $state['pending'] && 0 === $state['retries'] && 'snapshot' === $state['reason'] && '' !== $state['failed_at'] );
$check( 'the stored error holds no address', false === strpos( $state['error'], '@' ) );
$warning = $copy()->warning();
$check( 'the Overview has a warning that says it will try again', false !== strpos( $warning, 'did not go out' ) && false !== strpos( $warning, 'try again tomorrow (3 tries left)' ), $warning );
$check( 'no file is left behind after a failure', $tmp_files() === $before_tmp );
$copy()->daily();
$check( 'the daily run does not try again on the same day', 0 === $copy()->state()['retries'] );

$expected = array( 1, 2, 3 );
foreach ( $expected as $n ) {
	$set_state( array( 'failed_at' => $yesterday ) );
	$copy()->daily();
	$check( "retry {$n} is made the next day", $n === $copy()->state()['retries'] && $copy()->state()['pending'], (string) $copy()->state()['retries'] );
}
$check( 'after the third retry the warning says it has stopped', false !== strpos( $copy()->warning(), 'has stopped trying' ), $copy()->warning() );
$mail_ok = true;
$set_state( array( 'failed_at' => $yesterday ) );
$copy()->daily();
$check( 'a fourth retry is never made, even when the mail would work', array() === $mails && 3 === $copy()->state()['retries'] );

// A later success clears the warning.
$r = $copy()->send( 'snapshot' );
$check( 'a later send that works clears the failure', 'sent' === $r['state'] && '' === $copy()->warning() && ! $copy()->state()['pending'] && 0 === $copy()->state()['retries'], json_encode( $copy()->state() ) );

// A retry that works.
$mails   = array();
$mail_ok = false;
$copy()->send( 'snapshot' );
$mail_ok = true;
$set_state( array( 'failed_at' => $yesterday ) );
$copy()->daily();
$check( 'a retry the next day that works sends and clears the warning', 2 === count( $mails ) && '' === $copy()->warning() && ! $copy()->state()['pending'] );

// A failed press of the button is shown but not queued.
$reset_limit();
$mails   = array();
$mail_ok = false;
$r       = $copy()->send( 'manual' );
$mail_ok = true;
$check( 'a failed press says so', 'failed' === $r['state'], json_encode( $r ) );
$check( 'it is not queued for another try', ! $copy()->state()['pending'] && '' !== $copy()->warning() );
$set_state( array( 'failed_at' => $yesterday ) );
$copy()->daily();
$check( 'the daily run leaves it alone', array() === $mails );
$set_state( array( 'pending' => false, 'retries' => 0, 'failed_at' => '', 'error' => '', 'reason' => '' ) );

// ---- a copy of the site -------------------------------------------------------------------------------------------
$mails = array();
update_option( CopyGuard::HOST_OPTION, 'some-other-host.example' );
delete_option( CopyGuard::STATE_OPTION );
$reset_limit();
$plugin->reset();
$r = $plugin->emailedCopy()->send( 'manual' );
$check( 'on a copy of the site nothing is sent', 'paused' === $r['state'] && array() === $mails, json_encode( $r ) );
$set_state( array( 'month' => '2000-01' ) );
$plugin->reset();
$plugin->emailedCopy()->daily();
$check( 'and neither does the daily run', array() === $mails );
update_option( CopyGuard::HOST_OPTION, $plugin->copyGuard()->currentHost() );
delete_option( CopyGuard::STATE_OPTION );

// ---- the sheet itself is made without Sensitive rights ------------------------------------------------------------
$json = $plugin->exports()->make( Profile::EMERGENCY, 'json', new ExportOptions( false, array(), 0 ) );
$check( 'the same pack as JSON holds the plain record and not the Sensitive one', false !== strpos( $json->bytes, 'COPYTEST-PLAIN-HOST' ) && false === strpos( $json->bytes, 'COPYTEST-SECRET-HOST' ) );

$cleanup();
$check( 'the records and snapshots made by this test are gone', array() === array_filter( $plugin->records()->listKind( Kinds::HOST, true ), static fn( array $r ): bool => 0 === strpos( (string) $r['title'], 'COPYTEST' ) ) );

echo "\n{$checks} checks, {$failures} failed.\n";
if ( $failures > 0 ) {
	exit( 1 );
}
