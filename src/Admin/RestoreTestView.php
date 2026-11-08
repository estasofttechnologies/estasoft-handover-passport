<?php
/**
 * The restore-test log screen (BKP-4, BKP-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Services\RestoreTestService;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Shows restore tests, the last Pass and the recovery targets, and takes a new log entry.
 */
final class RestoreTestView {

	public const ACTION = 'estasoft_hp_restore_test';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
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
		$service  = $this->plugin->restoreTests();
		$tests    = $service->all();
		$can_edit = Capabilities::can( Capabilities::EDIT );
		$settings = $this->plugin->settings();
		$last     = $service->lastPass();

		View::cardStart( __( 'Recovery targets', 'estasoft-handover-passport' ) );
		View::facts(
			array(
				__( 'Last test that passed', 'estasoft-handover-passport' ) => null === $last ? __( 'None logged', 'estasoft-handover-passport' ) : Dates::displayDate( $last ),
				__( 'Tests logged', 'estasoft-handover-passport' )           => (string) count( $tests ),
				__( 'Most data that may be lost', 'estasoft-handover-passport' ) => $this->hours( $settings->get( 'recovery.data_loss_hours' ) ),
				__( 'Longest the site may be down', 'estasoft-handover-passport' ) => $this->hours( $settings->get( 'recovery.downtime_hours' ) ),
			)
		);
		if ( Capabilities::can( Capabilities::CONFIGURE ) ) {
			echo '<p><a href="' . esc_url( add_query_arg( array( 'page' => SettingsScreen::SLUG, 'tab' => 'findings' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Change the recovery targets', 'estasoft-handover-passport' ) . '</a></p>';
		}
		View::cardEnd();

		if ( $can_edit ) {
			$this->form( $base );
		}

		if ( array() === $tests ) {
			echo '<p>' . esc_html__( 'No restore test has been logged yet.', 'estasoft-handover-passport' ) . '</p>';
			return;
		}
		$runbooks = array();
		foreach ( $this->plugin->runbooks()->all() as $runbook ) {
			$runbooks[ (int) $runbook['id'] ] = (string) $runbook['title'];
		}
		View::cardStart( __( 'Restore tests', 'estasoft-handover-passport' ), '', true );
		echo '<div class="scr"><table class="tbl cards estasoft-hp-table"><caption class="screen-reader-text">' . esc_html__( 'Restore tests', 'estasoft-handover-passport' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Date', 'estasoft-handover-passport' ), __( 'Who', 'estasoft-handover-passport' ), __( 'What', 'estasoft-handover-passport' ), __( 'Where', 'estasoft-handover-passport' ), __( 'Backup used', 'estasoft-handover-passport' ), __( 'Time taken', 'estasoft-handover-passport' ), __( 'Result', 'estasoft-handover-passport' ), __( 'Runbook', 'estasoft-handover-passport' ), __( 'Problems found', 'estasoft-handover-passport' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'estasoft-handover-passport' ) . '</span></th></tr></thead><tbody>';
		foreach ( $tests as $test ) {
			$runbook = (int) $test['runbook_id'] > 0 ? ( $runbooks[ (int) $test['runbook_id'] ] ?? __( 'Deleted runbook', 'estasoft-handover-passport' ) ) . ' v' . (int) $test['runbook_version'] : '';
			echo '<tr><td class="t1" data-label="' . esc_attr__( 'Date', 'estasoft-handover-passport' ) . '">' . esc_html( Dates::displayDate( (string) $test['test_date'] ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Who', 'estasoft-handover-passport' ) . '">' . esc_html( (string) $test['who'] ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'What', 'estasoft-handover-passport' ) . '">' . esc_html( RestoreTestService::scopes()[ (string) $test['scope'] ] ?? '' ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Where', 'estasoft-handover-passport' ) . '">' . esc_html( RestoreTestService::places()[ (string) $test['place'] ] ?? '' ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Backup used', 'estasoft-handover-passport' ) . '">' . esc_html( Dates::displayDate( empty( $test['backup_date'] ) ? null : (string) $test['backup_date'] ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Time taken', 'estasoft-handover-passport' ) . '">' . esc_html( (int) $test['minutes'] > 0 ? sprintf( /* translators: %d: minutes. */ _n( '%d minute', '%d minutes', (int) $test['minutes'], 'estasoft-handover-passport' ), (int) $test['minutes'] ) : '' ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Result', 'estasoft-handover-passport' ) . '">' . View::badge( 'result-' . (string) $test['result'], RestoreTestService::results()[ (string) $test['result'] ] ?? '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			echo '<td data-label="' . esc_attr__( 'Runbook', 'estasoft-handover-passport' ) . '">' . esc_html( $runbook ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Problems found', 'estasoft-handover-passport' ) . '">' . esc_html( (string) $test['problems'] ) . '</td><td class="act estasoft-hp-actions" data-label="">';
			if ( $can_edit ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
				$this->hidden( 'delete', (int) $test['id'], $base );
				echo '<button type="submit" class="btn sm estasoft-hp-danger">' . esc_html__( 'Remove', 'estasoft-handover-passport' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		View::cardEnd();
	}

	/**
	 * Save or remove a test.
	 */
	public function handle(): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to change records.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$op      = Request::post( 'op' );
		$raw     = Request::postedUrl( 'return' );
		$return  = ( '' !== $raw && 0 === strpos( $raw, admin_url( 'admin.php' ) ) ) ? $raw : add_query_arg( array( 'page' => SafetyScreen::SLUG, 'tab' => 'tests' ), admin_url( 'admin.php' ) );
		$service = $this->plugin->restoreTests();
		try {
			if ( 'delete' === $op ) {
				$service->delete( absint( Request::post( 'id' ) ) );
				$notice = 'test_removed';
			} else {
				$service->log( Request::postedFields() );
				$notice = 'test_logged';
			}
		} catch ( ValidationError | SecretFound $e ) {
			$label = $e instanceof ValidationError ? $e->field() : (string) strtok( $e->field(), '.' );
			FormState::put( $this->plugin->secretGuard(), 'restore_test', Request::postedFields(), array( $this->errorKey( $label ) => $e->getMessage() ) );
			wp_safe_redirect( add_query_arg( 'estasoft_hp_notice', 'test_invalid', $return ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'estasoft_hp_notice', $notice, $return ) );
		exit;
	}

	/**
	 * Form that logs a test.
	 *
	 * @param string $base Screen URL.
	 */
	private function form( string $base ): void {
		$state  = FormState::take( 'restore_test' );
		$input  = null === $state ? array() : $state['input'];
		$errors = null === $state ? array() : $state['errors'];
		$value  = static fn( string $key, string $fallback = '' ) => $input[ $key ] ?? $fallback;

		$backups = array();
		foreach ( $this->plugin->records()->listKind( Kinds::BACKUP, Capabilities::canSeeSensitive() ) as $backup ) {
			if ( empty( $backup['hidden'] ) ) {
				$backups[ (int) $backup['id'] ] = (string) $backup['title'];
			}
		}
		$runbooks = array();
		foreach ( $this->plugin->runbooks()->all() as $runbook ) {
			$runbooks[ (int) $runbook['id'] ] = (string) $runbook['title'];
		}

		echo '<details class="estasoft-hp-card" id="estasoft-hp-log-test"' . ( array() !== $errors || '1' === Request::key( 'log' ) ? ' open' : '' ) . '><summary><strong>' . esc_html__( 'Log a restore test', 'estasoft-handover-passport' ) . '</strong></summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$this->hidden( 'log', 0, $base );
		Form::row( 'f[test_date]', array( 'type' => 'date', 'label' => __( 'Date of the test', 'estasoft-handover-passport' ), 'required' => true ), $value( 'test_date', Clock::today() ), $errors['test_date'] ?? '' );
		Form::row( 'f[who]', array( 'type' => 'text', 'label' => __( 'Who did it', 'estasoft-handover-passport' ), 'max' => 190 ), $value( 'who' ), $errors['who'] ?? '' );
		Form::row( 'f[scope]', array( 'type' => 'select', 'label' => __( 'What was restored', 'estasoft-handover-passport' ), 'options' => RestoreTestService::scopes() ), $value( 'scope' ) );
		Form::row( 'f[place]', array( 'type' => 'select', 'label' => __( 'Where it was restored', 'estasoft-handover-passport' ), 'options' => RestoreTestService::places() ), $value( 'place' ) );
		Form::row( 'f[backup_date]', array( 'type' => 'date', 'label' => __( 'Date of the backup used', 'estasoft-handover-passport' ) ), $value( 'backup_date' ), $errors['backup_date'] ?? '' );
		Form::row( 'f[backup_item_id]', array( 'type' => 'select', 'label' => __( 'Backup record', 'estasoft-handover-passport' ), 'options' => $backups ), $value( 'backup_item_id' ) );
		Form::row( 'f[minutes]', array( 'type' => 'int', 'label' => __( 'Time taken (minutes)', 'estasoft-handover-passport' ) ), $value( 'minutes' ) );
		Form::row( 'f[result]', array( 'type' => 'select', 'label' => __( 'Result', 'estasoft-handover-passport' ), 'required' => true, 'options' => RestoreTestService::results() ), $value( 'result' ), $errors['result'] ?? '' );
		Form::row( 'f[runbook_id]', array( 'type' => 'select', 'label' => __( 'Runbook used', 'estasoft-handover-passport' ), 'options' => $runbooks ), $value( 'runbook_id' ) );
		Form::row( 'f[problems]', array( 'type' => 'textarea', 'label' => __( 'Problems found', 'estasoft-handover-passport' ), 'help' => __( 'Describe what went wrong. Never write passwords or keys here.', 'estasoft-handover-passport' ) ), $value( 'problems' ), $errors['problems'] ?? '' );
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Log the test', 'estasoft-handover-passport' ) . '</button></p></form></details>';
	}

	/**
	 * Hidden fields of a form.
	 *
	 * @param string $op   log or delete.
	 * @param int    $id   Test ID.
	 * @param string $base Return URL.
	 */
	private function hidden( string $op, int $id, string $base ): void {
		Form::nonce( self::ACTION, false );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="return" value="' . esc_url( $base ) . '">';
	}

	/**
	 * Map an error label back to a field key.
	 *
	 * @param string $label Label from the exception.
	 */
	private function errorKey( string $label ): string {
		$map = array(
			__( 'Date of the test', 'estasoft-handover-passport' ) => 'test_date',
			__( 'Result', 'estasoft-handover-passport' ) => 'result',
			__( 'Date of the backup used', 'estasoft-handover-passport' ) => 'backup_date',
			__( 'Who did it', 'estasoft-handover-passport' ) => 'who',
			__( 'Problems found', 'estasoft-handover-passport' ) => 'problems',
		);
		return $map[ $label ] ?? 'problems';
	}

	/**
	 * Hours or "Not set".
	 *
	 * @param mixed $value Setting.
	 */
	private function hours( $value ): string {
		if ( null === $value || '' === $value ) {
			return __( 'Not set', 'estasoft-handover-passport' );
		}
		/* translators: %d: hours. */
		return sprintf( _n( '%d hour', '%d hours', (int) $value, 'estasoft-handover-passport' ), (int) $value );
	}
}
