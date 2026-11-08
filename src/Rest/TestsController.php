<?php
/**
 * REST: restore tests.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Rest;

use Estasoft\HandoverPassport\Data\TestRepository;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Services\RestoreTestService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /tests.
 */
final class TestsController extends BaseController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'tests';

	/**
	 * {@inheritDoc}
	 */
	protected function orderbyChoices(): array {
		return array( 'date', 'result', 'id' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function defaultOrder(): string {
		return 'desc';
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
					'args'                => $this->get_collection_params() + array(
						'result' => array( 'description' => __( 'Only tests with this result.', 'estasoft-handover-passport' ), 'type' => 'string', 'enum' => array_keys( RestoreTestService::results() ) ),
					),
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
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'canEdit' ),
					'args'                => $this->idArgs(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * A page of restore tests.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_items( $request ): WP_REST_Response {
		$result = (string) $request->get_param( 'result' );
		$rows   = array();
		foreach ( $this->plugin->restoreTests()->all() as $test ) {
			if ( '' === $result || $result === $test['result'] ) {
				$rows[] = $this->present( $test );
			}
		}
		return $this->listResponse(
			Pager::apply(
				$rows,
				$this->listArgs( $request ),
				static fn( array $row ): string => $row['who'] . ' ' . $row['problems'] . ' ' . $row['test_date'],
				array(
					'date'   => static fn( array $row ): string => $row['test_date'],
					'result' => static fn( array $row ): string => $row['result'],
					'id'     => static fn( array $row ): int => $row['id'],
				),
				'date'
			)
		);
	}

	/**
	 * One restore test.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$test = $this->plugin->restoreTests()->repo()->find( $this->id( $request ) );
		return null === $test || TestRepository::RESTORE_TEST !== (string) $test['kind'] ? $this->notFound() : new WP_REST_Response( $this->present( $test ) );
	}

	/**
	 * Log a restore test.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$input = array();
		foreach ( array_keys( $this->writeArgs() ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = $request->get_param( $key );
			}
		}
		try {
			$test = $this->plugin->restoreTests()->log( $input, 'rest' );
		} catch ( \Throwable $failure ) {
			return $this->failure( $failure );
		}
		$response = new WP_REST_Response( $this->present( $test ), 201 );
		$response->header( 'Location', rest_url( $this->namespace . '/' . $this->rest_base . '/' . (int) $test['id'] ) );
		return $response;
	}

	/**
	 * Remove a test that was logged by mistake. The removal stays in the history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$test = $this->plugin->restoreTests()->repo()->find( $this->id( $request ) );
		if ( null === $test || TestRepository::RESTORE_TEST !== (string) $test['kind'] ) {
			return $this->notFound();
		}
		$this->plugin->restoreTests()->delete( $this->id( $request ), 'rest' );
		return new WP_REST_Response( array( 'deleted' => true, 'id' => $this->id( $request ) ) );
	}

	/**
	 * The schema of one restore test.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		return $this->add_additional_fields_schema(
			array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'estasoft-passport-restore-test',
				'type'       => 'object',
				'properties' => array(
					'id'              => array( 'type' => 'integer', 'readonly' => true ),
					'test_date'       => array( 'type' => 'string', 'description' => __( 'The day of the test, YYYY-MM-DD, not in the future.', 'estasoft-handover-passport' ) ),
					'result'          => array( 'type' => 'string', 'enum' => array_keys( RestoreTestService::results() ) ),
					'who'             => array( 'type' => 'string' ),
					'scope'           => array( 'type' => 'string', 'enum' => array_merge( array( '' ), array_keys( RestoreTestService::scopes() ) ) ),
					'place'           => array( 'type' => 'string', 'enum' => array_merge( array( '' ), array_keys( RestoreTestService::places() ) ) ),
					'backup_date'     => array( 'type' => 'string', 'description' => __( 'Date of the backup used, YYYY-MM-DD, or empty.', 'estasoft-handover-passport' ) ),
					'minutes'         => array( 'type' => 'integer' ),
					'problems'        => array( 'type' => 'string' ),
					'runbook_id'      => array( 'type' => 'integer', 'description' => __( 'The runbook followed, or 0.', 'estasoft-handover-passport' ) ),
					'runbook_version' => array( 'type' => 'integer', 'readonly' => true ),
					'backup_item_id'  => array( 'type' => 'integer', 'description' => __( 'The backup record used, or 0.', 'estasoft-handover-passport' ) ),
					'created_at'      => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
					'created_by'      => array( 'type' => 'integer', 'readonly' => true ),
				),
			)
		);
	}

	/**
	 * Arguments of a write.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function writeArgs(): array {
		return array(
			'test_date'      => array( 'type' => 'string', 'required' => true, 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
			'result'         => array( 'type' => 'string', 'required' => true, 'enum' => array_keys( RestoreTestService::results() ) ),
			'who'            => array( 'type' => 'string', 'maxLength' => 190 ),
			'scope'          => array( 'type' => 'string', 'enum' => array_merge( array( '' ), array_keys( RestoreTestService::scopes() ) ) ),
			'place'          => array( 'type' => 'string', 'enum' => array_merge( array( '' ), array_keys( RestoreTestService::places() ) ) ),
			'backup_date'    => array( 'type' => 'string' ),
			'minutes'        => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100000 ),
			'problems'       => array( 'type' => 'string', 'maxLength' => 3000 ),
			'runbook_id'     => array( 'type' => 'integer', 'minimum' => 0 ),
			'backup_item_id' => array( 'type' => 'integer', 'minimum' => 0 ),
		);
	}

	/**
	 * A test as the API shows it. The number of a Sensitive backup record is not given to a caller who cannot see it.
	 *
	 * @param array<string, mixed> $test Stored test.
	 * @return array<string, mixed>
	 */
	private function present( array $test ): array {
		$backup = (int) $test['backup_item_id'];
		if ( $backup > 0 && ! Capabilities::canSeeSensitive() && array() !== $this->plugin->records()->items()->sensitiveIds( array( $backup ) ) ) {
			$backup = 0;
		}
		return array(
			'id'              => (int) $test['id'],
			'test_date'       => (string) $test['test_date'],
			'result'          => (string) $test['result'],
			'who'             => (string) $test['who'],
			'scope'           => (string) $test['scope'],
			'place'           => (string) $test['place'],
			'backup_date'     => (string) ( $test['backup_date'] ?? '' ),
			'minutes'         => (int) $test['minutes'],
			'problems'        => (string) $test['problems'],
			'runbook_id'      => (int) $test['runbook_id'],
			'runbook_version' => (int) $test['runbook_version'],
			'backup_item_id'  => $backup,
			'created_at'      => $this->time( $test['created_at'] ),
			'created_by'      => (int) $test['created_by'],
		);
	}
}
