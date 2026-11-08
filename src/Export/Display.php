<?php
/**
 * Turns a stored value into text a person can read in a pack (EXP-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export;

use Estasoft\HandoverPassport\Support\Str;

defined( 'ABSPATH' ) || exit;

/**
 * Display text for one value, given the definition of its field. The stored value stays as it is in the JSON file;
 * this is only for HTML, Markdown, CSV, calendar and PDF.
 */
final class Display {

	/**
	 * Text for a value.
	 *
	 * @param array<string, mixed> $def   Field definition: type and, for selects, options.
	 * @param mixed                $value Value as stored in the content.
	 */
	public static function text( array $def, $value ): string {
		$type = (string) ( $def['type'] ?? 'text' );
		if ( 'select' === $type ) {
			$options = isset( $def['options'] ) && is_array( $def['options'] ) ? $def['options'] : array();
			$key     = is_scalar( $value ) ? (string) $value : '';
			return (string) ( $options[ $key ] ?? $key );
		}
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( static fn( $item ): string => is_scalar( $item ) ? (string) $item : '', $value ) );
		}
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'estasoft-handover-passport' ) : __( 'No', 'estasoft-handover-passport' );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * The text as lines, for values that read as a list.
	 *
	 * @param array<string, mixed> $def   Field definition.
	 * @param mixed                $value Value.
	 * @return array<int, string>
	 */
	public static function lines( array $def, $value ): array {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
					$out[] = trim( (string) $item );
				}
			}
			return $out;
		}
		$text = self::text( $def, $value );
		return '' === trim( $text ) ? array() : array_values( array_filter( array_map( 'trim', Str::split( '/\R/', $text ) ), static fn( string $line ): bool => '' !== $line ) );
	}

	/**
	 * Whether a value is empty and can be left out of a card.
	 *
	 * @param mixed $value Value.
	 */
	public static function isEmpty( $value ): bool {
		if ( is_array( $value ) ) {
			return array() === array_filter( $value, static fn( $item ): bool => ! self::isEmpty( $item ) );
		}
		return null === $value || '' === trim( (string) ( is_scalar( $value ) ? $value : '' ) );
	}
}
