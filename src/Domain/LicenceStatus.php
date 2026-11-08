<?php
/**
 * Computed licence status (LIC-3). The status is never stored.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Domain;

use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Expired, Renews soon, Active, Cancelled or Unknown.
 */
final class LicenceStatus {

	public const EXPIRED   = 'expired';
	public const SOON      = 'renews_soon';
	public const ACTIVE    = 'active';
	public const CANCELLED = 'cancelled';
	public const UNKNOWN   = 'unknown';
	public const FREE      = 'free';

	/**
	 * Compute the status of a record.
	 *
	 * @param array<string, mixed> $data      Record data (with renews_on, cancelled, free).
	 * @param string               $today     Today as Y-m-d in site time.
	 * @param int                  $lead_days Days before the date that count as "renews soon".
	 */
	public static function compute( array $data, string $today, int $lead_days = 30 ): string {
		if ( 'yes' === ( $data['free'] ?? 'no' ) ) {
			return self::FREE;
		}
		if ( 'yes' === ( $data['cancelled'] ?? 'no' ) ) {
			return self::CANCELLED;
		}
		$date = (string) ( $data['renews_on'] ?? '' );
		if ( ! Dates::isDate( $date ) ) {
			return self::UNKNOWN;
		}
		$days = Dates::daysBetween( $today, $date );
		if ( $days < 0 ) {
			return self::EXPIRED;
		}
		return $days <= $lead_days ? self::SOON : self::ACTIVE;
	}

	/**
	 * Plain label for a status.
	 *
	 * @param string $status One of the constants.
	 */
	public static function label( string $status ): string {
		$labels = array(
			self::EXPIRED   => __( 'Expired', 'estasoft-handover-passport' ),
			self::SOON      => __( 'Renews soon', 'estasoft-handover-passport' ),
			self::ACTIVE    => __( 'Active', 'estasoft-handover-passport' ),
			self::CANCELLED => __( 'Cancelled', 'estasoft-handover-passport' ),
			self::UNKNOWN   => __( 'Unknown', 'estasoft-handover-passport' ),
			self::FREE      => __( 'Free', 'estasoft-handover-passport' ),
		);
		return $labels[ $status ] ?? $labels[ self::UNKNOWN ];
	}
}
