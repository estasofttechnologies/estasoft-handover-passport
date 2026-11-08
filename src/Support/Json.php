<?php
/**
 * JSON helpers, including the canonical form used for snapshot hashes (DAT-7).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Encoding, decoding and canonical encoding.
 */
final class Json {

	/**
	 * Encode a value for storage in a long text column.
	 *
	 * @param mixed $value Value to encode.
	 */
	public static function encode( $value ): string {
		$json = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Canonical output must not depend on WordPress filters.
		return false === $json ? '[]' : $json;
	}

	/**
	 * Decode stored JSON into an array. Anything unreadable becomes an empty array.
	 *
	 * @param mixed $json Stored text.
	 * @return array<mixed>
	 */
	public static function decode( $json ): array {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}
		$value = json_decode( $json, true );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Canonical JSON: sorted keys at every level, UTF-8, no extra whitespace and
	 * floats written as strings, so the same data gives the same hash on every PHP version.
	 *
	 * @param mixed $value Value to encode.
	 */
	public static function canonical( $value ): string {
		$json = json_encode( self::normalise( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- See encode().
		return $json;
	}

	/**
	 * SHA-256 of the canonical form.
	 *
	 * @param mixed $value Value to hash.
	 */
	public static function hash( $value ): string {
		return hash( 'sha256', self::canonical( $value ) );
	}

	/**
	 * Sort associative arrays by key and turn floats into strings.
	 *
	 * @param mixed $value Value to normalise.
	 * @return mixed
	 */
	private static function normalise( $value ) {
		if ( is_float( $value ) ) {
			return rtrim( rtrim( sprintf( '%.10F', $value ), '0' ), '.' );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array_is_list( $value );
		$out     = array();
		foreach ( $value as $key => $item ) {
			$out[ $key ] = self::normalise( $item );
		}
		if ( ! $is_list ) {
			ksort( $out, SORT_STRING );
		}
		return $out;
	}
}
