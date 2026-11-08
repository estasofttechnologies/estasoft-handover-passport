<?php
/**
 * One check of a kind per subject per minute (DOM-8).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers when a subject was last checked, using a short-lived transient.
 */
final class CheckLimiter {

	public const SECONDS = 60;

	/**
	 * Take a turn: true when the subject may be checked now, false when it was checked within the last minute.
	 *
	 * @param string $kind    dns, ssl or url.
	 * @param string $subject Domain, host or record ID.
	 */
	public static function take( string $kind, string $subject ): bool {
		$key = 'estasoft_hp_chk_' . substr( md5( $kind . '|' . strtolower( $subject ) ), 0, 20 );
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, self::SECONDS );
		return true;
	}
}
