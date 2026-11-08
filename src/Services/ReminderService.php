<?php
/**
 * Email reminders before dates, the weekly summary and the delivery check (RMD-1 to RMD-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Services;

use Estasoft\HandoverPassport\Admin\Admin;
use Estasoft\HandoverPassport\Admin\FindingLinks;
use Estasoft\HandoverPassport\Admin\FindingsScreen;
use Estasoft\HandoverPassport\Admin\HistoryScreen;
use Estasoft\HandoverPassport\Checks\CheckLimiter;
use Estasoft\HandoverPassport\Data\LinkRepository;
use Estasoft\HandoverPassport\Data\PeopleRepository;
use Estasoft\HandoverPassport\Data\ReminderRepository;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\LeadTimes;
use Estasoft\HandoverPassport\Domain\Sensitivity;
use Estasoft\HandoverPassport\Findings\Level;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Reminders\ReminderMessage;
use Estasoft\HandoverPassport\Reminders\ReminderPlanner;
use Estasoft\HandoverPassport\Reminders\UpcomingDates;
use Estasoft\HandoverPassport\Security\CopyGuard;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Sends each reminder once per record, date and lead time, and keeps a log without addresses.
 */
class ReminderService {

	public const HEALTH_OPTION = 'estasoft_hp_reminder_health';
	public const DIGEST_OPTION = 'estasoft_hp_digest_last';

	/**
	 * How far ahead the weekly summary looks, in days.
	 */
	public const DIGEST_DAYS = 30;

	/**
	 * Most recipients one email may have.
	 */
	public const MAX_RECIPIENTS = 20;

	/**
	 * Why the last mail call failed, set by the wp_mail_failed hook while sending.
	 *
	 * @var string
	 */
	private string $mail_error = '';

	/**
	 * Constructor.
	 *
	 * @param Plugin             $plugin Container.
	 * @param ReminderRepository $log    Sent log.
	 * @param CopyGuard          $guard  Copy guard.
	 */
	public function __construct( private Plugin $plugin, private ReminderRepository $log, private CopyGuard $guard ) {}

	/**
	 * Send the reminders that are due today. Safe to run twice: a second run sends nothing (RMD-4).
	 *
	 * @param bool $dry_run Only report what would go out.
	 * @return array{state: string, planned: array<int, array<string, mixed>>, sent: int, failed: int, no_recipients: int}
	 *         state is "ok", "disabled" or "paused" (the copy guard).
	 */
	public function run( bool $dry_run = false ): array {
		$report = array(
			'state'         => 'ok',
			'planned'       => array(),
			'sent'          => 0,
			'failed'        => 0,
			'no_recipients' => 0,
		);
		if ( ! $this->enabled() ) {
			$report['state'] = 'disabled';
			return $report;
		}
		$this->guard->check();
		if ( $this->guard->paused() ) {
			$report['state'] = 'paused';
			return $report;
		}

		$today = Clock::today();
		$plan  = ReminderPlanner::plan(
			$this->candidates( $today ),
			$today,
			fn( int $id, string $cycle, int $lead ): ?array => $this->log->forCycle( $id, $cycle, $lead )
		);

		foreach ( $plan as $step ) {
			$row = $this->plugin->records()->items()->find( $step['id'] );
			if ( null === $row ) {
				continue;
			}
			$recipients = $this->recipients( $step['id'] );
			if ( array() === $recipients ) {
				++$report['no_recipients'];
				continue;
			}
			$report['planned'][] = array(
				'id'         => $step['id'],
				'title'      => $row['sensitive'] ? '' : (string) $row['title'],
				'lead'       => $step['lead'],
				'days_left'  => $step['days_left'],
				'due'        => (string) $row['due_date'],
				'recipients' => count( $recipients ),
				'attempt'    => $step['attempt'],
			);
			if ( $dry_run ) {
				continue;
			}
			$delivered = $this->deliver( $row, $step['days_left'], $recipients );
			$entry     = array(
				'item_id'         => $step['id'],
				'lead_days'       => $step['lead'],
				'cycle_key'       => $step['cycle'],
				'sent_at'         => Clock::sql(),
				'status'          => $delivered > 0 ? 'sent' : 'failed',
				'attempts'        => $step['attempt'],
				'recipient_count' => $delivered,
			);
			if ( $step['log_id'] > 0 ) {
				$this->log->update( $step['log_id'], $entry );
			} else {
				$this->log->insert( $entry );
			}
			if ( $delivered > 0 ) {
				++$report['sent'];
				do_action( 'estasoft_hp_reminder_sent', $step['id'], $step['lead'] );
			} else {
				++$report['failed'];
			}
		}

		if ( ! $dry_run ) {
			$this->noteRun( $report['sent'], $report['failed'] );
		}
		return $report;
	}

	/**
	 * Send the weekly summary (RMD-3) if it is switched on.
	 *
	 * @param bool $dry_run Only report whether it would go out.
	 * @return array{state: string, recipients: int, dates: int, needs_fixing: int, changes: int}
	 *         state is "sent", "failed", "disabled", "paused" or "dry_run".
	 */
	public function digest( bool $dry_run = false ): array {
		$report = array( 'state' => 'disabled', 'recipients' => 0, 'dates' => 0, 'needs_fixing' => 0, 'changes' => 0 );
		if ( ! $this->enabled() || ! (bool) $this->plugin->settings()->get( 'digest_enabled', false ) ) {
			return $report;
		}
		$this->guard->check();
		if ( $this->guard->paused() ) {
			$report['state'] = 'paused';
			return $report;
		}

		$today = Clock::today();
		$rows  = ( new UpcomingDates( $this->plugin->records() ) )->between( $today, Dates::addDays( $today, self::DIGEST_DAYS ), false );
		$dates = array();
		foreach ( $rows as $row ) {
			$dates[] = array(
				'date'      => Dates::displayDate( $row['date'] ),
				'kind'      => $row['kind_label'],
				'title'     => $row['sensitive'] ? __( 'A record marked Sensitive', 'estasoft-handover-passport' ) : $row['title'],
				'days_left' => Dates::daysBetween( $today, $row['date'] ),
			);
		}
		$last    = (string) get_option( self::DIGEST_OPTION, '' );
		$changes = $this->plugin->changeLog()->repo()->countFor( '' === $last ? array() : array( 'since' => $last ) );
		$needs   = (int) ( $this->plugin->findings()->counts()[ Level::NEEDS ] ?? 0 );

		$recipients = $this->summaryRecipients();
		$report     = array( 'state' => 'dry_run', 'recipients' => count( $recipients ), 'dates' => count( $dates ), 'needs_fixing' => $needs, 'changes' => $changes );
		if ( $dry_run ) {
			return $report;
		}

		$message   = ReminderMessage::digest(
			array(
				'site'         => $this->siteName(),
				'site_url'     => home_url(),
				'dates'        => $dates,
				'needs_fixing' => $needs,
				'changes'      => $changes,
				'since'        => '' === $last ? '' : Dates::display( $last ),
				'overview'     => add_query_arg( 'page', Admin::MENU_SLUG, admin_url( 'admin.php' ) ),
				'findings'     => add_query_arg( array( 'page' => FindingsScreen::SLUG, 'tab' => 'open' ), admin_url( 'admin.php' ) ),
				'history'      => add_query_arg( 'page', HistoryScreen::SLUG, admin_url( 'admin.php' ) ),
			)
		);
		$delivered = $this->mailEach( $recipients, $message['subject'], $message['body'] );
		if ( $delivered > 0 ) {
			update_option( self::DIGEST_OPTION, Clock::sql(), false );
		}
		$report['state'] = $delivered > 0 ? 'sent' : 'failed';
		return $report;
	}

	/**
	 * Send a test email to one address (RMD-5).
	 *
	 * @param string $to Address.
	 * @return array{ok: bool, reason: string, wait: int} reason is "sent", "invalid", "wait" or "failed".
	 */
	public function sendTest( string $to ): array {
		$to = sanitize_email( $to );
		if ( '' === $to || ! is_email( $to ) ) {
			return array( 'ok' => false, 'reason' => 'invalid', 'wait' => 0 );
		}
		if ( ! CheckLimiter::take( 'mail', $to ) ) {
			return array( 'ok' => false, 'reason' => 'wait', 'wait' => CheckLimiter::secondsLeft( 'mail', $to ) );
		}
		$message = ReminderMessage::test( array( 'site' => $this->siteName(), 'site_url' => home_url() ) );
		$ok      = 1 === $this->mailEach( array( $to ), $message['subject'], $message['body'] );

		$health               = $this->health();
		$health['test_at']    = Clock::sql();
		$health['test_ok']    = $ok;
		$health['last_error'] = $ok ? '' : $this->mail_error;
		update_option( self::HEALTH_OPTION, $health, false );
		return array( 'ok' => $ok, 'reason' => $ok ? 'sent' : 'failed', 'wait' => 0 );
	}

	/**
	 * Whether reminders are switched on in Settings.
	 */
	public function enabled(): bool {
		return (bool) $this->plugin->settings()->get( 'reminders_enabled', true );
	}

	/**
	 * What is known about delivery (read by the Overview and by finding HP-RMD-001).
	 *
	 * @return array{failed_days: int, last_failed_day: string, last_run: string, last_sent: string, last_error: string, test_at: string, test_ok: bool|null}
	 */
	public function health(): array {
		$stored = (array) get_option( self::HEALTH_OPTION, array() );
		return array(
			'failed_days'     => (int) ( $stored['failed_days'] ?? 0 ),
			'last_failed_day' => (string) ( $stored['last_failed_day'] ?? '' ),
			'last_run'        => (string) ( $stored['last_run'] ?? '' ),
			'last_sent'       => (string) ( $stored['last_sent'] ?? '' ),
			'last_error'      => (string) ( $stored['last_error'] ?? '' ),
			'test_at'         => (string) ( $stored['test_at'] ?? '' ),
			'test_ok'         => array_key_exists( 'test_ok', $stored ) ? (bool) $stored['test_ok'] : null,
		);
	}

	/**
	 * The lead times from Settings, after the developer filter.
	 *
	 * @return array<int, int>
	 */
	public function leadTimes(): array {
		$days = LeadTimes::clean( $this->plugin->settings()->get( 'reminder_days', LeadTimes::DEFAULT ) );
		return array() === $days ? LeadTimes::DEFAULT : $days;
	}

	/**
	 * Who gets the reminder for one record: the Passport's list, the account holder and the payer (RMD-2).
	 *
	 * @param int $item_id Record ID.
	 * @return array<int, string> Valid, unique addresses.
	 */
	public function recipients( int $item_id ): array {
		$found  = $this->listedAddresses();
		$links  = $this->plugin->records()->links();
		$people = new PeopleRepository();
		foreach ( array( LinkRepository::HOLDER, LinkRepository::PAYER ) as $relation ) {
			foreach ( $links->people( $relation, 'item', $item_id ) as $person_id ) {
				$person = $people->find( $person_id );
				if ( null !== $person && null === $person['deleted_at'] && (int) $person['active'] > 0 ) {
					$found[] = (string) $person['email'];
				}
			}
		}
		$row   = $this->plugin->records()->items()->find( $item_id );
		$found = (array) apply_filters( 'estasoft_hp_reminder_recipients', $found, null === $row ? array() : $this->plugin->records()->hydrate( $row ) );
		$found = $this->cleanAddresses( $found );
		return array() === $found ? $this->fallback() : $found;
	}

	/**
	 * Who gets the weekly summary: the Passport's list, or the fallback.
	 *
	 * @return array<int, string>
	 */
	public function summaryRecipients(): array {
		$found = $this->cleanAddresses( (array) apply_filters( 'estasoft_hp_reminder_recipients', $this->listedAddresses(), array() ) );
		return array() === $found ? $this->fallback() : $found;
	}

	/**
	 * Records with a date, as the planner reads them.
	 *
	 * @param string $today Today, Y-m-d.
	 * @return array<int, array{id: int, due: string, leads: array<int, int>, skip: bool}>
	 */
	private function candidates( string $today ): array {
		$records = array();
		$default = $this->leadTimes();
		$items   = $this->plugin->records();
		foreach ( $items->items()->dueBetween( Dates::addDays( $today, -3 ), Dates::addDays( $today, LeadTimes::MAX_DAY ) ) as $row ) {
			$def = Kinds::get( (string) $row['kind'] );
			if ( null === $def || empty( $def['due'] ) || ! in_array( (string) $row['kind'], Kinds::registerKinds(), true ) ) {
				continue;
			}
			$data  = is_array( $row['data'] ) ? $row['data'] : array();
			$leads = LeadTimes::forRecord( (string) ( $data['reminder_days'] ?? '' ), $default );
			if ( has_filter( 'estasoft_hp_reminder_days' ) ) {
				$leads = LeadTimes::clean( apply_filters( 'estasoft_hp_reminder_days', $leads, Sensitivity::forViewer( $items->hydrate( $row ), false ) ) );
			}
			$records[] = array(
				'id'    => (int) $row['id'],
				'due'   => (string) $row['due_date'],
				'leads' => $leads,
				'skip'  => 'yes' === ( $data['cancelled'] ?? '' ),
			);
		}
		return $records;
	}

	/**
	 * Send one record's email to each recipient. Each person gets their own message, so nobody sees the others' addresses.
	 *
	 * @param array<string, mixed> $row        Stored record.
	 * @param int                  $days_left  Days to the date.
	 * @param array<int, string>   $recipients Addresses.
	 * @return int How many messages were accepted for delivery.
	 */
	private function deliver( array $row, int $days_left, array $recipients ): int {
		$kind    = Kinds::get( (string) $row['kind'] );
		$message = ReminderMessage::due(
			array(
				'site'      => $this->siteName(),
				'site_url'  => home_url(),
				'kind'      => (string) ( $kind['label'] ?? '' ),
				'title'     => (string) $row['title'],
				'sensitive' => (bool) $row['sensitive'],
				'date'      => Dates::displayDate( (string) $row['due_date'] ),
				'days_left' => $days_left,
				'link'      => $this->recordLink( (string) $row['kind'], (int) $row['id'] ),
			)
		);
		return $this->mailEach( $recipients, $message['subject'], $message['body'] );
	}

	/**
	 * Send the same plain-text message to each address on its own.
	 *
	 * @param array<int, string> $recipients Addresses.
	 * @param string             $subject    Subject.
	 * @param string             $body       Body.
	 * @return int Messages accepted for delivery.
	 */
	private function mailEach( array $recipients, string $subject, string $body ): int {
		$this->mail_error = '';
		$capture          = function ( $error ): void {
			if ( is_wp_error( $error ) && '' === $this->mail_error ) {
				$this->mail_error = self::scrub( $error->get_error_message() );
			}
		};
		add_action( 'wp_mail_failed', $capture );
		$sent = 0;
		foreach ( $recipients as $address ) {
			if ( wp_mail( $address, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) ) ) {
				++$sent;
			}
		}
		remove_action( 'wp_mail_failed', $capture );
		return $sent;
	}

	/**
	 * Record how the run went: a day with failures and no success counts towards HP-RMD-001; one success clears it.
	 *
	 * @param int $sent   Reminders delivered.
	 * @param int $failed Reminders that could not be delivered.
	 */
	private function noteRun( int $sent, int $failed ): void {
		$health             = $this->health();
		$health['last_run'] = Clock::sql();
		if ( $sent > 0 ) {
			$health['failed_days']     = 0;
			$health['last_failed_day'] = '';
			$health['last_sent']       = Clock::sql();
			$health['last_error']      = '';
		} elseif ( $failed > 0 ) {
			$today = Clock::today();
			if ( $health['last_failed_day'] !== $today ) {
				++$health['failed_days'];
				$health['last_failed_day'] = $today;
			}
			$health['last_error'] = $this->mail_error;
		}
		update_option( self::HEALTH_OPTION, $health, false );
	}

	/**
	 * Addresses on the Passport's reminder list.
	 *
	 * @return array<int, string>
	 */
	private function listedAddresses(): array {
		return array_values( array_map( 'strval', array_filter( (array) $this->plugin->settings()->get( 'reminder_emails', array() ), 'is_scalar' ) ) );
	}

	/**
	 * Valid, unique addresses, at most MAX_RECIPIENTS.
	 *
	 * @param array<mixed> $addresses Candidates.
	 * @return array<int, string>
	 */
	private function cleanAddresses( array $addresses ): array {
		$out = array();
		foreach ( $addresses as $address ) {
			$clean = is_scalar( $address ) ? sanitize_email( (string) $address ) : '';
			if ( '' !== $clean && is_email( $clean ) ) {
				$out[ strtolower( $clean ) ] = $clean;
			}
		}
		return array_slice( array_values( $out ), 0, self::MAX_RECIPIENTS );
	}

	/**
	 * With nobody listed, reminders go to the site's administration address, so a new install is not silent.
	 *
	 * @return array<int, string>
	 */
	private function fallback(): array {
		return $this->cleanAddresses( array( get_option( 'admin_email', '' ) ) );
	}

	/**
	 * The record's address in the admin area.
	 *
	 * @param string $kind Kind key.
	 * @param int    $id   Record ID.
	 */
	private function recordLink( string $kind, int $id ): string {
		$args = ( new FindingLinks( $this->plugin ) )->tabFor( $kind ) + array( 'view' => 'edit', 'kind' => $kind, 'id' => $id );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * The site name as plain text.
	 */
	private function siteName(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Keep an error message short and free of addresses.
	 *
	 * @param string $message Message from the mailer.
	 */
	private static function scrub( string $message ): string {
		$message = (string) preg_replace( '/[^\s@<>]+@[^\s@<>]+/', '[address]', $message );
		return mb_substr( ReminderMessage::line( $message ), 0, 200 );
	}
}
