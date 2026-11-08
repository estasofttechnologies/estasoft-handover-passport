<?php
/**
 * Integration test for the transfer tracker (OWN-3). Run on a WordPress test site:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/transfers.php
 *
 * It writes records to the site and removes them again, so use a test site.
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Handover\HandoverChecklist;
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

$plugin = Plugin::instance();
$plugin->reset();
wp_set_current_user( 1 );

$cleanup = static function () use ( $plugin ): void {
	foreach ( $plugin->records()->everything() as $old ) {
		if ( 0 === stripos( (string) $old['title'], 'trftest' ) ) {
			$plugin->records()->items()->delete( (int) $old['id'] );
		}
	}
};
$cleanup();

$row = static function ( int $id ) use ( $plugin ): ?array {
	foreach ( $plugin->handover()->items() as $item ) {
		if ( 'transfer:' . $id === $item['key'] ) {
			return $item;
		}
	}
	return null;
};
$open_findings = static function ( int $id ) use ( $plugin ): array {
	$plugin->findings()->run( true );
	return array_values(
		array_filter(
			$plugin->findings()->list( array( 'state' => 'open' ) ),
			static fn( array $f ): bool => 'HP-OWN-002' === $f['rule_id'] && $id === (int) $f['subject_id']
		)
	);
};

// ---- the fields --------------------------------------------------------------------------------------------------
$fields = Kinds::get( Kinds::DOMAIN )['fields'];
foreach ( array( 'transfer_status', 'transfer_date', 'transfer_owner', 'transfer_notes' ) as $name ) {
	$check( "a domain has the field {$name}", isset( $fields[ $name ] ) );
}
$check( 'the five states are To do, Requested, In progress, Done and Not needed', array( 'todo', 'requested', 'in_progress', 'done', 'not_needed' ) === array_keys( Kinds::transferStates() ) );
$check( 'a note kind has no transfer fields', ! isset( Kinds::get( Kinds::STACKNOTE )['fields']['transfer_status'] ) );
$check( 'an agency-owned asset with no status needs a transfer', true === Kinds::transferNeeded( 'agency', '' ) );
$check( 'a third-party-owned asset does too', true === Kinds::transferNeeded( 'third_party', '' ) );
$check( 'an asset with an unknown owner does too', true === Kinds::transferNeeded( 'unknown', '' ) );
$check( 'a client-owned asset does not', false === Kinds::transferNeeded( 'client', '' ) );
$check( 'an asset with no owner recorded does not', false === Kinds::transferNeeded( '', '' ) );
$check( 'but a client-owned asset with a status is tracked', true === Kinds::transferNeeded( 'client', 'requested' ) );
$check( 'Done and Not needed close a transfer, the others do not', Kinds::transferClosed( 'done' ) && Kinds::transferClosed( 'not_needed' ) && ! Kinds::transferClosed( 'todo' ) && ! Kinds::transferClosed( 'requested' ) && ! Kinds::transferClosed( 'in_progress' ) && ! Kinds::transferClosed( '' ) );

// ---- an agency-owned asset, no status yet -------------------------------------------------------------------------------
$domain = $plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'trftest-agency.example', 'expires_on' => '2027-04-01', 'legal_owner' => 'agency' ) );
$id     = (int) $domain['id'];
$item   = $row( $id );
$check( 'it appears on the handover checklist', null !== $item );
$check( 'as Not done, saying no status has been set', null !== $item && HandoverChecklist::NOT_DONE === $item['state'] && 'No status yet' === $item['detail'] );
$check( 'and the label names the asset', null !== $item && false !== strpos( $item['label'], 'trftest-agency.example' ) );
$check( 'a finding says the transfer is not marked Done', 1 === count( $open_findings( $id ) ) );

// ---- each state --------------------------------------------------------------------------------------------------------------
$plugin->records()->update( $id, array( 'transfer_status' => 'requested', 'transfer_date' => '2027-02-01', 'transfer_owner' => 'Bob', 'transfer_notes' => 'Waiting for the registrar to send the code.' ) );
$item = $row( $id );
$check( 'Requested is still Not done', null !== $item && HandoverChecklist::NOT_DONE === $item['state'] && 'Requested' === $item['detail'] );
$check( 'the task owner and the date go to the checklist', null !== $item && 'Bob' === $item['who'] && '2027-02-01' === $item['when'] );
$saved = $plugin->records()->get( $id, true );
$check( 'the notes are stored with the record', 'Waiting for the registrar to send the code.' === ( $saved['fields']['transfer_notes'] ?? '' ) );
$check( 'the finding stays open', 1 === count( $open_findings( $id ) ) );

foreach ( array( 'todo' => 'To do', 'in_progress' => 'In progress' ) as $status => $label ) {
	$plugin->records()->update( $id, array( 'transfer_status' => $status ) );
	$item = $row( $id );
	$check( "{$label} is Not done", null !== $item && HandoverChecklist::NOT_DONE === $item['state'] && $label === $item['detail'] );
}

$plugin->records()->update( $id, array( 'transfer_status' => 'done' ) );
$item = $row( $id );
$check( 'Done is Done on the checklist', null !== $item && HandoverChecklist::DONE === $item['state'] );
$check( 'and closes the finding', array() === $open_findings( $id ) );

$plugin->records()->update( $id, array( 'transfer_status' => 'not_needed' ) );
$item = $row( $id );
$check( 'Not needed is Not applicable on the checklist', null !== $item && HandoverChecklist::NA === $item['state'] );
$check( 'and the finding stays closed', array() === $open_findings( $id ) );

// ---- validation ---------------------------------------------------------------------------------------------------------------
$check( 'an unknown status is refused', $throws( fn() => $plugin->records()->update( $id, array( 'transfer_status' => 'sorted' ) ) ) instanceof ValidationError );
$check( 'a date that is not a date is refused', $throws( fn() => $plugin->records()->update( $id, array( 'transfer_date' => 'next week' ) ) ) instanceof ValidationError );
$check( 'notes over 1000 characters are refused', $throws( fn() => $plugin->records()->update( $id, array( 'transfer_notes' => str_repeat( 'n', 1001 ) ) ) ) instanceof ValidationError );
$secret = 'AKIAIOSFODNN7EXAMPLE';
$e      = $throws( fn() => $plugin->records()->update( $id, array( 'transfer_notes' => "code {$secret}" ) ) );
$check( 'notes that look like a secret are refused and not repeated', $e instanceof SecretFound && false === strpos( $e->getMessage(), $secret ) );
$e = $throws( fn() => $plugin->records()->update( $id, array( 'transfer_owner' => "token {$secret}" ) ) );
$check( 'so is the name of who handles it', $e instanceof SecretFound );
$check( 'nothing was stored by the refusals', false === strpos( wp_json_encode( $plugin->records()->get( $id, true ) ), $secret ) );

// ---- other owners ------------------------------------------------------------------------------------------------------------------
$client = $plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'trftest-client.example', 'expires_on' => '2027-04-01', 'legal_owner' => 'client' ) );
$check( 'a client-owned asset is not on the checklist', null === $row( (int) $client['id'] ) );
$plugin->records()->update( (int) $client['id'], array( 'transfer_status' => 'requested' ) );
$check( 'until a status is set', null !== $row( (int) $client['id'] ) );
$third = $plugin->records()->create( Kinds::LICENCE, array( 'product' => 'TRFTEST Plugin licence', 'legal_owner' => 'third_party' ) );
$item  = $row( (int) $third['id'] );
$check( 'a third-party licence is tracked as well', null !== $item && HandoverChecklist::NOT_DONE === $item['state'] );

// ---- Sensitive records -----------------------------------------------------------------------------------------------------------------
$hidden = $plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'trftest-hidden.example', 'expires_on' => '2027-04-01', 'legal_owner' => 'agency', 'sensitive' => '1' ) );
$check( 'a Sensitive asset is left off the checklist, so no pack can name it', null === $row( (int) $hidden['id'] ) );
$check( 'the checklist text holds no trace of it', false === strpos( wp_json_encode( $plugin->handover()->items() ), 'trftest-hidden' ) );

// ---- progress ------------------------------------------------------------------------------------------------------------------------------
$before = $plugin->handover()->progress();
$plugin->records()->update( (int) $third['id'], array( 'transfer_status' => 'done' ) );
$after = $plugin->handover()->progress();
$check( 'finishing a transfer moves the checklist progress by one', $after['done'] === $before['done'] + 1 && $after['total'] === $before['total'] );

$cleanup();
$check( 'cleanup: nothing is left behind', array() === array_filter( $plugin->records()->everything(), static fn( array $r ): bool => 0 === stripos( (string) $r['title'], 'trftest' ) ) );
echo "\n" . ( $checks - $failures ) . ' of ' . $checks . " checks passed\n";
if ( $failures > 0 ) {
	exit( 1 );
}
