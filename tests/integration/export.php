<?php
/**
 * Integration test for the export service: every format for every pack, the Sensitive rules, the log and snapshots.
 * Run on a WordPress test site with the GD and mbstring extensions:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/export.php
 *
 * It writes records, snapshots and log rows to the site, so use a test site.
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Export\ExportOptions;
use Estasoft\HandoverPassport\Export\ExportRefused;
use Estasoft\HandoverPassport\Export\ExportService;
use Estasoft\HandoverPassport\Export\Profile;
use Estasoft\HandoverPassport\Passport\Passport;
use Estasoft\HandoverPassport\Plugin;
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
$exports = $plugin->exports();

$cleanup = static function () use ( $plugin, $wpdb ): void {
	foreach ( $plugin->records()->everything() as $old ) {
		if ( 0 === stripos( (string) $old['title'], 'exptest' ) ) {
			$plugin->records()->delete( (int) $old['id'] );
		}
	}
	foreach ( $plugin->snapshots()->all() as $old ) {
		if ( 0 === strpos( (string) $old['name'], 'EXPTEST' ) ) {
			$plugin->snapshots()->delete( (int) $old['id'] );
		}
	}
	$wpdb->query( "DELETE FROM {$wpdb->prefix}estasoft_hp_exports WHERE profile LIKE 'exptest%' OR user_id = 987654" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
};
$cleanup();

// A Passport with a Sentinel Sensitive record, a Sensitive field and Arabic, Hebrew and Cyrillic text.
$sentinel_domain = 'exptest-sentinel-domain.example';
$sentinel_note   = 'EXPTEST-SENTINEL-NOTE-5521';
$sentinel_email  = 'exptest-admin@example.test';
$plugin->passport()->update( array( 'site_name' => 'EXPTEST Site', 'client_name' => 'EXPTEST Client', 'status' => Passport::STATUS_ACTIVE ) );
$plain  = (int) $plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'exptest-plain.example', 'registrar' => 'مسجل النطاقات', 'expires_on' => '2027-03-01' ) )['id'];
$secret = (int) $plugin->records()->create( Kinds::DOMAIN, array( 'domain' => $sentinel_domain, 'expires_on' => '2027-03-02', 'sensitive' => '1', 'note' => $sentinel_note ) )['id'];
$plugin->records()->create( Kinds::LICENCE, array( 'product' => 'EXPTEST Licence Заметка', 'renews_on' => '2027-04-01', 'account_email' => $sentinel_email ) );
$plugin->records()->create( Kinds::HOST, array( 'provider' => 'EXPTEST שרת אירוח', 'plan' => 'Pro' ) );

$none  = new ExportOptions( false, array(), 987654 );
$full  = new ExportOptions( true, array(), 987654 );
$ticks = new ExportOptions( true, array( $secret ), 987654 );

$text_of = static function ( string $bytes, string $format ): string {
	if ( 'pdf' === $format ) {
		$path = TempFiles::create( '.pdf' );
		file_put_contents( $path, $bytes );
		$out = (string) shell_exec( 'pdftotext -enc UTF-8 ' . escapeshellarg( $path ) . ' - 2>/dev/null' );
		TempFiles::discard( $path );
		return $out;
	}
	if ( 'csv' === $format ) {
		$path = TempFiles::create( '.zip' );
		file_put_contents( $path, $bytes );
		$zip = new ZipArchive();
		$all = '';
		if ( true === $zip->open( $path ) ) {
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$all .= (string) $zip->getFromIndex( $i ) . "\n";
			}
			$zip->close();
		}
		TempFiles::discard( $path );
		return $all;
	}
	return $bytes;
};

// AS-2: no Client, Technical or Emergency pack holds a Sensitive value in any format.
$formats = array_keys( ExportService::formats() );
$leaks   = array();
$made    = 0;
foreach ( array( Profile::CLIENT, Profile::TECHNICAL, Profile::EMERGENCY ) as $profile ) {
	foreach ( array_merge( $formats, array( ExportService::PRINT ) ) as $format ) {
		try {
			$result = $exports->make( $profile, $format, $full );
		} catch ( \Throwable $e ) {
			$check( "{$profile}/{$format}: is made", false, $e->getMessage() );
			continue;
		}
		++$made;
		$text = $text_of( $result->bytes, 'print' === $format ? 'html' : $format );
		foreach ( array( $sentinel_domain, $sentinel_note, $sentinel_email ) as $needle ) {
			if ( false !== stripos( $text, $needle ) ) {
				$leaks[] = "{$profile}/{$format}: {$needle}";
			}
		}
		if ( '' === $result->bytes || '' === $result->mime || ! preg_match( '/^[a-z0-9-]+\.[a-z]{2,4}$/', $result->filename ) ) {
			$check( "{$profile}/{$format}: has bytes, a media type and a safe file name", false, $result->filename );
		}
	}
}
$check( 'every pack in every format is made (' . $made . ' files)', 21 === $made );
$check( 'no Client, Technical or Emergency file of any format holds a Sensitive value', array() === $leaks, implode( '; ', $leaks ) );

// The plain record and the Arabic text are there.
$client_html = $exports->make( Profile::CLIENT, 'html', $none )->bytes;
$check( 'the Client HTML holds the plain domain and the Arabic registrar', false !== strpos( $client_html, 'exptest-plain.example' ) && false !== strpos( $client_html, 'مسجل النطاقات' ) );
$client_pdf = $exports->make( Profile::TECHNICAL, 'pdf', $none );
$text       = $text_of( $client_pdf->bytes, 'pdf' );
$check( 'the PDF holds the Cyrillic and Hebrew text', false !== strpos( $text, 'Заметка' ) && ( false !== strpos( $text, 'שרת' ) || false !== strpos( $text, 'תרש' ) ) );

// File names.
$check( 'file name is ASCII, lower case and holds the site, the pack and the date', 1 === preg_match( '/^exptest-site-technical-\d{4}-\d{2}-\d{2}\.pdf$/', $client_pdf->filename ), $client_pdf->filename );
$check( 'CSV is a zip file', 'zip' === pathinfo( $exports->make( Profile::CLIENT, 'csv', $none )->filename, PATHINFO_EXTENSION ) );
$check( 'JSON has a schema version', false !== strpos( $exports->make( Profile::CLIENT, 'json', $none )->bytes, '"schema_version"' ) );
$arabic_site = $exports->make( Profile::CLIENT, 'md', $none );
$plugin->passport()->update( array( 'site_name' => 'موقع الاختبار' ) );
$ar = $exports->make( Profile::CLIENT, 'md', $none );
$check( 'a site name with no Latin letters still gives a usable file name', 1 === preg_match( '/^passport-client-\d{4}-\d{2}-\d{2}\.md$/', $ar->filename ), $ar->filename );
$plugin->passport()->update( array( 'site_name' => 'EXPTEST Site' ) );

// Ticked Sensitive records and the Full pack.
$ticked = $exports->make( Profile::TECHNICAL, 'md', $ticks );
$check( 'a ticked Sensitive record appears in the Technical pack, by someone who may', false !== strpos( $ticked->bytes, $sentinel_domain ) );
$check( 'but its note and the Sensitive field value do not', false === strpos( $ticked->bytes, $sentinel_email ) );
$check( 'ticks from someone who may not are ignored', false === strpos( $exports->make( Profile::TECHNICAL, 'md', new ExportOptions( false, array( $secret ), 987654 ) )->bytes, $sentinel_domain ) );
$fullpack = $exports->make( Profile::FULL, 'md', $full );
$check( 'the Full pack holds the Sensitive field value', false !== strpos( $fullpack->bytes, $sentinel_email ) );
$e = $throws( fn() => $exports->make( Profile::FULL, 'md', $none ) );
$check( 'the Full pack is refused to someone who may not see Sensitive records', $e instanceof ExportRefused );

// Bad input.
$e = $throws( fn() => $exports->make( 'nope', 'md', $none ) );
$check( 'an unknown pack is refused', $e instanceof ValidationError );
$e = $throws( fn() => $exports->make( Profile::CLIENT, 'exe', $none ) );
$check( 'an unknown format is refused', $e instanceof ValidationError );

// The log (EXP-9).
$rows = $exports->recent( 987654 );
$check( 'every file made was logged for that person', count( $rows ) >= $made, (string) count( $rows ) );
$row = $rows[0];
$check( 'a log row holds time, person, pack, format, size and a hash of 64 characters', '' !== $row['exported_at'] && 987654 === (int) $row['user_id'] && '' !== $row['profile'] && '' !== $row['format'] && (int) $row['size'] > 0 && 64 === strlen( (string) $row['hash'] ) );
$check( 'someone else sees none of those rows', array() === array_filter( $exports->recent( 987655 ), static fn( $r ) => 987654 === (int) $r['user_id'] ) );
$columns = array_keys( $row );
$check( 'the log holds no file', array() === array_intersect( $columns, array( 'content', 'file', 'body', 'bytes' ) ) );
$old = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}estasoft_hp_exports WHERE user_id = 987654 LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
$wpdb->update( "{$wpdb->prefix}estasoft_hp_exports", array( 'exported_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ), array( 'id' => $old ) );
$check( 'a row older than 12 months is not listed', ! in_array( $old, array_map( 'intval', array_column( $exports->recent( 987654 ), 'id' ) ), true ) );

// Hooks.
$seen = array();
add_action(
	'estasoft_hp_export_created',
	static function ( $profile, $format, $snapshot, $user ) use ( &$seen ): void {
		$seen = array( $profile, $format, $snapshot, $user );
	},
	10,
	4
);
$exports->make( Profile::EMERGENCY, 'json', $none );
$check( 'the export hook fires with the pack, the format and the person', array( 'emergency', 'json', 0, 987654 ) === $seen );
remove_all_actions( 'estasoft_hp_export_created' );

// Snapshots.
$snaps = $plugin->snapshots();
$sid   = $snaps->create( Profile::CLIENT, 'EXPTEST snapshot', '', $none );
$res   = $exports->makeFromSnapshot( $sid, 'html', $none );
$check( 'a snapshot is exported with its own name and hash in the footer', false !== strpos( $res->bytes, 'EXPTEST snapshot' ) && false !== strpos( $res->bytes, substr( (string) $snaps->get( $sid )['hash'], 0, 16 ) ) );
$check( 'the file name carries the snapshot name', false !== strpos( $res->filename, 'exptest-snapshot' ), $res->filename );
$check( 'the log notes which snapshot', $sid === (int) $exports->recent( 987654 )[0]['snapshot_id'] );
$pdf_snap = $exports->makeFromSnapshot( $sid, 'pdf', $none );
$check( 'a snapshot makes a PDF too', str_starts_with( $pdf_snap->bytes, '%PDF-' ) );
$tid = $snaps->create( Profile::TECHNICAL, 'EXPTEST sensitive snapshot', '', $ticks );
$e   = $throws( fn() => $exports->makeFromSnapshot( $tid, 'md', $none ) );
$check( 'a snapshot with Sensitive records is refused to someone who may not see them', $e instanceof ExportRefused );
$check( 'but allowed to someone who may', false !== strpos( $exports->makeFromSnapshot( $tid, 'md', $full )->bytes, $sentinel_domain ) );
$e = $throws( fn() => $exports->makeFromSnapshot( 999999, 'md', $none ) );
$check( 'a missing snapshot is refused', $e instanceof ValidationError );
$wpdb->update( $snaps->repo()->table(), array( 'content' => str_replace( 'exptest-plain.example', 'exptest-evil.example', (string) $snaps->get( $sid )['content'] ) ), array( 'id' => $sid ) );
$e = $throws( fn() => $exports->makeFromSnapshot( $sid, 'html', $none ) );
$check( 'a tampered snapshot is not exported', $e instanceof ExportRefused );

// Style.
$style = $exports->style();
$check( 'the style takes the brand colour from the Passport', 1 === preg_match( '/^#[0-9a-f]{6}$/', $style->brand_color ) );
$check( 'the style has no logo when none is set', '' === $style->logo_data_uri );
$check( 'right-to-left languages are recognised', ExportService::isRtlLanguage( 'ar' ) && ExportService::isRtlLanguage( 'he-IL' ) && ExportService::isRtlLanguage( 'fa_IR' ) && ! ExportService::isRtlLanguage( 'en-US' ) && ! ExportService::isRtlLanguage( 'de' ) );

// A logo from the media library goes into the pack as a data address.
$png  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
$file = wp_upload_bits( 'exptest-logo.png', null, $png );
$att  = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'EXPTEST logo', 'post_status' => 'inherit' ), $file['file'] );
$plugin->passport()->update( array( 'logo_id' => $att ) );
$check( 'a PNG logo becomes a data address', str_starts_with( $exports->style()->logo_data_uri, 'data:image/png;base64,' ) );
$check( 'the logo is in the HTML pack and the PDF is still made', false !== strpos( $exports->make( Profile::CLIENT, 'html', $none )->bytes, 'data:image/png;base64,' ) && str_starts_with( $exports->make( Profile::CLIENT, 'pdf', $none )->bytes, '%PDF-' ) );
$plugin->passport()->update( array( 'logo_id' => 0 ) );
wp_delete_attachment( $att, true );
$text_att = wp_insert_attachment( array( 'post_mime_type' => 'text/plain', 'post_title' => 'EXPTEST text', 'post_status' => 'inherit' ), $file['file'] );
$plugin->passport()->update( array( 'logo_id' => $text_att ) );
$check( 'a file that is not an image is never used as a logo', '' === $exports->style()->logo_data_uri );
$plugin->passport()->update( array( 'logo_id' => 0 ) );
wp_delete_attachment( $text_att, true );

// Nothing is left in the private folder.
$left = array_filter( glob( TempFiles::dir() . '/*' ) ?: array(), static fn( $p ) => ! in_array( basename( $p ), array( 'index.php', 'web.config' ), true ) );
$check( 'no working file is left behind', array() === $left, implode( ',', $left ) );

// The daily clean-up trims the log by the retention setting and removes stale files from the private folder.
$wpdb->insert( "{$wpdb->prefix}estasoft_hp_exports", array( 'exported_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ), 'user_id' => 987654, 'profile' => 'exptest-old', 'format' => 'md', 'size' => 1, 'hash' => str_repeat( 'a', 64 ) ) );
$wpdb->insert( "{$wpdb->prefix}estasoft_hp_exports", array( 'exported_at' => gmdate( 'Y-m-d H:i:s' ), 'user_id' => 987654, 'profile' => 'exptest-new', 'format' => 'md', 'size' => 1, 'hash' => str_repeat( 'b', 64 ) ) );
$stale = TempFiles::create( '.tmp' );
file_put_contents( $stale, 'x' );
touch( $stale, time() - 3 * DAY_IN_SECONDS );
$stale_dir = TempFiles::createDir();
file_put_contents( $stale_dir . '/inner.txt', 'x' );
touch( $stale_dir, time() - 3 * DAY_IN_SECONDS );
$plugin->runRetention();
$remaining = array_column( $wpdb->get_results( "SELECT profile FROM {$wpdb->prefix}estasoft_hp_exports WHERE profile LIKE 'exptest-%'", ARRAY_A ), 'profile' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
$check( 'clean-up removes log rows older than the retention window and keeps newer ones', ! in_array( 'exptest-old', $remaining, true ) && in_array( 'exptest-new', $remaining, true ), implode( ',', $remaining ) );
$check( 'clean-up removes a stale file and a stale folder from the private folder', ! file_exists( $stale ) && ! is_dir( $stale_dir ) );
$wpdb->query( "DELETE FROM {$wpdb->prefix}estasoft_hp_exports WHERE profile LIKE 'exptest-%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

$cleanup();
echo "\n{$checks} checks, {$failures} failed\n";
exit( $failures > 0 ? 1 : 0 );
