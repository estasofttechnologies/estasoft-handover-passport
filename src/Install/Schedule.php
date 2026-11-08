<?php
/**
 * Scheduled events (WPI-3). Registered once and removed on deactivation.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Install;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Daily scan, reminders, retention, plus the weekly digest and URL check.
 */
final class Schedule {

	public const SCAN       = 'estasoft_hp_daily_scan';
	public const REMINDERS  = 'estasoft_hp_daily_reminders';
	public const RETENTION  = 'estasoft_hp_daily_retention';
	public const DIGEST     = 'estasoft_hp_weekly_digest';
	public const URLS       = 'estasoft_hp_weekly_urls';
	public const COPIES     = 'estasoft_hp_monthly_copy';
	public const EVENT_SCAN = 'estasoft_hp_event_scan';

	/**
	 * Event hook, first run time (site time, HH:MM) and recurrence.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function events(): array {
		return array(
			self::SCAN      => array( '03:00', 'daily' ),
			self::RETENTION => array( '04:00', 'daily' ),
			self::REMINDERS => array( '07:00', 'daily' ),
			self::DIGEST    => array( 'monday 08:00', 'weekly' ),
			self::URLS      => array( 'sunday 05:00', 'weekly' ),
			self::COPIES    => array( '06:00', 'daily' ),
		);
	}

	/**
	 * Register each event once.
	 */
	public static function register(): void {
		foreach ( self::events() as $hook => $when ) {
			if ( false === wp_next_scheduled( $hook ) ) {
				wp_schedule_event( self::firstRun( $when[0] ), $when[1], $hook );
			}
		}
	}

	/**
	 * Remove every event.
	 */
	public static function clear(): void {
		foreach ( array_keys( self::events() ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		wp_clear_scheduled_hook( self::EVENT_SCAN );
	}

	/**
	 * Move the daily scan to a new time of day, site time (Settings).
	 *
	 * @param string $time HH:MM.
	 */
	public static function rescheduleScan( string $time ): void {
		if ( 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			return;
		}
		wp_clear_scheduled_hook( self::SCAN );
		wp_schedule_event( self::firstRun( $time ), 'daily', self::SCAN );
	}

	/**
	 * Ask for one scan soon, once, after an event that changes the stack (SCN-6).
	 *
	 * @param int $delay Seconds from now.
	 */
	public static function queueScan( int $delay = 60 ): void {
		if ( false === wp_next_scheduled( self::EVENT_SCAN ) ) {
			wp_schedule_single_event( time() + max( 5, $delay ), self::EVENT_SCAN );
		}
	}

	/**
	 * Next time a "HH:MM" or "weekday HH:MM" slot occurs in site time, as a Unix time.
	 *
	 * @param string $slot Slot text.
	 */
	private static function firstRun( string $slot ): int {
		$zone = wp_timezone();
		$now  = new DateTimeImmutable( 'now', $zone );
		try {
			if ( str_contains( $slot, ' ' ) ) {
				// "monday 08:00": the next such weekday and time after now.
				$next = new DateTimeImmutable( 'next ' . $slot, $zone );
			} else {
				// "03:00": today if still ahead, otherwise tomorrow.
				$today = new DateTimeImmutable( 'today ' . $slot, $zone );
				$next  = $today > $now ? $today : $today->modify( '+1 day' );
			}
		} catch ( \Exception $e ) {
			return $now->modify( '+1 hour' )->getTimestamp();
		}
		return $next->setTimezone( new DateTimeZone( 'UTC' ) )->getTimestamp();
	}
}
