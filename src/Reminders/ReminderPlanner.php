<?php
/**
 * Decides which reminders are due today (RMD-1, RMD-4, RMD-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Reminders;

use Estasoft\HandoverPassport\Domain\LeadTimes;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Pure planning. It reads plain records and a lookup into the sent log, and touches nothing else.
 */
final class ReminderPlanner {

	public const MAX_ATTEMPTS = 3;

	/**
	 * Reminders to send now.
	 *
	 * Each record is array{id: int, due: string, leads: array<int, int>, skip: bool}. The log lookup gets the record ID, the
	 * cycle key and the lead time, and returns the log row (with status, attempts and sent_at) or null.
	 *
	 * @param array<int, array<string, mixed>>                        $records Records with a date.
	 * @param string                                                  $today   Today, site time, Y-m-d.
	 * @param callable(int, string, int): (array<string, mixed>|null) $lookup  Finds a log row.
	 * @return array<int, array{id: int, lead: int, cycle: string, days_left: int, attempt: int, log_id: int}>
	 */
	public static function plan( array $records, string $today, callable $lookup ): array {
		$out = array();
		foreach ( $records as $record ) {
			$due = (string) ( $record['due'] ?? '' );
			if ( ! empty( $record['skip'] ) || ! Dates::isDate( $due ) ) {
				continue;
			}
			$days_left = Dates::daysBetween( $today, $due );
			$lead      = LeadTimes::reached( $days_left, (array) ( $record['leads'] ?? array() ) );
			if ( null === $lead ) {
				continue;
			}
			$id    = (int) $record['id'];
			$cycle = LeadTimes::cycleKey( $due );
			$row   = $lookup( $id, $cycle, $lead );
			$step  = array(
				'id'        => $id,
				'lead'      => $lead,
				'cycle'     => $cycle,
				'days_left' => $days_left,
				'attempt'   => 1,
				'log_id'    => 0,
			);
			if ( null !== $row ) {
				if ( 'failed' !== (string) $row['status'] ) {
					continue; // Sent once for this cycle and lead time: never again.
				}
				$attempts = (int) $row['attempts'];
				if ( $attempts >= self::MAX_ATTEMPTS || self::attemptedToday( (string) $row['sent_at'], $today ) ) {
					continue; // Gave up, or already tried today: the next try is tomorrow.
				}
				$step['attempt'] = $attempts + 1;
				$step['log_id']  = (int) $row['id'];
			}
			$out[] = $step;
		}
		return $out;
	}

	/**
	 * Whether an attempt happened today. The stored time is UTC and today is a site-time date, so the attempt is
	 * compared by the site-time date it falls on.
	 *
	 * @param string $sent_at UTC date-time of the attempt.
	 * @param string $today   Today, site time, Y-m-d.
	 */
	private static function attemptedToday( string $sent_at, string $today ): bool {
		$stamp = strtotime( $sent_at . ' UTC' );
		if ( false === $stamp ) {
			return false;
		}
		$day = function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d', $stamp ) : gmdate( 'Y-m-d', $stamp );
		return $day === $today;
	}
}
