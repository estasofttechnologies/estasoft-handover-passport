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
	 * @return array<int, string>
	 */
	public static function lines( string $text ): array {
		$lines = preg_split( '/\R/u', $text );
		if ( false === $lines ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', $lines ), static fn( string $line ): bool => '' !== $line ) );
	}

	/**
	 * Split text on a pattern; an empty list when the pattern cannot be applied.
	 *
	 * @param string $pattern Regular expression.
	 * @param string $text    Text to split.
	 * @param int    $flags   PREG_SPLIT_* flags.
	 * @return array<int, string>
	 */
	public static function split( string $pattern, string $text, int $flags = 0 ): array {
		$parts = preg_split( $pattern, $text, -1, $flags );
		return false === $parts ? array() : $parts;
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

	/**
	 * One CSV line ending in CRLF. A cell is wrapped in quotes when it holds a comma, a quote, a line break, a tab or a
	 * space, and a quote inside it is doubled; this is what PHP's fputcsv() writes, without needing a stream.
	 *
	 * @param array<int, string> $cells Cell texts, already made safe for a spreadsheet with csvCell().
	 */
	public static function csvLine( array $cells ): string {
		$out = array();
		foreach ( $cells as $cell ) {
			$cell  = (string) $cell;
			$out[] = 1 === preg_match( '/[,"\r\n\t ]/', $cell ) ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell;
		}
		return implode( ',', $out ) . "\r\n";
	}
}
