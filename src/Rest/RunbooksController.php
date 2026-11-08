<?php
/**
 * REST: runbooks.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Rest;

use Estasoft\HandoverPassport\Services\RunbookService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /runbooks.
 */
final class RunbooksController extends BaseController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'runbooks';

	/**
	 * {@inheritDoc}
	 */
	protected function orderbyChoices(): array {
		return array( 'title', 'updated', 'reviewed', 'id' );
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
					'args'                => $this->writeArgs() + array( 'template' => array( 'description' => __( 'Start from one of the shipped templates instead of sending the content.', 'estasoft-handover-passport' ), 'type' => 'string', 'enum' => array_keys( RunbookService::templates() ) ) ),
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
			'/' . $this->rest_base . '/(?P<id>\d+)/versions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_versions' ),
				'permission_callback' => array( $this, 'canView' ),
				'args'                => $this->idArgs(),
			)
		);
		foreach ( array( 'reviewed' => 'markReviewed', 'rehearsed' => 'markRehearsed' ) as $route => $method ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>\d+)/' . $route,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => function ( WP_REST_Request $request ) use ( $method ) {
						return $this->stamp( $request, $method );
					},
					'permission_callback' => array( $this, 'canEdit' ),
					'args'                => $this->idArgs(),
				)
			);
		}
	}

	/**
	 * A page of runbooks.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_items( $request ): WP_REST_Response {
		$status   = (string) $request->get_param( 'status' );
		$critical = $request->get_param( 'critical' );
		$rows     = array();
		foreach ( $this->plugin->runbooks()->all() as $runbook ) {
			if ( ( '' !== $status && $status !== $runbook['status'] ) || ( null !== $critical && rest_sanitize_boolean( $critical ) !== (bool) $runbook['critical'] ) ) {
				continue;
			}
			$rows[] = $this->present( $runbook );
		}
		return $this->listResponse(
			Pager::apply(
				$rows,
				$this->listArgs( $request ),
				static fn( array $row ): string => $row['title'] . ' ' . $row['purpose'] . ' ' . $row['when_to_use'],
				array(
					'title'    => static fn( array $row ): string => $row['title'],
					'updated'  => static fn( array $row ): string => $row['updated_at'],
					'reviewed' => static fn( array $row ): string => $row['last_reviewed'],
					'id'       => static fn( array $row ): int => $row['id'],
				),
				'title'
			)
		);
	}

	/**
	 * One runbook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$runbook = $this->plugin->runbooks()->get( $this->id( $request ) );
		return null === $runbook ? $this->notFound() : new WP_REST_Response( $this->present( $runbook ) );
	}

	/**
	 * Add a runbook, from a template or from the content sent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		try {
			$template = (string) $request->get_param( 'template' );
			$runbook  = '' !== $template
				? $this->plugin->runbooks()->createFromTemplate( $template )
				: $this->plugin->runbooks()->create( $this->input( $request ), 'rest' );
		} catch ( \Throwable $failure ) {
			return $this->failure( $failure );
		}
		$response = new WP_REST_Response( $this->present( $runbook ), 201 );
		$response->header( 'Location', rest_url( $this->namespace . '/' . $this->rest_base . '/' . (int) $runbook['id'] ) );
		return $response;
	}

	/**
	 * Change a runbook. A published runbook gets a new version when its content changes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		if ( null === $this->plugin->runbooks()->get( $this->id( $request ) ) ) {
			return $this->notFound();
		}
		try {
			$runbook = $this->plugin->runbooks()->update( $this->id( $request ), $this->input( $request ), 'rest' );
		} catch ( \Throwable $failure ) {
			return $this->failure( $failure );
		}
		return new WP_REST_Response( $this->present( $runbook ) );
	}

	/**
	 * Delete a runbook. It can be brought back for 30 days.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		if ( null === $this->plugin->runbooks()->get( $this->id( $request ) ) ) {
			return $this->notFound();
		}
		$this->plugin->runbooks()->delete( $this->id( $request ), 'rest' );
		return new WP_REST_Response( array( 'deleted' => true, 'id' => $this->id( $request ), 'undo_days' => 30 ) );
	}

	/**
	 * Earlier versions of a runbook, newest first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_versions( $request ) {
		if ( null === $this->plugin->runbooks()->get( $this->id( $request ) ) ) {
			return $this->notFound();
		}
		$out = array();
		foreach ( $this->plugin->runbooks()->versions( $this->id( $request ) ) as $version ) {
			$out[] = array(
				'version'       => (int) $version['version'],
				'created_at'    => $this->time( $version['created_at'] ),
				'created_by'    => (int) $version['created_by'],
				'change_note'   => (string) $version['change_note'],
				'steps'         => array_values( (array) $version['steps'] ),
				'prerequisites' => array_values( (array) $version['prerequisites'] ),
				'verification'  => array_values( (array) $version['verification'] ),
				'rollback_text' => (string) $version['rollback_text'],
			);
		}
		return new WP_REST_Response( $out );
	}

	/**
	 * Mark a runbook as reviewed or rehearsed today.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $method  markReviewed or markRehearsed.
	 * @return WP_REST_Response|WP_Error
	 */
	private function stamp( WP_REST_Request $request, string $method ) {
		if ( null === $this->plugin->runbooks()->get( $this->id( $request ) ) ) {
			return $this->notFound();
		}
		$this->plugin->runbooks()->$method( $this->id( $request ), 'rest' );
		return new WP_REST_Response( $this->present( (array) $this->plugin->runbooks()->get( $this->id( $request ) ) ) );
	}

	/**
	 * The schema of one runbook.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		return $this->add_additional_fields_schema(
			array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'estasoft-passport-runbook',
				'type'       => 'object',
				'properties' => array(
					'id'                => array( 'type' => 'integer', 'readonly' => true ),
					'title'             => array( 'type' => 'string' ),
					'purpose'           => array( 'type' => 'string' ),
					'when_to_use'       => array( 'type' => 'string' ),
					'owner_role'        => array( 'type' => 'string', 'enum' => array_merge( array( '' ), array_keys( RunbookService::roles() ) ) ),
					'estimated_minutes' => array( 'type' => 'integer', 'readonly' => true, 'description' => __( 'The total of the steps.', 'estasoft-handover-passport' ) ),
					'status'            => array( 'type' => 'string', 'enum' => RunbookService::STATUSES ),
					'template_key'      => array( 'type' => 'string', 'readonly' => true ),
					'restore_scope'     => array( 'type' => 'string', 'enum' => RunbookService::SCOPES ),
					'critical'          => array( 'type' => 'boolean' ),
					'review_days'       => array( 'type' => 'integer' ),
					'current_version'   => array( 'type' => 'integer', 'readonly' => true ),
					'last_reviewed'     => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
					'last_rehearsed'    => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
					'steps'             => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'prerequisites'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'verification'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'rollback_text'     => array( 'type' => 'string' ),
					'change_note'       => array( 'type' => 'string' ),
					'created_at'        => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
					'updated_at'        => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
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
			'status'   => array( 'description' => __( 'Only runbooks in this state.', 'estasoft-handover-passport' ), 'type' => 'string', 'enum' => RunbookService::STATUSES ),
			'critical' => array( 'description' => __( 'Only runbooks marked critical, or only those that are not.', 'estasoft-handover-passport' ), 'type' => 'boolean' ),
		);
	}

	/**
	 * Arguments of a write.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function writeArgs(): array {
		return array(
			'title'         => array( 'type' => 'string', 'maxLength' => 255 ),
			'purpose'       => array( 'type' => 'string', 'maxLength' => 2000 ),
			'when_to_use'   => array( 'type' => 'string', 'maxLength' => 2000 ),
			'owner_role'    => array( 'type' => 'string', 'enum' => array_merge( array( '' ), array_keys( RunbookService::roles() ) ) ),
			'status'        => array( 'type' => 'string', 'enum' => RunbookService::STATUSES ),
			'restore_scope' => array( 'type' => 'string', 'enum' => RunbookService::SCOPES ),
			'critical'      => array( 'type' => 'boolean' ),
			'review_days'   => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 3650 ),
			'steps'         => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'maxItems' => 100 ),
			'prerequisites' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'maxItems' => 50 ),
			'verification'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'maxItems' => 50 ),
			'rollback_text' => array( 'type' => 'string', 'maxLength' => 2000 ),
			'change_note'   => array( 'type' => 'string', 'maxLength' => 500 ),
		);
	}

	/**
	 * The service input from a request. Only what was sent is passed on, so the rest stays as it is.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function input( WP_REST_Request $request ): array {
		$input = array();
		foreach ( array_keys( $this->writeArgs() ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = 'critical' === $key ? ( rest_sanitize_boolean( $request->get_param( $key ) ) ? '1' : '0' ) : $request->get_param( $key );
			}
		}
		return $input;
	}

	/**
	 * A runbook as the API shows it.
	 *
	 * @param array<string, mixed> $runbook Runbook with its current content.
	 * @return array<string, mixed>
	 */
	private function present( array $runbook ): array {
		return array(
			'id'                => (int) $runbook['id'],
			'title'             => (string) $runbook['title'],
			'purpose'           => (string) $runbook['purpose'],
			'when_to_use'       => (string) $runbook['when_to_use'],
			'owner_role'        => (string) $runbook['owner_role'],
			'estimated_minutes' => (int) $runbook['estimated_minutes'],
			'status'            => (string) $runbook['status'],
			'template_key'      => (string) $runbook['template_key'],
			'restore_scope'     => (string) $runbook['restore_scope'],
			'critical'          => (bool) $runbook['critical'],
			'review_days'       => (int) $runbook['review_days'],
			'current_version'   => (int) $runbook['current_version'],
			'last_reviewed'     => $this->time( $runbook['last_reviewed'] ),
			'last_rehearsed'    => $this->time( $runbook['last_rehearsed'] ),
			'steps'             => array_values( (array) $runbook['steps'] ),
			'prerequisites'     => array_values( (array) $runbook['prerequisites'] ),
			'verification'      => array_values( (array) $runbook['verification'] ),
			'rollback_text'     => (string) $runbook['rollback_text'],
			'change_note'       => (string) $runbook['change_note'],
			'created_at'        => $this->time( $runbook['created_at'] ),
			'updated_at'        => $this->time( $runbook['updated_at'] ),
		);
	}
}
