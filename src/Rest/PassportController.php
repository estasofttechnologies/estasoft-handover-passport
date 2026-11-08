<?php
/**
 * REST: the summary of the Passport.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Rest;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Findings\Level;
use Estasoft\HandoverPassport\Passport\StatusSummary;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Support\Clock;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Route /passport: counts and the dates ahead. A Sensitive record is not counted or listed for a caller who cannot see it.
 */
final class PassportController extends BaseController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'passport';

	/**
	 * {@inheritDoc}
	 */
	protected function orderbyChoices(): array {
		return array( 'date' );
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
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'canView' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * The summary.
	 *
	 * @param \WP_REST_Request $request Request, not used.
	 */
	public function get_item( $request ): WP_REST_Response {
		$may     = Capabilities::canSeeSensitive();
		$summary = new StatusSummary( $this->plugin );
		$counts  = $summary->counts( $may );
		$dates   = array();
		foreach ( $summary->upcoming( $may, 30 ) as $date ) {
			$dates[] = array(
				'id'         => (int) $date['id'],
				'kind'       => (string) $date['kind'],
				'kind_label' => (string) $date['kind_label'],
				'title'      => (string) $date['title'],
				'date'       => (string) $date['date'],
			);
		}
		return new WP_REST_Response(
			array(
				'generated_at'       => gmdate( 'Y-m-d\TH:i:s\Z', Clock::now()->getTimestamp() ),
				'findings'           => (object) $counts['findings'],
				'readiness'          => $counts['readiness'],
				'records'            => array_merge( $counts['records'], array( 'by_kind' => (object) $counts['records']['by_kind'] ) ),
				'dates_next_30_days' => $counts['dates_next_30_days'],
				'upcoming'           => $dates,
				'last_scan'          => null === $counts['last_scan'] ? null : array(
					'result'      => $counts['last_scan']['result'],
					'finished_at' => $this->time( $counts['last_scan']['finished_at'] ),
				),
			)
		);
	}

	/**
	 * The schema of the summary.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		return $this->add_additional_fields_schema(
			array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'estasoft-passport-summary',
				'type'       => 'object',
				'properties' => array(
					'generated_at'       => array( 'type' => 'string', 'format' => 'date-time', 'readonly' => true ),
					'findings'           => array( 'type' => 'object', 'readonly' => true, 'description' => __( 'Open findings by level. Snoozed and accepted ones are not counted.', 'estasoft-handover-passport' ), 'properties' => array_fill_keys( Level::all(), array( 'type' => 'integer' ) ) ),
					'readiness'          => array( 'type' => 'object', 'readonly' => true, 'description' => __( 'How many of the handover checks are clear: n of m.', 'estasoft-handover-passport' ) ),
					'records'            => array( 'type' => 'object', 'readonly' => true, 'description' => __( 'Counts of records by state and by kind.', 'estasoft-handover-passport' ), 'properties' => array( 'by_kind' => array( 'type' => 'object', 'description' => implode( ', ', Kinds::registerKinds() ) ) ) ),
					'dates_next_30_days' => array( 'type' => 'integer', 'readonly' => true ),
					'upcoming'           => array( 'type' => 'array', 'readonly' => true, 'items' => array( 'type' => 'object' ) ),
					'last_scan'          => array( 'type' => array( 'object', 'null' ), 'readonly' => true ),
				),
			)
		);
	}
}
