<?php
/**
 * WP-CLI: wp estasoft-passport record (CLI-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Cli;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * List records, mark one as checked, or enter the next date of a renewal.
 */
final class RecordCommand {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * List the records in the registers.
	 *
	 * A Sensitive record is listed as hidden unless --user names someone who may see Sensitive records.
	 *
	 * ## OPTIONS
	 *
	 * [--kind=<kind>]
	 * : Only records of this kind, for example licence, domain, host, service, asset.
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
	 *     wp estasoft-passport record list --kind=domain
	 *
	 * @subcommand list
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function list_( $args, $assoc_args ): void {
		$kinds = Kinds::registerKinds();
		if ( ! empty( $assoc_args['kind'] ) ) {
			$kind = (string) $assoc_args['kind'];
			if ( ! in_array( $kind, $kinds, true ) ) {
				\WP_CLI::error( 'Unknown kind. Use one of: ' . implode( ', ', $kinds ) . '.' );
			}
			$kinds = array( $kind );
		}

		$can  = Access::seesSensitive();
		$rows = array();
		foreach ( $kinds as $kind ) {
			foreach ( $this->plugin->records()->listKind( $kind, $can ) as $record ) {
				$hidden = ! empty( $record['hidden'] );
				$rows[] = array(
					'id'        => (int) $record['id'],
					'kind'      => (string) $record['kind'],
					'title'     => (string) $record['title'],
					'source'    => $hidden ? '' : (string) $record['source'],
					'freshness' => $hidden ? '' : (string) $record['freshness'],
					'date'      => $hidden ? '' : (string) $record['due_date'],
					'verified'  => $hidden ? '' : (string) $record['verified_at'],
				);
			}
		}
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'kind', 'title', 'source', 'freshness', 'date', 'verified' ) );
	}

	/**
	 * Mark a record as checked today. This restarts its review period.
	 *
	 * Changing a record needs --user, so that the change is recorded under a name.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The record number from the list.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-passport record verify 7 --user=admin
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function verify( $args, $assoc_args ): void {
		Access::requireEditor();
		$record = $this->find( $args );
		$this->plugin->records()->verify( (int) $record['id'], 'cli' );
		\WP_CLI::success( sprintf( 'Record %d is marked as checked today.', (int) $record['id'] ) );
	}

	/**
	 * Enter the next renewal or expiry date of a record. A new reminder cycle starts from that date.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The record number from the list.
	 *
	 * <date>
	 * : The next date, as YYYY-MM-DD. It must be later than the current one.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-passport record renew 7 2027-03-01 --user=admin
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function renew( $args, $assoc_args ): void {
		Access::requireEditor();
		$record = $this->find( $args );
		$date   = isset( $args[1] ) ? (string) $args[1] : '';
		Access::attempt( fn() => $this->plugin->records()->renew( (int) $record['id'], $date, 'cli' ) );
		\WP_CLI::success( sprintf( 'Record %1$d now runs to %2$s.', (int) $record['id'], $date ) );
	}

	/**
	 * The record named by the first argument, or an error.
	 *
	 * @param list<string> $args Positional arguments.
	 * @return array<string, mixed>
	 */
	private function find( array $args ): array {
		$id = isset( $args[0] ) && ctype_digit( (string) $args[0] ) ? (int) $args[0] : 0;
		if ( $id < 1 ) {
			\WP_CLI::error( 'Give the record number from the list, for example: record verify 7.' );
		}
		$record = $this->plugin->records()->get( $id, Access::seesSensitive() );
		if ( null === $record || ! empty( $record['hidden'] ) ) {
			\WP_CLI::error( 'No such record, or it is Sensitive and --user does not name someone who may see Sensitive records.' );
		}
		return $record;
	}
}
