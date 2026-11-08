<?php
/**
 * Runs the rules and keeps the stored findings in step (HLT-1, FND-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Findings;

use Estasoft\HandoverPassport\Data\FindingRepository;
use Estasoft\HandoverPassport\Support\Clock;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Compares what the rules see now with what is stored.
 *
 * A finding that is still true keeps its first-seen date and its state. A finding that stopped being true is
 * resolved. A rule that could not run leaves its findings alone and is reported as "Not checked".
 */
final class Engine {

	/**
	 * Constructor.
	 *
	 * @param FindingRepository $findings Findings table.
	 */
	public function __construct( private FindingRepository $findings ) {}

	/**
	 * Run every rule once.
	 *
	 * @param Context              $context  What the rules look at.
	 * @param array<string, mixed> $disabled Disabled rules: ID to reason.
	 * @return array{opened: int, updated: int, resolved: int, not_checked: array<int, string>}
	 */
	public function run( Context $context, array $disabled = array() ): array {
		$now     = Clock::sql();
		$rules   = RuleCatalog::all();
		$stored  = $this->findings->query( array(), 'id', 'ASC' );
		$active  = array();
		$by_rule = array();
		foreach ( $stored as $row ) {
			if ( FindingRepository::RESOLVED === (string) $row['state'] ) {
				continue;
			}
			$identity            = $this->identity( $row );
			$active[ $identity ] = $row;
			$by_rule[ (string) $row['rule_id'] ][ $identity ] = true;
		}

		$summary = array( 'opened' => 0, 'updated' => 0, 'resolved' => 0, 'not_checked' => array() );
		$seen    = array();

		foreach ( $rules as $id => $rule ) {
			if ( isset( $disabled[ $id ] ) ) {
				$candidates = array();
			} else {
				try {
					$candidates = $rule->evaluate( $context );
				} catch ( Throwable $e ) {
					$candidates = null;
				}
			}
			if ( null === $candidates ) {
				$summary['not_checked'][] = $id;
				foreach ( array_keys( $by_rule[ $id ] ?? array() ) as $identity ) {
					$seen[ $identity ] = true;
				}
				continue;
			}

			foreach ( $candidates as $candidate ) {
				$identity          = $candidate->identity( $id );
				$seen[ $identity ] = true;
				$this->apply( $candidate, $id, $active[ $identity ] ?? null, $now, $summary );
			}
		}

		foreach ( $active as $identity => $row ) {
			if ( isset( $seen[ $identity ] ) ) {
				continue;
			}
			$this->findings->update( (int) $row['id'], array( 'state' => FindingRepository::RESOLVED, 'resolved_at' => $now, 'snoozed_until' => null ) );
			++$summary['resolved'];
			/**
			 * Fires when a finding stops being true.
			 *
			 * @param array<string, mixed> $finding Finding as stored.
			 */
			do_action( 'estasoft_hp_finding_resolved', array_merge( $row, array( 'state' => FindingRepository::RESOLVED, 'resolved_at' => $now ) ) );
		}

		return $summary;
	}

	/**
	 * Create or refresh one finding.
	 *
	 * @param Candidate                                                                        $candidate Candidate.
	 * @param string                                                                           $rule_id   Rule ID.
	 * @param array<string, mixed>|null                                                        $row       Stored finding or null.
	 * @param string                                                                           $now       UTC date-time.
	 * @param array{opened: int, updated: int, resolved: int, not_checked: array<int, string>} $summary   Counters.
	 */
	private function apply( Candidate $candidate, string $rule_id, ?array $row, string $now, array &$summary ): void {
		if ( null === $row ) {
			$new = array(
				'rule_id'         => $rule_id,
				'subject_type'    => $candidate->subject_type,
				'subject_id'      => $candidate->subject_id,
				'subject_key'     => $candidate->subject_key,
				'severity'        => $candidate->level,
				'state'           => FindingRepository::OPEN,
				'message_values'  => $candidate->values,
				'first_seen'      => $now,
				'last_seen'       => $now,
				'snoozed_until'   => null,
				'accepted_reason' => '',
				'accepted_by'     => 0,
				'resolved_at'     => null,
			);
			$id  = $this->findings->insert( $new );
			++$summary['opened'];
			/**
			 * Fires when a new finding appears.
			 *
			 * @param array<string, mixed> $finding Finding as stored.
			 */
			do_action( 'estasoft_hp_finding_opened', array_merge( $new, array( 'id' => $id ) ) );
			return;
		}

		$change = array( 'severity' => $candidate->level, 'message_values' => $candidate->values, 'last_seen' => $now );
		if ( FindingRepository::SNOOZED === (string) $row['state'] && ! empty( $row['snoozed_until'] ) && (string) $row['snoozed_until'] <= $now ) {
			$change['state']         = FindingRepository::OPEN;
			$change['snoozed_until'] = null;
		}
		$this->findings->update( (int) $row['id'], $change );
		++$summary['updated'];
	}

	/**
	 * Identity of a stored finding.
	 *
	 * @param array<string, mixed> $row Stored finding.
	 */
	private function identity( array $row ): string {
		return $row['rule_id'] . '|' . $row['subject_type'] . '|' . (int) $row['subject_id'] . '|' . $row['subject_key'];
	}
}
