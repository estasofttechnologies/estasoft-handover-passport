<?php
/**
 * WP-CLI: wp estasoft-passport restore-test (CLI-9).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Cli;

use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Services\RestoreTestService;
use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Log the result of a restore test, so a host job or CI pipeline that restores backups can record its own result.
 */
final class RestoreTestCommand {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Log a restore test.
	 *
	 * Needs --user with someone who may edit the Passport, so the change log names that person.
	 *
	 * ## OPTIONS
	 *
	 * --result=<result>
	 * : What happened.
	 * ---
	 * options:
	 *   - pass
	 *   - partial
	 *   - fail
	 * ---
	 *
	 * [--date=<date>]
	 * : The day of the test, as YYYY-MM-DD. Not in the future. Today when left out.
	 *
	 * [--duration=<minutes>]
	 * : How many minutes the restore took.
	 *
	 * [--scope=<scope>]
	 * : What was restored.
	 * ---
	 * options:
	 *   - files
	 *   - database
	 *   - both
	 * ---
	 *
	 * [--place=<place>]
	 * : Where it was restored to.
	 * ---
	 * options:
	 *   - staging
	 *   - local
	 *   - spare_host
	 *   - other
	 * ---
	 *
	 * [--who=<name>]
	 * : Who or what did it, such as "nightly CI job". The name of --user when left out.
	 *
	 * [--backup-date=<date>]
	 * : The date of the backup that was used.
	 *
	 * [--problems=<text>]
	 * : What went wrong, if anything. Never put a password in it.
	 *
	 * [--runbook=<id>]
	 * : The runbook that was followed.
	 *
	 * [--backup-item=<id>]
	 * : The backup record that was restored.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-passport restore-test add --result=pass --duration=42 --scope=both --place=staging --who="Nightly job" --user=ci
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function add( $args, $assoc_args ): void {
		Access::requireEditor();
		$value = static fn( string $key ): string => isset( $assoc_args[ $key ] ) ? (string) $assoc_args[ $key ] : '';
		if ( ! array_key_exists( $value( 'result' ), RestoreTestService::results() ) ) {
			\WP_CLI::error( 'Say how it went with --result=pass, partial or fail.' );
		}
		foreach ( array( 'scope' => RestoreTestService::scopes(), 'place' => RestoreTestService::places() ) as $key => $allowed ) {
			if ( '' !== $value( $key ) && ! array_key_exists( $value( $key ), $allowed ) ) {
				\WP_CLI::error( sprintf( 'Use --%1$s=%2$s.', $key, implode( ', ', array_keys( $allowed ) ) ) );
			}
		}
		if ( '' !== $value( 'duration' ) && ! ctype_digit( $value( 'duration' ) ) ) {
			\WP_CLI::error( 'Give --duration as a whole number of minutes.' );
		}
		$who   = '' !== $value( 'who' ) ? $value( 'who' ) : (string) wp_get_current_user()->display_name;
		$input = array(
			'test_date'      => '' !== $value( 'date' ) ? $value( 'date' ) : Clock::today(),
			'result'         => $value( 'result' ),
			'minutes'        => $value( 'duration' ),
			'scope'          => $value( 'scope' ),
			'place'          => $value( 'place' ),
			'who'            => $who,
			'backup_date'    => $value( 'backup-date' ),
			'problems'       => $value( 'problems' ),
			'runbook_id'     => $value( 'runbook' ),
			'backup_item_id' => $value( 'backup-item' ),
		);
		$row = Access::attempt( fn() => $this->plugin->restoreTests()->log( $input, 'cli' ) );
		if ( ! is_array( $row ) ) {
			\WP_CLI::error( 'The test could not be logged.' );
		}
		\WP_CLI::success( sprintf( 'Restore test %1$d logged: %2$s on %3$s.', (int) $row['id'], RestoreTestService::results()[ (string) $row['result'] ], (string) $row['test_date'] ) );
	}

	/**
	 * List the logged restore tests, newest first.
	 *
	 * ## OPTIONS
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
	 *     wp estasoft-passport restore-test list
	 *
	 * @subcommand list
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function list_( $args, $assoc_args ): void {
		$rows = array();
		foreach ( $this->plugin->restoreTests()->all() as $test ) {
			$rows[] = array(
				'id'      => (int) $test['id'],
				'date'    => (string) $test['test_date'],
				'result'  => (string) $test['result'],
				'minutes' => (int) $test['minutes'],
				'scope'   => (string) $test['scope'],
				'place'   => (string) $test['place'],
				'who'     => (string) $test['who'],
			);
		}
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'date', 'result', 'minutes', 'scope', 'place', 'who' ) );
	}
}
