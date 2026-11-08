<?php
/**
 * Reads query and form values with the right sanitising (SEC-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Small accessors. Nonces are checked by the handler that reads form data, not here.
 */
final class Request {

	/**
	 * A query-string key.
	 *
	 * @param string $name    Parameter.
	 * @param string $default Default.
	 */
	public static function key( string $name, string $default = '' ): string {
		return isset( $_GET[ $name ] ) && is_scalar( $_GET[ $name ] ) ? sanitize_key( wp_unslash( (string) $_GET[ $name ] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation parameter.
	}

	/**
	 * A query-string integer.
	 *
	 * @param string $name Parameter.
	 */
	public static function int( string $name ): int {
		return isset( $_GET[ $name ] ) && is_scalar( $_GET[ $name ] ) ? absint( wp_unslash( (string) $_GET[ $name ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation parameter.
	}

	/**
	 * A query-string text value.
	 *
	 * @param string $name Parameter.
	 */
	public static function text( string $name ): string {
		return isset( $_GET[ $name ] ) && is_scalar( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter parameter.
	}

	/**
	 * The posted field map "f". Values are returned raw as strings or lists; the services validate them.
	 * Call only after check_admin_referer().
	 *
	 * @return array<string, mixed>
	 */
	public static function postedFields(): array {
		$raw = isset( $_POST['f'] ) && is_array( $_POST['f'] ) ? wp_unslash( $_POST['f'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is checked by the caller; the field services validate and sanitise every value by type.
		$out = array();
		foreach ( $raw as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$out[ $key ] = array_map( 'strval', array_values( array_filter( $value, 'is_scalar' ) ) );
			} elseif ( is_scalar( $value ) ) {
				$out[ $key ] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * A posted scalar.
	 *
	 * @param string $name Field name.
	 */
	public static function post( string $name ): string {
		return isset( $_POST[ $name ] ) && is_scalar( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked by the caller.
	}

	/**
	 * A posted multi-line text value (line breaks are kept).
	 *
	 * @param string $name Field name.
	 */
	public static function postedTextarea( string $name ): string {
		return isset( $_POST[ $name ] ) && is_scalar( $_POST[ $name ] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked by the caller.
	}

	/**
	 * A posted address, cleaned as a URL.
	 *
	 * @param string $name Field name.
	 */
	public static function postedUrl( string $name ): string {
		return isset( $_POST[ $name ] ) && is_scalar( $_POST[ $name ] ) ? esc_url_raw( wp_unslash( (string) $_POST[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked by the caller.
	}

	/**
	 * Posted rows such as steps[0][text]. Every cell is returned as a raw string; the services validate them.
	 * Call only after check_admin_referer().
	 *
	 * @param string $name Field name.
	 * @return list<array<string, string>>
	 */
	public static function postedRows( string $name ): array {
		$raw = isset( $_POST[ $name ] ) && is_array( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is checked by the caller; the services validate and sanitise every cell by type.
		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = array();
			foreach ( $row as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$clean[ sanitize_key( (string) $key ) ] = (string) $value;
				}
			}
			$out[] = $clean;
		}
		return array_slice( $out, 0, 200 );
	}
}
