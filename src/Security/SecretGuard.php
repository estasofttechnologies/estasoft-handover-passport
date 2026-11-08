<?php
/**
 * The one secret guard (ACC-3, SEC-6). It runs on every route in and cannot be switched off.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Detects text that looks like a secret. It reports a pattern id, never the matched text.
 */
final class SecretGuard {

	/**
	 * Built-in patterns by id. A filter can add patterns but never remove these.
	 *
	 * @var array<string, string>
	 */
	private const BUILT_IN = array(
		'private_key'    => '/-----BEGIN [A-Z ]*PRIVATE KEY(?: BLOCK)?-----/',
		'aws_access_key' => '/\b(?:AKIA|ASIA|AGPA|AIDA|AROA|ANPA|ANVA)[0-9A-Z]{16}\b/',
		'github_token'   => '/\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,})\b/',
		'slack_token'    => '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/',
		'stripe_key'     => '/\b[rs]k_(?:live|test)_[A-Za-z0-9]{16,}\b/',
		'google_api_key' => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
		'jwt'            => '/\beyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/',
		'url_credential' => '/\b[a-z][a-z0-9+.\-]*:\/\/[^\/\s:@]+:[^\/\s@]+@/i',
		'bearer_token'   => '/\bBearer\s+[A-Za-z0-9._~+\/=-]{20,}/i',
		'labelled_value' => '/(?:^|[\s,;(])(?:pass(?:word|wd|phrase)?|pwd|secret|api[ _-]?key|access[ _-]?key|auth[ _-]?token|private[ _-]?key)\s*(?:[:=]|\bis\b)\s*[^\s]{3,}/iu',
	);

	/**
	 * Minimum length of a token judged by randomness.
	 */
	private const LONG_TOKEN = 32;

	/**
	 * Extra patterns added by a filter, keyed by id.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $extra = null;

	/**
	 * Return the pattern id when the text looks like a secret, otherwise null.
	 *
	 * @param string $text Text to inspect.
	 */
	public function inspect( string $text ): ?string {
		if ( '' === $text ) {
			return null;
		}
		foreach ( $this->patterns() as $id => $pattern ) {
			if ( 1 === @preg_match( $pattern, $text ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Filtered patterns may be invalid; an invalid one must not break saving.
				return (string) $id;
			}
		}
		return $this->hasRandomToken( $text ) ? 'random_token' : null;
	}

	/**
	 * Check every string in a nested value. Throws on the first match.
	 *
	 * @param mixed  $value Value (string or array) to scan.
	 * @param string $path  Label of the field, used in the message only.
	 * @throws SecretFound When a string looks like a secret.
	 */
	public function assertClean( $value, string $path = '' ): void {
		if ( is_string( $value ) ) {
			$id = $this->inspect( $value );
			if ( null !== $id ) {
				throw new SecretFound( '' === $path ? 'text' : $path, $id );
			}
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$this->assertClean( $item, '' === $path ? (string) $key : $path . ( is_int( $key ) ? '' : '.' . $key ) );
			}
		}
	}

	/**
	 * Built-in patterns plus any added through the filter. Built-ins always win.
	 *
	 * @return array<string, string>
	 */
	private function patterns(): array {
		if ( null === $this->extra ) {
			$this->extra = array();
			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'estasoft_hp_secret_patterns', array() );
				if ( is_array( $filtered ) ) {
					foreach ( $filtered as $id => $pattern ) {
						if ( is_string( $pattern ) && ! isset( self::BUILT_IN[ (string) $id ] ) ) {
							$this->extra[ 'custom_' . (string) $id ] = $pattern;
						}
					}
				}
			}
		}
		return self::BUILT_IN + $this->extra;
	}

	/**
	 * Whether any long, random-looking token is present.
	 *
	 * @param string $text Text to inspect.
	 */
	private function hasRandomToken( string $text ): bool {
		if ( strlen( $text ) < self::LONG_TOKEN ) {
			return false;
		}
		$tokens = preg_split( '/[\s,;"\'()<>]+/', $text );
		if ( false === $tokens ) {
			return false;
		}
		foreach ( $tokens as $token ) {
			if ( strlen( $token ) >= self::LONG_TOKEN && $this->looksRandom( $token ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Judge one token: mixed character classes and high Shannon entropy.
	 *
	 * @param string $token Token with no whitespace.
	 */
	private function looksRandom( string $token ): bool {
		if ( str_contains( $token, '://' ) || '/' === $token[0] || 1 !== preg_match( '/^[A-Za-z0-9+\/=_-]+$/', $token ) ) {
			return false;
		}
		if ( 1 === preg_match( '/^[0-9a-f]{40}$/i', $token ) ) {
			return false; // A Git commit id is common in deployment notes and is not a secret.
		}
		$has_digit = 1 === preg_match( '/\d/', $token );
		$has_alpha = 1 === preg_match( '/[A-Za-z]/', $token );
		if ( ! $has_digit || ! $has_alpha ) {
			return false;
		}
		$counts = count_chars( $token, 1 );
		$length = strlen( $token );
		$bits   = 0.0;
		foreach ( $counts as $count ) {
			$p     = $count / $length;
			$bits -= $p * log( $p, 2 );
		}
		return $bits >= 4.0;
	}
}
