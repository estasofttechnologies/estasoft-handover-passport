<?php
/**
 * Sends a finished pack to the browser (EXP-2, EXP-6, EXP-7).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Export\ExportResult;

defined( 'ABSPATH' ) || exit;

/**
 * One way to hand a file over: never cached, never sniffed, and the print view cannot run anything.
 */
final class Download {

	/**
	 * Send the file and stop.
	 *
	 * @param ExportResult $result The pack.
	 * @param bool         $inline True to show it in the browser (the print view), false to save it.
	 */
	public static function send( ExportResult $result, bool $inline = false ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		$name = (string) preg_replace( '/[^A-Za-z0-9._-]/', '-', $result->filename );
		nocache_headers();
		header( 'Content-Type: ' . $result->mime );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . $name . '"' );
		header( 'Content-Length: ' . (string) $result->size() );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		header( "Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; frame-ancestors 'none'" );
		header( 'X-Frame-Options: DENY' );
		echo $result->bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A finished file, sent as it is with its own media type; the print view is escaped when it is made and runs under a policy that blocks scripts.
		exit;
	}
}
