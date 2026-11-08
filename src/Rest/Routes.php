<?php
/**
 * REST routes (API-1, API-2). Every route has a real permission callback.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Rest;

use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Scan\ScanService;
use Estasoft\HandoverPassport\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the routes in the estasoft-passport/v1 namespace.
 */
final class Routes {

	public const NAMESPACE = 'estasoft-passport/v1';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Register the routes.
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/scans/latest',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'latestScan' ),
				'permission_callback' => static fn(): bool => Capabilities::can( Capabilities::VIEW ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/scans',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'runScan' ),
				'permission_callback' => static fn(): bool => Capabilities::can( Capabilities::EDIT ),
			)
		);
	}

	/**
	 * The latest finished scan. Sensitive facts are removed for people without the capability (CAP-4).
	 */
	public function latestScan(): WP_REST_Response {
		$service = $this->plugin->scanService();
		$latest  = $service->latest();
		$facts   = $service->latestFacts();
		if ( ! Capabilities::canSeeSensitive() ) {
			$facts = ScanService::redact( $facts );
		}
		return new WP_REST_Response(
			array(
				'status'      => null === $latest ? 'none' : (string) $latest['status'],
				'started_at'  => null === $latest ? null : (string) $latest['started_at'],
				'finished_at' => null === $latest ? null : $latest['finished_at'],
				'facts'       => $facts,
			)
		);
	}

	/**
	 * Run a scan.
	 *
	 * @param WP_REST_Request $request Request, not used.
	 * @return WP_REST_Response|WP_Error
	 */
	public function runScan( WP_REST_Request $request ) {
		$result = $this->plugin->scanService()->run( 'rest' );
		if ( 'busy' === $result['status'] ) {
			return new WP_Error( 'estasoft_hp_scan_busy', __( 'A scan is already running. Try again in a minute.', 'estasoft-handover-passport' ), array( 'status' => 409 ) );
		}
		if ( 'done' !== $result['status'] ) {
			return new WP_Error( 'estasoft_hp_scan_failed', __( 'The scan could not finish. Nothing was changed.', 'estasoft-handover-passport' ), array( 'status' => 500 ) );
		}
		return new WP_REST_Response( $result, 201 );
	}
}
