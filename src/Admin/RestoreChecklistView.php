<?php
/**
 * The after-restore checklist: what to look at, a printable copy, and the hand checks the agency can change (URL-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Export\ExportResult;
use Estasoft\HandoverPassport\Export\Render\PrintPage;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the checklist on the Critical addresses tab and prints it.
 */
final class RestoreChecklistView {

	public const ACTION       = 'estasoft_hp_restore_checks';
	public const PRINT_ACTION = 'estasoft_hp_restore_checklist_print';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Attach the handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::PRINT_ACTION, array( $this, 'printView' ) );
	}

	/**
	 * The address of the printable checklist.
	 */
	public static function printUrl(): string {
		return wp_nonce_url( add_query_arg( 'action', self::PRINT_ACTION, admin_url( 'admin-post.php' ) ), self::PRINT_ACTION );
	}

	/**
	 * The card on the Critical addresses tab.
	 *
	 * @param string $base Address of the tab.
	 */
	public function render( string $base ): void {
		$checklist = $this->plugin->restoreChecklist();
		$items     = $checklist->items();
		echo '<div class="estasoft-hp-card" id="restore-checklist"><h2>' . esc_html__( 'After a restore, check this', 'estasoft-handover-passport' ) . '</h2>';
		echo '<p>' . esc_html__( 'Getting the files and database back is not the end. This list is the critical addresses below, followed by the things only a person can check. Print it, or run it inside a drill of a restore runbook.', 'estasoft-handover-passport' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( self::printUrl() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Print the checklist', 'estasoft-handover-passport' ) . '</a></p>';
		echo '<ol>';
		foreach ( $items as $item ) {
			echo '<li>' . esc_html( $item['label'] ) . ( '' !== $item['detail'] ? ' <span class="description">' . esc_html( $item['detail'] ) . '</span>' : '' ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part is escaped.
		}
		echo '</ol>';
		if ( Capabilities::can( Capabilities::EDIT ) ) {
			$state = FormState::take( 'restore_checks' );
			$text  = null !== $state ? (string) ( $state['input']['checks'] ?? '' ) : implode( "\n", $checklist->manual() );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-form"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="return" value="' . esc_attr( $base ) . '">';
			Form::nonce( self::ACTION, false );
			if ( null !== $state && isset( $state['errors']['checks'] ) ) {
				echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html( (string) $state['errors']['checks'] ) . '</p></div>';
			}
			Form::row(
				'checks',
				array(
					'type'  => 'textarea',
					'label' => __( 'Checks a person makes by hand', 'estasoft-handover-passport' ),
					'help'  => sprintf(
						/* translators: 1: maximum number of lines, 2: maximum length of a line. */
						__( 'One check per line, up to %1$d lines of %2$d characters. Leave it empty to use the shipped list. Never write a password here.', 'estasoft-handover-passport' ),
						\Estasoft\HandoverPassport\Services\RestoreChecklist::MAX_LINES,
						\Estasoft\HandoverPassport\Services\RestoreChecklist::MAX_LENGTH
					),
				),
				$text
			);
			echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save the checks', 'estasoft-handover-passport' ) . '</button></p></form>';
		}
		echo '</div>';
	}

	/**
	 * Save the hand checks.
	 */
	public function handle(): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to change the checklist.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$raw    = Request::postedUrl( 'return' );
		$return = ( '' !== $raw && 0 === strpos( $raw, admin_url( 'admin.php' ) ) ) ? $raw : add_query_arg( array( 'page' => SafetyScreen::SLUG, 'tab' => 'urls' ), admin_url( 'admin.php' ) );
		$text   = Request::postedTextarea( 'checks' );
		try {
			$this->plugin->restoreChecklist()->saveManual( $text );
			View::say( __( 'Checklist saved.', 'estasoft-handover-passport' ), false );
		} catch ( ValidationError $e ) {
			FormState::put( $this->plugin->secretGuard(), 'restore_checks', array( 'checks' => $text ), array( 'checks' => $e->getMessage() ) );
		} catch ( SecretFound $e ) {
			FormState::put( $this->plugin->secretGuard(), 'restore_checks', array( 'checks' => '' ), array( 'checks' => $e->getMessage() ) );
		}
		wp_safe_redirect( $return . '#restore-checklist' );
		exit;
	}

	/**
	 * The printable page.
	 */
	public function printView(): void {
		if ( ! Capabilities::can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to view the checklist.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::PRINT_ACTION );
		$style = $this->plugin->exports()->style();
		$site  = (string) $this->plugin->passport()->get( 'site_name', '' );
		$site  = '' !== $site ? $site : (string) get_bloginfo( 'name' );
		$body  = PrintPage::checklist(
			__( 'After a restore, check this', 'estasoft-handover-passport' ),
			__( 'Tick each line when you have looked at it yourself. Write what you saw beside any line that is not right.', 'estasoft-handover-passport' ),
			$this->plugin->restoreChecklist()->items(),
			$style,
			$site,
			Clock::sql()
		);
		Download::send( new ExportResult( PrintPage::document( __( 'After a restore, check this', 'estasoft-handover-passport' ) . ' · ' . $site, $body, $style ), 'restore-checklist-print.html', 'text/html; charset=utf-8', 'print', 'restore-checklist' ), true );
	}
}
