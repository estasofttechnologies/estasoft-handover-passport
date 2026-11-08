<?php
/**
 * Integration test for the WordPress personal-data export and erase tools (PRV-3, PPL-5). Run on a WordPress test site:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/privacy_tools.php
 *
 * It writes people, records, snapshots, users and log rows to the site and removes them again, so use a test site.
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Data\ChangeRepository;
use Estasoft\HandoverPassport\Data\LinkRepository;
use Estasoft\HandoverPassport\Data\PeopleRepository;
use Estasoft\HandoverPassport\Data\ScanRepository;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Export\ExportOptions;
use Estasoft\HandoverPassport\Export\Profile;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Privacy\PersonalData;
use Estasoft\HandoverPassport\Services\PeopleService;

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
require_once ABSPATH . 'wp-admin/includes/user.php';

$people_repo = new PeopleRepository();
$changes     = new ChangeRepository();
$tools       = new PersonalData( $plugin );

const PRVTEST_OPTION = 'estasoft_hp_prvtest_people';

$cleanup = static function () use ( $plugin, $people_repo, $changes ): void {
	global $wpdb;
	$links = new LinkRepository();
	foreach ( $plugin->records()->everything() as $old ) {
		if ( 0 === stripos( (string) $old['title'], 'prvtest' ) ) {
			$links->forget( 'item', (int) $old['id'] );
			$plugin->records()->items()->delete( (int) $old['id'] );
		}
	}
	foreach ( $plugin->snapshots()->all() as $old ) {
		if ( 0 === strpos( (string) $old['name'], 'PRVTEST' ) ) {
			$plugin->snapshots()->delete( (int) $old['id'] );
		}
	}
	// People are found by number, because an erased person no longer carries a name or an address.
	$ids = array_map( 'intval', (array) get_option( PRVTEST_OPTION, array() ) );
	foreach ( $people_repo->query() as $old ) {
		if ( 0 === stripos( (string) $old['name'], 'prvtest' ) || 0 === stripos( (string) $old['email'], 'prvtest' ) ) {
			$ids[] = (int) $old['id'];
		}
	}
	foreach ( array_unique( $ids ) as $id ) {
		$links->forget( 'person', $id );
		$people_repo->delete( $id );
	}
	delete_option( PRVTEST_OPTION );
	foreach ( array( 'prvtest.admin@example.org', 'prvtest.gone@example.org', 'prvtest.editor@example.org' ) as $email ) {
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			wp_delete_user( (int) $user->ID );
		}
	}
	$wpdb->query( "DELETE FROM {$changes->table()} WHERE subject_label LIKE 'Prvtest%' OR actor_label LIKE 'Prvtest%' OR kind = 'personal_data_erased' OR actor_label = 'Former user' OR subject_label IN ('Former user', 'Former contact')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
};
$cleanup();

/**
 * All values in an export or an erase result, for a "does it mention" search.
 */
$flat = static fn( $value ): string => (string) wp_json_encode( $value );

// ---- the hooks ----------------------------------------------------------------------------------------------------------------------
$tools->register();
$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
$check( 'two exporters are registered', isset( $exporters['estasoft-handover-passport'], $exporters['estasoft-handover-passport-changes'] ) );
$check( 'the exporters have a name and a callable', '' !== $exporters['estasoft-handover-passport']['exporter_friendly_name'] && is_callable( $exporters['estasoft-handover-passport']['callback'] ) && is_callable( $exporters['estasoft-handover-passport-changes']['callback'] ) );
$check( 'one eraser is registered', isset( $erasers['estasoft-handover-passport'] ) && is_callable( $erasers['estasoft-handover-passport']['callback'] ) && '' !== $erasers['estasoft-handover-passport']['eraser_friendly_name'] );
$check( 'another plugin\'s exporters are kept', isset( apply_filters( 'wp_privacy_personal_data_exporters', array( 'other' => array( 'exporter_friendly_name' => 'X', 'callback' => '__return_empty_array' ) ) )['other'] ) );

// ---- people to work with -------------------------------------------------------------------------------------------------------------
$alice = $plugin->people()->create(
	array(
		'name'          => 'Prvtest Alice',
		'organisation'  => 'PrvOrg',
		'kind'          => 'client',
		'title'         => 'Finance lead',
		'email'         => 'prvtest.alice@example.org',
		'phone'         => '+44 20 7946 0000',
		'language'      => 'en',
		'timezone'      => 'Europe/London',
		'urgent_method' => 'Call the office',
		'note'          => 'Prefers email in the morning',
		'roles'         => array( 'billing_contact', 'emergency_contact' ),
	)
);
$bob   = $plugin->people()->create( array( 'name' => 'Prvtest Bob', 'organisation' => 'PrvOrg', 'email' => 'prvtest.bob@example.org', 'phone' => '+44 20 7946 1111', 'roles' => array( 'site_owner' ) ) );
$other = $plugin->people()->create( array( 'name' => 'Prvtest Alice', 'organisation' => 'OtherOrg', 'email' => 'prvtest.alice.other@example.org', 'phone' => '+44 20 7946 2222' ) );
$aid   = (int) $alice['id'];
$bid   = (int) $bob['id'];
$oid   = (int) $other['id'];
update_option( PRVTEST_OPTION, array( $aid, $bid, $oid ), false );

$domain = $plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'prvtest-owned.example', 'expires_on' => '2027-03-01', 'account_holder' => array( $aid ), 'payer' => array( $bid ) ) );
$hidden = $plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'prvtest-hidden.example', 'expires_on' => '2027-03-01', 'sensitive' => '1', 'administrators' => array( $aid ) ) );
$lic    = $plugin->records()->create( Kinds::LICENCE, array( 'product' => 'PRVTEST licence', 'renews_on' => '2027-04-01', 'account_email' => 'prvtest.alice@example.org' ) );
$plain  = $plugin->records()->create( Kinds::LICENCE, array( 'product' => 'PRVTEST unrelated', 'renews_on' => '2027-04-01', 'account_email' => 'prvtest.bob@example.org' ) );

$snap = $plugin->snapshots()->create( Profile::CLIENT, 'PRVTEST snapshot', '', new ExportOptions( false, array(), 1 ) );
$plugin->snapshots()->acknowledge( $snap, array( 'name' => 'Prvtest Alice', 'organisation' => 'PrvOrg', 'role' => 'Owner', 'method' => 'email', 'note' => 'Alice confirmed by reply' ) );
$plugin->snapshots()->acknowledge( $snap, array( 'name' => 'prvtest alice', 'organisation' => 'OtherOrg', 'role' => 'Assistant', 'method' => 'phone', 'note' => 'The other Alice' ) );
$plugin->snapshots()->acknowledge( $snap, array( 'name' => 'Prvtest Bob', 'method' => 'in_person', 'note' => 'Bob took the copy' ) );
$snap_before = $plugin->snapshots()->get( $snap );

// ---- export ---------------------------------------------------------------------------------------------------------------------------
$none = $tools->export( 'nobody-prvtest@example.org' );
$check( 'an address nobody uses gives an empty export that is done', array() === $none['data'] && true === $none['done'] );
$check( 'an invalid address gives an empty export', array() === $tools->export( 'not an address' )['data'] && array() === $tools->export( '' )['data'] );
$check( 'a changes export for an unknown address is empty and done', array() === $tools->exportChanges( 'nobody-prvtest@example.org' )['data'] && true === $tools->exportChanges( 'nobody-prvtest@example.org' )['done'] );

$out    = $tools->export( 'PRVTEST.Alice@Example.org ' );
$groups = array();
foreach ( $out['data'] as $group ) {
	$groups[ $group['group_id'] ][] = $group;
}
$check( 'the export is done in one page', true === $out['done'] );
$check( 'the address is matched without regard to case or spaces', isset( $groups['estasoft-hp-directory'] ) );
$check( 'one directory item for the one person', 1 === count( $groups['estasoft-hp-directory'] ?? array() ) && 'person-' . $aid === $groups['estasoft-hp-directory'][0]['item_id'] );
$values = array();
foreach ( $groups['estasoft-hp-directory'][0]['data'] ?? array() as $pair ) {
	$values[ $pair['name'] ] = $pair['value'];
}
$check( 'it lists the name, organisation, title, email, phone, language, time zone, urgent method and note', 'Prvtest Alice' === ( $values['Name'] ?? '' ) && 'PrvOrg' === ( $values['Organisation'] ?? '' ) && 'Finance lead' === ( $values['Title'] ?? '' ) && 'prvtest.alice@example.org' === ( $values['Email'] ?? '' ) && '+44 20 7946 0000' === ( $values['Phone'] ?? '' ) && 'en' === ( $values['Language'] ?? '' ) && 'Europe/London' === ( $values['Time zone'] ?? '' ) && 'Call the office' === ( $values['How to reach them urgently'] ?? '' ) && 'Prefers email in the morning' === ( $values['Note'] ?? '' ), $flat( $values ) );
$check( 'it names the roles in words', false !== strpos( (string) ( $values['Roles'] ?? '' ), 'Billing contact' ) && false !== strpos( (string) ( $values['Roles'] ?? '' ), 'Emergency contact' ), (string) ( $values['Roles'] ?? '' ) );
$check( 'it names the kind in words', 'Client' === ( $values['Kind'] ?? '' ) );
$empty_ok = true;
foreach ( $out['data'] as $group ) {
	foreach ( $group['data'] as $pair ) {
		if ( '' === trim( (string) $pair['value'] ) || '' === (string) $pair['name'] ) {
			$empty_ok = false;
		}
	}
	foreach ( array( 'group_id', 'group_label', 'item_id', 'data' ) as $key ) {
		if ( ! array_key_exists( $key, $group ) ) {
			$empty_ok = false;
		}
	}
}
$check( 'every group has the keys WordPress needs and no empty values', $empty_ok );
$check( 'nothing about the other people is in it', false === strpos( $flat( $out ), 'Bob' ) && false === strpos( $flat( $out ), 'prvtest.bob' ) && false === strpos( $flat( $out ), 'OtherOrg' ) && false === strpos( $flat( $out ), 'alice.other' ) );

$records = array_map( static fn( array $g ): string => (string) ( $g['data'][0]['value'] ?? '' ), $groups['estasoft-hp-records'] ?? array() );
$check( 'it lists the record the person holds', in_array( 'Domain: prvtest-owned.example', $records, true ), $flat( $records ) );
$check( 'a Sensitive record is named by its kind only', in_array( 'Domain', $records, true ) && false === strpos( $flat( $out ), 'prvtest-hidden' ) );
$mentions = array_map( static fn( array $g ): string => (string) ( $g['data'][0]['value'] ?? '' ), $groups['estasoft-hp-mentions'] ?? array() );
$check( 'it lists the record whose text holds the address', array( 'Licence: PRVTEST licence' ) === $mentions, $flat( $mentions ) );

$acks = $groups['estasoft-hp-acknowledgements'] ?? array();
$check( 'one acknowledgement is listed: the one by this name and organisation', 1 === count( $acks ), (string) count( $acks ) );
$ack_text = $flat( $acks );
$check( 'it carries the snapshot name, the way it was received and the note', false !== strpos( $ack_text, 'PRVTEST snapshot' ) && false !== strpos( $ack_text, 'Email' ) && false !== strpos( $ack_text, 'Alice confirmed by reply' ) );
$check( 'the same name at another organisation is not listed', false === strpos( $ack_text, 'The other Alice' ) );
$check( 'another person\'s acknowledgement is not listed', false === strpos( $ack_text, 'Bob took the copy' ) );

$other_out = $tools->export( 'prvtest.alice.other@example.org' );
$other_ack = $flat( array_values( array_filter( $other_out['data'], static fn( array $g ): bool => 'estasoft-hp-acknowledgements' === $g['group_id'] ) ) );
$check( 'the other Alice gets her own acknowledgement and not the first one', false !== strpos( $other_ack, 'The other Alice' ) && false === strpos( $other_ack, 'Alice confirmed by reply' ) );

// A deleted person is still stored, so the export lists them.
$gone = $plugin->people()->create( array( 'name' => 'Prvtest Gone', 'email' => 'prvtest.deleted@example.org' ) );
update_option( PRVTEST_OPTION, array( $aid, $bid, $oid, (int) $gone['id'] ), false );
$plugin->people()->delete( (int) $gone['id'] );
$del = $tools->export( 'prvtest.deleted@example.org' );
$check( 'a person deleted in the last 30 days is still exported, with the time of deletion', 1 === count( $del['data'] ) && false !== strpos( $flat( $del ), 'Deleted (UTC)' ) );

// ---- WordPress users: scan and change log ------------------------------------------------------------------------------------------
$admin_id  = wp_insert_user( array( 'user_login' => 'prvtest_admin', 'user_pass' => wp_generate_password(), 'user_email' => 'prvtest.admin@example.org', 'display_name' => 'Prvtest Admin', 'role' => 'administrator' ) );
$gone_id   = wp_insert_user( array( 'user_login' => 'prvtest_gone', 'user_pass' => wp_generate_password(), 'user_email' => 'prvtest.gone@example.org', 'display_name' => 'Prvtest Gone Admin', 'role' => 'administrator' ) );
$editor_id = wp_insert_user( array( 'user_login' => 'prvtest_editor', 'user_pass' => wp_generate_password(), 'user_email' => 'prvtest.editor@example.org', 'display_name' => 'Prvtest Editor', 'role' => 'editor' ) );
$check( 'test users exist', is_int( $admin_id ) && is_int( $gone_id ) && is_int( $editor_id ) );
$plugin->scanService()->run( 'cli' );

// Changes made by the admin.
wp_set_current_user( (int) $admin_id );
$plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'prvtest-by-admin.example', 'expires_on' => '2027-05-01' ) );
$plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'prvtest-by-admin-hidden.example', 'expires_on' => '2027-05-01', 'sensitive' => '1' ) );
wp_set_current_user( (int) $gone_id );
$plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'prvtest-by-gone.example', 'expires_on' => '2027-05-01' ) );
wp_set_current_user( (int) $editor_id );
$plugin->records()->create( Kinds::DOMAIN, array( 'domain' => 'prvtest-by-editor.example', 'expires_on' => '2027-05-01' ) );
wp_set_current_user( 1 );

$adm = $tools->export( 'prvtest.admin@example.org' );
$adm_groups = array_column( $adm['data'], 'group_id' );
$check( 'the administrator found by the scan is exported', in_array( 'estasoft-hp-administrators', $adm_groups, true ) && false !== strpos( $flat( $adm ), 'prvtest.admin@example.org' ), $flat( $adm_groups ) );
$check( 'the editor is not in the scan, so not in this export', array() === $tools->export( 'prvtest.editor@example.org' )['data'] );

$ch = $tools->exportChanges( 'prvtest.admin@example.org' );
$ch_text = $flat( $ch );
$check( 'the change-log export lists what the administrator did', count( $ch['data'] ) >= 2 && true === $ch['done'], (string) count( $ch['data'] ) );
$check( 'each entry has a time, a sentence and where it came from', false !== strpos( $ch_text, 'When (UTC)' ) && false !== strpos( $ch_text, 'prvtest-by-admin.example' ) && false !== strpos( $ch_text, 'Made through' ) );
$check( 'a change to a Sensitive record is listed without its name', false !== strpos( $ch_text, 'A Sensitive record' ) && false === strpos( $ch_text, 'prvtest-by-admin-hidden' ) );
$check( 'nobody else\'s changes are listed', false === strpos( $ch_text, 'prvtest-by-gone' ) && false === strpos( $ch_text, 'prvtest-by-editor' ) );
$editor_changes = $tools->exportChanges( 'prvtest.editor@example.org' );
$check( 'an editor, who is not an administrator, can still export their own changes', 1 <= count( $editor_changes['data'] ) && false !== strpos( $flat( $editor_changes ), 'prvtest-by-editor' ) );

// Pages of the change-log export.
$extra = PersonalData::PAGE_SIZE + 5;
for ( $i = 0; $i < $extra; $i++ ) {
	$changes->insert(
		array(
			'changed_at'    => '2026-01-01 00:00:00',
			'kind'          => 'record_added',
			'subject_type'  => 'item',
			'subject_id'    => 999999,
			'subject_label' => 'PRVTEST bulk ' . $i,
			'before_data'   => array(),
			'after_data'    => array(),
			'actor_id'      => (int) $editor_id,
			'actor_label'   => 'Prvtest Editor',
			'source'        => 'wordpress',
			'batch_id'      => '',
			'note'          => '',
			'sensitive'     => 0,
		)
	);
}
$total   = count( $changes->byActors( array( (int) $editor_id ) ) );
$p1      = $tools->exportChanges( 'prvtest.editor@example.org', 1 );
$p2      = $tools->exportChanges( 'prvtest.editor@example.org', 2 );
$check( 'there are more entries than one page holds', $total > PersonalData::PAGE_SIZE, (string) $total );
$check( 'the first page holds one page of entries and is not done', PersonalData::PAGE_SIZE === count( $p1['data'] ) && false === $p1['done'], count( $p1['data'] ) . ' ' . var_export( $p1['done'], true ) );
$check( 'the second page holds the rest and is done', count( $p2['data'] ) === $total - PersonalData::PAGE_SIZE && true === $p2['done'], count( $p2['data'] ) . ' of ' . $total );
$ids = array_merge( array_column( $p1['data'], 'item_id' ), array_column( $p2['data'], 'item_id' ) );
$check( 'no entry appears twice across the pages', count( $ids ) === count( array_unique( $ids ) ) && count( $ids ) === $total );

// ---- erase: the person in the directory ----------------------------------------------------------------------------------------
$change_before = $changes->countFor( array() );
$res           = $tools->erase( 'prvtest.alice@example.org' );
$check( 'the erase is done in one page', true === $res['done'] );
$check( 'it says something was removed', true === $res['items_removed'] );
$check( 'it says something is kept, because a snapshot and a record still mention her', true === $res['items_retained'] && count( $res['messages'] ) >= 1, $flat( $res ) );
$msg_text = implode( ' ', $res['messages'] );
$check( 'the messages say what to do about the record', false !== strpos( $msg_text, 'record' ) && false !== strpos( $msg_text, 'by hand' ), $msg_text );

$row = $people_repo->find( $aid );
$check( 'the row is kept', null !== $row );
$check( 'the name is Former contact', PeopleService::FORMER_CONTACT === $row['name'] );
$check( 'organisation, title, email, phone, language, time zone, urgent method and note are blank', '' === $row['organisation'] && '' === $row['title'] && '' === $row['email'] && '' === $row['phone'] && '' === $row['language'] && '' === $row['timezone'] && '' === $row['urgent_method'] && '' === $row['note'], $flat( $row ) );
$check( 'the person is marked as having left', 0 === (int) $row['active'] && ! empty( $row['left_on'] ) );
$check( 'the kind and the sharing flags stay', 'client' === $row['kind'] && is_array( $row['share_flags'] ) );
$check( 'the roles stay as placeholders', in_array( $aid, $plugin->people()->inRole( 'billing_contact' ), true ) && in_array( $aid, $plugin->people()->inRole( 'emergency_contact' ), true ) );
$check( 'the link from the record stays', in_array( array( 'item_id' => (int) $domain['id'], 'relation' => 'account_holder' ), $plugin->people()->assetsFor( $aid ), true ) );
$check( 'the person reads as Former contact in the directory', 'Former contact' === ( $plugin->people()->get( $aid )['name'] ?? '' ) );
$check( 'Bob is untouched', 'Prvtest Bob' === $people_repo->find( $bid )['name'] && 'prvtest.bob@example.org' === $people_repo->find( $bid )['email'] && in_array( $bid, $plugin->people()->inRole( 'site_owner' ), true ) );
$check( 'the other Alice is untouched', 'Prvtest Alice' === $people_repo->find( $oid )['name'] && 'prvtest.alice.other@example.org' === $people_repo->find( $oid )['email'] );

$acks_after = $plugin->snapshots()->repo()->find( $snap )['acknowledgements'];
$check( 'there are still three acknowledgements', 3 === count( $acks_after ) );
$check( 'Alice\'s is anonymised: name, organisation and note', 'Former contact' === $acks_after[0]['name'] && '' === $acks_after[0]['organisation'] && '' === $acks_after[0]['note'], $flat( $acks_after[0] ) );
$check( 'its date, the way it was received, the role and who recorded it stay', $snap_before['acknowledgements'][0]['date'] === $acks_after[0]['date'] && 'email' === $acks_after[0]['method'] && 'Owner' === $acks_after[0]['role'] && $snap_before['acknowledgements'][0]['recorded_by'] === $acks_after[0]['recorded_by'] );
$check( 'the same name at another organisation is untouched', 'prvtest alice' === $acks_after[1]['name'] && 'The other Alice' === $acks_after[1]['note'] );
$check( 'Bob\'s is untouched', 'Prvtest Bob' === $acks_after[2]['name'] && 'Bob took the copy' === $acks_after[2]['note'] );
$check( 'the snapshot content, hash and name are untouched', $snap_before['content'] === $plugin->snapshots()->repo()->find( $snap )['content'] && $snap_before['hash'] === $plugin->snapshots()->repo()->find( $snap )['hash'] );
$check( 'the snapshot still verifies', true === $plugin->snapshots()->verify( $snap )['ok'] );

$entries = $changes->search( array( 'subject_type' => 'person', 'subject_id' => $aid ), 0 );
$labels  = array_unique( array_column( $entries, 'subject_label' ) );
$check( 'the log entries about her name her as Former contact', count( $entries ) >= 1 && array( 'Former contact' ) === array_values( $labels ), $flat( $labels ) );
$bob_entries = array_unique( array_column( $changes->search( array( 'subject_type' => 'person', 'subject_id' => $bid ), 0 ), 'subject_label' ) );
$check( 'the log entries about Bob keep his name', array( 'Prvtest Bob' ) === array_values( $bob_entries ) );
$received = array();
foreach ( $changes->search( array( 'kind' => 'snapshot_acknowledged', 'subject_id' => $snap ), 0, 0, 'ASC' ) as $e ) {
	$received[] = (string) $e['after_data']['received_by'];
}
$check( 'the acknowledgement entries name Former contact, the other Alice and Bob', array( 'Former contact', 'prvtest alice', 'Prvtest Bob' ) === $received, $flat( $received ) );

$mark = $changes->search( array( 'kind' => 'personal_data_erased' ), 1 );
$check( 'one entry records that an erase happened', 1 === count( $mark ) );
$check( 'it holds counts and no address or name', false === strpos( $flat( $mark ), 'prvtest' ) && false === strpos( $flat( $mark ), 'alice' ) && 1 === (int) $mark[0]['after_data']['people'] && 1 === (int) $mark[0]['after_data']['acknowledgements'], $flat( $mark ) );
$check( 'it reads as a sentence in the change log', 'Personal data erased at the request of a person' === \Estasoft\HandoverPassport\Admin\ChangeText::describe( $mark[0] ) );

$again = $tools->erase( 'prvtest.alice@example.org' );
$check( 'running the erase again removes nothing more', false === $again['items_removed'] );
$check( 'the export of the same address is now empty of the directory', array() === array_filter( $tools->export( 'prvtest.alice@example.org' )['data'], static fn( array $g ): bool => 'estasoft-hp-directory' === $g['group_id'] ) );

// ---- erase: a WordPress administrator ------------------------------------------------------------------------------------------
$res_admin = $tools->erase( 'prvtest.admin@example.org' );
$check( 'an administrator\'s erase reports a removal', true === $res_admin['items_removed'] );
$check( 'it warns that the account still exists and will be listed again', true === $res_admin['items_retained'] && false !== strpos( implode( ' ', $res_admin['messages'] ), 'account' ), $flat( $res_admin ) );
$facts = (array) ( new ScanRepository() )->latestDone()['facts'];
$found = array();
foreach ( (array) $facts['users']['admins'] as $a ) {
	$found[ (int) $a['id'] ] = $a;
}
$check( 'the scan keeps the user number but not the name or address', isset( $found[ $admin_id ] ) && 'Former user' === $found[ $admin_id ]['name'] && '' === $found[ $admin_id ]['email'], $flat( $found ) );
$check( 'other administrators in the scan are untouched', isset( $found[ $gone_id ] ) && 'prvtest.gone@example.org' === $found[ $gone_id ]['email'] );
$mine = $changes->search( array( 'subject_type' => 'user', 'subject_id' => $admin_id ), 0 );
$check( 'the entries about the new administrator say Former user', array() === array_filter( $mine, static fn( array $e ): bool => 'Former user' !== $e['subject_label'] ), $flat( $mine ) );
$by_admin = $changes->search( array(), 0, 0, 'ASC' );
$by_admin = array_values( array_filter( $by_admin, static fn( array $e ): bool => false !== strpos( (string) $e['subject_label'], 'prvtest-by-admin' ) ) );
$check( 'the entries the administrator made no longer name them or carry the number', count( $by_admin ) >= 2 && array() === array_filter( $by_admin, static fn( array $e ): bool => 'Former user' !== $e['actor_label'] || 0 !== (int) $e['actor_id'] ), $flat( $by_admin ) );
$check( 'the export of the address finds nothing', array() === $tools->exportChanges( 'prvtest.admin@example.org' )['data'] && array() === $tools->export( 'prvtest.admin@example.org' )['data'] );
$check( 'the editor\'s entries are untouched', 'Prvtest Editor' === $changes->search( array( 'search' => 'PRVTEST bulk 0' ), 1 )[0]['actor_label'] );

// The account is gone: the address is found in the saved scans only.
wp_delete_user( (int) $gone_id );
$check( 'the account is gone', false === get_user_by( 'email', 'prvtest.gone@example.org' ) );
$res_gone = $tools->erase( 'prvtest.gone@example.org' );
$check( 'an erase after the account was deleted still removes the saved copies', true === $res_gone['items_removed'] );
$check( 'and has nothing to say about an account', false === strpos( implode( ' ', $res_gone['messages'] ), 'account' ), $flat( $res_gone ) );
$facts = (array) ( new ScanRepository() )->latestDone()['facts'];
$left  = array_filter( (array) $facts['users']['admins'], static fn( array $a ): bool => (int) $a['id'] === $gone_id );
$left  = array_values( $left );
$check( 'the saved scan no longer holds the name or address', 1 === count( $left ) && 'Former user' === $left[0]['name'] && '' === $left[0]['email'] );
$gone_entries = array_values( array_filter( $changes->search( array(), 0 ), static fn( array $e ): bool => false !== strpos( (string) $e['subject_label'], 'prvtest-by-gone' ) ) );
$check( 'the entries the deleted account made are anonymised', 1 === count( $gone_entries ) && 'Former user' === $gone_entries[0]['actor_label'] && 0 === (int) $gone_entries[0]['actor_id'] );

// A later scan does not announce the erased administrators as new.
$before_scan = $changes->countFor( array( 'kind' => 'administrator_added' ) );
$plugin->scanService()->run( 'cli' );
$check( 'the next scan does not log a "new administrator" for the account it already knew', $before_scan === $changes->countFor( array( 'kind' => 'administrator_added' ) ) );

// ---- an unrelated address and a malformed one change nothing --------------------------------------------------------------------
$untouched = $changes->countFor( array() );
$noop      = $tools->erase( 'nobody-prvtest@example.org' );
$check( 'an unknown address removes nothing and keeps nothing', false === $noop['items_removed'] && false === $noop['items_retained'] && array() === $noop['messages'] );
$noop2 = $tools->erase( 'nonsense' );
$check( 'a malformed address is ignored', false === $noop2['items_removed'] && true === $noop2['done'] );
$check( 'neither wrote to the log', $untouched === $changes->countFor( array() ) );

// ---- through WordPress ----------------------------------------------------------------------------------------------------------------
$request_id = wp_create_user_request( 'prvtest.bob@example.org', 'export_personal_data', array(), 'confirmed' );
$check( 'WordPress accepts an export request for the address', is_int( $request_id ) && $request_id > 0 );
$core_groups = array();
foreach ( apply_filters( 'wp_privacy_personal_data_exporters', array() ) as $key => $exporter ) {
	if ( 0 !== strpos( (string) $key, 'estasoft-handover-passport' ) ) {
		continue;
	}
	$page = 1;
	do {
		$r = call_user_func( $exporter['callback'], 'prvtest.bob@example.org', $page );
		foreach ( $r['data'] as $g ) {
			$core_groups[] = $g['group_id'];
		}
		++$page;
	} while ( empty( $r['done'] ) && $page < 20 );
}
$check( 'calling the registered exporters the way WordPress does gives Bob\'s directory entry', in_array( 'estasoft-hp-directory', $core_groups, true ) );
$html = wp_privacy_generate_personal_data_export_group_html( array( 'group_label' => 'Passport directory', 'group_description' => '', 'items' => array( array( array( 'name' => 'Name', 'value' => 'Prvtest Bob' ) ) ) ), 'estasoft-hp-directory', 1 );
$check( 'WordPress can render a group in the shape we return', false !== strpos( $html, 'Prvtest Bob' ) );
if ( is_int( $request_id ) ) {
	wp_delete_post( $request_id, true );
}

// ---- tidy up --------------------------------------------------------------------------------------------------------------------------
$cleanup();
$left_people = array_filter( $people_repo->query(), static fn( array $p ): bool => 0 === stripos( (string) $p['name'], 'prvtest' ) || 0 === stripos( (string) $p['email'], 'prvtest' ) );
$check( 'cleanup: no test people are left', array() === $left_people );
$left_log = $wpdb->get_var( "SELECT COUNT(*) FROM {$changes->table()} WHERE subject_label LIKE 'PRVTEST%' OR actor_label LIKE 'Prvtest%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
$check( 'cleanup: no test log entries are left', 0 === (int) $left_log, (string) $left_log );
echo "\n" . ( $checks - $failures ) . ' of ' . $checks . " checks passed\n";
if ( $failures > 0 ) {
	exit( 1 );
}
