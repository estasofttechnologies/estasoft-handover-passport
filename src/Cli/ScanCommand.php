<?php
/**
 * WP-CLI: wp estasoft-passport scan (CLI-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Cli;

use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Scan\ScanService;

defined( 'ABSPATH' ) || exit;

/**
 * Scan the site and print what changed.
 */
final class ScanCommand {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Run a scan and print the differences from the previous one.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. With json, the facts (without Sensitive values) are printed.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-passport scan
	 *     wp estasoft-passport scan --format=json
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function __invoke( $args, $assoc_args ): void {
		$format  = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$service = $this->plugin->scanService();
		$result  = $service->runOnDemand( 'cli' );

		if ( 'wait' === $result['status'] ) {
			$seconds = (int) ( $result['wait'] ?? 60 );
			\WP_CLI::error( sprintf( 'A scan was started a moment ago. Try again in %d second%s.', $seconds, 1 === $seconds ? '' : 's' ) );
		}

		if ( 'busy' === $result['status'] ) {
			\WP_CLI::error( 'A scan is already running. Try again in a minute.' );
		}
		if ( 'done' !== $result['status'] ) {
			\WP_CLI::error( 'The scan did not finish. Nothing was changed.' );
		}

		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( ScanService::redact( $service->latestFacts() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		$latest = $service->latest();
		$diffs  = null === $latest ? array() : (array) $latest['differences'];
		if ( ! empty( $result['baseline'] ) ) {
			\WP_CLI::success( 'Baseline scan recorded. Changes are listed from the next scan on.' );
		} elseif ( array() === $diffs ) {
			\WP_CLI::success( 'Scan finished. Nothing has changed since the previous scan.' );
		} else {
			$rows = array();
			foreach ( $diffs as $diff ) {
				$rows[] = array(
					'change' => (string) $diff['kind'],
					'name'   => (string) $diff['label'],
					'before' => $this->flat( (array) $diff['before'] ),
					'after'  => $this->flat( (array) $diff['after'] ),
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'change', 'name', 'before', 'after' ) );
			\WP_CLI::success( sprintf( 'Scan finished. %1$d difference(s) found, %2$d newly added to the change log (the rest were already recorded when they happened).', (int) $result['found'], (int) $result['changes'] ) );
		}
		if ( array() !== $result['gaps'] ) {
			\WP_CLI::warning( 'Not available on this server: ' . implode( ', ', $result['gaps'] ) );
		}
	}

	/**
	 * One-line text of a small value map.
	 *
	 * @param array<string, mixed> $values Values.
	 */
	private function flat( array $values ): string {
		$parts = array();
		foreach ( $values as $key => $value ) {
			$parts[] = $key . '=' . ( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value );
		}
		return implode( ', ', $parts );
	}
}
