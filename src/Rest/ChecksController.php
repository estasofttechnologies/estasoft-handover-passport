<?php
/**
 * REST: checks started by a caller, limited to one a minute for each subject (SEC-9, API-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Rest;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /checks. They look at the domain and critical-address records that exist, and at this site's own host.
 */
final class ChecksController extends BaseController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'checks';

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
		$record = array( 'record_id' => array( 'description' => __( 'The number of the record to check.', 'estasoft-handover-passport' ), 'type' => 'integer', 'required' => true, 'minimum' => 1 ) );
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/dns',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dns' ),
				'permission_callback' => array( $this, 'canEdit' ),
				'args'                => $record,
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/url',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'url' ),
				'permission_callback' => array( $this, 'canEdit' ),
				'args'                => $record,
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/ssl',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ssl' ),
				'permission_callback' => array( $this, 'canEdit' ),
				'args'                => array( 'host' => array( 'description' => __( 'This site\'s own host name. Empty uses the site address.', 'estasoft-handover-passport' ), 'type' => 'string', 'maxLength' => 190 ) ),
			)
		);
	}

	/**
	 * Read the DNS of a domain record from this server.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function dns( $request ) {
		$record = $this->record( $request, Kinds::DOMAIN );
		if ( null === $record ) {
			return $this->notFound();
		}
		$domain = strtolower( rtrim( trim( (string) ( $record['fields']['domain'] ?? '' ) ), '.' ) );
		$result = $this->plugin->checks()->dns( $domain, 'rest' );
		switch ( $result['status'] ) {
			case 'ok':
				return new WP_REST_Response( array( 'status' => 'ok', 'changed' => array_keys( (array) ( $result['changes'] ?? array() ) ) ) );
			case 'wait':
				return $this->wait( (int) ( $result['wait'] ?? 60 ) );
			case 'invalid':
				return $this->error( 'invalid', __( 'That record does not hold a domain name that can be looked up.', 'estasoft-handover-passport' ), 400 );
			default:
				return $this->error( 'check_failed', __( 'This server could not read any DNS records for that domain. The last snapshot was kept.', 'estasoft-handover-passport' ), 502 );
		}
	}

	/**
	 * Check a critical address.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function url( $request ) {
		$record = $this->record( $request, Kinds::URL );
		if ( null === $record ) {
			return $this->notFound();
		}
		$result = $this->plugin->checks()->url( (int) $record['id'] );
		switch ( $result['status'] ) {
			case 'ok':
				return new WP_REST_Response( array( 'status' => 'ok', 'ok' => ! empty( $result['result']['ok'] ), 'checked_at' => $this->time( $result['result']['checked_at'] ?? '' ) ) );
			case 'wait':
				return $this->wait( (int) ( $result['wait'] ?? 60 ) );
			case 'refused':
				return $this->error( 'refused', __( 'That address is not on this site, so it cannot be checked.', 'estasoft-handover-passport' ), 400 );
			default:
				return $this->notFound();
		}
	}

	/**
	 * Read the certificate of this site's own host.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ssl( $request ) {
		$checks = $this->plugin->checks();
		$host   = strtolower( trim( (string) $request->get_param( 'host' ) ) );
		if ( '' === $host ) {
			$host = (string) ( $checks->siteHosts()[0] ?? '' );
		}
		$result = $checks->ssl( $host );
		switch ( $result['status'] ) {
			case 'ok':
				return new WP_REST_Response( array( 'status' => 'ok', 'valid_to' => (string) ( $result['certificate']['valid_to'] ?? '' ) ) );
			case 'wait':
				return $this->wait( (int) ( $result['wait'] ?? 60 ) );
			case 'refused':
				return $this->error( 'refused', __( 'Only this site\'s own host can be checked for a certificate.', 'estasoft-handover-passport' ), 400 );
			default:
				return $this->error( 'check_failed', __( 'The certificate could not be read from this server. The last reading was kept.', 'estasoft-handover-passport' ), 502 );
		}
	}

	/**
	 * The record named in the request when it is of the right kind and the caller may see it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $kind    Kind needed.
	 * @return array<string, mixed>|null
	 */
	private function record( WP_REST_Request $request, string $kind ): ?array {
		$record = $this->plugin->records()->get( (int) $request->get_param( 'record_id' ), true );
		if ( null === $record || $kind !== $record['kind'] || ( $record['sensitive'] && ! Capabilities::canSeeSensitive() ) ) {
			return null;
		}
		return $record;
	}
}
