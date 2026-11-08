<?php
/**
 * Small output helpers shared by the screens. Every value is escaped where it is printed (SEC-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Markup helpers that follow the look of core screens (ADM-3).
 */
final class View {

	/**
	 * Print the opening of a screen: wrapper, title and an optional action link.
	 *
	 * @param string                                 $title   Screen title.
	 * @param array{label: string, url: string}|null $action  Optional primary link.
	 */
	public static function open( string $title, ?array $action = null ): void {
		echo '<div class="wrap estasoft-hp"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
		if ( null !== $action ) {
			echo ' <a class="page-title-action" href="' . esc_url( $action['url'] ) . '">' . esc_html( $action['label'] ) . '</a>';
		}
		echo '<hr class="wp-header-end">';
		self::flash();
	}

	/**
	 * Print the end of a screen.
	 */
	public static function close(): void {
		echo '</div>';
	}

	/**
	 * Show the result of the last action once, announced to screen readers (ADM-4).
	 */
	public static function flash(): void {
		self::flashSaid();
		$key = Request::key( 'estasoft_hp_notice' ); // The value only picks a fixed message.
		$map = self::messages();
		if ( '' === $key || ! isset( $map[ $key ] ) ) {
			return;
		}
		$class = in_array( $key, array( 'scan_failed', 'denied', 'finding_reason', 'finding_secret', 'finding_gone', 'check_failed', 'check_wait', 'scan_wait', 'check_refused', 'url_failed', 'urls_failed', 'dns_failed', 'dns_invalid', 'ssl_failed', 'ssl_refused', 'test_invalid', 'runbook_invalid', 'renew_invalid', 'mail_failed', 'mail_invalid', 'mail_wait', 'copy_failed', 'copy_wait', 'copy_nobody', 'copy_paused', 'checklist_invalid', 'snapshot_invalid', 'snapshot_mismatch', 'snapshot_confirm', 'snapshot_gone', 'ack_invalid', 'confirm_expired', 'export_refused', 'pack_copy_invalid', 'notes_invalid', 'changes_csv_toolarge', 'setup_invalid' ), true ) ? 'notice-error' : 'notice-success';
		$text  = $map[ $key ];
		if ( '_wait' === substr( $key, -5 ) ) {
			$seconds = min( 60, max( 1, Request::int( 'estasoft_hp_wait' ) ) );
			/* translators: %d: seconds until the action may be repeated. */
			$text .= ' ' . sprintf( _n( '%d second left.', '%d seconds left.', $seconds, 'estasoft-handover-passport' ), $seconds );
		}
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible" role="' . ( str_contains( $class, 'notice-error' ) ? 'alert' : 'status' ) . '"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Keep a message, written by this plugin, for the next screen the person opens. Used where the words depend on what
	 * happened, such as why a PDF could not be made. It is shown once and only to the person it was kept for.
	 *
	 * @param string $text  Message.
	 * @param bool   $error True for an error.
	 */
	public static function say( string $text, bool $error = true ): void {
		set_transient( 'estasoft_hp_said_' . get_current_user_id(), array( 'text' => $text, 'error' => $error ), 300 );
	}

	/**
	 * Show the message kept by say(), once.
	 */
	private static function flashSaid(): void {
		$key  = 'estasoft_hp_said_' . get_current_user_id();
		$said = get_transient( $key );
		if ( ! is_array( $said ) || '' === (string) ( $said['text'] ?? '' ) ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice ' . ( ! empty( $said['error'] ) ? 'notice-error' : 'notice-success' ) . ' is-dismissible" role="' . ( ! empty( $said['error'] ) ? 'alert' : 'status' ) . '"><p>' . esc_html( (string) $said['text'] ) . '</p></div>';
	}

	/**
	 * Fixed messages, so a URL cannot show arbitrary text.
	 *
	 * @return array<string, string>
	 */
	private static function messages(): array {
		return array(
			'scan_done'            => __( 'Scan finished.', 'estasoft-handover-passport' ),
			'changes_csv_toolarge' => __( 'More entries match than one file can hold. Choose a kind of change or a snapshot to narrow the list, then download again.', 'estasoft-handover-passport' ),
			'renewed'              => __( 'Renewed. The next date is saved and the reminders start again for it.', 'estasoft-handover-passport' ),
			'renew_invalid'        => __( 'The date was not accepted. Use a real date in the form YYYY-MM-DD that is later than the current one.', 'estasoft-handover-passport' ),
			'mail_sent'            => __( 'The test email was sent. Check the inbox of that address.', 'estasoft-handover-passport' ),
			'mail_failed'          => __( 'The site could not send the test email. Reminders depend on working site email.', 'estasoft-handover-passport' ),
			'mail_invalid'         => __( 'Enter a valid email address for the test.', 'estasoft-handover-passport' ),
			'mail_wait'            => __( 'A test email was just sent to that address. Wait a little and try again.', 'estasoft-handover-passport' ),
			'copy_sent'            => __( 'The Emergency sheet was emailed. Check the inbox of each address listed.', 'estasoft-handover-passport' ),
			'copy_failed'          => __( 'The site could not send the Emergency sheet. Copies depend on working site email.', 'estasoft-handover-passport' ),
			'copy_wait'            => __( 'A copy was just sent. Wait a little and try again.', 'estasoft-handover-passport' ),
			'copy_nobody'          => __( 'Nothing was sent. Switch the emailed copy on and list at least one address first.', 'estasoft-handover-passport' ),
			'copy_paused'          => __( 'Nothing was sent: this address is waiting for the question about a copy of the site to be answered.', 'estasoft-handover-passport' ),
			'copy_moved'           => __( 'Saved. Passport now treats this address as the site\'s address, and reminders carry on.', 'estasoft-handover-passport' ),
			'copy_marked'          => __( 'Saved. This site is marked as a copy, so reminders, emailed copies and scheduled checks stay off here.', 'estasoft-handover-passport' ),
			'scan_busy'            => __( 'A scan is already running. Try again in a minute.', 'estasoft-handover-passport' ),
			'scan_wait'            => __( 'A scan was started a moment ago, so another one now would show nothing new.', 'estasoft-handover-passport' ),
			'scan_failed'          => __( 'The scan could not finish. Nothing was changed.', 'estasoft-handover-passport' ),
			'saved'                => __( 'Saved.', 'estasoft-handover-passport' ),
			'deleted'              => __( 'Deleted. You can undo this for 30 days.', 'estasoft-handover-passport' ),
			'restored'             => __( 'Restored.', 'estasoft-handover-passport' ),
			'verified'             => __( 'Marked as verified today.', 'estasoft-handover-passport' ),
			'denied'               => __( 'You do not have permission to change that record.', 'estasoft-handover-passport' ),
			'note_added'           => __( 'Note added to the history.', 'estasoft-handover-passport' ),
			'finding_snoozed'      => __( 'Finding snoozed.', 'estasoft-handover-passport' ),
			'finding_accepted'     => __( 'Finding accepted. The reason will appear in exports.', 'estasoft-handover-passport' ),
			'finding_reopened'     => __( 'Finding reopened.', 'estasoft-handover-passport' ),
			'finding_reason'       => __( 'Say why before accepting a finding or switching off a rule.', 'estasoft-handover-passport' ),
			'finding_secret'       => __( 'That text looks like a password, key or token. Passport never stores those, so nothing was saved.', 'estasoft-handover-passport' ),
			'finding_gone'         => __( 'That finding no longer exists.', 'estasoft-handover-passport' ),
			'rules_checked'        => __( 'Rules checked again.', 'estasoft-handover-passport' ),
			'rule_off'             => __( 'Rule switched off.', 'estasoft-handover-passport' ),
			'rule_on'              => __( 'Rule switched on.', 'estasoft-handover-passport' ),
			'check_failed'         => __( 'The check could not run on this server.', 'estasoft-handover-passport' ),
			'check_wait'           => __( 'That check ran a moment ago. Try again shortly.', 'estasoft-handover-passport' ),
			'check_refused'        => __( 'That address is not on this site, so it cannot be checked.', 'estasoft-handover-passport' ),
			'check_done'           => __( 'Check finished.', 'estasoft-handover-passport' ),
			'confirmed'            => __( 'Backup confirmed as of today.', 'estasoft-handover-passport' ),
			'reviewed'             => __( 'Marked as reviewed today.', 'estasoft-handover-passport' ),
			'url_ok'               => __( 'The address answered as expected.', 'estasoft-handover-passport' ),
			'url_failed'           => __( 'The address did not answer as expected. The result is in the list.', 'estasoft-handover-passport' ),
			'urls_done'            => __( 'Every address answered as expected.', 'estasoft-handover-passport' ),
			'urls_failed'          => __( 'At least one address did not answer as expected. The results are in the list.', 'estasoft-handover-passport' ),
			'urls_none'            => __( 'There are no addresses to check yet.', 'estasoft-handover-passport' ),
			'dns_done'             => __( 'DNS read from this server and stored.', 'estasoft-handover-passport' ),
			'dns_failed'           => __( 'This server could not read any DNS records for that domain. The last snapshot was kept.', 'estasoft-handover-passport' ),
			'dns_invalid'          => __( 'That does not look like a domain name, so it was not looked up.', 'estasoft-handover-passport' ),
			'ssl_done'             => __( 'Certificate read from this server and stored.', 'estasoft-handover-passport' ),
			'ssl_failed'           => __( 'The certificate could not be read from this server. The last reading was kept.', 'estasoft-handover-passport' ),
			'ssl_refused'          => __( 'Only this site\'s own host can be checked for a certificate.', 'estasoft-handover-passport' ),
			'test_logged'          => __( 'Restore test logged.', 'estasoft-handover-passport' ),
			'test_removed'         => __( 'Restore test removed.', 'estasoft-handover-passport' ),
			'test_invalid'         => __( 'The restore test was not saved. Fix the problem shown in the form.', 'estasoft-handover-passport' ),
			'runbook_saved'        => __( 'Runbook saved.', 'estasoft-handover-passport' ),
			'runbook_created'      => __( 'Draft created. Check every step against how this site is built.', 'estasoft-handover-passport' ),
			'runbook_invalid'      => __( 'The runbook was not saved. Fix the problem shown in the form.', 'estasoft-handover-passport' ),
			'rehearsed'            => __( 'Marked as rehearsed today.', 'estasoft-handover-passport' ),
			'drill_started'        => __( 'The drill has started. Press Done as you finish each step.', 'estasoft-handover-passport' ),
			'checklist_added'      => __( 'Item added to the checklist.', 'estasoft-handover-passport' ),
			'checklist_updated'    => __( 'Checklist updated.', 'estasoft-handover-passport' ),
			'checklist_deleted'    => __( 'Item removed from the checklist.', 'estasoft-handover-passport' ),
			'checklist_invalid'    => __( 'The checklist was not changed. Fix the problem shown in the form.', 'estasoft-handover-passport' ),
			'snapshot_made'        => __( 'Snapshot made. It cannot be changed any more.', 'estasoft-handover-passport' ),
			'snapshot_invalid'     => __( 'The snapshot was not made. Fix the problem shown in the form.', 'estasoft-handover-passport' ),
			'snapshot_match'       => __( 'The fingerprint matches: the stored content is the same as when the snapshot was made.', 'estasoft-handover-passport' ),
			'snapshot_mismatch'    => __( 'The fingerprint does not match: the stored content is not what was saved. Treat this snapshot as unreliable.', 'estasoft-handover-passport' ),
			'snapshot_acked'       => __( 'Acknowledgement recorded. The Passport is now marked as handed over.', 'estasoft-handover-passport' ),
			'ack_invalid'          => __( 'The acknowledgement was not saved. Fix the problem shown in the form.', 'estasoft-handover-passport' ),
			'snapshot_deleted'     => __( 'Snapshot deleted. Its name, fingerprint and date are kept in the history.', 'estasoft-handover-passport' ),
			'snapshot_confirm'     => __( 'Tick the box to confirm that you want to delete the snapshot for good.', 'estasoft-handover-passport' ),
			'snapshot_gone'        => __( 'That snapshot does not exist.', 'estasoft-handover-passport' ),
			'confirm_expired'      => __( 'That request has expired. Start again.', 'estasoft-handover-passport' ),
			'confirm_cancelled'    => __( 'Cancelled. Nothing was made.', 'estasoft-handover-passport' ),
			'export_refused'       => __( 'You may not make that pack.', 'estasoft-handover-passport' ),
			'pack_copy_made'       => __( 'Your own pack is saved.', 'estasoft-handover-passport' ),
			'pack_copy_deleted'    => __( 'Your own pack is deleted. Snapshots made from it are unchanged.', 'estasoft-handover-passport' ),
			'pack_copy_invalid'    => __( 'The pack was not saved. Fix the problem shown in the form.', 'estasoft-handover-passport' ),
			'notes_saved'          => __( 'Notes saved.', 'estasoft-handover-passport' ),
			'notes_invalid'        => __( 'The notes were not saved. Fix the problem shown in the form.', 'estasoft-handover-passport' ),
			'status_active'        => __( 'The Passport is marked Active again.', 'estasoft-handover-passport' ),
			'setup_saved'          => __( 'Details saved.', 'estasoft-handover-passport' ),
			'setup_invalid'        => __( 'The details were not saved. Fix the problem shown in the form.', 'estasoft-handover-passport' ),
			'setup_done'           => __( 'Step marked as done.', 'estasoft-handover-passport' ),
			'setup_skipped'        => __( 'Step skipped. It stays on the Overview as something to do.', 'estasoft-handover-passport' ),
			'setup_reopened'       => __( 'Step put back on the list.', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Print a status in words, with a class for styling; colour is never the only signal (ADM-4).
	 *
	 * @param string $state Slug used for the class.
	 * @param string $label Words shown.
	 */
	public static function badge( string $state, string $label ): string {
		return '<span class="estasoft-hp-badge estasoft-hp-badge--' . esc_attr( sanitize_html_class( $state ) ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Print a definition list of label and value pairs. Values are plain text.
	 *
	 * @param array<string, string> $pairs Label => value.
	 */
	public static function facts( array $pairs ): void {
		echo '<dl class="estasoft-hp-facts">';
		foreach ( $pairs as $label => $value ) {
			echo '<div><dt>' . esc_html( (string) $label ) . '</dt><dd>' . ( '' === (string) $value ? '<span class="estasoft-hp-muted">' . esc_html__( 'Not available on this server', 'estasoft-handover-passport' ) . '</span>' : esc_html( (string) $value ) ) . '</dd></div>';
		}
		echo '</dl>';
	}

	/**
	 * Yes or No in words.
	 *
	 * @param bool $value Value.
	 */
	public static function yesNo( bool $value ): string {
		return $value ? __( 'Yes', 'estasoft-handover-passport' ) : __( 'No', 'estasoft-handover-passport' );
	}

	/**
	 * Bytes in a short human form.
	 *
	 * @param int|null $bytes Size.
	 */
	public static function size( ?int $bytes ): string {
		return null === $bytes ? '' : size_format( $bytes, 1 );
	}
}
