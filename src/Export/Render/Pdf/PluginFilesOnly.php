<?php
/**
 * A file reader for the PDF library that reads only from the plugin's own PDF folder (SEC-12).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export\Render\Pdf;

use Estasoft\HandoverPassport\Vendor\Mpdf\File\LocalContentLoaderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * The logo is handed over as a data address, so the library has no reason to open any other file. This reader says no
 * to everything outside the folder that holds the library and its fonts.
 */
final class PluginFilesOnly implements LocalContentLoaderInterface {

	/**
	 * Constructor.
	 *
	 * @param list<string> $roots Folders that may be read.
	 */
	public function __construct( private array $roots ) {}

	/**
	 * Contents of a file inside an allowed folder, or null.
	 *
	 * @param string $path Path the library asked for.
	 * @return string|null
	 */
	public function load( $path ) {
		$real = realpath( (string) $path );
		if ( false === $real ) {
			return null;
		}
		foreach ( $this->roots as $root ) {
			$root = realpath( $root );
			if ( false !== $root && 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
				$body = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file inside the plugin's own PDF folder.
				return false === $body ? null : $body;
			}
		}
		return null;
	}
}
