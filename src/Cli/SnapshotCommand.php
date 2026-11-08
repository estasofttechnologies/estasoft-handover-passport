<?php
/**
 * WP-CLI: wp estasoft-passport snapshot (CLI-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Cli;

use Estasoft\HandoverPassport\Export\ExportOptions;
use Estasoft\HandoverPassport\Findings\SnapshotIntegrity;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Make, list, verify and compare snapshots, as the Handover screen does.
 */
final class SnapshotCommand {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Store a snapshot of the Passport as it is now. A snapshot never changes afterwards.
	 *
	 * Needs --user with someone who may export, so the change log names that person. Sensitive records are left out unless
	 * --include-sensitive is given and --user holds the Sensitive capability; the Full internal pack always holds them.
	 *
	 * ## OPTIONS
	 *
	 * --name=<name>
	 * : A name for the snapshot, up to 190 characters.
	 *
	 * [--pack=<pack>]
	 * : The pack to snapshot. A key such as client, technical, full or emergency, or the key of a copy made in Settings.
	 * ---
	 * default: technical
	 * ---
	 *
	 * [--note=<note>]
	 * : An optional note, up to 2000 characters.
	 *
	 * [--include-sensitive]
	 * : Put every Sensitive record in the snapshot. Needs a user who may see Sensitive records.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-passport snapshot create --name="Handover to Acme, May 2027" --pack=client --user=admin
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function create( $args, $assoc_args ): void {
		Access::requireExporter();
		$options = $this->options( $assoc_args );
		$name    = isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '';
		$pack    = isset( $assoc_args['pack'] ) ? sanitize_key( (string) $assoc_args['pack'] ) : 'technical';
		$note    = isset( $assoc_args['note'] ) ? (string) $assoc_args['note'] : '';
		$id      = (int) Access::attempt( fn() => $this->plugin->snapshots()->create( $pack, $name, $note, $options ) );
		$row     = $this->plugin->snapshots()->get( $id );
		\WP_CLI::success( sprintf( 'Snapshot %1$d stored. SHA-256: %2$s', $id, null === $row ? '' : (string) $row['hash'] ) );
	}

	/**
	 * List snapshots, newest first.
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
	 *     wp estasoft-passport snapshot list
	 *
	 * @subcommand list
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function list_( $args, $assoc_args ): void {
		$checked = (array) get_option( SnapshotIntegrity::OPTION, array() );
		$rows    = array();
		foreach ( $this->plugin->snapshots()->all() as $row ) {
			$id    = (int) $row['id'];
			$user  = get_userdata( (int) $row['created_by'] );
			$state = isset( $checked[ $id ]['ok'] ) ? ( $checked[ $id ]['ok'] ? 'ok' : 'MISMATCH' ) : 'not checked';
			$rows[] = array(
				'id'           => $id,
				'name'         => (string) $row['name'],
				'pack'         => (string) $row['profile_key'],
				'created_utc'  => (string) $row['created_at'],
				'by'           => false === $user ? '' : $user->user_login,
				'acknowledged' => count( (array) $row['acknowledgements'] ),
				'integrity'    => $state,
				'hash'         => (string) $row['hash'],
			);
		}
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'name', 'pack', 'created_utc', 'by', 'acknowledged', 'integrity', 'hash' ) );
	}

	/**
	 * Check that a snapshot still matches the hash stored when it was made.
	 *
	 * Exits with status 1 when it does not, so a monitoring job can call it.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The snapshot number from the list.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-passport snapshot verify 3
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function verify( $args, $assoc_args ): void {
		$id     = $this->id( $args, 0, 'verify 3' );
		$result = $this->plugin->snapshots()->verify( $id );
		if ( null === $result ) {
			\WP_CLI::error( 'No such snapshot.' );
		}
		if ( $result['ok'] ) {
			\WP_CLI::success( sprintf( 'Snapshot %1$d matches its stored hash (%2$s).', $id, $result['stored'] ) );
			return;
		}
		\WP_CLI::error( sprintf( 'Snapshot %1$d does NOT match its stored hash. Stored: %2$s. Found: %3$s. Do not hand it over; make a new snapshot.', $id, $result['stored'], $result['found'] ) );
	}

	/**
	 * Show what changed between a snapshot and another snapshot, or between a snapshot and the Passport as it is now.
	 *
	 * Sensitive records are left out of the comparison unless --user holds the Sensitive capability.
	 *
	 * ## OPTIONS
	 *
	 * <a>
	 * : The earlier snapshot number.
	 *
	 * <b>
	 * : The later snapshot number, or the word live.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-passport snapshot compare 2 live
	 *     wp estasoft-passport snapshot compare 2 3 --format=json
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function compare( $args, $assoc_args ): void {
		$a = $this->id( $args, 0, 'compare 2 live' );
		$b = isset( $args[1] ) ? (string) $args[1] : '';
		if ( 'live' !== $b && ! ctype_digit( $b ) ) {
			\WP_CLI::error( 'Give a second snapshot number, or the word live, for example: compare 2 live.' );
		}
		$options = new ExportOptions( Access::seesSensitive(), array(), get_current_user_id() );
		$diff    = Access::attempt( fn() => $this->plugin->snapshots()->compare( $a, 'live' === $b ? 'live' : (int) $b, $options ) );
		if ( ! is_array( $diff ) ) {
			\WP_CLI::error( 'No such snapshot.' );
		}
		if ( isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ) {
			\WP_CLI::line( (string) wp_json_encode( $diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		\WP_CLI::line(
			sprintf(
				'%1$d added, %2$d removed, %3$d changed, %4$d unchanged.%5$s',
				count( (array) $diff['added'] ),
				count( (array) $diff['removed'] ),
				count( (array) $diff['changed'] ),
				(int) $diff['unchanged'],
				! empty( $diff['notes_changed'] ) ? ' The notes changed.' : ''
			)
		);
		foreach ( array( 'added' => '+', 'removed' => '-', 'changed' => '~' ) as $kind => $mark ) {
			foreach ( (array) $diff[ $kind ] as $item ) {
				$title = '' === (string) $item['title'] ? '(Sensitive record)' : (string) $item['title'];
				$extra = 'changed' === $kind && ! empty( $item['fields'] ) ? ' [' . implode( ', ', array_map( static fn( $f ): string => is_array( $f ) ? (string) ( $f['label'] ?? $f['field'] ?? '' ) : (string) $f, (array) $item['fields'] ) ) . ']' : '';
				\WP_CLI::line( sprintf( '%1$s %2$s: %3$s%4$s', $mark, (string) $item['section'], $title, $extra ) );
			}
		}
		if ( ! empty( $diff['sensitive_skipped'] ) ) {
			\WP_CLI::line( sprintf( '%d Sensitive record(s) were left out; name a user who may see them with --user.', (int) $diff['sensitive_skipped'] ) );
		}
	}

	/**
	 * What the command may put in a pack.
	 *
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	private function options( array $assoc_args ): ExportOptions {
		$include = ! empty( $assoc_args['include-sensitive'] );
		if ( $include && ! Capabilities::canSeeSensitive() ) {
			\WP_CLI::error( '--include-sensitive needs --user with someone who may see Sensitive records.' );
		}
		$ids = $include ? $this->plugin->records()->items()->allSensitiveIds() : array();
		return new ExportOptions( $include, $ids, get_current_user_id() );
	}

	/**
	 * The snapshot number in an argument position, or an error.
	 *
	 * @param list<string> $args    Positional arguments.
	 * @param int          $at      Position.
	 * @param string       $example Example for the message.
	 */
	private function id( array $args, int $at, string $example ): int {
		$id = isset( $args[ $at ] ) && ctype_digit( (string) $args[ $at ] ) ? (int) $args[ $at ] : 0;
		if ( $id < 1 ) {
			\WP_CLI::error( 'Give the snapshot number from the list, for example: ' . $example . '.' );
		}
		return $id;
	}
}
