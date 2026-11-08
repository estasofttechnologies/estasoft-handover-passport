<?php
/**
 * Time source. All times are stored in UTC; dates entered by people are plain dates.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Support;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * A replaceable clock so reminders and freshness can be tested with a faked time.
 */
final class Clock {

	/**
	 * Frozen moment used in tests, or null for real time.
	 *
	 * @var DateTimeImmutable|null
	 */
	private static ?DateTimeImmutable $frozen = null;

	/**
	 * Freeze the clock at a moment, or pass null to release it.
	 *
	 * @param string|DateTimeImmutable|null $moment A UTC date-time string or object.
	 */
	public static function freeze( $moment ): void {
		if ( null === $moment ) {
			self::$frozen = null;
			return;
		}
		self::$frozen = $moment instanceof DateTimeImmutable
			? $moment->setTimezone( new DateTimeZone( 'UTC' ) )
			: new DateTimeImmutable( $moment, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Current moment in UTC.
	 */
	public static function now(): DateTimeImmutable {
		return self::$frozen ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Current moment as a database date-time string (UTC).
	 */
	public static function sql(): string {
		return self::now()->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Today's date in the site time zone as Y-m-d.
	 */
	public static function today(): string {
		$zone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		return self::now()->setTimezone( $zone )->format( 'Y-m-d' );
	}
}
