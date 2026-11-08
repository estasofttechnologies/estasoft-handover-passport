<?php
/**
 * The emailed off-site copy of the Emergency sheet (EXP-10).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export;

use Estasoft\HandoverPassport\Admin\SettingsScreen;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Reminders\ReminderMessage;
use Estasoft\HandoverPassport\Checks\CheckLimiter;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Dates;
use Estasoft\HandoverPassport\Support\TempFiles;

defined( 'ABSPATH' ) || exit;

/**
 * Off by default. When it is on and addresses are listed, the Emergency sheet is emailed as a PDF when a snapshot is made
 * and once a month. The message says email is not encrypted. The sheet is made without anyone's Sensitive rights, so it
 * holds no Sensitive record. A failed send is shown on the Overview and tried again once a day, up to three more times.
 * A copy of the site (the copy guard) sends nothing.
 */
final class EmailedCopy {

	public const OPTION = 'estasoft_hp_copy_state';

	/**
	 * Tries after the first one.
	 */
	public const RETRIES = 3;

	/**
	 * Most addresses that may be listed.
	 */
	public const MAX_ADDRESSES = 5;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * The setting, cleaned.
	 *
	 * @return array{enabled: bool, addresses: array<int, string>, on_snapshot: bool, monthly: bool}
	 */
	public function config(): array {
		$stored    = (array) $this->plugin->settings()->get( 'emailed_copy', array() );
		$addresses = array();
		foreach ( (array) ( $stored['addresses'] ?? array() ) as $address ) {
			$address = sanitize_email( (string) $address );
			if ( '' !== $address && is_email( $address ) ) {
				$addresses[ strtolower( $address ) ] = $address;
			}
		}
		return array(
			'enabled'     => ! empty( $stored['enabled'] ),
			'addresses'   => array_slice( array_values( $addresses ), 0, self::MAX_ADDRESSES ),
			'on_snapshot' => ! array_key_exists( 'on_snapshot', $stored ) || ! empty( $stored['on_snapshot'] ),
			'monthly'     => ! array_key_exists( 'monthly', $stored ) || ! empty( $stored['monthly'] ),
		);
	}

	/**
	 * Whether copies are switched on and somebody is listed to get them.
	 */
	public function active(): bool {
		$config = $this->config();
		return $config['enabled'] && array() !== $config['addresses'];
	}

	/**
	 * What is known about the copies.
	 *
	 * @return array{pending: bool, reason: string, retries: int, failed_at: string, error: string, sent_at: string, month: string}
	 */
	public function state(): array {
		$stored = (array) get_option( self::OPTION, array() );
		return array(
			'pending'   => ! empty( $stored['pending'] ),
			'reason'    => (string) ( $stored['reason'] ?? '' ),
			'retries'   => (int) ( $stored['retries'] ?? 0 ),
			'failed_at' => (string) ( $stored['failed_at'] ?? '' ),
			'error'     => (string) ( $stored['error'] ?? '' ),
			'sent_at'   => (string) ( $stored['sent_at'] ?? '' ),
			'month'     => (string) ( $stored['month'] ?? '' ),
		);
	}

	/**
	 * Note the current month as already handled, so switching copies on does not send one at once.
	 */
	public function startMonth(): void {
		$state          = $this->state();
		$state['month'] = substr( Clock::today(), 0, 7 );
		$this->save( $state );
	}

	/**
	 * A snapshot was made.
	 */
	public function onSnapshot(): void {
		$config = $this->config();
		if ( $config['enabled'] && $config['on_snapshot'] ) {
			$this->send( 'snapshot' );
		}
	}

	/**
	 * Run from the daily event: try a failed copy again, and send the monthly copy once a month.
	 */
	public function daily(): void {
		$config = $this->config();
		if ( ! $config['enabled'] ) {
			return;
		}
		$state = $this->state();
		if ( $state['pending'] ) {
			if ( $state['retries'] < self::RETRIES && '' !== $state['failed_at'] && substr( $state['failed_at'], 0, 10 ) !== Clock::now()->format( 'Y-m-d' ) ) {
				$this->send( '' !== $state['reason'] ? $state['reason'] : 'monthly', true );
			}
			return;
		}
		if ( $config['monthly'] && substr( Clock::today(), 0, 7 ) !== $state['month'] ) {
			$this->send( 'monthly' );
		}
	}

	/**
	 * Make the Emergency sheet and email it.
	 *
	 * @param string $why   "snapshot", "monthly" or "manual".
	 * @param bool   $retry Whether this is a retry of a failed copy.
	 * @return array{state: string, sent: int, error: string}
	 *         state is "disabled", "paused" (the copy guard), "nobody", "wait", "sent" or "failed".
	 */
	public function send( string $why, bool $retry = false ): array {
		$report = array( 'state' => 'disabled', 'sent' => 0, 'error' => '' );
		$config = $this->config();
		if ( ! $config['enabled'] ) {
			return $report;
		}
		$guard = $this->plugin->copyGuard();
		$guard->check();
		if ( $guard->paused() ) {
			$report['state'] = 'paused';
			return $report;
		}
		if ( array() === $config['addresses'] ) {
			$report['state'] = 'nobody';
			return $report;
		}
		if ( 'manual' === $why && ! CheckLimiter::take( 'copy', 'sheet' ) ) {
			$report['state'] = 'wait';
			return $report;
		}

		$state = $this->state();
		if ( 'monthly' === $why || ( $retry && 'monthly' === $state['reason'] ) ) {
			$state['month'] = substr( Clock::today(), 0, 7 );
		}

		$path = '';
		try {
			$sheet = $this->plugin->exports()->make( Profile::EMERGENCY, 'pdf', new ExportOptions( false, array(), 'manual' === $why ? get_current_user_id() : 0 ), true );
			$path  = TempFiles::create( '.pdf' );
			if ( '' === $path || false === file_put_contents( $path, $sheet->bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A private file in the protected folder.
				$sent  = 0;
				$error = __( 'No safe place to write the file could be made.', 'estasoft-handover-passport' );
			} else {
				$message   = ReminderMessage::copy(
					array(
						'site'     => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
						'site_url' => home_url(),
						'date'     => Clock::today(),
						'why'      => $why,
						'page'     => add_query_arg( array( 'page' => SettingsScreen::SLUG, 'tab' => 'privacy' ), admin_url( 'admin.php' ) ),
					)
				);
				$reminders = $this->plugin->reminders();
				$sent      = $reminders->notify( $config['addresses'], $message['subject'], $message['body'], array( $path ) );
				$error     = $sent > 0 ? '' : ( '' !== $reminders->lastMailError() ? $reminders->lastMailError() : __( 'The site could not send the email.', 'estasoft-handover-passport' ) );
			}
		} catch ( \Estasoft\HandoverPassport\Security\SecretFound $e ) {
			$sent  = 0;
			$error = __( 'A value in the Passport looks like a secret, so the sheet was not made. Find it in the findings and remove it.', 'estasoft-handover-passport' );
		} catch ( \Throwable $e ) {
			$sent  = 0;
			$error = $this->clean( $e->getMessage() );
		}
		if ( '' !== $path ) {
			TempFiles::discard( $path );
		}

		$report['sent']  = $sent;
		$report['error'] = $error;
		if ( $sent > 0 ) {
			$report['state'] = 'sent';
			$state           = array_merge( $state, array( 'pending' => false, 'reason' => '', 'retries' => 0, 'failed_at' => '', 'error' => '', 'sent_at' => Clock::sql() ) );
		} else {
			$report['state'] = 'failed';
			$state           = array_merge(
				$state,
				array(
					'pending'   => true,
					'reason'    => 'manual' === $why ? ( $retry ? $state['reason'] : '' ) : $why,
					'retries'   => $retry ? $state['retries'] + 1 : 0,
					'failed_at' => Clock::sql(),
					'error'     => $error,
				)
			);
			// A button press that fails is shown, but is not queued for another try: the person is there to see it.
			if ( 'manual' === $why && ! $retry ) {
				$state['pending'] = false;
			}
		}
		$this->save( $state );
		return $report;
	}

	/**
	 * A warning for the Overview, or an empty string when all is well.
	 */
	public function warning(): string {
		$state = $this->state();
		if ( '' === $state['failed_at'] || ( ! $state['pending'] && '' === $state['error'] ) ) {
			return '';
		}
		$config = $this->config();
		if ( ! $config['enabled'] ) {
			return '';
		}
		$left = max( 0, self::RETRIES - $state['retries'] );
		return sprintf(
			/* translators: 1: date and time of the failure, 2: the mailer's message, 3: sentence about the next try. */
			__( 'The emailed copy of the Emergency sheet did not go out (%1$s). %2$s %3$s', 'estasoft-handover-passport' ),
			Dates::display( $state['failed_at'], true ),
			'' !== $state['error'] ? $state['error'] : __( 'The site could not send the email.', 'estasoft-handover-passport' ),
			$state['pending'] && $left > 0
				? sprintf(
					/* translators: %d: number of tries left. */
					_n( 'Passport will try again tomorrow (%d try left).', 'Passport will try again tomorrow (%d tries left).', $left, 'estasoft-handover-passport' ),
					$left
				)
				: __( 'Passport has stopped trying. Check that the site can send email, then press Send a copy now.', 'estasoft-handover-passport' )
		);
	}

	/**
	 * Save the state.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * A short message with no address or control character in it.
	 *
	 * @param string $message Message.
	 */
	private function clean( string $message ): string {
		$message = (string) preg_replace( '/[^\s@<>]+@[^\s@<>]+/', '[address]', $message );
		return mb_substr( ReminderMessage::line( $message ), 0, 200 );
	}
}
