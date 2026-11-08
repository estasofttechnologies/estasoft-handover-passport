<?php
/**
 * Lead times for reminders (RMD-1, RMD-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Pure: reads lead times from text and decides which one a date has reached.
 */
final class LeadTimes {

	public const DEFAULT = array( 60, 30, 14, 7, 1, 0 );
	public const MAX_DAY = 365;
	public const MAX_LEN = 12;
	public const OFF     = 'off';

	/**
	 * Days from text such as "30, 7, 1, 0". Anything that is not a whole number from 0 to 365 is dropped;
	 * the result is unique and sorted from the longest lead to the shortest.
	 *
	 * @param string $text Comma or space separated numbers.
	 * @return list<int>
	 */
	public static function parse( string $text ): array {
		$days = array();
		foreach ( preg_split( '/[\s,;]+/', trim( $text ) ) ?: array() as $part ) {
			if ( '' !== $part && ctype_digit( $part ) && (int) $part <= self::MAX_DAY ) {
				$days[ (int) $part ] = (int) $part;
			}
		}
		rsort( $days );
		return array_slice( array_values( $days ), 0, self::MAX_LEN );
	}

	/**
	 * Clean a list that came from settings or a filter.
	 *
	 * @param mixed $value List of numbers.
	 * @return list<int>
	 */
	public static function clean( $value ): array {
		return self::parse( implode( ',', array_filter( array_map( static fn( $v ): string => is_scalar( $v ) ? (string) $v : '', (array) $value ), 'strlen' ) ) );
	}

	/**
	 * A per-record setting typed in a form: empty to use the Settings list, "off" for no reminders, or a list of days.
	 *
	 * @param string $text What was typed.
	 * @return string|null The stored form ('' , 'off' or "30, 7, 0"), or null when something is not a day count.
	 */
	public static function recordSetting( string $text ): ?string {
		$text = strtolower( trim( $text ) );
		if ( '' === $text ) {
			return '';
		}
		if ( self::OFF === $text || 'none' === $text ) {
			return self::OFF;
		}
		$parts = preg_split( '/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		foreach ( $parts as $part ) {
			if ( ! ctype_digit( $part ) || (int) $part > self::MAX_DAY ) {
				return null;
			}
		}
		$days = self::parse( $text );
		return array() === $days ? null : implode( ', ', $days );
	}

	/**
	 * The lead times that apply to one record.
	 *
	 * @param string    $setting A stored per-record setting.
	 * @param list<int> $default The list from Settings.
	 * @return list<int> Empty when reminders are off for the record.
	 */
	public static function forRecord( string $setting, array $default ): array {
		if ( self::OFF === $setting ) {
			return array();
		}
		$days = self::parse( $setting );
		return array() === $days ? $default : $days;
	}

	/**
	 * The lead time a date has reached: the shortest lead that is still at least as long as the days left.
	 * A date 20 days away has reached the 30-day lead; one 14 days away has reached the 14-day lead. Once the date has
	 * passed, only the on-the-day lead (0) counts, and only for a few days, so a missed run still sends it.
	 *
	 * @param int       $days_left   Days from today to the date; negative once it has passed.
	 * @param list<int> $leads       Lead times, any order.
	 * @param int       $grace_days  How many days after the date the on-the-day reminder may still go out.
	 * @return int|null The lead time, or null when none applies yet or any more.
	 */
	public static function reached( int $days_left, array $leads, int $grace_days = 3 ): ?int {
		if ( $days_left < -$grace_days ) {
			return null;
		}
		$days_left = max( 0, $days_left );
		$best      = null;
		foreach ( $leads as $lead ) {
			$lead = (int) $lead;
			if ( $lead >= $days_left && ( null === $best || $lead < $best ) ) {
				$best = $lead;
			}
		}
		return $best;
	}

	/**
	 * The key of a reminder cycle. A new date starts a new cycle, so marking a record renewed re-arms its reminders.
	 *
	 * @param string $due_date Date, Y-m-d.
	 */
	public static function cycleKey( string $due_date ): string {
		return $due_date;
	}
}
