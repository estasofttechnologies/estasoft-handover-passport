<?php
/**
 * Shared behaviour of the REST controllers (API-1 to API-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Rest;

use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Security\SecretFound;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Permission callbacks, error shapes, paging headers and the collection parameters.
 */
abstract class BaseController extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( protected Plugin $plugin ) {
		$this->namespace = Routes::NAMESPACE;
	}

	/**
	 * Names that the orderby parameter accepts, the first being the default.
	 *
	 * @return list<string>
	 */
	abstract protected function orderbyChoices(): array;

	/**
	 * The direction used when the request names none.
	 */
	protected function defaultOrder(): string {
		return 'asc';
	}

	/**
	 * Permission: may see the Passport.
	 *
	 * @return true|WP_Error
	 */
	public function canView() {
		return $this->requires( Capabilities::VIEW );
	}

	/**
	 * Permission: may change the Passport.
	 *
	 * @return true|WP_Error
	 */
	public function canEdit() {
		return $this->requires( Capabilities::EDIT );
	}

	/**
	 * Permission: may make exports and copies.
	 *
	 * @return true|WP_Error
	 */
	public function canExport() {
		return $this->requires( Capabilities::EXPORT );
	}

	/**
	 * Permission check with the Passport's own error code (API-5).
	 *
	 * @param string $capability One of the Capabilities constants.
	 * @return true|WP_Error
	 */
	protected function requires( string $capability ) {
		if ( Capabilities::can( $capability ) ) {
			return true;
		}
		return $this->error( 'forbidden', __( 'You are not allowed to do that.', 'estasoft-handover-passport' ), (int) rest_authorization_required_code() );
	}

	/**
	 * An error whose code starts with estasoft_hp_ and whose text never repeats what was sent (API-5, SEC-7).
	 *
	 * @param string               $code    Short code without the prefix.
	 * @param string               $message Plain sentence.
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $data    Extra data, such as retry_after.
	 */
	protected function error( string $code, string $message, int $status, array $data = array() ): WP_Error {
		return new WP_Error( 'estasoft_hp_' . $code, $message, array( 'status' => $status ) + $data );
	}

	/**
	 * The same answer for a thing that does not exist and a thing the caller may not see.
	 */
	protected function notFound(): WP_Error {
		return $this->error( 'not_found', __( 'That does not exist.', 'estasoft-handover-passport' ), 404 );
	}

	/**
	 * A refused write, turned into an error.
	 *
	 * @param \Throwable $failure What was thrown.
	 */
	protected function failure( \Throwable $failure ): WP_Error {
		if ( $failure instanceof ValidationError ) {
			return $this->error( 'invalid', $failure->getMessage(), 400, array( 'field' => $failure->field() ) );
		}
		if ( $failure instanceof SecretFound ) {
			return $this->error( 'secret', $failure->getMessage(), 400, array( 'field' => $failure->field() ) );
		}
		return $this->error( 'failed', __( 'The change could not be saved. Nothing was changed.', 'estasoft-handover-passport' ), 500 );
	}

	/**
	 * A limit reached: the time left is in the message and the data, and Routes adds the Retry-After header (SEC-9).
	 *
	 * @param int $seconds Seconds until the next try.
	 */
	protected function wait( int $seconds ): WP_Error {
		$seconds = max( 1, $seconds );
		return $this->error(
			'wait',
			sprintf(
				/* translators: %d: seconds. */
				_n( 'That ran a moment ago. Try again in %d second.', 'That ran a moment ago. Try again in %d seconds.', $seconds, 'estasoft-handover-passport' ),
				$seconds
			),
			429,
			array( 'retry_after' => $seconds )
		);
	}

	/**
	 * The values of the collection parameters.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{search: string, orderby: string, order: string, page: int, per_page: int}
	 */
	protected function listArgs( WP_REST_Request $request ): array {
		return array(
			'search'   => (string) $request->get_param( 'search' ),
			'orderby'  => (string) ( $request->get_param( 'orderby' ) ?? $this->orderbyChoices()[0] ),
			'order'    => (string) ( $request->get_param( 'order' ) ?? $this->defaultOrder() ),
			'page'     => (int) ( $request->get_param( 'page' ) ?? 1 ),
			'per_page' => (int) ( $request->get_param( 'per_page' ) ?? Pager::DEFAULT_PER_PAGE ),
		);
	}

	/**
	 * A page of items with the totals in the headers (API-3).
	 *
	 * @param array{items: list<array<string, mixed>>, total: int, pages: int} $result Output of the pager.
	 */
	protected function listResponse( array $result ): WP_REST_Response {
		$response = new WP_REST_Response( $result['items'] );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $result['pages'] );
		return $response;
	}

	/**
	 * Parameters every collection accepts (API-3).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_collection_params() {
		return array(
			'page'     => array(
				'description'       => __( 'Page of the results.', 'estasoft-handover-passport' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'description'       => __( 'How many results a page holds, at most 100.', 'estasoft-handover-passport' ),
				'type'              => 'integer',
				'default'           => Pager::DEFAULT_PER_PAGE,
				'minimum'           => 1,
				'maximum'           => Pager::MAX_PER_PAGE,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
			'search'   => array(
				'description'       => __( 'Only results that contain this text.', 'estasoft-handover-passport' ),
				'type'              => 'string',
				'maxLength'         => 100,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'orderby'  => array(
				'description' => __( 'What to sort by.', 'estasoft-handover-passport' ),
				'type'        => 'string',
				'default'     => $this->orderbyChoices()[0],
				'enum'        => $this->orderbyChoices(),
			),
			'order'    => array(
				'description' => __( 'Direction of the sort.', 'estasoft-handover-passport' ),
				'type'        => 'string',
				'default'     => $this->defaultOrder(),
				'enum'        => array( 'asc', 'desc' ),
			),
		);
	}

	/**
	 * A stored UTC time as an ISO 8601 time, or an empty string.
	 *
	 * @param mixed $value Stored value (Y-m-d H:i:s).
	 */
	protected function time( $value ): string {
		$text = is_scalar( $value ) ? (string) $value : '';
		return 1 === preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', $text, $m ) ? $m[1] . 'T' . $m[2] . 'Z' : '';
	}

	/**
	 * The id from the address of the request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	protected function id( WP_REST_Request $request ): int {
		return (int) $request->get_param( 'id' );
	}

	/**
	 * Argument definition for the id in an address.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function idArgs(): array {
		return array(
			'id' => array(
				'description' => __( 'Number of the item.', 'estasoft-handover-passport' ),
				'type'        => 'integer',
				'minimum'     => 1,
			),
		);
	}
}
