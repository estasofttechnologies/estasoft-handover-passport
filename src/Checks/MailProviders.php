<?php
/**
 * Name a mail provider from MX hosts using a shipped table (DOM-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Checks;

defined( 'ABSPATH' ) || exit;

/**
 * States a fact: the provider whose host name the MX records use. It says nothing about how well mail is set up.
 */
final class MailProviders {

	/**
	 * Provider names found in a list of MX records.
	 *
	 * @param list<string>              $mx    MX values such as "10 aspmx.l.google.com".
	 * @param array<string, string>|null $table Suffix table, or null for the shipped one.
	 * @return list<string> Distinct provider names; empty when none is recognised.
	 */
	public static function name( array $mx, ?array $table = null ): array {
		if ( null === $table ) {
			$file  = ESTASOFT_HP_DIR . 'data/mail-providers.php';
			$table = is_readable( $file ) ? (array) include $file : array();
			$table = (array) apply_filters( 'estasoft_hp_mail_providers', $table );
		}
		$found = array();
		foreach ( $mx as $value ) {
			$host = strtolower( rtrim( trim( (string) preg_replace( '/^\s*\d+\s+/', '', (string) $value ) ), '.' ) );
			if ( '' === $host ) {
				continue;
			}
			foreach ( $table as $suffix => $provider ) {
				$suffix = strtolower( (string) $suffix );
				if ( $host === $suffix || ( strlen( $host ) > strlen( $suffix ) && '.' . $suffix === substr( $host, -strlen( $suffix ) - 1 ) ) ) {
					$found[ (string) $provider ] = (string) $provider;
					break;
				}
			}
		}
		return array_values( $found );
	}
}
