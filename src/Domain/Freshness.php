<?php
/**
 * Freshness of a record (spec section 5, PRT-3, PRT-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Domain;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Verified until the review interval ends, Due for the next days, then Stale.
 */
final class Freshness {

	public const VERIFIED = 'verified';
	public const DUE      = 'due';
	public const STALE    = 'stale';

	/**
	 * Freshness state.
	 *
	 * @param string|null $verified_at UTC date-time of the last verification, or null.
	 * @param string      $created_at  UTC date-time the record was made (used when never verified).
	 * @param int         $interval    Review interval in days.
	 * @param int         $due_days    Days a record stays Due before it is Stale.
	 * @param string      $now         UTC date-time now.
	 */
	public static function state( ?string $verified_at, string $created_at, int $interval, int $due_days, string $now ): string {
		$base = new DateTimeImmutable( $verified_at ?: $created_at, new DateTimeZone( 'UTC' ) );
		$due  = $base->modify( sprintf( '+%d days', max( 1, $interval ) ) );
		$stale = $due->modify( sprintf( '+%d days', max( 0, $due_days ) ) );
		$at   = new DateTimeImmutable( $now, new DateTimeZone( 'UTC' ) );

		if ( $at < $due ) {
			return self::VERIFIED;
		}
		return $at < $stale ? self::DUE : self::STALE;
	}

	/**
	 * Plain label for a state.
	 *
	 * @param string $state One of the constants.
	 */
	public static function label( string $state ): string {
		switch ( $state ) {
			case self::VERIFIED:
				return __( 'Verified', 'estasoft-handover-passport' );
			case self::DUE:
				return __( 'Due', 'estasoft-handover-passport' );
			default:
				return __( 'Stale', 'estasoft-handover-passport' );
		}
	}
}
