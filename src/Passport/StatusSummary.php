<?php
/**
 * A short summary of how the Passport stands, shared by the command line and the REST API.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Passport;

use Estasoft\HandoverPassport\Domain\Freshness;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Reminders\UpcomingDates;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Counts and dates. A Sensitive record is left out of every count unless the caller may see Sensitive records, so a count
 * cannot reveal that one exists.
 */
final class StatusSummary {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * The numbers.
	 *
	 * @param bool $with_sensitive Whether Sensitive records are counted.
	 * @return array<string, mixed>
	 */
	public function counts( bool $with_sensitive ): array {
		$findings  = $this->plugin->findings();
		$readiness = $findings->readiness();

		$fresh = array(
			Freshness::VERIFIED => 0,
			Freshness::DUE      => 0,
			Freshness::STALE    => 0,
		);
		$by_kind = array();
		$total   = 0;
		foreach ( $this->plugin->records()->everything() as $record ) {
			if ( ! in_array( (string) $record['kind'], Kinds::registerKinds(), true ) || ( $record['sensitive'] && ! $with_sensitive ) ) {
				continue;
			}
			++$total;
			$by_kind[ (string) $record['kind'] ] = ( $by_kind[ (string) $record['kind'] ] ?? 0 ) + 1;
			if ( isset( $fresh[ (string) $record['freshness'] ] ) ) {
				++$fresh[ (string) $record['freshness'] ];
			}
		}

		$dates = $this->upcoming( $with_sensitive, 30 );
		$scan  = $this->plugin->scanService()->latest();

		return array(
			'findings'           => $findings->counts(),
			'readiness'          => array(
				'ready' => (int) $readiness['ready'],
				'total' => (int) $readiness['total'],
				'rules' => $readiness['rules'],
			),
			'records'            => array(
				'total'    => $total,
				'verified' => $fresh[ Freshness::VERIFIED ],
				'due'      => $fresh[ Freshness::DUE ],
				'stale'    => $fresh[ Freshness::STALE ],
				'by_kind'  => $by_kind,
			),
			'dates_next_30_days' => count( $dates ),
			'next_date'          => array() === $dates ? '' : (string) $dates[0]['date'],
			'last_scan'          => null === $scan ? null : array(
				'result'      => (string) $scan['status'],
				'finished_at' => (string) ( $scan['finished_at'] ?? '' ),
			),
		);
	}

	/**
	 * Dates that fall in the next days, oldest first.
	 *
	 * @param bool $with_sensitive Whether Sensitive records are listed.
	 * @param int  $days           How many days ahead.
	 * @return list<array{id: int, kind: string, kind_label: string, title: string, date: string, sensitive: bool, certificate: bool}>
	 */
	public function upcoming( bool $with_sensitive, int $days ): array {
		$today = Clock::today();
		$rows  = ( new UpcomingDates( $this->plugin->records() ) )->between( $today, Dates::addDays( $today, $days ) );
		return array_values( array_filter( $rows, static fn( array $row ): bool => $with_sensitive || ! $row['sensitive'] ) );
	}
}
