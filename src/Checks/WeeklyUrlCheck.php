<?php
/**
 * The optional weekly look at the critical addresses (URL-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Checks;

use Estasoft\HandoverPassport\Admin\SafetyScreen;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Reminders\ReminderMessage;
use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Off by default. When it is on, the server asks each address in the Critical URLs list once a week and sends one email
 * to the reminder recipients if any of them did not answer as expected. It is not an uptime monitor: it sees the site
 * once a week, from this server. Addresses on other hosts are refused by the same guard as the button. A record marked
 * Sensitive is checked like any other but is never named in the email.
 */
final class WeeklyUrlCheck {

	public const OPTION = 'estasoft_hp_url_weekly';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Whether the weekly check is switched on in Settings.
	 */
	public function enabled(): bool {
		return (bool) $this->plugin->settings()->get( 'url_check_weekly', false );
	}

	/**
	 * What the last run did.
	 *
	 * @return array{at: string, state: string, checked: int, failed: int}
	 */
	public function last(): array {
		$stored = (array) get_option( self::OPTION, array() );
		return array(
			'at'      => (string) ( $stored['at'] ?? '' ),
			'state'   => (string) ( $stored['state'] ?? '' ),
			'checked' => (int) ( $stored['checked'] ?? 0 ),
			'failed'  => (int) ( $stored['failed'] ?? 0 ),
		);
	}

	/**
	 * Check every address and mail the ones that failed.
	 *
	 * @param bool $dry_run Check, but send nothing.
	 * @return array{state: string, checked: int, failed: int, recipients: int}
	 *         state is "disabled", "paused" (the copy guard), "none" (no addresses), "ok", "sent", "mail_failed" or "dry_run".
	 */
	public function run( bool $dry_run = false ): array {
		$report = array( 'state' => 'disabled', 'checked' => 0, 'failed' => 0, 'recipients' => 0 );
		if ( ! $this->enabled() ) {
			return $report;
		}
		$guard = $this->plugin->copyGuard();
		$guard->check();
		if ( $guard->paused() ) {
			$report['state'] = 'paused';
			return $report;
		}

		$items = $this->plugin->records()->items();
		$rows  = $items->query( array( 'kind' => Kinds::URL, 'deleted_at' => null ), 'id', 'ASC' );
		if ( array() === $rows ) {
			$report['state'] = 'none';
			return $report;
		}

		$failed = array();
		$hidden = 0;
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			$this->plugin->checks()->url( $id );
			$fresh = $items->find( $id );
			$data  = null === $fresh || ! is_array( $fresh['data'] ) ? array() : $fresh['data'];
			$check = is_array( $data['last_check'] ?? null ) ? $data['last_check'] : array();
			if ( array() === $check || isset( $check['refused'] ) ) {
				continue;
			}
			++$report['checked'];
			if ( ! empty( $check['ok'] ) ) {
				continue;
			}
			++$report['failed'];
			if ( ! empty( $fresh['sensitive'] ) ) {
				++$hidden;
				continue;
			}
			$failed[] = array(
				'name'     => (string) $fresh['title'],
				'address'  => (string) ( $data['address'] ?? '' ),
				'expected' => (int) ( $data['expected_status'] ?? 200 ),
				'status'   => (int) ( $check['status'] ?? 0 ),
				'reason'   => (string) ( $check['reason'] ?? '' ),
			);
		}

		$report['state'] = 'ok';
		if ( $report['failed'] > 0 ) {
			$recipients           = $this->plugin->reminders()->summaryRecipients();
			$report['recipients'] = count( $recipients );
			$report['state']      = 'dry_run';
			if ( ! $dry_run ) {
				$message         = ReminderMessage::urls(
					array(
						'site'     => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
						'site_url' => home_url(),
						'failed'   => $failed,
						'hidden'   => $hidden,
						'checked'  => $report['checked'],
						'page'     => add_query_arg( array( 'page' => SafetyScreen::SLUG, 'tab' => 'urls' ), admin_url( 'admin.php' ) ),
					)
				);
				$sent            = $this->plugin->reminders()->notify( $recipients, $message['subject'], $message['body'] );
				$report['state'] = $sent > 0 ? 'sent' : 'mail_failed';
			}
		}
		if ( ! $dry_run ) {
			update_option(
				self::OPTION,
				array(
					'at'      => Clock::sql(),
					'state'   => $report['state'],
					'checked' => $report['checked'],
					'failed'  => $report['failed'],
				),
				false
			);
		}
		return $report;
	}
}
