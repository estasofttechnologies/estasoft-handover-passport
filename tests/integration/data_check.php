<?php
/**
 * Integration test for the data check (DAT-8). Run on a WordPress test site:
 *
 *     wp eval-file wp-content/plugins/estasoft-handover-passport/tests/integration/data_check.php
 *
 * It writes rows straight into the tables, as a failed import or a downgrade would leave them, and removes them again.
 *
 * @package Estasoft\HandoverPassport
 */

use Estasoft\HandoverPassport\Data\Tables;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Plugin;
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

global $wpdb;
$plugin = Plugin::instance();
$plugin->reset();
wp_set_current_user( 1 );
$items    = $plugin->records()->items();
$links    = $plugin->records()->links();
$baseline = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Tables::name( 'changes' ) );
$ours     = array( 'links' => array(), 'items' => array(), 'people' => array() );

$row = static function ( string $kind, string $title, bool $sensitive = false ): array {
	return array(
		'kind'          => $kind,
		'subject_key'   => '',
		'title'         => $title,
		'data'          => array(),
		'source'        => 'manual',
		'vis_client'    => 0,
		'vis_emergency' => 0,
		'sensitive'     => $sensitive ? 1 : 0,
		'legal_owner'   => '',
		'status'        => '',
		'created_at'    => Clock::sql(),
		'created_by'    => 1,
		'updated_at'    => Clock::sql(),
	);
};

$cleanup = static function () use ( $plugin, $wpdb, $baseline, &$ours ): void {
	$links = $plugin->records()->links();
	$items = $plugin->records()->items();
	$wpdb->query( 'DELETE FROM ' . Tables::name( 'changes' ) . " WHERE id > {$baseline}" );
	foreach ( $ours['links'] as $id ) {
		$links->remove( (int) $id );
	}
	foreach ( $ours['items'] as $id ) {
		$items->delete( (int) $id );
	}
	foreach ( $ours['people'] as $id ) {
		$plugin->people()->repo()->delete( (int) $id );
	}
	$wpdb->query( 'DELETE FROM ' . Tables::name( 'changes' ) . " WHERE id > {$baseline}" );
	$plugin->reset();
};

// ---- a clean start: whatever the site already has is not the test's business ------------------------------------------
$before = $plugin->dataCheck()->scan();
$base_links   = array_column( $before['links'], 'id' );
$base_records = array_column( $before['records'], 'id' );

// ---- good rows --------------------------------------------------------------------------------------------------------
$person    = $plugin->people()->create( array( 'name' => 'DATACHECK Person', 'kind' => 'other' ) );
$person_id = (int) $person['id'];
$ours['people'][] = $person_id;
$good_item = $items->insert( $row( Kinds::DOMAIN, 'datacheck-good.example' ) );
$ours['items'][] = $good_item;
$links->setPeople( 'account_holder', 'item', $good_item, array( $person_id ) );
$good_links = array_map( static fn( array $l ): int => (int) $l['id'], $links->query( array( 'to_type' => 'item', 'to_id' => $good_item ) ) );
foreach ( $good_links as $id ) {
	$ours['links'][] = $id;
}
$check( 'a good link was made', 1 === count( $good_links ) );

// A record that is soft-deleted keeps its row, and so its links are not orphaned.
$soft = $items->insert( $row( Kinds::DOMAIN, 'datacheck-soft.example' ) );
$ours['items'][] = $soft;
$links->setPeople( 'payer', 'item', $soft, array( $person_id ) );
$soft_links = array_map( static fn( array $l ): int => (int) $l['id'], $links->query( array( 'to_type' => 'item', 'to_id' => $soft ) ) );
foreach ( $soft_links as $id ) {
	$ours['links'][] = $id;
}
$items->softDelete( $soft );

// ---- broken rows ------------------------------------------------------------------------------------------------------
$ghost_item   = 9000000 + wp_rand( 1, 999 );
$ghost_person = 9100000 + wp_rand( 1, 999 );
$orphan_a = $links->insert( array( 'from_type' => 'person', 'from_id' => $person_id, 'to_type' => 'item', 'to_id' => $ghost_item, 'relation' => 'account_holder', 'position' => 0 ) );
$orphan_b = $links->insert( array( 'from_type' => 'person', 'from_id' => $ghost_person, 'to_type' => 'item', 'to_id' => $good_item, 'relation' => 'payer', 'position' => 0 ) );
$orphan_c = $links->insert( array( 'from_type' => 'person', 'from_id' => $ghost_person, 'to_type' => 'passport', 'to_id' => 1, 'relation' => 'role:primary', 'position' => 0 ) );
array_push( $ours['links'], $orphan_a, $orphan_b, $orphan_c );
$strange = $items->insert( $row( 'from_the_future', 'datacheck-strange' ) );
$secret  = $items->insert( $row( 'from_the_future', 'datacheck-secret-strange', true ) );
array_push( $ours['items'], $strange, $secret );
$links->insert( array( 'from_type' => 'person', 'from_id' => $person_id, 'to_type' => 'item', 'to_id' => $strange, 'relation' => 'account_holder', 'position' => 0 ) );
$ours['links'] = array_merge( $ours['links'], array_map( static fn( array $l ): int => (int) $l['id'], $links->query( array( 'to_type' => 'item', 'to_id' => $strange ) ) ) );

// ---- the scan ---------------------------------------------------------------------------------------------------------
$plugin->reset();
$found     = $plugin->dataCheck()->scan();
$link_ids  = array_column( $found['links'], 'id' );
$rec_ids   = array_column( $found['records'], 'id' );
$check( 'the scan finds a link to a record that is gone', in_array( $orphan_a, $link_ids, true ) );
$check( 'and a link from a person who is gone', in_array( $orphan_b, $link_ids, true ) );
$check( 'also when the other end is the Passport itself', in_array( $orphan_c, $link_ids, true ) );
$check( 'it does not flag a good link', array() === array_intersect( $good_links, $link_ids ) );
$check( 'it does not flag a link to a record that was only soft-deleted', array() === array_intersect( $soft_links, $link_ids ) );
$check( 'it gives a reason in words for each', array() === array_filter( $found['links'], static fn( array $l ): bool => '' === $l['reason'] ) );
$check( 'it finds records of a kind this version does not know', in_array( $strange, $rec_ids, true ) && in_array( $secret, $rec_ids, true ) );
$check( 'and not the good ones', ! in_array( $good_item, $rec_ids, true ) && ! in_array( $soft, $rec_ids, true ) );
$flags = array_column( $found['records'], 'sensitive', 'id' );
$check( 'it says which of them is Sensitive', false === $flags[ $strange ] && true === $flags[ $secret ] );
$check( 'it counts the links it looked at', $found['checked_links'] >= count( $good_links ) + 3 );

// ---- removing ---------------------------------------------------------------------------------------------------------
$none = $plugin->dataCheck()->remove( array(), array() );
$check( 'removing nothing removes nothing and writes nothing to the history', 0 === $none['links'] && 0 === $none['records'] && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::name( 'changes' ) . " WHERE id > {$baseline} AND kind = 'data_repaired'" ) );

$removed = $plugin->dataCheck()->remove( array_merge( array( $orphan_a, $orphan_c ), $good_links ), array( $strange, $good_item ) );
$check( 'what was ticked and is wrong is removed: 2 links and 1 record', 2 === $removed['links'] && 1 === $removed['records'], json_encode( $removed ) );
$check( 'a good link that was ticked is kept', 1 === count( $links->query( array( 'to_type' => 'item', 'to_id' => $good_item, 'relation' => 'account_holder' ) ) ) );
$check( 'a good record that was ticked is kept', null !== $items->find( $good_item ) );
$check( 'the link to the removed record went with it', array() === $links->query( array( 'to_type' => 'item', 'to_id' => $strange ) ) );
$check( 'what was not ticked is left', null !== $links->find( $orphan_b ) && null !== $items->find( $secret ) );
$check( 'an ID that does not exist is ignored', array( 'links' => 0, 'records' => 0 ) === $plugin->dataCheck()->remove( array( 123456789 ), array( 123456789 ) ) );

$log = $wpdb->get_row( 'SELECT * FROM ' . Tables::name( 'changes' ) . " WHERE id > {$baseline} AND kind = 'data_repaired' ORDER BY id DESC LIMIT 1", ARRAY_A );
$check( 'the history has one entry with the counts', is_array( $log ) && false !== strpos( (string) $log['after_data'], '"links":2' ) && false !== strpos( (string) $log['after_data'], '"records":1' ), json_encode( $log ) );
$check( 'the entry holds no record title', is_array( $log ) && false === strpos( json_encode( $log ), 'datacheck-strange' ) );

$removed = $plugin->dataCheck()->remove( array( $orphan_b ), array( $secret ) );
$check( 'the rest can be removed in a second round', 1 === $removed['links'] && 1 === $removed['records'] );
$plugin->reset();
$after = $plugin->dataCheck()->scan();
$check( 'a new scan finds only what the site had before', array() === array_diff( array_column( $after['links'], 'id' ), $base_links ) && array() === array_diff( array_column( $after['records'], 'id' ), $base_records ) );

$cleanup();
$check( 'the rows made by this test are gone', null === $items->find( $good_item ) && null === $items->find( $soft ) );

echo "\n{$checks} checks, {$failures} failed.\n";
if ( $failures > 0 ) {
	exit( 1 );
}
