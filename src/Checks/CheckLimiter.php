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
	 * @param string $kind    dns, ssl, url or mail.
	 * @param string $subject Domain, host or record ID.
	 */
	public static function take( string $kind, string $subject ): bool {
		$key = self::key( $kind, $subject );
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, time() + self::SECONDS, self::SECONDS );
		return true;
	}

	/**
	 * Seconds until the subject may be checked again (SEC-9), at least 1 while the wait lasts and 0 once it is over.
	 *
	 * @param string $kind    dns, ssl, url or mail.
	 * @param string $subject Domain, host, record ID or address.
	 */
	public static function secondsLeft( string $kind, string $subject ): int {
		$until = get_transient( self::key( $kind, $subject ) );
		if ( false === $until ) {
			return 0;
		}
		return is_numeric( $until ) && (int) $until > 1000 ? max( 1, (int) $until - time() ) : self::SECONDS;
	}

	/**
	 * Transient name for one subject.
	 *
	 * @param string $kind    Kind of check.
	 * @param string $subject Subject.
	 */
	private static function key( string $kind, string $subject ): string {
		return 'estasoft_hp_chk_' . substr( md5( $kind . '|' . strtolower( $subject ) ), 0, 20 );
	}
}
