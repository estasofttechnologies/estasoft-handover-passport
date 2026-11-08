<?php
/**
 * The Notes and status tab (HND-7, PRT-8).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Passport\Passport;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Security\SecretFound;

defined( 'ABSPATH' ) || exit;

/**
 * What the sender would tell the next person, and the status of the Passport.
 */
final class NotesView {

	public const ACTION = 'estasoft_hp_notes';

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
		$can_edit = Capabilities::can( Capabilities::EDIT );
		$status   = $this->plugin->handoverStatus()->current();
		$labels   = Passport::statusLabels();

		View::cardStart( __( 'Status', 'estasoft-handover-passport' ) );
		echo '<p>' . View::badge( 'status-' . $status, (string) ( $labels[ $status ] ?? $status ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
		echo '<p>' . esc_html__( 'A Passport is Handed over when someone records that a snapshot was received. If you carry on looking after the site, mark it Active again.', 'estasoft-handover-passport' ) . '</p>';
		if ( $can_edit && Passport::STATUS_ACTIVE !== $status ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$this->hidden( 'active', $base );
			echo '<button type="submit" class="btn sm">' . esc_html__( 'Mark as Active', 'estasoft-handover-passport' ) . '</button></form>';
		}
		View::cardEnd();

		$state  = FormState::take( 'successor_notes' );
		$text   = null === $state ? $this->plugin->successorNotes()->get() : (string) ( $state['input']['notes'] ?? '' );
		$errors = null === $state ? array() : $state['errors'];
		View::cardStart( __( 'Notes to the successor', 'estasoft-handover-passport' ) );
		echo '<p class="t2">' . esc_html__( 'What you would do first, what is fragile, and what is unfinished. These notes go into the Client pack and the Technical pack. You can use **bold**, *italic*, lists that start with - or 1., and links.', 'estasoft-handover-passport' ) . '</p>';
		if ( ! $can_edit ) {
			echo '<div class="estasoft-hp-prose">' . ( '' === $text ? '<p>' . esc_html__( 'No notes yet.', 'estasoft-handover-passport' ) . '</p>' : '<pre class="pre">' . esc_html( $text ) . '</pre>' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The text is escaped; the rest is fixed markup.
			View::cardEnd();
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$this->hidden( 'save', $base );
		Form::row( 'notes', array( 'type' => 'textarea', 'label' => __( 'Notes', 'estasoft-handover-passport' ), 'help' => __( 'Never write passwords or keys here. Write where they are kept.', 'estasoft-handover-passport' ) ), $text, (string) ( $errors['notes'] ?? '' ) );
		echo '<p class="submit"><button type="submit" class="btn pri">' . esc_html__( 'Save notes', 'estasoft-handover-passport' ) . '</button></p></form>';
		View::cardEnd();
	}

	/**
	 * Save the notes or change the status.
	 */
	public function handle(): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to change the Passport.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$raw    = Request::postedUrl( 'return' );
		$return = ( '' !== $raw && 0 === strpos( $raw, admin_url( 'admin.php' ) ) ) ? $raw : add_query_arg( array( 'page' => HandoverScreen::SLUG, 'tab' => 'notes' ), admin_url( 'admin.php' ) );
		try {
			if ( 'active' === Request::post( 'op' ) ) {
				$this->plugin->handoverStatus()->set( Passport::STATUS_ACTIVE );
				$notice = 'status_active';
			} else {
				$this->plugin->successorNotes()->save( Request::postedTextarea( 'notes' ) );
				$notice = 'notes_saved';
			}
		} catch ( ValidationError | SecretFound $e ) {
			FormState::put( $this->plugin->secretGuard(), 'successor_notes', array( 'notes' => Request::postedTextarea( 'notes' ) ), array( 'notes' => $e->getMessage() ) );
			$notice = 'notes_invalid';
		}
		wp_safe_redirect( add_query_arg( 'estasoft_hp_notice', $notice, $return ) );
		exit;
	}

	/**
	 * Hidden fields of a form.
	 *
	 * @param string $op   Operation.
	 * @param string $base Return URL.
	 */
	private function hidden( string $op, string $base ): void {
		Form::nonce( self::ACTION, false );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="return" value="' . esc_url( $base ) . '">';
	}
}
