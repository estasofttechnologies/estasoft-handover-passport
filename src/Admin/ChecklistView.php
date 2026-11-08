<?php
/**
 * The Handover checklist tab (HND-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Handover\HandoverChecklist;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Shows each item as Done, Not done or Not applicable with who and when, and takes custom items.
 */
final class ChecklistView {

	public const ACTION = 'estasoft_hp_checklist';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Attach the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Print the tab.
	 *
	 * @param string $base Screen URL with the tab.
	 */
	public function render( string $base ): void {
		$items    = $this->plugin->handover()->items();
		$progress = $this->plugin->handover()->progress();
		$can_edit = Capabilities::can( Capabilities::EDIT );

		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Handover checklist', 'estasoft-handover-passport' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: number of items done, 2: number of items counted. */
				__( '%1$d of %2$d items are done. Items from the rules are checked by Passport itself; items you add are ticked by hand. Items marked Not applicable are not counted.', 'estasoft-handover-passport' ),
				$progress['done'],
				$progress['total']
			)
		) . '</p></div>';

		$groups = array(
			'rule'     => __( 'Checked by the rules', 'estasoft-handover-passport' ),
			'transfer' => __( 'Ownership transfers', 'estasoft-handover-passport' ),
			'restore'  => __( 'Restore test', 'estasoft-handover-passport' ),
			'custom'   => __( 'Your own items', 'estasoft-handover-passport' ),
		);
		foreach ( $groups as $group => $heading ) {
			$rows = array_values( array_filter( $items, static fn( array $item ): bool => $group === $item['group'] ) );
			if ( array() === $rows && 'custom' !== $group ) {
				continue;
			}
			echo '<h3>' . esc_html( $heading ) . '</h3>';
			if ( array() === $rows ) {
				echo '<p>' . esc_html__( 'Nothing here yet.', 'estasoft-handover-passport' ) . '</p>';
				continue;
			}
			$this->table( $rows, $base, $can_edit );
		}
		if ( $can_edit ) {
			$this->addForm( $base );
		}
	}

	/**
	 * One table of items.
	 *
	 * @param list<array<string, mixed>> $rows     Items.
	 * @param string                     $base     Screen URL.
	 * @param bool                       $can_edit Whether the person may change items.
	 */
	private function table( array $rows, string $base, bool $can_edit ): void {
		$labels = Kinds::checklistStates();
		echo '<table class="widefat striped estasoft-hp-table"><thead><tr>';
		foreach ( array( __( 'Item', 'estasoft-handover-passport' ), __( 'State', 'estasoft-handover-passport' ), __( 'Who', 'estasoft-handover-passport' ), __( 'When', 'estasoft-handover-passport' ), __( 'Note', 'estasoft-handover-passport' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'estasoft-handover-passport' ) . '</span></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( (string) $row['label'] ) . '</td>';
			echo '<td>' . View::badge( 'check-' . (string) $row['state'], (string) ( $labels[ (string) $row['state'] ] ?? '' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			echo '<td>' . esc_html( (string) $row['who'] ) . '</td>';
			echo '<td>' . esc_html( '' === (string) $row['when'] ? '' : Dates::displayDate( (string) $row['when'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['detail'] ) . '</td><td class="estasoft-hp-actions">';
			if ( $can_edit ) {
				$this->actions( $row, $base );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Buttons for one item.
	 *
	 * @param array<string, mixed> $row  Item.
	 * @param string               $base Screen URL.
	 */
	private function actions( array $row, string $base ): void {
		if ( 'rule' === $row['group'] ) {
			$rule_id = (string) strtok( (string) $row['label'], ' ' );
			if ( HandoverChecklist::NA === $row['state'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
				$this->hidden( 'rule_auto', $base, array( 'rule_id' => $rule_id ) );
				echo '<button type="submit" class="button-link">' . esc_html__( 'Check automatically again', 'estasoft-handover-passport' ) . '</button></form>';
				return;
			}
			echo '<details><summary>' . esc_html__( 'Not applicable', 'estasoft-handover-passport' ) . '</summary>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$this->hidden( 'rule_na', $base, array( 'rule_id' => $rule_id ) );
			echo '<p><label>' . esc_html__( 'Why does this not apply?', 'estasoft-handover-passport' ) . '<br><input type="text" name="reason" class="regular-text" maxlength="500" required></label></p>';
			echo '<p><button type="submit" class="button">' . esc_html__( 'Mark not applicable', 'estasoft-handover-passport' ) . '</button></p></form></details>';
			return;
		}
		if ( 'custom' !== $row['group'] ) {
			return;
		}
		foreach ( Kinds::checklistStates() as $state => $label ) {
			if ( $state === $row['state'] ) {
				continue;
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
			$this->hidden( 'state', $base, array( 'id' => (string) (int) $row['id'], 'state' => $state ) );
			echo '<button type="submit" class="button-link">' . esc_html( $label ) . '</button></form> ';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
		$this->hidden( 'delete', $base, array( 'id' => (string) (int) $row['id'] ) );
		echo '<button type="submit" class="button-link estasoft-hp-danger">' . esc_html__( 'Delete', 'estasoft-handover-passport' ) . '</button></form>';
	}

	/**
	 * The form that adds an item.
	 *
	 * @param string $base Screen URL.
	 */
	private function addForm( string $base ): void {
		$state  = FormState::take( 'checklist_add' );
		$input  = null === $state ? array() : $state['input'];
		$errors = null === $state ? array() : $state['errors'];
		echo '<details class="estasoft-hp-card"' . ( array() !== $errors ? ' open' : '' ) . '><summary><strong>' . esc_html__( 'Add your own item', 'estasoft-handover-passport' ) . '</strong></summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$this->hidden( 'add', $base );
		Form::row( 'text', array( 'type' => 'text', 'label' => __( 'What has to be done', 'estasoft-handover-passport' ), 'required' => true, 'max' => 190 ), (string) ( $input['text'] ?? '' ), (string) ( $errors['text'] ?? '' ) );
		Form::row( 'owner', array( 'type' => 'text', 'label' => __( 'Who does it', 'estasoft-handover-passport' ), 'max' => 190 ), (string) ( $input['owner'] ?? '' ), (string) ( $errors['owner'] ?? '' ) );
		Form::row( 'due', array( 'type' => 'date', 'label' => __( 'Due date', 'estasoft-handover-passport' ) ), (string) ( $input['due'] ?? '' ), (string) ( $errors['due'] ?? '' ) );
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Add item', 'estasoft-handover-passport' ) . '</button></p></form></details>';
	}

	/**
	 * Save a change.
	 */
	public function handle(): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to change the checklist.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$raw    = Request::postedUrl( 'return' );
		$return = ( '' !== $raw && 0 === strpos( $raw, admin_url( 'admin.php' ) ) ) ? $raw : add_query_arg( array( 'page' => HandoverScreen::SLUG, 'tab' => 'checklist' ), admin_url( 'admin.php' ) );
		$list   = $this->plugin->handover();
		$op     = Request::post( 'op' );
		try {
			switch ( $op ) {
				case 'add':
					$list->addCustom( Request::post( 'text' ), Request::post( 'owner' ), Request::post( 'due' ) );
					$notice = 'checklist_added';
					break;
				case 'state':
					$list->setCustomState( absint( Request::post( 'id' ) ), Request::post( 'state' ) );
					$notice = 'checklist_updated';
					break;
				case 'delete':
					$id     = absint( Request::post( 'id' ) );
					$record = $this->plugin->records()->get( $id, false );
					if ( null === $record || Kinds::CHECKLIST !== (string) $record['kind'] ) {
						throw new ValidationError( __( 'Item', 'estasoft-handover-passport' ), __( 'That item is not on the checklist.', 'estasoft-handover-passport' ) );
					}
					$this->plugin->records()->delete( $id );
					$notice = 'checklist_deleted';
					break;
				case 'rule_na':
					$list->setRuleNotApplicable( Request::post( 'rule_id' ), true, Request::post( 'reason' ) );
					$notice = 'checklist_updated';
					break;
				case 'rule_auto':
					$list->setRuleNotApplicable( Request::post( 'rule_id' ), false );
					$notice = 'checklist_updated';
					break;
				default:
					$notice = 'checklist_invalid';
			}
		} catch ( ValidationError | SecretFound $e ) {
			$field = $e instanceof ValidationError ? $e->field() : (string) strtok( $e->field(), '.' );
			$keys  = array(
				__( 'What has to be done', 'estasoft-handover-passport' ) => 'text',
				__( 'Who does it', 'estasoft-handover-passport' )         => 'owner',
				__( 'Due date', 'estasoft-handover-passport' )            => 'due',
			);
			if ( 'add' === $op ) {
				FormState::put( $this->plugin->secretGuard(), 'checklist_add', array( 'text' => Request::post( 'text' ), 'owner' => Request::post( 'owner' ), 'due' => Request::post( 'due' ) ), array( ( $keys[ $field ] ?? 'text' ) => $e->getMessage() ) );
			}
			wp_safe_redirect( add_query_arg( 'estasoft_hp_notice', 'checklist_invalid', $return ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'estasoft_hp_notice', $notice, $return ) );
		exit;
	}

	/**
	 * Hidden fields of a form.
	 *
	 * @param string               $op    Operation.
	 * @param string               $base  Return URL.
	 * @param array<string, string> $extra More hidden fields.
	 */
	private function hidden( string $op, string $base, array $extra = array() ): void {
		Form::nonce( self::ACTION, false );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="return" value="' . esc_url( $base ) . '">';
		foreach ( $extra as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
	}
}
