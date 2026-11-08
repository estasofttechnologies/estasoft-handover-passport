<?php
/**
 * Where a finding is fixed (HLT-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a finding into the address of the screen that fixes it.
 */
final class FindingLinks {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Address for a finding as presented by the finding service.
	 *
	 * @param array<string, mixed> $finding Presented finding.
	 */
	public function url( array $finding ): string {
		$screen = (array) $finding['screen'];
		$args   = array( 'page' => (string) ( $screen['page'] ?? Admin::MENU_SLUG ) );
		if ( '' !== (string) ( $screen['tab'] ?? '' ) ) {
			$args['tab'] = (string) $screen['tab'];
		}

		$id = (int) $finding['subject_id'];
		if ( 'item' === $finding['subject_type'] && $id > 0 ) {
			$row = $this->plugin->records()->items()->find( $id );
			if ( null !== $row && in_array( (string) $row['kind'], Kinds::registerKinds(), true ) ) {
				$args = $this->tabFor( (string) $row['kind'] ) + array( 'view' => 'edit', 'kind' => (string) $row['kind'], 'id' => $id );
			}
		} elseif ( 'person' === $finding['subject_type'] && $id > 0 ) {
			$args = array( 'page' => PeopleScreen::SLUG, 'tab' => 'people', 'view' => 'edit', 'id' => $id );
		} elseif ( 'runbook' === $finding['subject_type'] && $id > 0 ) {
			$args = array( 'page' => SafetyScreen::SLUG, 'tab' => 'runbooks', 'view' => 'edit', 'id' => $id );
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Page and tab where a kind of record lives.
	 *
	 * @param string $kind Kind key.
	 * @return array{page: string, tab: string}
	 */
	public function tabFor( string $kind ): array {
		switch ( $kind ) {
			case Kinds::LICENCE:
				return array( 'page' => InventoryScreen::SLUG, 'tab' => 'licences' );
			case Kinds::DOMAIN:
			case Kinds::ZONE:
				return array( 'page' => InventoryScreen::SLUG, 'tab' => 'domains' );
			case Kinds::HOST:
			case Kinds::ENV:
			case Kinds::LAYER:
				return array( 'page' => InventoryScreen::SLUG, 'tab' => 'hosting' );
			case Kinds::SERVICE:
				return array( 'page' => InventoryScreen::SLUG, 'tab' => 'services' );
			case Kinds::BACKUP:
				return array( 'page' => SafetyScreen::SLUG, 'tab' => 'backups' );
			case Kinds::URL:
				return array( 'page' => SafetyScreen::SLUG, 'tab' => 'urls' );
			case Kinds::ACCESS:
				return array( 'page' => PeopleScreen::SLUG, 'tab' => 'access' );
			case Kinds::STACKNOTE:
				return array( 'page' => InventoryScreen::SLUG, 'tab' => 'stack' );
			default:
				return array( 'page' => Admin::MENU_SLUG, 'tab' => '' );
		}
	}
}
