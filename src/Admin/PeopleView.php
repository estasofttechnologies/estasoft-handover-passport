<?php
/**
 * People directory, roles and the emergency contact order (PPL-1 to PPL-3, PRV-7).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\Freshness;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Services\PeopleService;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Draws the People tab.
 */
final class PeopleView {

	/**
	 * Constructor.
	 *
	 * @param Plugin       $plugin   Container.
	 * @param RegisterView $register Shared helper for hidden fields.
	 */
	public function __construct( private Plugin $plugin, private RegisterView $register ) {}

	/**
	 * Print the tab.
	 *
	 * @param string $page Menu slug.
	 */
	public function render( string $page ): void {
		$base = add_query_arg( array( 'page' => $page, 'tab' => 'people' ), admin_url( 'admin.php' ) );
		$view = Request::key( 'view', 'list' );
		$id   = Request::int( 'id' );
		if ( 'edit' === $view ) {
			$this->form( $base, $id );
		} elseif ( 'delete' === $view ) {
			$this->confirmDelete( $base, $id );
		} else {
			$this->listing( $base );
			$this->roles( $base );
		}
	}

	/**
	 * The directory.
	 *
	 * @param string $base Screen URL.
	 */
	private function listing( string $base ): void {
		$can_edit = Capabilities::can( Capabilities::EDIT );
		$people   = $this->plugin->people()->all();
		$add_url  = add_query_arg( array( 'view' => 'edit', 'id' => 0 ), $base );

		echo '<div class="estasoft-hp-register"><div class="estasoft-hp-register__head"><h2>' . esc_html__( 'People and organisations', 'estasoft-handover-passport' ) . '</h2>';
		if ( $can_edit ) {
			echo '<a class="button button-primary" href="' . esc_url( $add_url ) . '">' . esc_html__( 'Add a person', 'estasoft-handover-passport' ) . '</a>';
		}
		echo '</div>';
		if ( array() === $people ) {
			echo '<div class="estasoft-hp-empty"><p>' . esc_html__( 'List the client, the agency team, the host and the vendors, so a new person knows who to call.', 'estasoft-handover-passport' ) . '</p>';
			if ( $can_edit ) {
				echo '<p><a class="button button-primary" href="' . esc_url( $add_url ) . '">' . esc_html__( 'Add the first person', 'estasoft-handover-passport' ) . '</a></p>';
			}
			echo '</div></div>';
			return;
		}

		$roles = PeopleService::roles();
		$kinds = PeopleService::kinds();
		echo '<table class="widefat striped estasoft-hp-table"><thead><tr>';
		foreach ( array( __( 'Name', 'estasoft-handover-passport' ), __( 'Organisation', 'estasoft-handover-passport' ), __( 'Kind', 'estasoft-handover-passport' ), __( 'Roles', 'estasoft-handover-passport' ), __( 'Email', 'estasoft-handover-passport' ), __( 'State', 'estasoft-handover-passport' ), __( 'Review', 'estasoft-handover-passport' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'estasoft-handover-passport' ) . '</span></th></tr></thead><tbody>';
		foreach ( $people as $person ) {
			$role_names = array_map( static fn( string $key ): string => $roles[ $key ] ?? $key, (array) $person['roles'] );
			$edit       = add_query_arg( array( 'view' => 'edit', 'id' => $person['id'] ), $base );
			echo '<tr><td><strong>';
			echo $can_edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( (string) $person['name'] ) . '</a>' : esc_html( (string) $person['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both branches escape.
			echo '</strong></td><td>' . esc_html( (string) $person['organisation'] ) . '</td><td>' . esc_html( $kinds[ (string) $person['kind'] ] ?? (string) $person['kind'] ) . '</td><td>' . esc_html( implode( ', ', $role_names ) ) . '</td><td>' . esc_html( (string) $person['email'] ) . '</td>';
			echo '<td>' . ( ! empty( $person['left_on'] ) ? esc_html(
				sprintf(
					/* translators: %s: date. */
					__( 'Left %s', 'estasoft-handover-passport' ),
					Dates::displayDate( (string) $person['left_on'] )
				)
			) : esc_html__( 'Active', 'estasoft-handover-passport' ) ) . '</td>';
			echo '<td>' . View::badge( (string) $person['freshness'], Freshness::label( (string) $person['freshness'] ) ) . '</td><td class="estasoft-hp-actions">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			if ( $can_edit ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
				$this->register->hidden( 'verify', 'person', (int) $person['id'], $base );
				echo '<button type="submit" class="button-link">' . esc_html__( 'Verify', 'estasoft-handover-passport' ) . '</button></form> ';
				echo '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'estasoft-handover-passport' ) . '</a> ';
				echo '<a class="estasoft-hp-danger" href="' . esc_url( add_query_arg( array( 'view' => 'delete', 'id' => $person['id'] ), $base ) ) . '">' . esc_html__( 'Delete', 'estasoft-handover-passport' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Roles and the emergency contact order (PPL-2, PPL-3).
	 *
	 * @param string $base Screen URL.
	 */
	private function roles( string $base ): void {
		$people = $this->plugin->people();
		$names  = array();
		foreach ( $people->all() as $person ) {
			$names[ (int) $person['id'] ] = (string) $person['name'];
		}
		if ( array() === $names ) {
			return;
		}
		$can_edit = Capabilities::can( Capabilities::EDIT );

		echo '<div class="estasoft-hp-register"><h2>' . esc_html__( 'Roles', 'estasoft-handover-passport' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Each role can have several people. Emergency contacts are called in the order shown.', 'estasoft-handover-passport' ) . '</p>';
		echo '<table class="widefat striped estasoft-hp-table"><tbody>';
		foreach ( PeopleService::roles() as $key => $label ) {
			$ids = $people->inRole( $key );
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			if ( array() === $ids ) {
				echo '<span class="estasoft-hp-muted">' . esc_html__( 'Nobody yet', 'estasoft-handover-passport' ) . '</span>';
			} else {
				echo '<ol class="estasoft-hp-order">';
				foreach ( $ids as $position => $pid ) {
					echo '<li>' . esc_html( $names[ $pid ] ?? '' );
					if ( $can_edit && 'emergency_contact' === $key && count( $ids ) > 1 ) {
						foreach ( array( 'up' => __( 'Move up', 'estasoft-handover-passport' ), 'down' => __( 'Move down', 'estasoft-handover-passport' ) ) as $dir => $dir_label ) {
							if ( ( 'up' === $dir && 0 === $position ) || ( 'down' === $dir && count( $ids ) - 1 === $position ) ) {
								continue;
							}
							echo ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
							$this->register->hidden( 'move', 'role', (int) $pid, $base, $key );
							echo '<input type="hidden" name="dir" value="' . esc_attr( $dir ) . '"><button type="submit" class="button-link">' . esc_html( $dir_label ) . '</button></form>';
						}
					}
					echo '</li>';
				}
				echo '</ol>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Add or edit a person.
	 *
	 * @param string $base Screen URL.
	 * @param int    $id   Person ID, 0 for new.
	 */
	private function form( string $base, int $id ): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to edit people.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		$person = $id > 0 ? $this->plugin->people()->get( $id ) : null;
		if ( $id > 0 && null === $person ) {
			echo '<p>' . esc_html__( 'That person was not found.', 'estasoft-handover-passport' ) . ' <a href="' . esc_url( $base ) . '">' . esc_html__( 'Back to the list', 'estasoft-handover-passport' ) . '</a></p>';
			return;
		}
		$state  = FormState::take( 'person:' . $id );
		$input  = null === $state ? array() : $state['input'];
		$errors = null === $state ? array() : $state['errors'];

		echo '<div class="estasoft-hp-form"><h2>' . esc_html( null === $person ? __( 'Add a person', 'estasoft-handover-passport' ) : (string) $person['name'] ) . '</h2>';
		if ( array() !== $errors ) {
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html__( 'The person was not saved. Fix the fields below and try again.', 'estasoft-handover-passport' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$this->register->hidden( 'save', 'person', $id, $base );

		echo '<fieldset class="estasoft-hp-fieldset"><legend>' . esc_html__( 'Details', 'estasoft-handover-passport' ) . '</legend>';
		foreach ( PeopleService::fields() as $key => $field ) {
			$value = $input['f'][ $key ] ?? $input[ $key ] ?? ( null === $person ? '' : (string) ( $person[ $key ] ?? '' ) );
			Form::row( 'f[' . $key . ']', $field, $value, $errors[ $key ] ?? '' );
		}
		echo '</fieldset>';

		$roles  = PeopleService::roles();
		$mine   = (array) ( $input['f']['roles'] ?? $input['roles'] ?? ( null === $person ? array() : $person['roles'] ) );
		echo '<fieldset class="estasoft-hp-fieldset"><legend>' . esc_html__( 'Roles', 'estasoft-handover-passport' ) . '</legend>';
		foreach ( $roles as $key => $label ) {
			echo '<div class="estasoft-hp-field estasoft-hp-field--check"><label><input type="checkbox" name="f[roles][]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, $mine, true ), true, false ) . '> ' . esc_html( $label ) . '</label></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a fixed attribute.
		}
		echo '</fieldset>';

		$share = null === $person ? PeopleService::defaultShare() : (array) $person['share_flags'];
		echo '<fieldset class="estasoft-hp-fieldset"><legend>' . esc_html__( 'May appear in the Client pack and the Emergency sheet', 'estasoft-handover-passport' ) . '</legend>';
		foreach ( array(
			'name'  => __( 'Name', 'estasoft-handover-passport' ),
			'role'  => __( 'Role', 'estasoft-handover-passport' ),
			'email' => __( 'Email', 'estasoft-handover-passport' ),
			'phone' => __( 'Phone', 'estasoft-handover-passport' ),
		) as $flag => $label ) {
			$on = array() !== $input ? ! empty( $input[ 'share_' . $flag ] ) : ! empty( $share[ $flag ] );
			Form::checkbox( 'share_' . $flag, $label, $on );
		}
		echo '</fieldset>';

		echo '<fieldset class="estasoft-hp-fieldset"><legend>' . esc_html__( 'Review', 'estasoft-handover-passport' ) . '</legend>';
		Form::row(
			'review_days',
			array( 'type' => 'int', 'label' => __( 'Review every (days)', 'estasoft-handover-passport' ), 'help' => __( 'Leave empty to use the interval from Settings.', 'estasoft-handover-passport' ) ),
			$input['review_days'] ?? ( null !== $person && (int) $person['review_days'] !== $this->plugin->settings()->reviewDays( 'people' ) ? $person['review_days'] : '' )
		);
		echo '</fieldset>';
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'estasoft-handover-passport' ) . '</button> <a class="button" href="' . esc_url( $base ) . '">' . esc_html__( 'Cancel', 'estasoft-handover-passport' ) . '</a></p></form></div>';
	}

	/**
	 * Confirm a delete.
	 *
	 * @param string $base Screen URL.
	 * @param int    $id   Person ID.
	 */
	private function confirmDelete( string $base, int $id ): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to delete people.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		$person = $this->plugin->people()->get( $id );
		if ( null === $person ) {
			echo '<p>' . esc_html__( 'That person was not found.', 'estasoft-handover-passport' ) . '</p>';
			return;
		}
		echo '<div class="estasoft-hp-form"><h2>' . esc_html__( 'Delete this person?', 'estasoft-handover-passport' ) . '</h2><p>' . esc_html(
			sprintf(
				/* translators: %s: person's name. */
				__( '"%s" is hidden for 30 days. You can undo this until then. Records that list this person keep working.', 'estasoft-handover-passport' ),
				(string) $person['name']
			)
		) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$this->register->hidden( 'delete', 'person', $id, $base );
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Delete', 'estasoft-handover-passport' ) . '</button> <a class="button" href="' . esc_url( $base ) . '">' . esc_html__( 'Keep them', 'estasoft-handover-passport' ) . '</a></form></div>';
	}
}
