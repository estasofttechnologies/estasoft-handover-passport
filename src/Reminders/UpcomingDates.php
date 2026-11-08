<?php
/**
 * Dates coming up, shared by the Overview, the reminders and the weekly summary.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Reminders;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Services\RecordService;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Records with a real renewal or expiry date, plus the end dates of certificates the plugin checked. Review dates that are
 * worked out from a "last confirmed" day (backups, access) are not dates anybody renews, so they are left out.
 */
final class UpcomingDates {

	/**
	 * Constructor.
	 *
	 * @param RecordService $records Records.
	 */
	public function __construct( private RecordService $records ) {}

	/**
	 * Dates between two days, oldest first.
	 *
	 * @param string $from              First day, Y-m-d.
	 * @param string $to                Last day, Y-m-d.
	 * @param bool   $include_cancelled Whether a record marked as cancelled is listed.
	 * @return list<array{id: int, kind: string, kind_label: string, title: string, date: string, sensitive: bool, certificate: bool}>
	 */
	public function between( string $from, string $to, bool $include_cancelled = true ): array {
		$rows = array();
		foreach ( $this->records->items()->dueBetween( $from, $to ) as $row ) {
			$kind = (string) $row['kind'];
			$def  = Kinds::get( $kind );
			if ( null === $def || empty( $def['due'] ) || ! in_array( $kind, Kinds::registerKinds(), true ) ) {
				continue;
			}
			$data = is_array( $row['data'] ) ? $row['data'] : array();
			if ( ! $include_cancelled && 'yes' === ( $data['cancelled'] ?? '' ) ) {
				continue;
			}
			$rows[] = array(
				'id'          => (int) $row['id'],
				'kind'        => $kind,
				'kind_label'  => (string) $def['label'],
				'title'       => (string) $row['title'],
				'date'        => (string) $row['due_date'],
				'sensitive'   => (bool) $row['sensitive'],
				'certificate' => false,
			);
		}
		foreach ( $this->records->items()->ofKind( Kinds::SSL ) as $cert ) {
			$valid_to = (string) ( ( is_array( $cert['data'] ) ? $cert['data'] : array() )['valid_to'] ?? '' );
			if ( Dates::isDate( $valid_to ) && $valid_to >= $from && $valid_to <= $to ) {
				$rows[] = array(
					'id'          => (int) $cert['id'],
					'kind'        => Kinds::SSL,
					'kind_label'  => __( 'Certificate', 'estasoft-handover-passport' ),
					'title'       => (string) $cert['title'],
					'date'        => $valid_to,
					'sensitive'   => (bool) $cert['sensitive'],
					'certificate' => true,
				);
			}
		}
		usort( $rows, static fn( array $a, array $b ): int => strcmp( $a['date'], $b['date'] ) );
		return $rows;
	}
}
