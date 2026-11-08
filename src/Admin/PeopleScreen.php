<?php
/**
 * People and access: People, Ownership, Access (ADM-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The People and access screen.
 */
final class PeopleScreen extends Screen {

	public const SLUG = 'estasoft-passport-people';

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'People and access', 'estasoft-handover-passport' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tabs(): array {
		return array(
			'people'    => __( 'People', 'estasoft-handover-passport' ),
			'ownership' => __( 'Ownership', 'estasoft-handover-passport' ),
			'access'    => __( 'Access', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function tab( string $tab ): void {
		$register = new RegisterView( $this->plugin );
		switch ( $tab ) {
			case 'ownership':
				$this->ownership();
				break;
			case 'access':
				$register->render( Kinds::ACCESS, self::SLUG, $this->keep( 'access' ) );
				if ( 'list' === Request::key( 'view', 'list' ) ) {
					$this->administrators();
				}
				break;
			default:
				( new PeopleView( $this->plugin, $register ) )->render( self::SLUG );
		}
	}

	/**
	 * Who owns, holds and pays for each asset (OWN-1, OWN-2).
	 */
	private function ownership(): void {
		$people  = array();
		foreach ( $this->plugin->people()->all() as $person ) {
			$people[ (int) $person['id'] ] = (string) $person['name'];
		}
		$names = static fn( array $ids ): string => implode( ', ', array_map( static fn( $id ): string => $people[ (int) $id ] ?? '', $ids ) );
		$owners = Kinds::owners();
		$see    = Capabilities::canSeeSensitive();

		$rows = array();
		foreach ( $this->plugin->records()->everything() as $record ) {
			$def = Kinds::get( (string) $record['kind'] );
			if ( empty( $def['ownership'] ) ) {
				continue;
			}
			if ( $record['sensitive'] && ! $see ) {
				continue;
			}
			$rows[] = array( $def, $record );
		}
		echo '<div class="estasoft-hp-register"><h2>' . esc_html__( 'Who owns what', 'estasoft-handover-passport' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Every asset should have a legal owner, someone who holds the account and someone who pays. Gaps are marked so they can be closed before a handover.', 'estasoft-handover-passport' ) . '</p>';
		if ( array() === $rows ) {
			echo '<div class="estasoft-hp-empty"><p>' . esc_html__( 'No assets recorded yet. Add licences, domains, hosting or services first, then set their owners here.', 'estasoft-handover-passport' ) . '</p></div></div>';
			return;
		}
		echo '<table class="widefat striped estasoft-hp-table"><thead><tr>';
		foreach ( array( __( 'Asset', 'estasoft-handover-passport' ), __( 'Kind', 'estasoft-handover-passport' ), __( 'Legal owner', 'estasoft-handover-passport' ), __( 'Account holder', 'estasoft-handover-passport' ), __( 'Who pays', 'estasoft-handover-passport' ), __( 'Administrators', 'estasoft-handover-passport' ), __( 'Transfer', 'estasoft-handover-passport' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$transfer = Kinds::transferStates();
		foreach ( $rows as [ $def, $record ] ) {
			$owner  = (string) $record['legal_owner'];
			$holder = $names( (array) ( $record['owners']['account_holder'] ?? array() ) );
			$payer  = $names( (array) ( $record['owners']['payer'] ?? array() ) );
			$admins = $names( (array) ( $record['owners']['administrators'] ?? array() ) );
			$gap    = static fn( string $text ): string => '' === $text ? '<span class="estasoft-hp-gap">' . esc_html__( 'Not set', 'estasoft-handover-passport' ) . '</span>' : esc_html( $text );
			echo '<tr><td><strong>' . esc_html( (string) $record['title'] ) . '</strong></td><td>' . esc_html( (string) $def['label'] ) . '</td>';
			echo '<td>' . ( '' === $owner || 'unknown' === $owner ? '<span class="estasoft-hp-gap">' . esc_html__( 'Unknown', 'estasoft-handover-passport' ) . '</span>' : esc_html( $owners[ $owner ] ?? $owner ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both branches escape.
			echo '<td>' . $gap( $holder ) . '</td><td>' . $gap( $payer ) . '</td><td>' . $gap( $admins ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $gap() escapes.
			echo '<td>' . esc_html( $transfer[ (string) $record['status'] ] ?? '' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Administrators found by the scan (SCN-4).
	 */
	private function administrators(): void {
		$facts = $this->plugin->scanService()->latestFacts();
		$users = (array) ( $facts['users'] ?? array() );
		if ( array() === $users ) {
			return;
		}
		$see = Capabilities::canSeeSensitive();
		echo '<div class="estasoft-hp-register"><h2>' . esc_html__( 'Administrators on this site', 'estasoft-handover-passport' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Found by the last scan. Compare this list with the access entries above and remove anyone who should no longer have access.', 'estasoft-handover-passport' ) . '</p>';
		echo '<table class="widefat striped estasoft-hp-table"><thead><tr><th scope="col">' . esc_html__( 'Name', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Email', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Registered', 'estasoft-handover-passport' ) . '</th></tr></thead><tbody>';
		foreach ( (array) ( $users['admins'] ?? array() ) as $admin ) {
			echo '<tr><td>' . esc_html( (string) $admin['name'] ) . '</td><td>' . ( $see ? esc_html( (string) $admin['email'] ) : '<em>' . esc_html__( 'Sensitive, hidden', 'estasoft-handover-passport' ) . '</em>' ) . '</td><td>' . esc_html( Dates::displayDate( Dates::datePart( (string) $admin['registered'] ) ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both branches escape.
		}
		echo '</tbody></table>';
		if ( ! empty( $users['has_admin_user'] ) ) {
			echo '<p class="estasoft-hp-gap">' . esc_html__( 'A user named "admin" exists. This is a common target for guessing attacks; consider renaming it.', 'estasoft-handover-passport' ) . '</p>';
		}
		echo '</div>';
	}
}
