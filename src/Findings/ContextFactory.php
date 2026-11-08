<?php
/**
 * Builds the rule context from the live Passport.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Findings;

use Estasoft\HandoverPassport\Data\RunbookRepository;
use Estasoft\HandoverPassport\Data\SnapshotRepository;
use Estasoft\HandoverPassport\Data\TestRepository;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Scan\ServiceMap;
use Estasoft\HandoverPassport\Scan\SupportDates;
use Estasoft\HandoverPassport\Services\PeopleService;
use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Reads everything the rules need once, so the rules themselves stay free of queries.
 */
final class ContextFactory {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Build the context.
	 *
	 * @param bool $verify_snapshots Check every snapshot hash again.
	 */
	public function build( bool $verify_snapshots = false ): Context {
		$settings = $this->plugin->settings();
		$scans    = $this->plugin->scanService();
		$facts    = $scans->latestFacts();

		$items = array();
		foreach ( $this->plugin->records()->everything() as $record ) {
			$items[ (string) $record['kind'] ][] = $record;
		}

		$people_service = $this->plugin->people();
		$people         = $people_service->all();
		$today          = Clock::today();
		$assets         = array();
		foreach ( $people as $person ) {
			$left = (string) ( $person['left_on'] ?? '' );
			if ( ( '' !== $left && $left <= $today ) || empty( $person['active'] ) ) {
				$assets[ (int) $person['id'] ] = $people_service->assetsFor( (int) $person['id'] );
			}
		}
		$holders = array();
		foreach ( array_keys( PeopleService::roles() ) as $role ) {
			$holders[ $role ] = $people_service->inRole( $role );
		}

		$snapshots = new SnapshotRepository();
		$count     = $snapshots->count();

		return new Context(
			$today,
			Clock::sql(),
			$facts,
			$scans->latest(),
			$scans->hoursSinceLast(),
			$items,
			$people,
			$assets,
			$holders,
			( new RunbookRepository() )->allLive(),
			( new TestRepository() )->restoreTests(),
			$count,
			$count > 0 ? ( new SnapshotIntegrity( $snapshots ) )->mismatches( $verify_snapshots ) : array(),
			array_map( 'intval', (array) $settings->get( 'thresholds', array() ) ),
			(array) $settings->get( 'recovery', array() ),
			array_map( 'intval', (array) $settings->get( 'review_days', array() ) ),
			(int) $settings->get( 'due_days', 14 ),
			array(
				'reminder_health' => (array) get_option( 'estasoft_hp_reminder_health', array() ),
				'copy_guard'      => (array) get_option( 'estasoft_hp_copy_guard', array() ),
			),
			new SupportDates(),
			isset( $facts['plugins'] ) ? ( new ServiceMap() )->suggest( (array) $facts['plugins'] ) : array()
		);
	}
}
