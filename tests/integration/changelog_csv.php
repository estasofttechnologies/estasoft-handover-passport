<?php
/**
 * Integration test for the change-log CSV (CHG-6). Run on a WordPress test site:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/changelog_csv.php
 *
 * It writes records, notes and log entries to the site and removes them again, so use a test site.
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Admin\ChangeCsv;
use Estasoft\HandoverPassport\Data\Tables;
use Estasoft\HandoverPassport\Domain\Kinds;
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
$baseline = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Tables::name( 'changes' ) );

$cleanup = static function () use ( $plugin, $wpdb, $baseline ): void {
	foreach ( $plugin->records()->everything() as $old ) {
		if ( 0 === stripos( (string) $old['title'], 'csvtest' ) ) {
			$plugin->records()->items()->delete( (int) $old['id'] );
		}
	}
	$wpdb->query( 'DELETE FROM ' . Tables::name( 'changes' ) . " WHERE id > {$baseline}" );
};
$cleanup();

$parse = static function ( string $bytes ): array {
	$h = fopen( 'php://temp', 'r+' );
	fwrite( $h, $bytes );
	rewind( $h );
	$out = array();
	while ( false !== ( $row = fgetcsv( $h, 0, ',', '"', '' ) ) ) {
		$out[] = $row;
	}
	fclose( $h );
	return $out;
};

// ---- the file -----------------------------------------------------------------------------------------------------
$plugin->changeLog()->add( 'maintenance_note', 'note', 0, 'Fix', array(), array( 'date' => '2027-01-05', 'type' => 'fix', 'asset' => 'Shop' ), 'CSVTEST has a comma, "quotes" and' . "\n" . 'a new line', 'wordpress', 'Alex Rowe', 1 );
$plugin->changeLog()->add( 'maintenance_note', 'note', 0, 'Update', array(), array( 'date' => '2027-01-06', 'type' => 'update' ), '=HYPERLINK("http://evil.example","click")', 'wordpress', '@mallory', 1 );
$rows  = $plugin->changeLog()->repo()->search( array( 'kind' => 'maintenance_note' ), 10 );
$bytes = ChangeCsv::build( $rows );
$check( 'the file starts with a byte order mark', 0 === strpos( $bytes, "\xEF\xBB\xBF" ) );
$check( 'rows end with a carriage return and a line feed', false !== strpos( $bytes, "\r\n" ) );
$table = $parse( substr( $bytes, 3 ) );
$check( 'the first row is the header', array( 'When (UTC)', 'What', 'Kind', 'Who', 'Source', 'Note' ) === $table[0] );
$check( 'one row per entry follows', count( $table ) === count( $rows ) + 1 );
$mine = array_values( array_filter( $table, static fn( array $r ): bool => false !== strpos( (string) ( $r[5] ?? '' ), 'CSVTEST' ) ) );
$check( 'a comma, quotes and a new line survive in one cell', 1 === count( $mine ) && 'CSVTEST has a comma, "quotes" and' . "\n" . 'a new line' === $mine[0][5] );
$check( 'who and kind are in their columns', 1 === count( $mine ) && 'Alex Rowe' === $mine[0][3] && 'maintenance_note' === $mine[0][2] );
$check( 'the time is the stored UTC time', 1 === count( $mine ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $mine[0][0] ) );
$hyper = array_values( array_filter( $table, static fn( array $r ): bool => false !== strpos( (string) ( $r[5] ?? '' ), 'HYPERLINK' ) ) );
$check( 'a note that starts with = is quoted so a spreadsheet reads it as text', 1 === count( $hyper ) && "'=HYPERLINK(\"http://evil.example\",\"click\")" === $hyper[0][5] );
$check( 'so is a name that starts with @', 1 === count( $hyper ) && "'@mallory" === $hyper[0][3] );
$check( 'an empty list gives just the header', 1 === count( $parse( substr( ChangeCsv::build( array() ), 3 ) ) ) );

// ---- Sensitive entries, as the screen masks them ---------------------------------------------------------------------------
$secret = $plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'csvtest-secret.example', 'expires_on' => '2027-04-01', 'sensitive' => '1' ) );
$plain  = $plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'csvtest-plain.example', 'expires_on' => '2027-04-01' ) );
$all    = $plugin->changeLog()->repo()->search( array( 'kind' => 'record_added' ), 20 );
$seen   = ChangeCsv::build( $plugin->changeLog()->forViewer( $all, true ) );
$masked = ChangeCsv::build( $plugin->changeLog()->forViewer( $all, false ) );
$check( 'someone who may see Sensitive records gets the name', false !== strpos( $seen, 'csvtest-secret.example' ) );
$check( 'someone who may not never gets it', false === strpos( $masked, 'csvtest-secret.example' ) );
$check( 'they get the plain record, and a generic line for the Sensitive one', false !== strpos( $masked, 'csvtest-plain.example' ) && false !== strpos( $masked, 'A Sensitive record' ) );

// ---- the limit -----------------------------------------------------------------------------------------------------------------
$check( 'one file holds at most 10000 entries', 10000 === ChangeCsv::LIMIT );

$cleanup();
echo "\n" . ( $checks - $failures ) . ' of ' . $checks . " checks passed\n";
if ( $failures > 0 ) {
	exit( 1 );
}
