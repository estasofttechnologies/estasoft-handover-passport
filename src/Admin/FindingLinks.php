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
		return add_query_arg( $this->args( $finding ), admin_url( 'admin.php' ) );
	}

	/**
	 * The words for the button that opens a finding, naming where it leads: "Open Licences".
	 *
	 * @param array<string, mixed> $finding Presented finding.
	 */
	public function label( array $finding ): string {
		$name = $this->where( $finding );
		if ( '' === $name ) {
			return __( 'Go and fix', 'estasoft-handover-passport' );
		}
		/* translators: %s: name of the place that opens, for example "Licences". */
		return sprintf( __( 'Open %s', 'estasoft-handover-passport' ), $name );
	}

	/**
	 * The name of the place where a finding is fixed, for example "Licences". Empty when it has no particular place.
	 *
	 * @param array<string, mixed> $finding Presented finding.
	 */
	public function where( array $finding ): string {
		$args  = $this->args( $finding );
		$names = array(
			InventoryScreen::SLUG . '|licences' => __( 'Licences', 'estasoft-handover-passport' ),
			InventoryScreen::SLUG . '|domains'  => __( 'Domains', 'estasoft-handover-passport' ),
			InventoryScreen::SLUG . '|hosting'  => __( 'Hosting', 'estasoft-handover-passport' ),
			InventoryScreen::SLUG . '|services' => __( 'Services', 'estasoft-handover-passport' ),
			InventoryScreen::SLUG . '|stack'    => __( 'Stack', 'estasoft-handover-passport' ),
			SafetyScreen::SLUG . '|backups'     => __( 'Backups', 'estasoft-handover-passport' ),
			SafetyScreen::SLUG . '|tests'       => __( 'Restore tests', 'estasoft-handover-passport' ),
			SafetyScreen::SLUG . '|runbooks'    => __( 'Runbooks', 'estasoft-handover-passport' ),
			SafetyScreen::SLUG . '|urls'        => __( 'Critical URLs', 'estasoft-handover-passport' ),
			PeopleScreen::SLUG . '|people'      => __( 'People', 'estasoft-handover-passport' ),
			PeopleScreen::SLUG . '|ownership'   => __( 'Ownership', 'estasoft-handover-passport' ),
			PeopleScreen::SLUG . '|access'      => __( 'Access', 'estasoft-handover-passport' ),
			HistoryScreen::SLUG . '|incidents'  => __( 'Incidents', 'estasoft-handover-passport' ),
			HandoverScreen::SLUG . '|checklist' => __( 'Checklist', 'estasoft-handover-passport' ),
			HandoverScreen::SLUG . '|snapshots' => __( 'Snapshots', 'estasoft-handover-passport' ),
			SettingsScreen::SLUG . '|general'   => __( 'Settings', 'estasoft-handover-passport' ),
		);
		return $names[ (string) ( $args['page'] ?? '' ) . '|' . (string) ( $args['tab'] ?? '' ) ] ?? '';
	}

	/**
	 * Query arguments of the place that fixes a finding.
	 *
	 * @param array<string, mixed> $finding Presented finding.
	 * @return array<string, mixed>
	 */
	private function args( array $finding ): array {
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
		return $args;
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
			case Kinds::INCIDENT:
				return array( 'page' => HistoryScreen::SLUG, 'tab' => 'incidents' );
			case Kinds::STACKNOTE:
				return array( 'page' => InventoryScreen::SLUG, 'tab' => 'stack' );
			default:
				return array( 'page' => Admin::MENU_SLUG, 'tab' => '' );
		}
	}
}
