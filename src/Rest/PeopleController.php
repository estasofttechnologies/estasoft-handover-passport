<?php
/**
 * REST: the people directory.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Rest;

use Estasoft\HandoverPassport\Domain\Freshness;
use Estasoft\HandoverPassport\Services\PeopleService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /people.
 */
final class PeopleController extends BaseController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'people';

	/**
	 * {@inheritDoc}
	 */
	protected function orderbyChoices(): array {
		return array( 'name', 'organisation', 'updated', 'id' );
	}

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'canView' ),
					'args'                => $this->get_collection_params() + $this->filterArgs(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'canEdit' ),
					'args'                => $this->writeArgs(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'canView' ),
					'args'                => $this->idArgs(),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'canEdit' ),
					'args'                => $this->idArgs() + $this->writeArgs(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'canEdit' ),
					'args'                => $this->idArgs(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'verify_item' ),
				'permission_callback' => array( $this, 'canEdit' ),
				'args'                => $this->idArgs(),
			)
		);
	}

	/**
	 * A page of people.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_items( $request ): WP_REST_Response {
		$kind   = (string) $request->get_param( 'kind' );
		$role   = (string) $request->get_param( 'role' );
		$active = $request->get_param( 'active' );
		$rows   = array();
		foreach ( $this->plugin->people()->all() as $person ) {
			if ( ( '' !== $kind && $kind !== $person['kind'] )
				|| ( '' !== $role && ! in_array( $role, (array) $person['roles'], true ) )
				|| ( null !== $active && rest_sanitize_boolean( $active ) !== (bool) $person['active'] ) ) {
				continue;
			}
			$rows[] = $this->present( $person );
		}
		return $this->listResponse(
			Pager::apply(
				$rows,
				$this->listArgs( $request ),
				static fn( array $row ): string => implode( ' ', array( $row['name'], $row['organisation'], $row['title'], $row['email'] ) ),
				array(
					'name'         => static fn( array $row ): string => $row['name'],
					'organisation' => static fn( array $row ): string => $row['organisation'],
					'updated'      => static fn( array $row ): string => $row['updated_at'],
					'id'           => static fn( array $row ): int => $row['id'],
				),
				'name'
			)
		);
	}

	/**
	 * One person.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$person = $this->plugin->people()->get( $this->id( $request ) );
		return null === $person ? $this->notFound() : new WP_REST_Response( $this->present( $person ) );
	}

	/**
	 * Add a person.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		try {
			$person = $this->plugin->people()->create( $this->input( $request ), 'rest' );
		} catch ( \Throwable $failure ) {
			return $this->failure( $failure );
		}
		$response = new WP_REST_Response( $this->present( $person ), 201 );
		$response->header( 'Location', rest_url( $this->namespace . '/' . $this->rest_base . '/' . (int) $person['id'] ) );
		return $response;
	}

	/**
	 * Change a person. Only what is sent is changed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		if ( null === $this->plugin->people()->get( $this->id( $request ) ) ) {
			return $this->notFound();
		}
		try {
			$person = $this->plugin->people()->update( $this->id( $request ), $this->input( $request ), 'rest' );
		} catch ( \Throwable $failure ) {
			return $this->failure( $failure );
		}
		return new WP_REST_Response( $this->present( $person ) );
	}

	/**
	 * Delete a person. They are hidden and can be brought back for 30 days.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		if ( null === $this->plugin->people()->get( $this->id( $request ) ) ) {
			return $this->notFound();
		}
		$this->plugin->people()->delete( $this->id( $request ), 'rest' );
		return new WP_REST_Response( array( 'deleted' => true, 'id' => $this->id( $request ), 'undo_days' => 30 ) );
	}

	/**
	 * Mark a person's details as checked today.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify_item( $request ) {
		if ( null === $this->plugin->people()->get( $this->id( $request ) ) ) {
			return $this->notFound();
		}
		$this->plugin->people()->verify( $this->id( $request ), 'rest' );
		return new WP_REST_Response( $this->present( (array) $this->plugin->people()->get( $this->id( $request ) ) ) );
	}

	/**
	 * The schema of one person.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		$text = static fn( string $label ): array => array( 'type' => 'string', 'description' => $label );
		return $this->add_additional_fields_schema(
			array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'estasoft-passport-person',
				'type'       => 'object',
				'properties' => array(
					'id'            => array( 'type' => 'integer', 'readonly' => true ),
					'name'          => $text( __( 'Name.', 'estasoft-handover-passport' ) ),
					'organisation'  => $text( __( 'Organisation.', 'estasoft-handover-passport' ) ),
					'kind'          => array( 'type' => 'string', 'enum' => array_keys( PeopleService::kinds() ) ),
					'title'         => $text( __( 'Job title.', 'estasoft-handover-passport' ) ),
					'email'         => $text( __( 'Email address.', 'estasoft-handover-passport' ) ),
					'phone'         => $text( __( 'Phone number.', 'estasoft-handover-passport' ) ),
					'language'      => $text( __( 'Language.', 'estasoft-handover-passport' ) ),
					'timezone'      => $text( __( 'Time zone.', 'estasoft-handover-passport' ) ),
					'urgent_method' => $text( __( 'How to reach them urgently.', 'estasoft-handover-passport' ) ),
					'left_on'       => $text( __( 'Date they stopped working on the site, YYYY-MM-DD, or empty.', 'estasoft-handover-passport' ) ),
					'note'          => $text( __( 'Note.', 'estasoft-handover-passport' ) ),
					'active'        => array( 'type' => 'boolean', 'readonly' => true ),
					'roles'         => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array_keys( PeopleService::roles() ) ) ),
					'share'         => array( 'type' => 'object', 'description' => __( 'What exports may show: name, role, email, phone.', 'estasoft-handover-passport' ) ),
					'freshness'     => array( 'type' => 'string', 'enum' => array( Freshness::VERIFIED, Freshness::DUE, Freshness::STALE ), 'readonly' => true ),
					'review_days'   => array( 'type' => 'integer' ),
					'verified_at'   => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
					'verified_by'   => array( 'type' => 'integer', 'readonly' => true ),
					'created_at'    => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
					'updated_at'    => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
				),
			)
		);
	}

	/**
	 * Filters of the collection.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function filterArgs(): array {
		return array(
			'kind'   => array( 'description' => __( 'Only people of this kind.', 'estasoft-handover-passport' ), 'type' => 'string', 'enum' => array_keys( PeopleService::kinds() ) ),
			'role'   => array( 'description' => __( 'Only people who hold this role.', 'estasoft-handover-passport' ), 'type' => 'string', 'enum' => array_keys( PeopleService::roles() ) ),
			'active' => array( 'description' => __( 'Only people who still work on the site, or only those who left.', 'estasoft-handover-passport' ), 'type' => 'boolean' ),
		);
	}

	/**
	 * Arguments of a write.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function writeArgs(): array {
		$args = array();
		foreach ( PeopleService::fields() as $key => $field ) {
			$args[ $key ] = array( 'type' => 'string', 'description' => (string) $field['label'] );
		}
		$args['kind']        = array( 'type' => 'string', 'enum' => array_keys( PeopleService::kinds() ) );
		$args['roles']       = array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array_keys( PeopleService::roles() ) ) );
		$args['share']       = array( 'type' => 'object' );
		$args['review_days'] = array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 3650 );
		return $args;
	}

	/**
	 * The service input from a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function input( WP_REST_Request $request ): array {
		$input = array();
		foreach ( array_keys( PeopleService::fields() ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = $request->get_param( $key );
			}
		}
		if ( is_array( $request->get_param( 'roles' ) ) ) {
			$input['roles'] = $request->get_param( 'roles' );
		}
		if ( is_array( $request->get_param( 'share' ) ) ) {
			$input['share'] = array_map( 'rest_sanitize_boolean', $request->get_param( 'share' ) );
		}
		if ( null !== $request->get_param( 'review_days' ) ) {
			$input['review_days'] = (int) $request->get_param( 'review_days' );
		}
		return $input;
	}

	/**
	 * A person as the API shows them.
	 *
	 * @param array<string, mixed> $person Hydrated person.
	 * @return array<string, mixed>
	 */
	private function present( array $person ): array {
		$out = array( 'id' => (int) $person['id'] );
		foreach ( array_keys( PeopleService::fields() ) as $key ) {
			$out[ $key ] = (string) ( $person[ $key ] ?? '' );
		}
		return $out + array(
			'active'      => (bool) $person['active'],
			'roles'       => array_values( (array) $person['roles'] ),
			'share'       => (object) array_map( 'boolval', (array) $person['share_flags'] ),
			'freshness'   => (string) $person['freshness'],
			'review_days' => (int) $person['review_days'],
			'verified_at' => $this->time( $person['verified_at'] ),
			'verified_by' => (int) $person['verified_by'],
			'created_at'  => $this->time( $person['created_at'] ),
			'updated_at'  => $this->time( $person['updated_at'] ),
		);
	}
}
