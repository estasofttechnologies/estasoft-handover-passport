<?php
/**
 * Validation and sanitising of record fields by type (SEC-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Domain;

use Estasoft\HandoverPassport\Support\Dates;
use Estasoft\HandoverPassport\Support\Str;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans one value according to its field definition. Unknown fields are dropped by the caller.
 */
final class Fields {

	/**
	 * Clean a value. Returns null when the value is empty and the field is optional.
	 *
	 * @param array<string, mixed> $def   Field definition from Kinds.
	 * @param mixed                $value Raw value.
	 * @return mixed Clean value ('' or array() when empty).
	 * @throws ValidationError When the value cannot be accepted.
	 */
	public static function clean( array $def, $value ) {
		$type = (string) $def['type'];
		$max  = isset( $def['max'] ) ? (int) $def['max'] : 0;

		switch ( $type ) {
			case 'text':
				$clean = sanitize_text_field( self::scalar( $value ) );
				return self::limit( $clean, $max > 0 ? $max : 255, $def );

			case 'textarea':
				$clean = sanitize_textarea_field( self::scalar( $value ) );
				return self::limit( $clean, $max > 0 ? $max : 4000, $def );

			case 'date':
				$clean = trim( self::scalar( $value ) );
				if ( '' !== $clean && ! Dates::isDate( $clean ) ) {
					throw new ValidationError( (string) $def['label'], __( 'Use a real date in the form YYYY-MM-DD.', 'estasoft-handover-passport' ) );
				}
				return $clean;

			case 'int':
				$clean = trim( self::scalar( $value ) );
				if ( '' === $clean ) {
					return '';
				}
				if ( 1 !== preg_match( '/^\d{1,9}$/', $clean ) ) {
					throw new ValidationError( (string) $def['label'], __( 'Use a whole number.', 'estasoft-handover-passport' ) );
				}
				return (int) $clean;

			case 'money':
				return self::money( $def, $value );

			case 'select':
				$clean = sanitize_key( self::scalar( $value ) );
				$opts  = isset( $def['options'] ) && is_array( $def['options'] ) ? $def['options'] : array();
				if ( '' !== $clean && ! array_key_exists( $clean, $opts ) ) {
					throw new ValidationError( (string) $def['label'], __( 'Pick one of the listed choices.', 'estasoft-handover-passport' ) );
				}
				return $clean;

			case 'url':
				$raw = trim( self::scalar( $value ) );
				if ( '' === $raw ) {
					return '';
				}
				$clean = esc_url_raw( $raw, array( 'http', 'https' ) );
				if ( '' === $clean ) {
					throw new ValidationError( (string) $def['label'], __( 'Use a full web address starting with http:// or https://.', 'estasoft-handover-passport' ) );
				}
				return self::limit( $clean, 500, $def );

			case 'email':
				$raw = trim( self::scalar( $value ) );
				if ( '' === $raw ) {
					return '';
				}
				$clean = sanitize_email( $raw );
				if ( '' === $clean || ! is_email( $clean ) ) {
					throw new ValidationError( (string) $def['label'], __( 'Use a valid email address.', 'estasoft-handover-passport' ) );
				}
				return $clean;

			case 'lines':
				return self::lines( $def, $value, false );

			case 'links':
				return self::lines( $def, $value, true );

			case 'people':
				return self::ids( $def, $value );
		}
		throw new ValidationError( (string) $def['label'], __( 'This field type is not supported.', 'estasoft-handover-passport' ) );
	}

	/**
	 * Parse a decimal amount into integer minor units (LIC-6). "1,234.50" becomes 123450.
	 *
	 * @param array<string, mixed> $def   Field definition.
	 * @param mixed                $value Raw value.
	 * @return int|string Minor units, or '' when empty.
	 * @throws ValidationError When the amount cannot be read.
	 */
	public static function money( array $def, $value ) {
		if ( is_int( $value ) ) {
			return $value;
		}
		$raw = trim( self::scalar( $value ) );
		if ( '' === $raw ) {
			return '';
		}
		$raw = str_replace( array( ' ', "\u{00A0}" ), '', $raw );
		if ( 1 === preg_match( '/^\d{1,3}(?:,\d{3})*(?:\.\d{1,2})?$|^\d+(?:\.\d{1,2})?$/', $raw ) ) {
			$raw = str_replace( ',', '', $raw );
		} elseif ( 1 === preg_match( '/^\d+(?:,\d{1,2})$/', $raw ) ) {
			$raw = str_replace( ',', '.', $raw );
		} else {
			throw new ValidationError( (string) $def['label'], __( 'Use an amount such as 1234.50.', 'estasoft-handover-passport' ) );
		}
		[ $whole, $cents ] = array_pad( explode( '.', $raw, 2 ), 2, '0' );
		return ( (int) $whole * 100 ) + (int) str_pad( $cents, 2, '0' );
	}

	/**
	 * Format minor units as a decimal string for forms and exports.
	 *
	 * @param int|string $minor Minor units.
	 */
	public static function moneyDisplay( $minor ): string {
		if ( '' === $minor || null === $minor ) {
			return '';
		}
		return number_format( ( (int) $minor ) / 100, 2, '.', '' );
	}

	/**
	 * Turn a value into a string without notices.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function scalar( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Enforce a maximum length in characters.
	 *
	 * @param string               $clean Clean text.
	 * @param int                  $max   Maximum characters.
	 * @param array<string, mixed> $def   Field definition.
	 * @throws ValidationError When too long.
	 */
	private static function limit( string $clean, int $max, array $def ): string {
		if ( mb_strlen( $clean ) > $max ) {
			throw new ValidationError(
				(string) $def['label'],
				/* translators: %d: maximum number of characters. */
				sprintf( __( 'Keep this to %d characters or fewer.', 'estasoft-handover-passport' ), $max )
			);
		}
		return $clean;
	}

	/**
	 * Parse lines of text or addresses.
	 *
	 * @param array<string, mixed> $def   Field definition.
	 * @param mixed                $value Array or multi-line text.
	 * @param bool                 $urls  Whether each line must be a web address.
	 * @return list<string>
	 * @throws ValidationError When a line is invalid or there are too many.
	 */
	private static function lines( array $def, $value, bool $urls ): array {
		$lines = is_array( $value ) ? array_map( array( self::class, 'scalar' ), $value ) : Str::lines( self::scalar( $value ) );
		$out   = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( $urls ) {
				$line = esc_url_raw( $line, array( 'http', 'https' ) );
				if ( '' === $line ) {
					throw new ValidationError( (string) $def['label'], __( 'Each link must be a full web address starting with http:// or https://.', 'estasoft-handover-passport' ) );
				}
			} else {
				$line = sanitize_text_field( $line );
			}
			$out[] = self::limit( $line, 500, $def );
		}
		$max = isset( $def['max'] ) ? (int) $def['max'] : 20;
		if ( count( $out ) > $max ) {
			throw new ValidationError(
				(string) $def['label'],
				/* translators: %d: maximum number of lines. */
				sprintf( __( 'Use at most %d lines.', 'estasoft-handover-passport' ), $max )
			);
		}
		return $out;
	}

	/**
	 * Parse a list of IDs from an array or comma-separated text.
	 *
	 * @param array<string, mixed> $def   Field definition.
	 * @param mixed                $value Raw value.
	 * @return list<int>
	 * @throws ValidationError When there are too many.
	 */
	private static function ids( array $def, $value ): array {
		if ( ! is_array( $value ) ) {
			$value = '' === trim( self::scalar( $value ) ) ? array() : explode( ',', self::scalar( $value ) );
		}
		$ids = array();
		foreach ( $value as $item ) {
			$id = (int) self::scalar( $item );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		$max = isset( $def['max'] ) ? (int) $def['max'] : 20;
		if ( count( $ids ) > $max ) {
			throw new ValidationError(
				(string) $def['label'],
				/* translators: %d: maximum number of people. */
				sprintf( __( 'Pick at most %d people.', 'estasoft-handover-passport' ), $max )
			);
		}
		return $ids;
	}
}
