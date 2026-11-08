<?php
/**
 * WP-CLI: wp estasoft-passport status (CLI-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Cli;

use Estasoft\HandoverPassport\Findings\Level;
use Estasoft\HandoverPassport\Passport\StatusSummary;
use Estasoft\HandoverPassport\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * A short summary of the Passport that a script can act on.
 */
final class StatusCommand {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Print how the Passport stands: open findings, readiness, how current the records are and the next dates.
	 *
	 * Only counts and dates are printed, never the name of a record, so the output is safe to keep in a build log. Sensitive
	 * records are not counted unless --user names someone who may see them.
	 * With --fail-on the command exits with status 1 when findings at that level or above are open.
	 *
	 * ## OPTIONS
	 *
	 * [--fail-on=<level>]
	 * : Exit with status 1 when findings at this level or above are open.
	 * ---
	 * options:
	 *   - needs-fixing
	 *   - worth-fixing
	 * ---
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
	 *     wp estasoft-passport status
	 *     wp estasoft-passport status --fail-on=needs-fixing
	 *     wp estasoft-passport status --format=json
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function __invoke( $args, $assoc_args ): void {
		$fail_on = isset( $assoc_args['fail-on'] ) ? (string) $assoc_args['fail-on'] : '';
		if ( '' !== $fail_on && ! in_array( $fail_on, array( 'needs-fixing', 'worth-fixing' ), true ) ) {
			\WP_CLI::error( 'Use --fail-on=needs-fixing or --fail-on=worth-fixing.' );
		}
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$status = ( new StatusSummary( $this->plugin ) )->counts( Access::seesSensitive() );
		unset( $status['readiness']['rules'], $status['records']['by_kind'] );

		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		} else {
			$this->print( $status );
		}

		$failing = (int) $status['findings'][ Level::NEEDS ];
		if ( 'worth-fixing' === $fail_on ) {
			$failing += (int) $status['findings'][ Level::WORTH ];
		}
		if ( '' !== $fail_on && $failing > 0 ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Print the summary as a table.
	 *
	 * @param array<string, mixed> $status Summary.
	 */
	private function print( array $status ): void {
		$rows = array();
		foreach ( Level::all() as $level ) {
			$rows[] = array( 'item' => 'Open findings, ' . strtolower( Level::label( $level ) ), 'value' => (string) $status['findings'][ $level ] );
		}
		$rows[] = array( 'item' => 'Ready for a handover', 'value' => sprintf( '%1$d of %2$d', (int) $status['readiness']['ready'], (int) $status['readiness']['total'] ) );
		$rows[] = array( 'item' => 'Records', 'value' => (string) $status['records']['total'] );
		$rows[] = array( 'item' => 'Records verified', 'value' => (string) $status['records']['verified'] );
		$rows[] = array( 'item' => 'Records due for a check', 'value' => (string) $status['records']['due'] );
		$rows[] = array( 'item' => 'Records stale', 'value' => (string) $status['records']['stale'] );
		$rows[] = array( 'item' => 'Dates in the next 30 days', 'value' => (string) $status['dates_next_30_days'] );
		$rows[] = array( 'item' => 'Next date', 'value' => '' === $status['next_date'] ? 'none' : (string) $status['next_date'] );
		$rows[] = array( 'item' => 'Last scan', 'value' => null === $status['last_scan'] ? 'never' : (string) $status['last_scan']['finished_at'] . ' UTC (' . (string) $status['last_scan']['result'] . ')' );
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'item', 'value' ) );
	}
}
