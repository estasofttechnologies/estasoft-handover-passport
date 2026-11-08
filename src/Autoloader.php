<?php
/**
 * Minimal PSR-4 autoloader.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a namespace prefix to a base directory.
 */
final class Autoloader {

	/**
	 * Register the loader for one prefix.
	 *
	 * @param string $prefix   Namespace prefix with a trailing backslash.
	 * @param string $base_dir Directory with a trailing slash.
	 */
	public static function register( string $prefix, string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $prefix, $base_dir ): void {
				if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
					return;
				}
				$relative = substr( $class_name, strlen( $prefix ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
