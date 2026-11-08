<?php
/**
 * Integration test for incident records (CHG-4). Run on a WordPress test site:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/incidents.php
 *
 * It writes records and snapshots to the site and removes them again, so use a test site.
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Domain\Freshness;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Export\ExportOptions;
use Estasoft\HandoverPassport\Export\Profile;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\SecretFound;

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

$cleanup = static function () use ( $plugin ): void {
	foreach ( $plugin->snapshots()->all() as $snap ) {
		if ( 0 === strpos( (string) $snap['name'], 'INCTEST' ) ) {
			$plugin->snapshots()->repo()->delete( (int) $snap['id'] );
		}
	}
	foreach ( $plugin->records()->everything() as $old ) {
		if ( 0 === stripos( (string) $old['title'], 'inctest' ) ) {
			$plugin->records()->items()->delete( (int) $old['id'] );
		}
	}
};
$cleanup();

$full = new ExportOptions( true, array(), 1 );
$none = new ExportOptions( false, array(), 0 );

// ---- the type ------------------------------------------------------------------------------------------------------
$def = Kinds::get( Kinds::INCIDENT );
foreach ( array( 'summary', 'occurred_on', 'what_happened', 'impact', 'cause', 'fix', 'minutes_to_restore', 'runbook_used', 'follow_ups' ) as $key ) {
	$check( "an incident has the field {$key}", isset( $def['fields'][ $key ] ) );
}
$check( 'the date and the one-line summary are required', ! empty( $def['fields']['summary']['required'] ) && ! empty( $def['fields']['occurred_on']['required'] ) );
$check( 'it is a register kind, so the CLI and REST list it', in_array( Kinds::INCIDENT, Kinds::registerKinds(), true ) );
$check( 'it is not offered to a client or on the Emergency sheet unless chosen', array( 0, 0 ) === $def['vis'] );

// ---- a whole report ---------------------------------------------------------------------------------------------------
$input = array(
	'summary'            => 'INCTEST Checkout down after a plugin update',
	'occurred_on'        => '2027-01-12',
	'what_happened'      => 'The shop plugin updated overnight and the checkout page returned a fatal error.',
	'impact'             => 'No orders for 3 hours.',
	'cause'              => 'The update needed a newer PHP version than the host ran.',
	'fix'                => 'Rolled the plugin back, then asked the host to move the site to PHP 8.2.',
	'minutes_to_restore' => '185',
	'runbook_used'       => 'Restore the site from a backup',
	'follow_ups'         => "Turn off automatic updates for the shop plugin\nTest updates on staging first",
);
$one = $plugin->records()->create( Kinds::INCIDENT, $input );
$id  = (int) $one['id'];
$got = $plugin->records()->get( $id, true );
$check( 'the report is stored', is_array( $got ) && 'INCTEST Checkout down after a plugin update' === $got['title'] );
$check( 'every field comes back as written', '2027-01-12' === $got['fields']['occurred_on'] && 185 === (int) $got['fields']['minutes_to_restore'] && 'Restore the site from a backup' === $got['fields']['runbook_used'] && 'No orders for 3 hours.' === $got['fields']['impact'] );
$check( 'the follow-ups are a list', array( 'Turn off automatic updates for the shop plugin', 'Test updates on staging first' ) === $got['fields']['follow_ups'] );
$check( 'an incident never goes stale', Freshness::VERIFIED === $got['freshness'] );
$later = $wpdb->query( $wpdb->prepare( 'UPDATE ' . \Estasoft\HandoverPassport\Data\Tables::name( 'items' ) . ' SET created_at = %s, verified_at = NULL WHERE id = %d', '2015-01-01 00:00:00', $id ) );
$check( 'not even years later', Freshness::VERIFIED === $plugin->records()->get( $id, true )['freshness'] && false !== $later );
$plugin->findings()->run( true );
$stale = array_filter( $plugin->findings()->list( array( 'state' => 'open' ) ), static fn( array $f ): bool => 'HP-PRT-001' === $f['rule_id'] && $id === (int) $f['subject_id'] );
$check( 'so no finding asks for it to be verified', array() === $stale );

// ---- validation ---------------------------------------------------------------------------------------------------------------
$check( 'a report needs a summary', $throws( fn() => $plugin->records()->create( Kinds::INCIDENT, array( 'occurred_on' => '2027-01-12' ) ) ) instanceof ValidationError );
$check( 'and a date', $throws( fn() => $plugin->records()->create( Kinds::INCIDENT, array( 'summary' => 'INCTEST no date' ) ) ) instanceof ValidationError );
$check( 'the date must be a real date', $throws( fn() => $plugin->records()->create( Kinds::INCIDENT, array( 'summary' => 'INCTEST bad date', 'occurred_on' => '12/01/2027' ) ) ) instanceof ValidationError );
$check( 'the time to restore must be a whole number', $throws( fn() => $plugin->records()->update( $id, array( 'minutes_to_restore' => 'three hours' ) ) ) instanceof ValidationError );
$check( 'a text over its limit is refused', $throws( fn() => $plugin->records()->update( $id, array( 'cause' => str_repeat( 'c', 2001 ) ) ) ) instanceof ValidationError );
$check( 'more than ten follow-ups are refused', $throws( fn() => $plugin->records()->update( $id, array( 'follow_ups' => implode( "\n", array_fill( 0, 11, 'x' ) ) ) ) ) instanceof ValidationError );
$secret = 'AKIAIOSFODNN7EXAMPLE';
foreach ( array( 'what_happened', 'cause', 'fix', 'impact', 'follow_ups', 'runbook_used' ) as $field ) {
	$e = $throws( fn() => $plugin->records()->update( $id, array( $field => "see {$secret}" ) ) );
	$check( "a secret in {$field} is refused and not repeated", $e instanceof SecretFound && false === strpos( $e->getMessage(), $secret ) );
}
$check( 'nothing was stored by the refusals', false === strpos( wp_json_encode( $plugin->records()->get( $id, true ) ), $secret ) );

// ---- history ---------------------------------------------------------------------------------------------------------------------
$log = array_column( $plugin->changeLog()->repo()->search( array( 'subject_id' => $id ), 10 ), 'kind' );
$check( 'adding the report is in the change log', in_array( 'record_added', $log, true ) );
$plugin->records()->update( $id, array( 'minutes_to_restore' => '190' ) );
$check( 'and so is changing it', in_array( 'record_edited', array_column( $plugin->changeLog()->repo()->search( array( 'subject_id' => $id ), 10 ), 'kind' ), true ) );

// ---- packs ---------------------------------------------------------------------------------------------------------------------------
$sens = $plugin->records()->create( Kinds::INCIDENT, array( 'summary' => 'INCTEST Sensitive breach report', 'occurred_on' => '2027-01-20', 'sensitive' => '1' ) );
$older = $plugin->records()->create( Kinds::INCIDENT, array( 'summary' => 'INCTEST Earlier slow-down', 'occurred_on' => '2026-11-02' ) );
$snaps = $plugin->snapshots();
$tech  = $snaps->create( Profile::TECHNICAL, 'INCTEST technical', '', $none );
$text  = (string) $snaps->get( $tech )['content'];
$check( 'the Technical pack holds the incident', false !== strpos( $text, 'INCTEST Checkout down after a plugin update' ) && false !== strpos( $text, 'Rolled the plugin back' ) );
$check( 'with its follow-ups', false !== strpos( $text, 'Test updates on staging first' ) );
$check( 'but not a Sensitive one', false === strpos( $text, 'Sensitive breach report' ) );
$check( 'the latest incident comes first', strpos( $text, 'INCTEST Checkout down' ) < strpos( $text, 'INCTEST Earlier slow-down' ) );
$client = $snaps->create( Profile::CLIENT, 'INCTEST client', '', $none );
$check( 'the Client pack has no incidents', false === strpos( (string) $snaps->get( $client )['content'], 'INCTEST Checkout' ) );
$emergency = $snaps->create( Profile::EMERGENCY, 'INCTEST emergency', '', $none );
$check( 'nor does the Emergency sheet', false === strpos( (string) $snaps->get( $emergency )['content'], 'INCTEST Checkout' ) );
$full_id = $snaps->create( Profile::FULL, 'INCTEST full', '', $full );
$check( 'the Full pack of someone who may see Sensitive records has the Sensitive report', false !== strpos( (string) $snaps->get( $full_id )['content'], 'Sensitive breach report' ) );
$check( 'the snapshots still verify', true === $snaps->verify( $tech )['ok'] && true === $snaps->verify( $full_id )['ok'] );

$plugin->records()->update( $id, array( 'fix' => 'Changed after the snapshot' ) );
$diff = $snaps->compare( $tech, 'live', $full );
$check( 'compare notices that the incident changed since the snapshot', false !== strpos( wp_json_encode( $diff ), 'INCTEST Checkout down' ) );

// ---- deleting -----------------------------------------------------------------------------------------------------------------------------
$plugin->records()->delete( (int) $older['id'] );
$check( 'a deleted report is no longer returned', null === $plugin->records()->get( (int) $older['id'], true ) );
$listed = array_column( $plugin->records()->listKind( Kinds::INCIDENT, false ), 'title' );
$check( 'the list holds the other reports but not the deleted one', in_array( 'INCTEST Checkout down after a plugin update', $listed, true ) && ! in_array( 'INCTEST Earlier slow-down', $listed, true ) );

$cleanup();
$left = array_filter( $plugin->records()->everything(), static fn( array $r ): bool => 0 === stripos( (string) $r['title'], 'inctest' ) );
$check( 'cleanup: nothing is left behind', array() === $left );
echo "\n" . ( $checks - $failures ) . ' of ' . $checks . " checks passed\n";
if ( $failures > 0 ) {
	exit( 1 );
}
