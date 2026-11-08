<?php
/**
 * Short-lived files for exports (SEC-10).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A folder under the uploads folder that the web server is told not to serve, holding files with random names that
 * are deleted as soon as they have been read and, if something went wrong, within an hour.
 */
final class TempFiles {

	public const FOLDER  = 'estasoft-handover-passport-tmp';
	public const MAX_AGE = 3600;

	/**
	 * Path of the folder, made and protected on first use. An empty string means no safe place could be made.
	 */
	public static function dir(): string {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		$dir = rtrim( (string) $uploads['basedir'], '/\\' ) . '/' . self::FOLDER;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		self::protect( $dir );
		return $dir;
	}

	/**
	 * A new, empty file with a random name. Returns an empty string when no safe place exists.
	 *
	 * @param string $suffix Ending such as ".zip".
	 */
	public static function create( string $suffix = '' ): string {
		$dir = self::dir();
		if ( '' === $dir ) {
			return '';
		}
		$suffix = 1 === preg_match( '/^\.[a-z0-9]{1,6}$/', $suffix ) ? $suffix : '';
		$path   = $dir . '/' . bin2hex( random_bytes( 16 ) ) . $suffix;
		return false === file_put_contents( $path, '' ) ? '' : $path; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A private file in the protected folder.
	}

	/**
	 * Delete one file made here. A path outside the folder is left alone.
	 *
	 * @param string $path Path from create().
	 */
	public static function discard( string $path ): void {
		$dir = self::dir();
		if ( '' === $path || '' === $dir || 0 !== strpos( $path, $dir . '/' ) || false !== strpos( $path, '..' ) ) {
			return;
		}
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Delete files older than an hour. Runs when a file is made and in the daily clean-up.
	 *
	 * @param int $max_age Seconds a file may stay.
	 * @return int Files deleted.
	 */
	public static function purge( int $max_age = self::MAX_AGE ): int {
		$dir = self::dir();
		if ( '' === $dir ) {
			return 0;
		}
		$gone = 0;
		foreach ( glob( $dir . '/*' ) ?: array() as $path ) {
			$name = basename( $path );
			if ( ! is_file( $path ) || 'index.php' === $name || 'web.config' === $name ) {
				continue;
			}
			$time = filemtime( $path );
			if ( false !== $time && time() - $time > $max_age ) {
				wp_delete_file( $path );
				++$gone;
			}
		}
		return $gone;
	}

	/**
	 * Put an index file and a deny rule in the folder, for the servers that read one.
	 *
	 * @param string $dir Folder.
	 */
	private static function protect( string $dir ): void {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $body ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Protection files for the private folder.
			}
		}
	}
}
