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
	 * @param string                    $title   Screen title.
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
		$key = Request::key( 'estasoft_hp_notice' ); // The value only picks a fixed message.
		$map = self::messages();
		if ( '' === $key || ! isset( $map[ $key ] ) ) {
			return;
		}
		$class = in_array( $key, array( 'scan_failed', 'denied', 'finding_reason', 'finding_secret', 'finding_gone', 'check_failed', 'check_wait', 'check_refused', 'url_failed', 'urls_failed', 'dns_failed', 'dns_invalid', 'ssl_failed', 'ssl_refused', 'test_invalid', 'runbook_invalid' ), true ) ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible" role="status"><p>' . esc_html( $map[ $key ] ) . '</p></div>';
	}

	/**
	 * Fixed messages, so a URL cannot show arbitrary text.
	 *
	 * @return array<string, string>
	 */
	private static function messages(): array {
		return array(
			'scan_done'   => __( 'Scan finished.', 'estasoft-handover-passport' ),
			'scan_busy'   => __( 'A scan is already running. Try again in a minute.', 'estasoft-handover-passport' ),
			'scan_failed' => __( 'The scan could not finish. Nothing was changed.', 'estasoft-handover-passport' ),
			'saved'       => __( 'Saved.', 'estasoft-handover-passport' ),
			'deleted'     => __( 'Deleted. You can undo this for 30 days.', 'estasoft-handover-passport' ),
			'restored'    => __( 'Restored.', 'estasoft-handover-passport' ),
			'verified'    => __( 'Marked as verified today.', 'estasoft-handover-passport' ),
			'denied'      => __( 'You do not have permission to change that record.', 'estasoft-handover-passport' ),
			'note_added'  => __( 'Note added to the history.', 'estasoft-handover-passport' ),
			'finding_snoozed'  => __( 'Finding snoozed.', 'estasoft-handover-passport' ),
			'finding_accepted' => __( 'Finding accepted. The reason will appear in exports.', 'estasoft-handover-passport' ),
			'finding_reopened' => __( 'Finding reopened.', 'estasoft-handover-passport' ),
			'finding_reason'   => __( 'Say why before accepting a finding or switching off a rule.', 'estasoft-handover-passport' ),
			'finding_secret'   => __( 'That text looks like a password, key or token. Passport never stores those, so nothing was saved.', 'estasoft-handover-passport' ),
			'finding_gone'     => __( 'That finding no longer exists.', 'estasoft-handover-passport' ),
			'rules_checked'    => __( 'Rules checked again.', 'estasoft-handover-passport' ),
			'rule_off'         => __( 'Rule switched off.', 'estasoft-handover-passport' ),
			'rule_on'          => __( 'Rule switched on.', 'estasoft-handover-passport' ),
			'check_failed'     => __( 'The check could not run on this server.', 'estasoft-handover-passport' ),
			'check_wait'       => __( 'That check ran a moment ago. Try again in a minute.', 'estasoft-handover-passport' ),
			'check_refused'    => __( 'That address is not on this site, so it cannot be checked.', 'estasoft-handover-passport' ),
			'check_done'       => __( 'Check finished.', 'estasoft-handover-passport' ),
			'confirmed'        => __( 'Backup confirmed as of today.', 'estasoft-handover-passport' ),
			'reviewed'         => __( 'Marked as reviewed today.', 'estasoft-handover-passport' ),
			'url_ok'           => __( 'The address answered as expected.', 'estasoft-handover-passport' ),
			'url_failed'       => __( 'The address did not answer as expected. The result is in the list.', 'estasoft-handover-passport' ),
			'urls_done'        => __( 'Every address answered as expected.', 'estasoft-handover-passport' ),
			'urls_failed'      => __( 'At least one address did not answer as expected. The results are in the list.', 'estasoft-handover-passport' ),
			'urls_none'        => __( 'There are no addresses to check yet.', 'estasoft-handover-passport' ),
			'dns_done'         => __( 'DNS read from this server and stored.', 'estasoft-handover-passport' ),
			'dns_failed'       => __( 'This server could not read any DNS records for that domain. The last snapshot was kept.', 'estasoft-handover-passport' ),
			'dns_invalid'      => __( 'That does not look like a domain name, so it was not looked up.', 'estasoft-handover-passport' ),
			'ssl_done'         => __( 'Certificate read from this server and stored.', 'estasoft-handover-passport' ),
			'ssl_failed'       => __( 'The certificate could not be read from this server. The last reading was kept.', 'estasoft-handover-passport' ),
			'ssl_refused'      => __( 'Only this site\'s own host can be checked for a certificate.', 'estasoft-handover-passport' ),
			'test_logged'      => __( 'Restore test logged.', 'estasoft-handover-passport' ),
			'test_removed'     => __( 'Restore test removed.', 'estasoft-handover-passport' ),
			'test_invalid'     => __( 'The restore test was not saved. Fix the problem shown in the form.', 'estasoft-handover-passport' ),
			'runbook_saved'    => __( 'Runbook saved.', 'estasoft-handover-passport' ),
			'runbook_created'  => __( 'Draft created. Check every step against how this site is built.', 'estasoft-handover-passport' ),
			'runbook_invalid'  => __( 'The runbook was not saved. Fix the problem shown in the form.', 'estasoft-handover-passport' ),
			'rehearsed'        => __( 'Marked as rehearsed today.', 'estasoft-handover-passport' ),
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
