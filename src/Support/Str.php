<?php
/**
 * String helpers.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Small, dependency-free string utilities.
 */
final class Str {

	/**
	 * Split text into trimmed, non-empty lines.
	 *
	 * @param string $text Multi-line text.
	 * @return list<string>
	 */
	public static function lines( string $text ): array {
		$lines = preg_split( '/\R/u', $text );
		if ( false === $lines ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', $lines ), static fn( string $line ): bool => '' !== $line ) );
	}

	/**
	 * Shorten text to a number of characters, adding an ellipsis when cut.
	 *
	 * @param string $text   Text.
	 * @param int    $length Maximum characters.
	 */
	public static function truncate( string $text, int $length ): string {
		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, max( 0, $length - 1 ) ) ) . '…';
	}

	/**
	 * Lower-case slug of letters, digits and hyphens.
	 *
	 * @param string $text Text.
	 */
	public static function slug( string $text ): string {
		$text = strtolower( trim( $text ) );
		$text = (string) preg_replace( '/[^a-z0-9]+/', '-', $text );
		return trim( $text, '-' );
	}

	/**
	 * Replace {placeholders} using a map. Unknown placeholders stay as written.
	 *
	 * @param string                $text   Text with {name} tokens.
	 * @param array<string, string> $values Values by token name.
	 */
	public static function fill( string $text, array $values ): string {
		return (string) preg_replace_callback(
			'/\{([a-z0-9_]+)\}/',
			static fn( array $m ): string => $values[ $m[1] ] ?? $m[0],
			$text
		);
	}

	/**
	 * Make a spreadsheet cell safe: a leading = + - or @ is prefixed so it reads as text (SEC-11).
	 *
	 * @param string $value Cell text.
	 */
	public static function csvCell( string $value ): string {
		if ( '' !== $value && false !== strpbrk( $value[0], "=+-@\t\r" ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
