<?php
/**
 * Reads the Passport into the plain data that packs are made from (EXP-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export;

use Estasoft\HandoverPassport\Passport\Passport;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Scan\ScanService;
use Estasoft\HandoverPassport\Services\PeopleService;
use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * The only place where packs touch the services. Everything after this works on arrays.
 */
final class ExportDataLoader {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Read the Passport as it is now.
	 */
	public function load(): ExportData {
		$passport  = $this->plugin->passport();
		$scan      = $this->plugin->scanService();
		$facts     = $scan->latestFacts();
		$latest    = $scan->latest();
		$readiness = $this->plugin->findings()->readiness();
		$status    = (string) $passport->get( 'status', Passport::STATUS_DRAFT );
		$labels    = Passport::statusLabels();

		$roles = array();
		foreach ( array_keys( PeopleService::roles() ) as $role ) {
			$roles[ $role ] = $this->plugin->people()->inRole( $role );
		}

		$site = array(
			'name'         => (string) $passport->get( 'site_name', '' ),
			'url'          => (string) ( $facts['core']['home_url'] ?? home_url() ),
			'client'       => (string) $passport->get( 'client_name', '' ),
			'purpose'      => (string) $passport->get( 'purpose', '' ),
			'status'       => $status,
			'status_label' => (string) ( $labels[ $status ] ?? $status ),
			'readiness'    => array( 'ready' => (int) $readiness['ready'], 'total' => (int) $readiness['total'] ),
			'last_scan'    => null === $latest ? '' : (string) ( $latest['finished_at'] ?? '' ),
			'wp_version'   => (string) ( $facts['core']['wp_version'] ?? '' ),
		);

		return new ExportData(
			$site,
			$this->plugin->records()->everything(),
			$this->plugin->people()->all(),
			$roles,
			$this->plugin->runbooks()->all(),
			$this->plugin->restoreTests()->all(),
			$this->plugin->findings()->list( array( 'state' => 'open' ) ),
			ScanService::redact( $facts ),
			$this->plugin->handover()->items(),
			(string) $passport->get( 'successor_notes', '' ),
			Clock::sql()
		);
	}
}
