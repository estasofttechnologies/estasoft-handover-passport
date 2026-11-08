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
		} elseif ( 'offboard' === $view ) {
			( new OffboardView( $this->plugin ) )->render( $base, $id );
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

		echo '<div class="estasoft-hp-register">';
		if ( array() === $people ) {
			echo '<div class="estasoft-hp-empty"><p>' . esc_html__( 'List the client, the agency team, the host and the vendors, so a new person knows who to call.', 'estasoft-handover-passport' ) . '</p>';
			if ( $can_edit ) {
				echo '<p><a class="btn pri" href="' . esc_url( $add_url ) . '">' . esc_html__( 'Add the first person', 'estasoft-handover-passport' ) . '</a></p>';
			}
			echo '</div></div>';
			return;
		}

		$roles = PeopleService::roles();
		$kinds = PeopleService::kinds();
		$orgs  = array();
		foreach ( $people as $person ) {
			$org = trim( (string) $person['organisation'] );
			if ( '' !== $org ) {
				$orgs[ $org ] = $org;
			}
		}
		natcasesort( $orgs );

		echo '<section class="card flat"><div class="bdy">';
		if ( count( $people ) > 1 ) {
			echo '<div class="tb"><label class="screen-reader-text" for="estasoft-hp-find-people">' . esc_html__( 'Search people', 'estasoft-handover-passport' ) . '</label>';
			echo '<input class="fld" type="search" id="estasoft-hp-find-people" data-estasoft-find placeholder="' . esc_attr__( 'Search people', 'estasoft-handover-passport' ) . '" autocomplete="off">';
			if ( count( $orgs ) > 1 ) {
				echo '<label class="screen-reader-text" for="estasoft-hp-find-org">' . esc_html__( 'Organisation', 'estasoft-handover-passport' ) . '</label>';
				echo '<select class="fld" id="estasoft-hp-find-org" data-estasoft-filter="org"><option value="">' . esc_html__( 'All organisations', 'estasoft-handover-passport' ) . '</option>';
				foreach ( $orgs as $org ) {
					echo '<option value="' . esc_attr( sanitize_title( $org ) ) . '">' . esc_html( $org ) . '</option>';
				}
				echo '</select>';
			}
			echo '<label class="screen-reader-text" for="estasoft-hp-find-role">' . esc_html__( 'Role', 'estasoft-handover-passport' ) . '</label>';
			echo '<select class="fld" id="estasoft-hp-find-role" data-estasoft-filter="role"><option value="">' . esc_html__( 'All roles', 'estasoft-handover-passport' ) . '</option>';
			foreach ( $roles as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select></div>';
		}

		echo '<div class="scr"><table class="tbl cards estasoft-hp-table" data-estasoft-list><caption class="screen-reader-text">' . esc_html__( 'People and organisations', 'estasoft-handover-passport' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Person', 'estasoft-handover-passport' ), __( 'Role', 'estasoft-handover-passport' ), __( 'Email', 'estasoft-handover-passport' ), __( 'Phone', 'estasoft-handover-passport' ), __( 'Freshness', 'estasoft-handover-passport' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'estasoft-handover-passport' ) . '</span></th></tr></thead><tbody>';
		foreach ( $people as $person ) {
			$role_names = array_map( static fn( string $key ): string => $roles[ $key ] ?? $key, (array) $person['roles'] );
			$edit       = add_query_arg( array( 'view' => 'edit', 'id' => $person['id'] ), $base );
			$name       = (string) $person['name'];
			$org        = trim( (string) $person['organisation'] );
			$sub        = array();
			if ( '' !== $org ) {
				$sub[] = esc_html( $org );
			}
			if ( '' !== (string) ( $kinds[ (string) $person['kind'] ] ?? '' ) ) {
				$sub[] = esc_html( $kinds[ (string) $person['kind'] ] );
			}
			if ( ! empty( $person['left_on'] ) ) {
				$sub[] = esc_html(
					sprintf(
						/* translators: %s: date. */
						__( 'Left %s', 'estasoft-handover-passport' ),
						Dates::displayDate( (string) $person['left_on'] )
					)
				);
			}
			echo '<tr data-f-org="|' . esc_attr( sanitize_title( $org ) ) . '|" data-f-role="|' . esc_attr( implode( '|', array_map( 'strval', (array) $person['roles'] ) ) ) . '|">';
			echo '<td class="t1" data-label="' . esc_attr__( 'Person', 'estasoft-handover-passport' ) . '"><span class="rowx"><span class="av" aria-hidden="true">' . esc_html( self::initials( $name ) ) . '</span><span><b>';
			echo $can_edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both branches escape.
			echo '</b>' . ( array() !== $sub ? '<span class="sub">' . implode( ' · ', $sub ) . '</span>' : '' ) . '</span></span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part is escaped above.
			echo '<td data-label="' . esc_attr__( 'Role', 'estasoft-handover-passport' ) . '">' . esc_html( implode( ', ', $role_names ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Email', 'estasoft-handover-passport' ) . '">' . esc_html( (string) $person['email'] ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Phone', 'estasoft-handover-passport' ) . '">' . esc_html( (string) ( $person['phone'] ?? '' ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Freshness', 'estasoft-handover-passport' ) . '">' . View::badge( (string) $person['freshness'], Freshness::label( (string) $person['freshness'] ) ) . '</td><td class="act estasoft-hp-actions" data-label="">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			if ( $can_edit ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
				$this->register->hidden( 'verify', 'person', (int) $person['id'], $base );
				echo '<button type="submit" class="btn sm">' . esc_html__( 'Verify', 'estasoft-handover-passport' ) . '</button></form> ';
				echo '<a class="btn sm" href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'estasoft-handover-passport' ) . '</a> ';
				if ( empty( $person['left_on'] ) ) {
					echo '<a class="btn sm" href="' . esc_url( OffboardView::url( (int) $person['id'] ) ) . '">' . esc_html__( 'Offboard', 'estasoft-handover-passport' ) . '</a> ';
				}
				echo '<a class="btn sm estasoft-hp-danger" href="' . esc_url( add_query_arg( array( 'view' => 'delete', 'id' => $person['id'] ), $base ) ) . '">' . esc_html__( 'Delete', 'estasoft-handover-passport' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		/* translators: 1: number of items shown, 2: number of items. */
		$pattern = __( '%1$d of %2$d items', 'estasoft-handover-passport' );
		echo '<div class="pgn"><span data-estasoft-count data-total="' . esc_attr( (string) count( $people ) ) . '" data-pattern="' . esc_attr( $pattern ) . '" aria-live="polite">' . esc_html(
			sprintf(
				/* translators: 1: shown, 2: total. */
				__( '%1$d of %2$d items', 'estasoft-handover-passport' ),
				count( $people ),
				count( $people )
			)
		) . '</span></div>';
		echo '</div></section></div>';
	}

	/**
	 * Up to two capital letters from a name, for the round mark beside it.
	 *
	 * @param string $name Name.
	 */
	public static function initials( string $name ): string {
		$words = preg_split( '/\s+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $words || array() === $words ) {
			return '';
		}
		$first = mb_strtoupper( mb_substr( (string) $words[0], 0, 1 ) );
		return 1 === count( $words ) ? $first : $first . mb_strtoupper( mb_substr( (string) $words[ count( $words ) - 1 ], 0, 1 ) );
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

		echo '<div class="estasoft-hp-register" id="estasoft-hp-roles">';
		View::cardStart( __( 'Roles', 'estasoft-handover-passport' ), '', true );
		echo '<p class="estasoft-hp-cardnote">' . esc_html__( 'Each role can have several people. Emergency contacts are called in the order shown.', 'estasoft-handover-passport' ) . '</p>';
		echo '<table class="tbl estasoft-hp-roles"><tbody>';
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
							echo '<input type="hidden" name="dir" value="' . esc_attr( $dir ) . '"><button type="submit" class="btn sm">' . esc_html( $dir_label ) . '</button></form>';
						}
					}
					echo '</li>';
				}
				echo '</ol>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		View::cardEnd();
		echo '</div>';
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

		$roles = PeopleService::roles();
		$mine  = (array) ( $input['f']['roles'] ?? $input['roles'] ?? ( null === $person ? array() : $person['roles'] ) );
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
