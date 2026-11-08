<?php
/**
 * WP-CLI: wp estasoft-passport export (CLI-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Cli;

use Estasoft\HandoverPassport\Export\ExportOptions;
use Estasoft\HandoverPassport\Export\ExportResult;
use Estasoft\HandoverPassport\Export\ExportService;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Make a pack from the command line: write it to a file, or print a text format to standard output.
 */
final class ExportCommand {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Make a pack of the Passport, or of a stored snapshot.
	 *
	 * Without --include-sensitive the file holds no Sensitive record and no Sensitive value. The Full internal pack always
	 * holds them, so it needs --include-sensitive and a --user who may see Sensitive records. A file with Sensitive records
	 * is written readable by its owner only. Text formats (html, md, json, ics) print to standard output when --file is left
	 * out; pdf and csv need --file.
	 *
	 * ## OPTIONS
	 *
	 * [<pack>]
	 * : The pack: client, technical, full, emergency, or the key of a copy made in Settings. Leave it out with --snapshot.
	 *
	 * [--format=<format>]
	 * : The file format.
	 * ---
	 * default: pdf
	 * options:
	 *   - pdf
	 *   - html
	 *   - md
	 *   - json
	 *   - csv
	 *   - ics
	 * ---
	 *
	 * [--file=<path>]
	 * : Where to write the file. It is not replaced if it already exists unless --force is given.
	 *
	 * [--snapshot=<id>]
	 * : Make the file from this stored snapshot instead of the Passport as it is now.
	 *
	 * [--include-sensitive]
	 * : Put every Sensitive record in the file. Needs a user who may see Sensitive records.
	 *
	 * [--force]
	 * : Replace the file if it exists.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-passport export client --format=pdf --file=/home/me/client-pack.pdf
	 *     wp estasoft-passport export technical --format=md > technical.md
	 *     wp estasoft-passport export full --format=json --file=/secure/full.json --include-sensitive --user=admin
	 *     wp estasoft-passport export --snapshot=3 --format=html --file=/tmp/snapshot-3.html
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function __invoke( $args, $assoc_args ): void {
		Access::allowExporter();

		$format   = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'pdf';
		$file     = isset( $assoc_args['file'] ) ? (string) $assoc_args['file'] : '';
		$snapshot = isset( $assoc_args['snapshot'] ) ? (string) $assoc_args['snapshot'] : '';
		$pack     = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : '';
		$include  = ! empty( $assoc_args['include-sensitive'] );

		if ( ! array_key_exists( $format, ExportService::formats() ) ) {
			\WP_CLI::error( 'Use --format=pdf, html, md, json, csv or ics.' );
		}
		if ( '' !== $snapshot && ( '' !== $pack || ! ctype_digit( $snapshot ) ) ) {
			\WP_CLI::error( 'Use either a pack name or --snapshot=<number>, not both.' );
		}
		if ( '' === $snapshot && '' === $pack ) {
			\WP_CLI::error( 'Say which pack to make, for example: export client --format=pdf --file=client.pdf.' );
		}
		if ( '' === $file && in_array( $format, array( 'pdf', 'csv' ), true ) ) {
			\WP_CLI::error( 'This format is a binary file, so say where to write it with --file=<path>.' );
		}
		if ( $include && ! Capabilities::canSeeSensitive() ) {
			\WP_CLI::error( '--include-sensitive needs --user with someone who may see Sensitive records.' );
		}
		if ( '' === $snapshot ) {
			$profile = $this->plugin->exports()->profiles()->get( $pack );
			if ( null !== $profile && $profile->needsSensitiveCapability() && ! $include ) {
				\WP_CLI::error( 'This pack holds Sensitive values. Add --include-sensitive and name a user who may see them with --user.' );
			}
		} elseif ( ! $include ) {
			$stored = $this->plugin->snapshots()->content( (int) $snapshot );
			if ( null !== $stored && array() !== (array) ( $stored['sensitive_included'] ?? array() ) ) {
				\WP_CLI::error( 'This snapshot holds Sensitive records. Add --include-sensitive and name a user who may see them with --user.' );
			}
		}
		if ( '' !== $file ) {
			$this->assertWritable( $file, ! empty( $assoc_args['force'] ) );
		}

		$ids     = $include ? $this->plugin->records()->items()->allSensitiveIds() : array();
		$options = new ExportOptions( $include, $ids, get_current_user_id() );
		$result  = Access::attempt(
			fn(): ExportResult => '' !== $snapshot
				? $this->plugin->exports()->makeFromSnapshot( (int) $snapshot, $format, $options )
				: $this->plugin->exports()->make( $pack, $format, $options )
		);
		if ( ! $result instanceof ExportResult ) {
			\WP_CLI::error( 'The file could not be made.' );
		}
		foreach ( $result->warnings as $warning ) {
			\WP_CLI::warning( $warning );
		}
		if ( '' === $file ) {
			\WP_CLI::out( $result->bytes );
			return;
		}
		$this->write( $file, $result, $include );
		\WP_CLI::success( sprintf( 'Wrote %1$s (%2$s bytes, SHA-256 %3$s).', $file, number_format_i18n( $result->size() ), $result->hash() ) );
	}

	/**
	 * Stop early when the file cannot be written, before any work is done.
	 *
	 * @param string $file  Path.
	 * @param bool   $force Whether an existing file may be replaced.
	 */
	private function assertWritable( string $file, bool $force ): void {
		if ( is_dir( $file ) ) {
			\WP_CLI::error( 'That path is a folder. Give a file name.' );
		}
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			\WP_CLI::error( 'The folder for the file does not exist or cannot be written to.' );
		}
		if ( file_exists( $file ) && ! $force ) {
			\WP_CLI::error( 'That file already exists. Choose another name, or add --force to replace it.' );
		}
	}

	/**
	 * Write the file, owner-readable only, and warn when it lands where the web server could serve it.
	 *
	 * @param string       $file      Path.
	 * @param ExportResult $result    The pack.
	 * @param bool         $sensitive Whether Sensitive records were asked for.
	 */
	private function write( string $file, ExportResult $result, bool $sensitive ): void {
		if ( false === file_put_contents( $file, $result->bytes, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Command-line tool writing to the path the operator named.
			\WP_CLI::error( 'The file could not be written.' );
		}
		chmod( $file, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Owner-only, so other accounts on the server cannot read a pack.
		$real = realpath( $file );
		$root = realpath( ABSPATH );
		if ( false !== $real && false !== $root && 0 === strpos( $real, rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
			\WP_CLI::warning( ( $sensitive ? 'This file holds Sensitive records and ' : 'This file ' ) . 'is inside the WordPress folder, where the web server may be able to serve it. Move it somewhere private.' );
		}
	}
}
