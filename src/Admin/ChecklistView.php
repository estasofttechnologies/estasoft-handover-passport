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

		$figure = sprintf(
			/* translators: 1: number of items done, 2: number of items counted. */
			__( '%1$d of %2$d', 'estasoft-handover-passport' ),
			$progress['done'],
			$progress['total']
		);
		$words = sprintf(
			/* translators: 1: number of items done, 2: number of items counted. */
			__( '%1$d of %2$d checklist items are done', 'estasoft-handover-passport' ),
			$progress['done'],
			$progress['total']
		);
		$card = View::kpi( $figure, __( 'Handover checklist', 'estasoft-handover-passport' ), View::bar( $progress['done'], $progress['total'], $words ) );
		echo $card; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kpi() and bar() escape their parts.

		View::bareCardStart();
		echo '<div class="scr">';
		$this->table( $items, $base, $can_edit );
		echo '</div>';
		View::cardEnd( $this->footer( $base, $can_edit ) );
	}

	/**
	 * The words under the table, and the form that adds an item.
	 *
	 * @param string $base     Screen URL.
	 * @param bool   $can_edit Whether the person may add an item.
	 */
	private function footer( string $base, bool $can_edit ): string {
		$note = '<p class="t2 cap">' . esc_html__( 'Items from the rules are checked by Passport itself; items you add are ticked by hand. Items marked Not applicable are not counted.', 'estasoft-handover-passport' ) . '</p>';
		if ( ! $can_edit ) {
			return $note;
		}
		ob_start();
		$this->addForm( $base );
		return $note . (string) ob_get_clean();
	}

	/**
	 * Where each rule is fixed, by rule ID.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function ruleScreens(): array {
		$out = array();
		foreach ( $this->plugin->findings()->readiness()['rules'] as $rule ) {
			$out[ (string) $rule['id'] ] = (array) $rule['screen'];
		}
		return $out;
	}

	/**
	 * The address that leads to the place where an item is dealt with, or an empty string.
	 *
	 * @param array<string, mixed>                 $row     Item.
	 * @param array<string, array<string, string>> $screens Where each rule is fixed.
	 */
	private function openUrl( array $row, array $screens ): string {
		if ( 'rule' === $row['group'] ) {
			$screen = $screens[ (string) strtok( (string) $row['label'], ' ' ) ] ?? array();
			if ( '' === (string) ( $screen['page'] ?? '' ) ) {
				return '';
			}
			return add_query_arg( array_filter( array( 'page' => (string) $screen['page'], 'tab' => (string) ( $screen['tab'] ?? '' ) ) ), admin_url( 'admin.php' ) );
		}
		if ( 'transfer' === $row['group'] ) {
			return add_query_arg( array( 'page' => PeopleScreen::SLUG, 'tab' => 'ownership' ), admin_url( 'admin.php' ) );
		}
		if ( 'restore' === $row['group'] ) {
			return add_query_arg( array( 'page' => SafetyScreen::SLUG, 'tab' => 'tests' ), admin_url( 'admin.php' ) );
		}
		return '';
	}

	/**
	 * One table of items.
	 *
	 * @param array<int, array<string, mixed>> $rows     Items.
	 * @param string                           $base     Screen URL.
	 * @param bool                             $can_edit Whether the person may change items.
	 */
	private function table( array $rows, string $base, bool $can_edit ): void {
		$screens = $this->ruleScreens();
		echo '<table class="tbl cards estasoft-hp-table"><caption class="sr">' . esc_html__( 'Handover checklist', 'estasoft-handover-passport' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Item', 'estasoft-handover-passport' ), __( 'Owner', 'estasoft-handover-passport' ), __( 'State', 'estasoft-handover-passport' ), __( 'Actions', 'estasoft-handover-passport' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$sub = array();
			if ( '' !== (string) $row['detail'] ) {
				$sub[] = (string) $row['detail'];
			}
			if ( '' !== (string) $row['when'] ) {
				$sub[] = Dates::displayDate( (string) $row['when'] );
			}
			echo '<tr><td class="t1" data-label="' . esc_attr__( 'Item', 'estasoft-handover-passport' ) . '"><b>' . esc_html( (string) $row['label'] ) . '</b>' . ( array() !== $sub ? '<div class="t2 cap">' . esc_html( implode( ' · ', $sub ) ) . '</div>' : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Parts are escaped above.
			echo '<td data-label="' . esc_attr__( 'Owner', 'estasoft-handover-passport' ) . '">' . esc_html( (string) $row['who'] ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'State', 'estasoft-handover-passport' ) . '">' . $this->stateBadge( (string) $row['state'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			echo '<td class="act" data-label="' . esc_attr__( 'Actions', 'estasoft-handover-passport' ) . '">';
			$url = $this->openUrl( $row, $screens );
			if ( '' !== $url ) {
				echo '<a class="btn sm" href="' . esc_url( $url ) . '">' . esc_html__( 'Open', 'estasoft-handover-passport' ) . '</a> ';
			}
			if ( $can_edit ) {
				$this->actions( $row, $base );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * The badge for a state: Done, To do or Not applicable.
	 *
	 * @param string $state done, not_done or na.
	 */
	private function stateBadge( string $state ): string {
		if ( HandoverChecklist::DONE === $state ) {
			return View::badge( 'check-done', __( 'Done', 'estasoft-handover-passport' ) );
		}
		if ( HandoverChecklist::NA === $state ) {
			return View::badge( 'check-na', __( 'Not applicable', 'estasoft-handover-passport' ) );
		}
		return View::badge( 'check-todo', __( 'To do', 'estasoft-handover-passport' ) );
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
				echo '<button type="submit" class="btn sm">' . esc_html__( 'Check automatically again', 'estasoft-handover-passport' ) . '</button></form>';
				return;
			}
			echo '<details class="estasoft-hp-tools"><summary class="btn sm">' . esc_html__( 'Not applicable', 'estasoft-handover-passport' ) . '</summary>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$this->hidden( 'rule_na', $base, array( 'rule_id' => $rule_id ) );
			echo '<p><label>' . esc_html__( 'Why does this not apply?', 'estasoft-handover-passport' ) . '<br><input type="text" name="reason" class="fld" maxlength="500" required></label></p>';
			echo '<p><button type="submit" class="btn sm">' . esc_html__( 'Mark not applicable', 'estasoft-handover-passport' ) . '</button></p></form></details>';
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
			echo '<button type="submit" class="btn sm">' . esc_html( $label ) . '</button></form> ';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
		$this->hidden( 'delete', $base, array( 'id' => (string) (int) $row['id'] ) );
		echo '<button type="submit" class="btn sm estasoft-hp-danger">' . esc_html__( 'Delete', 'estasoft-handover-passport' ) . '</button></form>';
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
		echo '<details class="estasoft-hp-tools"' . ( array() !== $errors ? ' open' : '' ) . '><summary class="btn sm">' . Icons::svg( 'plus', 16 ) . esc_html__( 'Add your own item', 'estasoft-handover-passport' ) . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is fixed markup.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$this->hidden( 'add', $base );
		Form::row( 'text', array( 'type' => 'text', 'label' => __( 'What has to be done', 'estasoft-handover-passport' ), 'required' => true, 'max' => 190 ), (string) ( $input['text'] ?? '' ), (string) ( $errors['text'] ?? '' ) );
		Form::row( 'owner', array( 'type' => 'text', 'label' => __( 'Who does it', 'estasoft-handover-passport' ), 'max' => 190 ), (string) ( $input['owner'] ?? '' ), (string) ( $errors['owner'] ?? '' ) );
		Form::row( 'due', array( 'type' => 'date', 'label' => __( 'Due date', 'estasoft-handover-passport' ) ), (string) ( $input['due'] ?? '' ), (string) ( $errors['due'] ?? '' ) );
		echo '<p class="submit"><button type="submit" class="btn pri">' . esc_html__( 'Add item', 'estasoft-handover-passport' ) . '</button></p></form></details>';
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
						$notice = 'checklist_invalid';
						break;
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
	 * @param string                $op    Operation.
	 * @param string                $base  Return URL.
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
