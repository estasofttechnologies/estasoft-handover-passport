<?php
/**
 * WP-CLI: wp estasoft-passport reminders (CLI-8).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Cli;

use Estasoft\HandoverPassport\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Run the reminders, see what would go out, or check that the site can send email.
 */
final class RemindersCommand {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Send the reminders that are due today, or list them with --dry-run.
	 *
	 * Each reminder is sent once per record, date and lead time, so running this twice is safe. A site that looks like a
	 * copy of another (see the question in the admin area) sends nothing.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List what would be sent now. Nothing is sent and nothing is logged.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-passport reminders run --dry-run
	 *     wp estasoft-passport reminders run
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function run( $args, $assoc_args ): void {
		$dry    = ! empty( $assoc_args['dry-run'] );
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$report = $this->plugin->reminders()->run( $dry );

		if ( 'disabled' === $report['state'] ) {
			\WP_CLI::warning( 'Reminders are switched off in Settings. Nothing was sent.' );
			return;
		}
		if ( 'paused' === $report['state'] ) {
			\WP_CLI::warning( 'This site is running on a different address from the one the Passport was set up on, so reminders are paused until the question in the admin area is answered. Nothing was sent.' );
			return;
		}
		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		$rows = array();
		foreach ( $report['planned'] as $planned ) {
			$rows[] = array(
				'id'         => (int) $planned['id'],
				'record'     => '' === (string) $planned['title'] ? '(Sensitive record)' : (string) $planned['title'],
				'date'       => (string) $planned['due'],
				'days_left'  => (int) $planned['days_left'],
				'lead'       => (int) $planned['lead'],
				'recipients' => (int) $planned['recipients'],
				'attempt'    => (int) $planned['attempt'],
			);
		}
		if ( array() !== $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'record', 'date', 'days_left', 'lead', 'recipients', 'attempt' ) );
		}
		if ( $report['no_recipients'] > 0 ) {
			\WP_CLI::warning( sprintf( '%d reminder(s) have nobody to send to.', (int) $report['no_recipients'] ) );
		}
		if ( $dry ) {
			\WP_CLI::success( sprintf( '%d reminder(s) would be sent now. Nothing was sent.', count( $rows ) ) );
			return;
		}
		if ( $report['failed'] > 0 ) {
			\WP_CLI::warning( sprintf( '%1$d reminder(s) sent, %2$d could not be sent. A failed reminder is tried again the next day, up to 3 times.', (int) $report['sent'], (int) $report['failed'] ) );
			return;
		}
		\WP_CLI::success( sprintf( '%d reminder(s) sent.', (int) $report['sent'] ) );
	}

	/**
	 * Send the weekly summary now, or check it with --dry-run. It must be switched on in Settings.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Count what the summary would hold. Nothing is sent.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-passport reminders digest --dry-run
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function digest( $args, $assoc_args ): void {
		$report = $this->plugin->reminders()->digest( ! empty( $assoc_args['dry-run'] ) );
		switch ( $report['state'] ) {
			case 'disabled':
				\WP_CLI::warning( 'The weekly summary is switched off, or reminders are. Nothing was sent.' );
				return;
			case 'paused':
				\WP_CLI::warning( 'This site looks like a copy, so the summary is paused. Nothing was sent.' );
				return;
			case 'failed':
				\WP_CLI::error( 'The site could not send the summary. Reminders depend on working site email.' );
				return;
		}
		$text = sprintf( '%1$d date(s), %2$d finding(s) needing a fix, %3$d change(s), for %4$d recipient(s).', (int) $report['dates'], (int) $report['needs_fixing'], (int) $report['changes'], (int) $report['recipients'] );
		\WP_CLI::success( 'dry_run' === $report['state'] ? 'The summary would hold ' . $text . ' Nothing was sent.' : 'Summary sent: ' . $text );
	}

	/**
	 * Send a test email to check that the site can send mail.
	 *
	 * ## OPTIONS
	 *
	 * --to=<email>
	 * : The address to send the test to.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-passport reminders test --to=ops@example.com
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function test( $args, $assoc_args ): void {
		$result = $this->plugin->reminders()->sendTest( isset( $assoc_args['to'] ) ? (string) $assoc_args['to'] : '' );
		switch ( $result['reason'] ) {
			case 'sent':
				\WP_CLI::success( 'The test email was handed to the mailer. Check the inbox of that address.' );
				return;
			case 'invalid':
				\WP_CLI::error( 'Give a valid address, for example --to=ops@example.com.' );
				return;
			case 'wait':
				\WP_CLI::error( sprintf( 'A test email was just sent to that address. Try again in %d second(s).', (int) $result['wait'] ) );
				return;
			default:
				\WP_CLI::error( 'The site could not send the test email. Reminders depend on working site email.' );
		}
	}
}
