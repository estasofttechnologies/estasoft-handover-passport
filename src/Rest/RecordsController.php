<?php
/**
 * REST: the registers (licences, domains, hosting, services and so on).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Rest;

use Estasoft\HandoverPassport\Domain\Fields;
use Estasoft\HandoverPassport\Domain\Freshness;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\Sensitivity;
use Estasoft\HandoverPassport\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /records. A Sensitive record, and the Sensitive fields of other records, do not appear for a caller who
 * lacks the Sensitive capability, and asking for a Sensitive record gives the same answer as asking for one that does not
 * exist (API-4).
 */
final class RecordsController extends BaseController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'records';

	/**
	 * {@inheritDoc}
	 */
	protected function orderbyChoices(): array {
		return array( 'title', 'kind', 'date', 'updated', 'id' );
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
					'args'                => $this->writeArgs( true ),
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
					'args'                => $this->idArgs() + $this->writeArgs( false ),
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
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/renew',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'renew_item' ),
				'permission_callback' => array( $this, 'canEdit' ),
				'args'                => $this->idArgs() + array(
					'date' => array(
						'description' => __( 'The next date, as YYYY-MM-DD, later than the current one.', 'estasoft-handover-passport' ),
						'type'        => 'string',
						'required'    => true,
						'pattern'     => '^\d{4}-\d{2}-\d{2}$',
					),
				),
			)
		);
	}

	/**
	 * A page of records.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_items( $request ): WP_REST_Response {
		$kind      = (string) $request->get_param( 'kind' );
		$source    = (string) $request->get_param( 'source' );
		$freshness = (string) $request->get_param( 'freshness' );
		$rows      = array();
		foreach ( $this->visible() as $record ) {
			if ( ( '' !== $kind && $kind !== $record['kind'] ) || ( '' !== $source && $source !== $record['source'] ) || ( '' !== $freshness && $freshness !== $record['freshness'] ) ) {
				continue;
			}
			$rows[] = $this->present( $record );
		}
		$result = Pager::apply(
			$rows,
			$this->listArgs( $request ),
			static fn( array $row ): string => $row['title'] . ' ' . $row['kind_label'] . ' ' . wp_json_encode( $row['fields'] ),
			array(
				'title'   => static fn( array $row ): string => $row['title'],
				'kind'    => static fn( array $row ): string => $row['kind'],
				'date'    => static fn( array $row ): string => '' === $row['date'] ? '9999-12-31' : $row['date'],
				'updated' => static fn( array $row ): string => $row['updated_at'],
				'id'      => static fn( array $row ): int => $row['id'],
			),
			'title'
		);
		return $this->listResponse( $result );
	}

	/**
	 * One record.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$record = $this->find( $this->id( $request ) );
		return null === $record ? $this->notFound() : new WP_REST_Response( $this->present( $record ) );
	}

	/**
	 * Add a record.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$kind = (string) $request->get_param( 'kind' );
		$def  = Kinds::get( $kind );
		if ( null === $def || ! in_array( $kind, Kinds::registerKinds(), true ) ) {
			return $this->error( 'invalid', __( 'Choose a kind of record that can be added.', 'estasoft-handover-passport' ), 400 );
		}
		$input = $this->input( $request, $kind );
		if ( $input instanceof WP_Error ) {
			return $input;
		}
		try {
			$record = $this->plugin->records()->create( $kind, $input, 'entered', 'rest' );
		} catch ( \Throwable $failure ) {
			return $this->failure( $failure );
		}
		$response = new WP_REST_Response( $this->present( $record ), 201 );
		$response->header( 'Location', rest_url( $this->namespace . '/' . $this->rest_base . '/' . (int) $record['id'] ) );
		return $response;
	}

	/**
	 * Change a record. Only the fields sent are changed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$existing = $this->find( $this->id( $request ) );
		if ( null === $existing ) {
			return $this->notFound();
		}
		$input = $this->input( $request, (string) $existing['kind'] );
		if ( $input instanceof WP_Error ) {
			return $input;
		}
		try {
			$record = $this->plugin->records()->update( (int) $existing['id'], $input, 'rest' );
		} catch ( \Throwable $failure ) {
			return $this->failure( $failure );
		}
		return new WP_REST_Response( $this->present( $record ) );
	}

	/**
	 * Delete a record. It is hidden and can be brought back for 30 days.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$existing = $this->find( $this->id( $request ) );
		if ( null === $existing ) {
			return $this->notFound();
		}
		try {
			$this->plugin->records()->delete( (int) $existing['id'], 'rest' );
		} catch ( \Throwable $failure ) {
			return $this->failure( $failure );
		}
		return new WP_REST_Response( array( 'deleted' => true, 'id' => (int) $existing['id'], 'undo_days' => 30 ) );
	}

	/**
	 * Mark a record as checked today.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify_item( $request ) {
		$existing = $this->find( $this->id( $request ) );
		if ( null === $existing ) {
			return $this->notFound();
		}
		$this->plugin->records()->verify( (int) $existing['id'], 'rest' );
		return new WP_REST_Response( $this->present( (array) $this->find( (int) $existing['id'] ) ) );
	}

	/**
	 * Enter the next date of a renewal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function renew_item( $request ) {
		$existing = $this->find( $this->id( $request ) );
		if ( null === $existing ) {
			return $this->notFound();
		}
		try {
			$record = $this->plugin->records()->renew( (int) $existing['id'], (string) $request->get_param( 'date' ), 'rest' );
		} catch ( \Throwable $failure ) {
			return $this->failure( $failure );
		}
		return new WP_REST_Response( $this->present( $record ) );
	}

	/**
	 * The schema of one record.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		return $this->add_additional_fields_schema(
			array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'estasoft-passport-record',
				'type'       => 'object',
				'properties' => array(
					'id'                   => array( 'type' => 'integer', 'readonly' => true ),
					'kind'                 => array( 'type' => 'string', 'enum' => Kinds::registerKinds(), 'description' => __( 'The register the record belongs to.', 'estasoft-handover-passport' ) ),
					'kind_label'           => array( 'type' => 'string', 'readonly' => true ),
					'title'                => array( 'type' => 'string', 'readonly' => true, 'description' => __( 'Made from the record\'s main field.', 'estasoft-handover-passport' ) ),
					'source'               => array( 'type' => 'string', 'enum' => array( 'detected', 'entered', 'imported' ), 'readonly' => true ),
					'sensitive'            => array( 'type' => 'boolean', 'description' => __( 'A Sensitive record is hidden from people without the Sensitive capability.', 'estasoft-handover-passport' ) ),
					'visible_to_client'    => array( 'type' => 'boolean' ),
					'visible_in_emergency' => array( 'type' => 'boolean' ),
					'fields'               => array( 'type' => 'object', 'description' => __( 'The values of the kind\'s fields. Money is a decimal string such as 1234.50. People fields are in owners.', 'estasoft-handover-passport' ) ),
					'owners'               => array( 'type' => 'object', 'description' => __( 'Person numbers for each people field, such as account_holder, payer and administrators. Write them in fields as lists of person numbers.', 'estasoft-handover-passport' ) ),
					'date'                 => array( 'type' => 'string', 'readonly' => true, 'description' => __( 'The next renewal, expiry or due date, or empty.', 'estasoft-handover-passport' ) ),
					'reminder_days'        => array( 'type' => 'string', 'description' => __( 'Empty uses the Settings list, off sends none, or days such as "30, 7, 0".', 'estasoft-handover-passport' ) ),
					'freshness'            => array( 'type' => 'string', 'enum' => array( Freshness::VERIFIED, Freshness::DUE, Freshness::STALE ), 'readonly' => true ),
					'review_days'          => array( 'type' => 'integer', 'description' => __( 'Days between checks.', 'estasoft-handover-passport' ) ),
					'verified_at'          => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
					'verified_by'          => array( 'type' => 'integer', 'readonly' => true, 'description' => __( 'User number, 0 when never verified.', 'estasoft-handover-passport' ) ),
					'created_at'           => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
					'updated_at'           => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
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
			'kind'      => array( 'description' => __( 'Only records of this kind.', 'estasoft-handover-passport' ), 'type' => 'string', 'enum' => Kinds::registerKinds() ),
			'source'    => array( 'description' => __( 'Only records from this source.', 'estasoft-handover-passport' ), 'type' => 'string', 'enum' => array( 'detected', 'entered', 'imported' ) ),
			'freshness' => array( 'description' => __( 'Only records in this state.', 'estasoft-handover-passport' ), 'type' => 'string', 'enum' => array( Freshness::VERIFIED, Freshness::DUE, Freshness::STALE ) ),
		);
	}

	/**
	 * Arguments of a write.
	 *
	 * @param bool $creating Whether the record is new.
	 * @return array<string, array<string, mixed>>
	 */
	private function writeArgs( bool $creating ): array {
		$args = array(
			'fields'               => array( 'description' => __( 'Field values by key.', 'estasoft-handover-passport' ), 'type' => 'object' ),
			'sensitive'            => array( 'description' => __( 'Hide the record from people without the Sensitive capability.', 'estasoft-handover-passport' ), 'type' => 'boolean' ),
			'visible_to_client'    => array( 'type' => 'boolean' ),
			'visible_in_emergency' => array( 'type' => 'boolean' ),
			'review_days'          => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 3650 ),
			'reminder_days'        => array( 'type' => 'string', 'maxLength' => 60 ),
		);
		if ( $creating ) {
			$args['kind'] = array( 'description' => __( 'The register to add the record to.', 'estasoft-handover-passport' ), 'type' => 'string', 'enum' => Kinds::registerKinds(), 'required' => true );
		}
		return $args;
	}

	/**
	 * Every live record in a register that this caller may see.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function visible(): array {
		$may   = Capabilities::canSeeSensitive();
		$kinds = Kinds::registerKinds();
		$out   = array();
		foreach ( $this->plugin->records()->everything() as $record ) {
			if ( in_array( (string) $record['kind'], $kinds, true ) && ( $may || ! $record['sensitive'] ) ) {
				$out[] = $record;
			}
		}
		return $out;
	}

	/**
	 * One record, or null when it does not exist or is Sensitive for this caller.
	 *
	 * @param int $id Record number.
	 * @return array<string, mixed>|null
	 */
	private function find( int $id ): ?array {
		$record = $this->plugin->records()->get( $id, true );
		if ( null === $record || ! in_array( (string) $record['kind'], Kinds::registerKinds(), true ) ) {
			return null;
		}
		return $record['sensitive'] && ! Capabilities::canSeeSensitive() ? null : $record;
	}

	/**
	 * The service input from a request, or an error.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $kind    Kind of the record.
	 * @return array<string, mixed>|WP_Error
	 */
	private function input( WP_REST_Request $request, string $kind ) {
		$def    = (array) Kinds::get( $kind );
		$fields = $request->get_param( 'fields' );
		$input  = array();
		if ( null !== $fields ) {
			if ( ! is_array( $fields ) ) {
				return $this->error( 'invalid', __( 'Send the fields as an object.', 'estasoft-handover-passport' ), 400 );
			}
			$known = array_keys( (array) $def['fields'] );
			if ( array() !== array_diff( array_map( 'strval', array_keys( $fields ) ), $known ) ) {
				return $this->error( 'unknown_field', __( 'Some of the fields are not part of this kind of record.', 'estasoft-handover-passport' ), 400 );
			}
			if ( ! Capabilities::canSeeSensitive() && array() !== array_intersect( array_keys( $fields ), Sensitivity::fieldKeys( $kind ) ) ) {
				return $this->error( 'forbidden', __( 'You are not allowed to change Sensitive fields.', 'estasoft-handover-passport' ), (int) rest_authorization_required_code() );
			}
			foreach ( $fields as $key => $value ) {
				$spec = (array) $def['fields'][ $key ];
				// A JSON number is an amount in the main unit, never minor units.
				$input[ $key ] = 'money' === ( $spec['type'] ?? '' ) && ( is_int( $value ) || is_float( $value ) ) ? (string) $value : $value;
			}
		}
		foreach ( array( 'sensitive' => 'sensitive', 'visible_to_client' => 'vis_client', 'visible_in_emergency' => 'vis_emergency' ) as $param => $key ) {
			if ( null !== $request->get_param( $param ) ) {
				$input[ $key ] = rest_sanitize_boolean( $request->get_param( $param ) ) ? '1' : '0';
			}
		}
		if ( null !== $request->get_param( 'review_days' ) ) {
			$input['review_days'] = (int) $request->get_param( 'review_days' );
		}
		if ( null !== $request->get_param( 'reminder_days' ) && ! empty( $def['due'] ) ) {
			$input['reminder_days'] = (string) $request->get_param( 'reminder_days' );
		}
		return $input;
	}

	/**
	 * A record as the API shows it.
	 *
	 * @param array<string, mixed> $record Hydrated record.
	 * @return array<string, mixed>
	 */
	private function present( array $record ): array {
		$kind   = (string) $record['kind'];
		$def    = (array) Kinds::get( $kind );
		$may    = Capabilities::canSeeSensitive();
		$hidden = $may ? array() : Sensitivity::fieldKeys( $kind );
		$fields = array();
		foreach ( (array) $def['fields'] as $key => $spec ) {
			if ( 'people' === $spec['type'] || in_array( (string) $key, $hidden, true ) ) {
				continue;
			}
			$value          = $record['fields'][ $key ] ?? '';
			$fields[ $key ] = 'money' === $spec['type'] ? Fields::moneyDisplay( $value ) : $value;
		}
		return array(
			'id'                   => (int) $record['id'],
			'kind'                 => $kind,
			'kind_label'           => (string) ( $def['label'] ?? $kind ),
			'title'                => (string) $record['title'],
			'source'               => (string) $record['source'],
			'sensitive'            => (bool) $record['sensitive'],
			'visible_to_client'    => (bool) $record['vis_client'],
			'visible_in_emergency' => (bool) $record['vis_emergency'],
			'fields'               => (object) $fields,
			'owners'               => (object) array_map( static fn( $ids ): array => array_values( array_map( 'intval', (array) $ids ) ), (array) $record['owners'] ),
			'date'                 => empty( $def['due'] ) ? '' : (string) $record['due_date'],
			'reminder_days'        => empty( $def['due'] ) ? '' : (string) ( $record['fields']['reminder_days'] ?? '' ),
			'freshness'            => (string) $record['freshness'],
			'review_days'          => (int) $record['review_days'],
			'verified_at'          => $this->time( $record['verified_at'] ),
			'verified_by'          => (int) $record['verified_by'],
			'created_at'           => $this->time( $record['created_at'] ),
			'updated_at'           => $this->time( $record['updated_at'] ),
		);
	}
}
