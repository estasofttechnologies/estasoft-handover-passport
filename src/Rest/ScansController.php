<?php
/**
 * REST: scans.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Rest;

use Estasoft\HandoverPassport\Scan\ScanService;
use Estasoft\HandoverPassport\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /scans. A scan can be started once a minute (SEC-9, API-6).
 */
final class ScansController extends BaseController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'scans';

	/**
	 * {@inheritDoc}
	 */
	protected function orderbyChoices(): array {
		return array( 'id' );
	}

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/latest',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'latest' ),
				'permission_callback' => array( $this, 'canView' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( $this, 'canEdit' ),
			)
		);
	}

	/**
	 * The latest finished scan. Sensitive facts are removed for people without the capability (CAP-4).
	 *
	 * @param WP_REST_Request $request Request, not used.
	 */
	public function latest( $request ): WP_REST_Response {
		$service = $this->plugin->scanService();
		$latest  = $service->latest();
		$facts   = $service->latestFacts();
		if ( ! Capabilities::canSeeSensitive() ) {
			$facts = ScanService::redact( $facts );
		}
		return new WP_REST_Response(
			array(
				'status'      => null === $latest ? 'none' : (string) $latest['status'],
				'started_at'  => null === $latest ? null : $this->time( $latest['started_at'] ),
				'finished_at' => null === $latest ? null : $this->time( $latest['finished_at'] ),
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
	public function run( $request ) {
		$result = $this->plugin->scanService()->runOnDemand( 'rest' );
		if ( 'wait' === $result['status'] ) {
			return $this->wait( (int) ( $result['wait'] ?? 60 ) );
		}
		if ( 'busy' === $result['status'] ) {
			return $this->error( 'scan_busy', __( 'A scan is already running. Try again in a minute.', 'estasoft-handover-passport' ), 409 );
		}
		if ( 'done' !== $result['status'] ) {
			return $this->error( 'scan_failed', __( 'The scan could not finish. Nothing was changed.', 'estasoft-handover-passport' ), 500 );
		}
		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * The schema of the result of a scan.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		return $this->add_additional_fields_schema(
			array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'estasoft-passport-scan',
				'type'       => 'object',
				'properties' => array(
					'status'      => array( 'type' => 'string', 'readonly' => true ),
					'started_at'  => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time', 'readonly' => true ),
					'finished_at' => array( 'type' => array( 'string', 'null' ), 'format' => 'date-time', 'readonly' => true ),
					'facts'       => array( 'type' => 'object', 'readonly' => true ),
				),
			)
		);
	}
}
