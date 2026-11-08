<?php
/**
 * REST routes (API-1 to API-6). Every route has a real permission callback.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Rest;

use Estasoft\HandoverPassport\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the controllers in the estasoft-passport/v1 namespace.
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
	 * Register the routes and the two filters that keep errors in the Passport's own shape.
	 */
	public function register(): void {
		foreach ( $this->controllers() as $controller ) {
			$controller->register_routes();
		}
		add_filter( 'rest_request_before_callbacks', array( $this, 'prefixErrors' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'addRetryAfter' ), 10, 3 );
	}

	/**
	 * Every controller.
	 *
	 * @return array<int, BaseController>
	 */
	private function controllers(): array {
		return array(
			new PassportController( $this->plugin ),
			new RecordsController( $this->plugin ),
			new PeopleController( $this->plugin ),
			new RunbooksController( $this->plugin ),
			new TestsController( $this->plugin ),
			new FindingsController( $this->plugin ),
			new ChangesController( $this->plugin ),
			new ChecksController( $this->plugin ),
			new ScansController( $this->plugin ),
		);
	}

	/**
	 * A request that fails WordPress's own check of its parameters gets an estasoft_hp_ code like every other failure
	 * (API-5). The text stays WordPress's, which names the parameter and never the value sent. Someone who may not use
	 * the route at all is refused first.
	 *
	 * @param WP_REST_Response|WP_Error|mixed $response Result so far.
	 * @param array<string, mixed>            $handler  Route handler.
	 * @param WP_REST_Request                 $request  Request.
	 * @return mixed
	 */
	public function prefixErrors( $response, $handler, $request ) {
		if ( ! $response instanceof WP_Error || 0 !== strpos( $request->get_route(), '/' . self::NAMESPACE . '/' ) ) {
			return $response;
		}
		$map  = array(
			'rest_invalid_param'          => 'estasoft_hp_invalid',
			'rest_missing_callback_param' => 'estasoft_hp_missing',
		);
		$code = $response->get_error_code();
		if ( ! isset( $map[ $code ] ) ) {
			return $response;
		}
		// WordPress checks the parameters before it asks who is calling, so a person with no right to the route would be
		// told what is wrong with the request. Ask who is calling first, and answer with that if they may not enter.
		$permission = $handler['permission_callback'] ?? null;
		if ( is_callable( $permission ) ) {
			$allowed = call_user_func( $permission, $request );
			if ( $allowed instanceof WP_Error ) {
				return $allowed;
			}
			if ( true !== $allowed ) {
				return new WP_Error( 'estasoft_hp_forbidden', __( 'You are not allowed to do that.', 'estasoft-handover-passport' ), array( 'status' => rest_authorization_required_code() ) );
			}
		}
		$status = 400;
		$data   = $response->get_error_data( $code );
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			$status = (int) $data['status'];
		}
		return new WP_Error( $map[ $code ], $response->get_error_message(), array( 'status' => $status ) );
	}

	/**
	 * Tell a client how long to wait when a limit was reached (SEC-9).
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 */
	public function addRetryAfter( $response, $server, $request ) {
		if ( $response instanceof WP_REST_Response && 429 === $response->get_status() && 0 === strpos( $request->get_route(), '/' . self::NAMESPACE . '/' ) ) {
			$data = $response->get_data();
			if ( is_array( $data ) && isset( $data['data']['retry_after'] ) ) {
				$response->header( 'Retry-After', (string) (int) $data['data']['retry_after'] );
			}
		}
		return $response;
	}
}
