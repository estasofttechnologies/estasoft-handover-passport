<?php
/**
 * REST: the change log.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Rest;

use Estasoft\HandoverPassport\Admin\ChangeText;
use Estasoft\HandoverPassport\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /changes. An entry about a Sensitive record keeps its time, kind and author but loses the record's name,
 * its values and its note for a caller who cannot see Sensitive records, and a search never looks inside such an entry.
 */
final class ChangesController extends BaseController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'changes';

	/**
	 * {@inheritDoc}
	 */
	protected function orderbyChoices(): array {
		return array( 'date' );
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
						'kind'         => array( 'description' => __( 'Only this kind of change.', 'estasoft-handover-passport' ), 'type' => 'string', 'enum' => array_keys( ChangeText::filterKinds() ) ),
						'subject_type' => array( 'description' => __( 'Only changes to this kind of thing, such as item, person or runbook.', 'estasoft-handover-passport' ), 'type' => 'string', 'pattern' => '^[a-z_]{1,20}$' ),
						'since'        => array( 'description' => __( 'Only changes after this moment, as an ISO 8601 date and time.', 'estasoft-handover-passport' ), 'type' => 'string', 'format' => 'date-time' ),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * A page of changes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$may     = Capabilities::canSeeSensitive();
		$log     = $this->plugin->changeLog();
		$args    = $this->listArgs( $request );
		$filters = array();
		foreach ( array( 'kind', 'subject_type' ) as $key ) {
			if ( '' !== (string) $request->get_param( $key ) ) {
				$filters[ $key ] = (string) $request->get_param( $key );
			}
		}
		if ( '' !== (string) $request->get_param( 'since' ) ) {
			$time = strtotime( (string) $request->get_param( 'since' ) );
			if ( false === $time ) {
				return $this->error( 'invalid', __( 'Send "since" as an ISO 8601 date and time.', 'estasoft-handover-passport' ), 400, array( 'field' => 'since' ) );
			}
			$filters['since'] = gmdate( 'Y-m-d H:i:s', $time );
		}
		if ( '' !== $args['search'] ) {
			$filters['search'] = $args['search'];
			if ( ! $may ) {
				$filters['visible_only'] = true;
				$filters['hidden_items'] = $this->plugin->records()->items()->allSensitiveIds();
			}
		}

		$repo   = $log->repo();
		$total  = $repo->countFor( $filters );
		$rows   = $log->forViewer( $repo->search( $filters, $args['per_page'], ( $args['page'] - 1 ) * $args['per_page'], 'asc' === $args['order'] ? 'ASC' : 'DESC' ), $may );
		$result = array(
			'items' => array_map( array( $this, 'present' ), $rows ),
			'total' => $total,
			'pages' => 0 === $total ? 0 : (int) ceil( $total / $args['per_page'] ),
		);
		return $this->listResponse( $result );
	}

	/**
	 * The schema of one change.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		return $this->add_additional_fields_schema(
			array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'estasoft-passport-change',
				'type'       => 'object',
				'properties' => array(
					'id'            => array( 'type' => 'integer', 'readonly' => true ),
					'changed_at'    => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
					'kind'          => array( 'type' => 'string', 'readonly' => true ),
					'summary'       => array( 'type' => 'string', 'readonly' => true, 'description' => __( 'The change in a sentence.', 'estasoft-handover-passport' ) ),
					'subject_type'  => array( 'type' => 'string', 'readonly' => true ),
					'subject_id'    => array( 'type' => 'integer', 'readonly' => true ),
					'subject_label' => array( 'type' => 'string', 'readonly' => true ),
					'before'        => array( 'type' => 'object', 'readonly' => true ),
					'after'         => array( 'type' => 'object', 'readonly' => true ),
					'actor_id'      => array( 'type' => 'integer', 'readonly' => true ),
					'actor_label'   => array( 'type' => 'string', 'readonly' => true ),
					'source'        => array( 'type' => 'string', 'readonly' => true, 'description' => __( 'Where the change came from: wordpress, screen, scan, cli, rest or import.', 'estasoft-handover-passport' ) ),
					'batch_id'      => array( 'type' => 'string', 'readonly' => true ),
					'note'          => array( 'type' => 'string', 'readonly' => true ),
					'masked'        => array( 'type' => 'boolean', 'readonly' => true, 'description' => __( 'True when the entry is about a Sensitive record and its details are hidden from this caller.', 'estasoft-handover-passport' ) ),
				),
			)
		);
	}

	/**
	 * An entry as the API shows it.
	 *
	 * @param array<string, mixed> $row Entry after the viewer's rights were applied.
	 * @return array<string, mixed>
	 */
	private function present( array $row ): array {
		$masked = ! empty( $row['masked'] );
		return array(
			'id'            => (int) $row['id'],
			'changed_at'    => $this->time( $row['changed_at'] ),
			'kind'          => (string) $row['kind'],
			'summary'       => ChangeText::describe( $row ),
			'subject_type'  => (string) $row['subject_type'],
			'subject_id'    => $masked ? 0 : (int) $row['subject_id'],
			'subject_label' => (string) $row['subject_label'],
			'before'        => (object) (array) $row['before_data'],
			'after'         => (object) (array) $row['after_data'],
			'actor_id'      => (int) $row['actor_id'],
			'actor_label'   => (string) $row['actor_label'],
			'source'        => (string) $row['source'],
			'batch_id'      => (string) $row['batch_id'],
			'note'          => (string) $row['note'],
			'masked'        => $masked,
		);
	}
}
