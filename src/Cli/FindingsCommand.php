<?php
/**
 * WP-CLI: wp estasoft-passport findings (CLI-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Cli;

use Estasoft\HandoverPassport\Findings\Level;
use Estasoft\HandoverPassport\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * List findings, hide one for a while, or accept one with a reason.
 */
final class FindingsCommand {

	private const STATES = array( 'open', 'snoozed', 'accepted', 'resolved', 'all' );

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * List findings, most urgent first.
	 *
	 * A finding about a Sensitive record is shown without its details unless --user names someone who may see Sensitive records.
	 *
	 * ## OPTIONS
	 *
	 * [--severity=<level>]
	 * : Only findings at this level.
	 * ---
	 * options:
	 *   - needs-fixing
	 *   - worth-fixing
	 *   - info
	 * ---
	 *
	 * [--state=<state>]
	 * : Only findings in this state.
	 * ---
	 * default: open
	 * options:
	 *   - open
	 *   - snoozed
	 *   - accepted
	 *   - resolved
	 *   - all
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-passport findings list
	 *     wp estasoft-passport findings list --severity=needs-fixing --format=json
	 *
	 * @subcommand list
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function list_( $args, $assoc_args ): void {
		$state = isset( $assoc_args['state'] ) ? (string) $assoc_args['state'] : 'open';
		if ( ! in_array( $state, self::STATES, true ) ) {
			\WP_CLI::error( 'Use --state=open, snoozed, accepted, resolved or all.' );
		}
		$filter = array( 'state' => 'all' === $state ? '' : $state );
		if ( ! empty( $assoc_args['severity'] ) ) {
			$level = $this->level( (string) $assoc_args['severity'] );
			if ( null === $level ) {
				\WP_CLI::error( 'Use --severity=needs-fixing, worth-fixing or info.' );
			}
			$filter['severity'] = $level;
		}

		$rows = array();
		foreach ( $this->plugin->findings()->list( $filter ) as $finding ) {
			$rows[] = array(
				'id'         => (int) $finding['id'],
				'severity'   => Level::token( (string) $finding['severity'] ),
				'state'      => (string) $finding['state'],
				'rule'       => (string) $finding['rule_id'],
				'message'    => (string) $finding['message'],
				'first_seen' => (string) $finding['first_seen'],
			);
		}
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'severity', 'state', 'rule', 'message', 'first_seen' ) );
	}

	/**
	 * Hide a finding for 7, 30 or 90 days. It comes back when the time is up if the condition is still there.
	 *
	 * Changing a finding needs --user, so that the change is recorded under a name.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The finding number from the list.
	 *
	 * [--days=<days>]
	 * : How long to hide it.
	 * ---
	 * default: 7
	 * options:
	 *   - 7
	 *   - 30
	 *   - 90
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-passport findings snooze 12 --days=30 --user=admin
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function snooze( $args, $assoc_args ): void {
		Access::requireEditor();
		$id   = $this->id( $args );
		$days = isset( $assoc_args['days'] ) ? (int) $assoc_args['days'] : 7;
		Access::attempt( fn() => $this->plugin->findings()->snooze( $id, $days ) );
		\WP_CLI::success( sprintf( 'Finding %1$d is hidden for %2$d days.', $id, $days ) );
	}

	/**
	 * Accept a finding with a reason. It stays accepted until the condition clears, and exports show the reason.
	 *
	 * Changing a finding needs --user, so that the change is recorded under a name. The reason must not hold a secret.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The finding number from the list.
	 *
	 * --reason=<text>
	 * : Why this is accepted.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-passport findings accept 12 --reason="The client renews this by hand" --user=admin
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function accept( $args, $assoc_args ): void {
		Access::requireEditor();
		$id     = $this->id( $args );
		$reason = isset( $assoc_args['reason'] ) ? (string) $assoc_args['reason'] : '';
		Access::attempt( fn() => $this->plugin->findings()->accept( $id, $reason, 'cli' ) );
		\WP_CLI::success( sprintf( 'Finding %d is accepted.', $id ) );
	}

	/**
	 * Put a hidden or accepted finding back to open.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The finding number from the list.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-passport findings reopen 12 --user=admin
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function reopen( $args, $assoc_args ): void {
		Access::requireEditor();
		$id = $this->id( $args );
		Access::attempt( fn() => $this->plugin->findings()->reopen( $id ) );
		\WP_CLI::success( sprintf( 'Finding %d is open again.', $id ) );
	}

	/**
	 * The number given as the first argument.
	 *
	 * @param list<string> $args Positional arguments.
	 */
	private function id( array $args ): int {
		$id = isset( $args[0] ) && ctype_digit( (string) $args[0] ) ? (int) $args[0] : 0;
		if ( $id < 1 ) {
			\WP_CLI::error( 'Give the finding number from the list, for example: findings snooze 12.' );
		}
		return $id;
	}

	/**
	 * Level key from the dash-case word.
	 *
	 * @param string $token Word given on the command line.
	 */
	private function level( string $token ): ?string {
		foreach ( Level::all() as $level ) {
			if ( Level::token( $level ) === $token ) {
				return $level;
			}
		}
		return null;
	}
}
