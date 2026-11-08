<?php
/**
 * Plain date arithmetic on Y-m-d strings.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Support;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Date helpers that never depend on the server time zone.
 */
final class Dates {

	/**
	 * Whether a string is a real calendar date in Y-m-d form.
	 *
	 * @param mixed $value Candidate.
	 */
	public static function isDate( $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );
		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $value;
	}

	/**
	 * Whole days from one date to another (negative when the second is earlier).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	public static function daysBetween( string $from, string $to ): int {
		$a = new DateTimeImmutable( $from, new DateTimeZone( 'UTC' ) );
		$b = new DateTimeImmutable( $to, new DateTimeZone( 'UTC' ) );
		return (int) $a->diff( $b )->format( '%r%a' );
	}

	/**
	 * Add days to a date.
	 *
	 * @param string $date Y-m-d.
	 * @param int    $days Days to add (may be negative).
	 */
	public static function addDays( string $date, int $days ): string {
		$d = new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) );
		return $d->modify( sprintf( '%+d days', $days ) )->format( 'Y-m-d' );
	}

	/**
	 * Add whole months to a date, clamping to the end of a short month.
	 *
	 * @param string $date   Y-m-d.
	 * @param int    $months Months to add (may be negative).
	 */
	public static function addMonths( string $date, int $months ): string {
		$d     = new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) );
		$day   = (int) $d->format( 'j' );
		$first = $d->modify( 'first day of this month' )->modify( sprintf( '%+d months', $months ) );
		$last  = (int) $first->format( 't' );
		return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), min( $day, $last ) )->format( 'Y-m-d' );
	}

	/**
	 * The date part of a UTC date-time string, or an empty string.
	 *
	 * @param string|null $datetime Y-m-d H:i:s.
	 */
	public static function datePart( ?string $datetime ): string {
		return ( null === $datetime || strlen( $datetime ) < 10 ) ? '' : substr( $datetime, 0, 10 );
	}

	/**
	 * Format a stored UTC date-time in the site time zone and date format.
	 *
	 * @param string|null $datetime Y-m-d H:i:s in UTC.
	 * @param bool        $with_time Include the time.
	 */
	public static function display( ?string $datetime, bool $with_time = false ): string {
		if ( null === $datetime || '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return '';
		}
		$stamp = strtotime( $datetime . ' UTC' );
		if ( false === $stamp ) {
			return '';
		}
		if ( function_exists( 'wp_date' ) ) {
			$format = (string) get_option( 'date_format', 'j M Y' );
			if ( $with_time ) {
				$format .= ' ' . (string) get_option( 'time_format', 'H:i' );
			}
			return (string) wp_date( $format, $stamp );
		}
		return gmdate( $with_time ? 'j M Y H:i' : 'j M Y', $stamp );
	}

	/**
	 * Format an entered plain date (Y-m-d) in the site date format.
	 *
	 * @param string|null $date Y-m-d.
	 */
	public static function displayDate( ?string $date ): string {
		if ( null === $date || ! self::isDate( $date ) ) {
			return '';
		}
		$stamp = strtotime( $date . ' 12:00:00 UTC' );
		if ( false === $stamp ) {
			return '';
		}
		if ( function_exists( 'wp_date' ) ) {
			return (string) wp_date( (string) get_option( 'date_format', 'j M Y' ), $stamp, new DateTimeZone( 'UTC' ) );
		}
		return gmdate( 'j M Y', $stamp );
	}

	/**
	 * A date in words relative to today: "in 25 days", "9 days ago", "today", "tomorrow" or "yesterday".
	 *
	 * @param string $date  Y-m-d.
	 * @param string $today Y-m-d.
	 */
	public static function relative( string $date, string $today ): string {
		if ( ! self::isDate( $date ) || ! self::isDate( $today ) ) {
			return '';
		}
		$days = self::daysBetween( $today, $date );
		if ( 0 === $days ) {
			return __( 'today', 'estasoft-handover-passport' );
		}
		if ( 1 === $days ) {
			return __( 'tomorrow', 'estasoft-handover-passport' );
		}
		if ( -1 === $days ) {
			return __( 'yesterday', 'estasoft-handover-passport' );
		}
		if ( $days > 0 ) {
			/* translators: %d: number of days from today. */
			return sprintf( __( 'in %d days', 'estasoft-handover-passport' ), $days );
		}
		/* translators: %d: number of days before today. */
		return sprintf( __( '%d days ago', 'estasoft-handover-passport' ), -$days );
	}
}
