<?php
/**
 * Clean the rows of the step editor into an ordered list of steps (RUN-2).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Pure: takes what a form posted and returns steps, so it can be tested without WordPress.
 */
final class RunbookSteps {

	public const MAX_STEPS = 60;
	public const MAX_TEXT  = 1000;

	/**
	 * Posted rows to steps. Rows without text or marked Remove are dropped, rows are put in order of Position,
	 * and a row marked Duplicate is followed by a copy of itself.
	 *
	 * @param array<int|string, mixed> $rows  Posted rows with position, text, who, minutes, link, warning, remove, duplicate.
	 * @param array<string, string>    $roles Valid role keys.
	 * @return list<array{text: string, who: string, minutes: int, link: string, warning: bool}>
	 */
	public static function clean( array $rows, array $roles ): array {
		$staged = array();
		$index  = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			++$index;
			$text = self::text( $row['text'] ?? '' );
			if ( '' === $text || ! empty( $row['remove'] ) ) {
				continue;
			}
			$who  = sanitize_key( self::scalar( $row['who'] ?? '' ) );
			$step = array(
				'text'    => $text,
				'who'     => array_key_exists( $who, $roles ) ? $who : '',
				'minutes' => max( 0, min( 10000, (int) self::scalar( $row['minutes'] ?? 0 ) ) ),
				'link'    => self::link( self::scalar( $row['link'] ?? '' ) ),
				'warning' => ! empty( $row['warning'] ) && '0' !== self::scalar( $row['warning'] ),
			);
			$position = '' === self::scalar( $row['position'] ?? '' ) ? (float) $index : (float) self::scalar( $row['position'] );
			$staged[] = array( $position, $index, $step, ! empty( $row['duplicate'] ) );
		}
		usort(
			$staged,
			static fn( array $a, array $b ): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]
		);
		$out = array();
		foreach ( $staged as $item ) {
			$out[] = $item[2];
			if ( $item[3] ) {
				$out[] = $item[2];
			}
		}
		return array_slice( $out, 0, self::MAX_STEPS );
	}

	/**
	 * Total minutes of a list of steps.
	 *
	 * @param list<array<string, mixed>> $steps Steps.
	 */
	public static function minutes( array $steps ): int {
		return array_sum( array_map( static fn( array $step ): int => (int) ( $step['minutes'] ?? 0 ), $steps ) );
	}

	/**
	 * Only http and https links are kept.
	 *
	 * @param string $value Posted link.
	 */
	private static function link( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || 1 !== preg_match( '#^https?://#i', $value ) ) {
			return '';
		}
		return mb_substr( esc_url_raw( $value ), 0, 500 );
	}

	/**
	 * Single line or multi-line text, limited in length.
	 *
	 * @param mixed $value Posted value.
	 */
	private static function text( $value ): string {
		return mb_substr( trim( sanitize_textarea_field( self::scalar( $value ) ) ), 0, self::MAX_TEXT );
	}

	/**
	 * A scalar as a string; anything else as an empty string.
	 *
	 * @param mixed $value Value.
	 */
	private static function scalar( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
