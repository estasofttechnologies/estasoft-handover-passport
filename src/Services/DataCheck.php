<?php
/**
 * The data check: links that point at nothing, and records of a kind this version does not know (DAT-8).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Services;

use Estasoft\HandoverPassport\Data\ItemRepository;
use Estasoft\HandoverPassport\Data\LinkRepository;
use Estasoft\HandoverPassport\Data\PeopleRepository;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Looks for what a failed import, a manual database edit or a downgrade can leave behind. It only reads until a person
 * confirms; a removal looks again first and removes only what is still wrong, so a stale page cannot delete a good row.
 */
final class DataCheck {

	/**
	 * Constructor.
	 *
	 * @param Plugin           $plugin Container.
	 * @param ItemRepository   $items  Records.
	 * @param PeopleRepository $people People.
	 * @param LinkRepository   $links  Links.
	 */
	public function __construct( private Plugin $plugin, private ItemRepository $items, private PeopleRepository $people, private LinkRepository $links ) {}

	/**
	 * Look for problems.
	 *
	 * @return array{links: array<int, array{id: int, relation: string, reason: string}>, records: array<int, array{id: int, kind: string, title: string, sensitive: bool}>, checked_links: int}
	 */
	public function scan(): array {
		$links = $this->links->everything();

		$person_ids = array();
		$item_ids   = array();
		foreach ( $links as $link ) {
			foreach ( array( 'from', 'to' ) as $end ) {
				if ( 'person' === $link[ $end . '_type' ] ) {
					$person_ids[] = (int) $link[ $end . '_id' ];
				} elseif ( 'item' === $link[ $end . '_type' ] ) {
					$item_ids[] = (int) $link[ $end . '_id' ];
				}
			}
		}
		$people = array_flip( $this->people->existingIds( $person_ids ) );
		$items  = array_flip( $this->items->existingIds( $item_ids ) );

		$broken = array();
		foreach ( $links as $link ) {
			$reason = '';
			foreach ( array( 'from', 'to' ) as $end ) {
				$type = (string) $link[ $end . '_type' ];
				$id   = (int) $link[ $end . '_id' ];
				if ( 'person' === $type && ! isset( $people[ $id ] ) ) {
					$reason = __( 'It points at a person who no longer exists.', 'estasoft-handover-passport' );
				} elseif ( 'item' === $type && ! isset( $items[ $id ] ) ) {
					$reason = __( 'It points at a record that no longer exists.', 'estasoft-handover-passport' );
				}
			}
			if ( '' !== $reason ) {
				$broken[] = array( 'id' => (int) $link['id'], 'relation' => (string) $link['relation'], 'reason' => $reason );
			}
		}

		$records = array();
		foreach ( $this->items->ofUnknownKind( array_keys( Kinds::all() ) ) as $row ) {
			$records[] = array(
				'id'        => (int) $row['id'],
				'kind'      => (string) $row['kind'],
				'title'     => (string) $row['title'],
				'sensitive' => ! empty( $row['sensitive'] ),
			);
		}

		return array( 'links' => $broken, 'records' => $records, 'checked_links' => count( $links ) );
	}

	/**
	 * Remove what a person chose, after looking again.
	 *
	 * @param array<int, int> $link_ids   Link IDs chosen.
	 * @param array<int, int> $record_ids Record IDs chosen.
	 * @return array{links: int, records: int} How many were removed.
	 */
	public function remove( array $link_ids, array $record_ids ): array {
		$now     = $this->scan();
		$allowed = array(
			'links'   => array_flip( array_column( $now['links'], 'id' ) ),
			'records' => array_flip( array_column( $now['records'], 'id' ) ),
		);

		$removed = array( 'links' => 0, 'records' => 0 );
		foreach ( array_unique( array_map( 'intval', $link_ids ) ) as $id ) {
			if ( isset( $allowed['links'][ $id ] ) && $this->links->remove( $id ) ) {
				++$removed['links'];
			}
		}
		foreach ( array_unique( array_map( 'intval', $record_ids ) ) as $id ) {
			if ( isset( $allowed['records'][ $id ] ) ) {
				$this->links->forget( 'item', $id );
				if ( $this->items->delete( $id ) ) {
					++$removed['records'];
				}
			}
		}

		if ( $removed['links'] > 0 || $removed['records'] > 0 ) {
			$this->plugin->changeLog()->add(
				'data_repaired',
				'settings',
				0,
				__( 'Data check', 'estasoft-handover-passport' ),
				array(),
				$removed,
				''
			);
		}
		return $removed;
	}
}
