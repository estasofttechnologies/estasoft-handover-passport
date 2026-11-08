<?php
/**
 * Minimal stand-ins for the few WordPress functions the pure-logic classes call.
 *
 * Unit tests cover logic that does not need a WordPress database. Behaviour that depends on real
 * WordPress sanitising is covered by the integration script in tests/integration.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stand-in.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain, ignored.
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * Plural translation stand-in: English rules.
	 *
	 * @param string $single Singular text.
	 * @param string $plural Plural text.
	 * @param int    $number Number.
	 * @param string $domain Domain, ignored.
	 */
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Filter stand-in: runs the callbacks a test registered in $GLOBALS['estasoft_hp_test_filters'].
	 *
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value.
	 */
	function apply_filters( $hook, $value ) {
		foreach ( $GLOBALS['estasoft_hp_test_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value );
		}
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Action stand-in: records the call so a test can look at it.
	 *
	 * @param string $hook Hook name.
	 * @param mixed  ...$args Arguments.
	 */
	function do_action( $hook, ...$args ) {
		$GLOBALS['estasoft_hp_test_actions'][] = array( $hook, $args );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Option stand-in: always the default.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Default.
	 */
	function get_option( $name, $default = false ) {
		return $default;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * URL parser stand-in.
	 *
	 * @param string $url       URL.
	 * @param int    $component Component.
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test stand-in.
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * Date stand-in.
	 *
	 * @param string $format    Format.
	 * @param int    $timestamp Time.
	 */
	function wp_date( $format, $timestamp = null ) {
		return gmdate( $format, $timestamp ?? time() );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Sanitiser stand-in.
	 *
	 * @param string $value Value.
	 */
	function sanitize_text_field( $value ) {
		return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * Sanitiser stand-in.
	 *
	 * @param string $value Value.
	 */
	function sanitize_textarea_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Key sanitiser stand-in.
	 *
	 * @param string $value Value.
	 */
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	/**
	 * Email sanitiser stand-in.
	 *
	 * @param string $value Value.
	 */
	function sanitize_email( $value ) {
		return trim( (string) $value );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * Email check stand-in.
	 *
	 * @param string $value Value.
	 */
	function is_email( $value ) {
		return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : false;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * URL cleaner stand-in.
	 *
	 * @param string $value     Value.
	 * @param array  $protocols Allowed schemes.
	 */
	function esc_url_raw( $value, $protocols = null ) {
		$parts = parse_url( (string) $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test stand-in.
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		return in_array( strtolower( $parts['scheme'] ), (array) ( $protocols ?? array( 'http', 'https' ) ), true ) ? (string) $value : '';
	}
}
