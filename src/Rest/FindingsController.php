<?php
/**
 * REST: findings.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Rest;

use Estasoft\HandoverPassport\Findings\Level;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /findings. A finding about a Sensitive record is shown without its details to a caller who cannot see
 * Sensitive records, and such a caller cannot change it.
 */
final class FindingsController extends BaseController {

	private const STATES = array( 'open', 'snoozed', 'accepted', 'resolved' );

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'findings';

	/**
	 * {@inheritDoc}
	 */
	protected function orderbyChoices(): array {
		return array( 'severity', 'first_seen', 'last_seen', 'rule', 'id' );
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
						'state'    => array( 'description' => __( 'Only findings in this state. Open by default.', 'estasoft-handover-passport' ), 'type' => 'string', 'default' => 'open', 'enum' => array_merge( self::STATES, array( 'all' ) ) ),
						'severity' => array( 'description' => __( 'Only findings at this level.', 'estasoft-handover-passport' ), 'type' => 'string', 'enum' => Level::all() ),
						'rule'     => array( 'description' => __( 'Only findings of this rule, such as HP-DOM-001.', 'estasoft-handover-passport' ), 'type' => 'string', 'pattern' => '^HP-[A-Z]{2,4}-\d{3}$' ),
					),
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
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/snooze',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'snooze' ),
				'permission_callback' => array( $this, 'canEdit' ),
				'args'                => $this->idArgs() + array(
					'days' => array( 'description' => __( 'How long to hide it.', 'estasoft-handover-passport' ), 'type' => 'integer', 'default' => 7, 'enum' => array( 7, 30, 90 ) ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/accept',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'accept' ),
				'permission_callback' => array( $this, 'canEdit' ),
				'args'                => $this->idArgs() + array(
					'reason' => array( 'description' => __( 'Why this is accepted. Exports show it. Never put a password or key here.', 'estasoft-handover-passport' ), 'type' => 'string', 'required' => true, 'maxLength' => 500 ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/reopen',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reopen' ),
				'permission_callback' => array( $this, 'canEdit' ),
				'args'                => $this->idArgs(),
			)
		);
	}

	/**
	 * A page of findings.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_items( $request ): WP_REST_Response {
		$state  = (string) ( $request->get_param( 'state' ) ?? 'open' );
		$filter = array(
			'state'    => 'all' === $state ? '' : $state,
			'severity' => (string) $request->get_param( 'severity' ),
			'rule_id'  => (string) $request->get_param( 'rule' ),
		);
		$rows   = array_map( array( $this, 'present' ), $this->plugin->findings()->list( $filter ) );
		return $this->listResponse(
			Pager::apply(
				$rows,
				$this->listArgs( $request ),
				static fn( array $row ): string => $row['message'] . ' ' . $row['rule_title'] . ' ' . $row['rule'],
				array(
					'severity'   => static fn( array $row ): int => Level::weight( $row['severity'] ),
					'first_seen' => static fn( array $row ): string => $row['first_seen'],
					'last_seen'  => static fn( array $row ): string => $row['last_seen'],
					'rule'       => static fn( array $row ): string => $row['rule'],
					'id'         => static fn( array $row ): int => $row['id'],
				),
				'severity'
			)
		);
	}

	/**
	 * One finding.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$finding = $this->plugin->findings()->get( $this->id( $request ) );
		return null === $finding ? $this->notFound() : new WP_REST_Response( $this->present( $finding ) );
	}

	/**
	 * Hide a finding for 7, 30 or 90 days.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function snooze( $request ) {
		return $this->change( $request, fn( int $id ) => $this->plugin->findings()->snooze( $id, (int) ( $request->get_param( 'days' ) ?? 7 ) ) );
	}

	/**
	 * Accept a finding with a reason.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function accept( $request ) {
		return $this->change( $request, fn( int $id ) => $this->plugin->findings()->accept( $id, (string) $request->get_param( 'reason' ), 'rest' ) );
	}

	/**
	 * Put a hidden or accepted finding back to open.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reopen( $request ) {
		return $this->change( $request, fn( int $id ) => $this->plugin->findings()->reopen( $id ) );
	}

	/**
	 * The schema of one finding.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		return $this->add_additional_fields_schema(
			array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'estasoft-passport-finding',
				'type'       => 'object',
				'properties' => array(
					'id'              => array( 'type' => 'integer', 'readonly' => true ),
					'rule'            => array( 'type' => 'string', 'readonly' => true, 'description' => __( 'The rule that found it, such as HP-DOM-001.', 'estasoft-handover-passport' ) ),
					'rule_title'      => array( 'type' => 'string', 'readonly' => true ),
					'severity'        => array( 'type' => 'string', 'enum' => Level::all(), 'readonly' => true ),
					'severity_label'  => array( 'type' => 'string', 'readonly' => true ),
					'state'           => array( 'type' => 'string', 'enum' => self::STATES, 'readonly' => true ),
					'message'         => array( 'type' => 'string', 'readonly' => true, 'description' => __( 'States a fact and the date it was seen, as seen from this server.', 'estasoft-handover-passport' ) ),
					'sensitive'       => array( 'type' => 'boolean', 'readonly' => true, 'description' => __( 'True when the finding is about a Sensitive record and its details are hidden from this caller.', 'estasoft-handover-passport' ) ),
					'handover'        => array( 'type' => 'boolean', 'readonly' => true, 'description' => __( 'Whether the rule counts toward readiness for a handover.', 'estasoft-handover-passport' ) ),
					'subject_type'    => array( 'type' => 'string', 'readonly' => true ),
					'subject_id'      => array( 'type' => 'integer', 'readonly' => true ),
					'first_seen'      => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
					'last_seen'       => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
					'snoozed_until'   => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
					'accepted_reason' => array( 'type' => 'string', 'readonly' => true ),
					'resolved_at'     => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
				),
			)
		);
	}

	/**
	 * Make a change to one finding and return it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param callable        $change  Receives the finding number.
	 * @return WP_REST_Response|WP_Error
	 */
	private function change( WP_REST_Request $request, callable $change ) {
		$finding = $this->plugin->findings()->get( $this->id( $request ) );
		// A finding the caller cannot read is a finding the caller cannot change.
		if ( null === $finding || ! empty( $finding['sensitive'] ) || 'resolved' === $finding['state'] ) {
			return $this->notFound();
		}
		try {
			$change( (int) $finding['id'] );
		} catch ( \Throwable $failure ) {
			return $this->failure( $failure );
		}
		return new WP_REST_Response( $this->present( (array) $this->plugin->findings()->get( (int) $finding['id'] ) ) );
	}

	/**
	 * A finding as the API shows it.
	 *
	 * @param array<string, mixed> $finding Presented finding from the service.
	 * @return array<string, mixed>
	 */
	private function present( array $finding ): array {
		$hidden = ! empty( $finding['sensitive'] );
		return array(
			'id'              => (int) $finding['id'],
			'rule'            => (string) $finding['rule_id'],
			'rule_title'      => (string) $finding['rule_title'],
			'severity'        => (string) $finding['severity'],
			'severity_label'  => (string) $finding['severity_label'],
			'state'           => (string) $finding['state'],
			'message'         => (string) $finding['message'],
			'sensitive'       => $hidden,
			'handover'        => (bool) $finding['handover'],
			'subject_type'    => $hidden ? '' : (string) $finding['subject_type'],
			'subject_id'      => $hidden ? 0 : (int) $finding['subject_id'],
			'first_seen'      => $this->time( $finding['first_seen'] ),
			'last_seen'       => $this->time( $finding['last_seen'] ),
			'snoozed_until'   => $this->time( $finding['snoozed_until'] ),
			'accepted_reason' => $hidden ? '' : (string) $finding['accepted_reason'],
			'resolved_at'     => $this->time( $finding['resolved_at'] ),
		);
	}
}
