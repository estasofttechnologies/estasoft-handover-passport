<?php
/**
 * Findings for screens, commands and the API (HLT-1 to HLT-5, PRT-7).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Findings;

use Estasoft\HandoverPassport\Changes\ChangeLog;
use Estasoft\HandoverPassport\Data\FindingRepository;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the engine and answers questions about its results.
 */
final class FindingService {

	public const META_OPTION = 'estasoft_hp_findings_meta';

	/**
	 * Whether a run is already queued for the end of this request.
	 *
	 * @var bool
	 */
	private bool $queued = false;

	/**
	 * Constructor.
	 *
	 * @param Plugin            $plugin   Container.
	 * @param FindingRepository $findings Findings table.
	 * @param ChangeLog         $log      Change log.
	 */
	public function __construct( private Plugin $plugin, private FindingRepository $findings, private ChangeLog $log ) {}

	/**
	 * Run the rules now.
	 *
	 * @param bool $deep Check snapshot hashes again (the daily task and the Run now button).
	 * @return array{opened: int, updated: int, resolved: int, not_checked: list<string>}
	 */
	public function run( bool $deep = false ): array {
		$context = ( new ContextFactory( $this->plugin ) )->build( $deep );
		$summary = ( new Engine( $this->findings ) )->run( $context, $this->disabledRules() );
		update_option( self::META_OPTION, array( 'ran_at' => Clock::sql(), 'not_checked' => $summary['not_checked'] ), false );
		return $summary;
	}

	/**
	 * Run once at the end of this request, however many times it is asked for (FND-3).
	 */
	public function queue(): void {
		if ( $this->queued ) {
			return;
		}
		$this->queued = true;
		add_action( 'shutdown', array( $this, 'runQueued' ), 20 );
	}

	/**
	 * Shutdown callback for queue().
	 */
	public function runQueued(): void {
		$this->queued = false;
		try {
			$this->run();
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Rules that were not checked on the last run, and when it ran.
	 *
	 * @return array{ran_at: string, not_checked: list<string>}
	 */
	public function meta(): array {
		$meta = (array) get_option( self::META_OPTION, array() );
		return array( 'ran_at' => (string) ( $meta['ran_at'] ?? '' ), 'not_checked' => array_values( array_map( 'strval', (array) ( $meta['not_checked'] ?? array() ) ) ) );
	}

	/**
	 * Disabled rules: ID to reason.
	 *
	 * @return array<string, string>
	 */
	public function disabledRules(): array {
		return array_map( 'strval', (array) $this->plugin->settings()->get( 'disabled_rules', array() ) );
	}

	/**
	 * Findings with their rule, message and link, most urgent first.
	 *
	 * @param array<string, mixed> $filter Optional state, severity and rule_id.
	 * @return list<array<string, mixed>>
	 */
	public function list( array $filter = array() ): array {
		$rules = RuleCatalog::all();
		$out   = array();
		foreach ( $this->findings->query( array(), 'last_seen', 'DESC' ) as $row ) {
			$state = $this->effectiveState( $row );
			if ( isset( $filter['state'] ) && '' !== $filter['state'] && $filter['state'] !== $state ) {
				continue;
			}
			if ( isset( $filter['severity'] ) && '' !== $filter['severity'] && $filter['severity'] !== (string) $row['severity'] ) {
				continue;
			}
			if ( isset( $filter['rule_id'] ) && '' !== $filter['rule_id'] && $filter['rule_id'] !== (string) $row['rule_id'] ) {
				continue;
			}
			$out[] = $this->present( $row, $rules[ (string) $row['rule_id'] ] ?? null, $state );
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				$by_level = Level::weight( (string) $a['severity'] ) <=> Level::weight( (string) $b['severity'] );
				return 0 !== $by_level ? $by_level : strcmp( (string) $a['first_seen'], (string) $b['first_seen'] );
			}
		);
		return $out;
	}

	/**
	 * One finding presented for display.
	 *
	 * @param int $id Finding ID.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->findings->find( $id );
		if ( null === $row ) {
			return null;
		}
		$rules = RuleCatalog::all();
		return $this->present( $row, $rules[ (string) $row['rule_id'] ] ?? null, $this->effectiveState( $row ) );
	}

	/**
	 * Open findings per level (snoozed and accepted ones are not counted).
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		$counts = array_fill_keys( Level::all(), 0 );
		foreach ( $this->findings->query( array(), 'id', 'ASC' ) as $row ) {
			if ( FindingRepository::OPEN === $this->effectiveState( $row ) && isset( $counts[ (string) $row['severity'] ] ) ) {
				++$counts[ (string) $row['severity'] ];
			}
		}
		return $counts;
	}

	/**
	 * The most urgent open findings.
	 *
	 * @param int $limit How many.
	 * @return list<array<string, mixed>>
	 */
	public function top( int $limit = 5 ): array {
		return array_slice( $this->list( array( 'state' => FindingRepository::OPEN ) ), 0, max( 1, $limit ) );
	}

	/**
	 * Readiness: the rules marked Handover and whether each is clear (PRT-7).
	 *
	 * @return array{ready: int, total: int, rules: list<array<string, mixed>>}
	 */
	public function readiness(): array {
		$meta     = $this->meta();
		$disabled = $this->disabledRules();
		$open     = array();
		foreach ( $this->findings->query( array(), 'id', 'ASC' ) as $row ) {
			if ( ! in_array( $this->effectiveState( $row ), array( FindingRepository::OPEN, FindingRepository::SNOOZED ), true ) ) {
				continue;
			}
			$open[ (string) $row['rule_id'] ] = ( $open[ (string) $row['rule_id'] ] ?? 0 ) + 1;
		}
		$rules = array();
		$ready = 0;
		foreach ( RuleCatalog::all() as $rule ) {
			if ( ! $rule->handover || isset( $disabled[ $rule->id ] ) || in_array( $rule->id, $meta['not_checked'], true ) ) {
				continue;
			}
			$count   = $open[ $rule->id ] ?? 0;
			$rules[] = array( 'id' => $rule->id, 'title' => $rule->title, 'ready' => 0 === $count, 'open' => $count, 'screen' => $rule->screen );
			if ( 0 === $count ) {
				++$ready;
			}
		}
		return array( 'ready' => $ready, 'total' => count( $rules ), 'rules' => $rules );
	}

	/**
	 * Hide a finding for a number of days.
	 *
	 * @param int $id   Finding ID.
	 * @param int $days 7, 30 or 90.
	 * @throws ValidationError When the finding or the length is not valid.
	 */
	public function snooze( int $id, int $days ): void {
		$row = $this->requireActive( $id );
		if ( ! in_array( $days, array( 7, 30, 90 ), true ) ) {
			throw new ValidationError( __( 'Snooze', 'estasoft-handover-passport' ), __( 'Choose 7, 30 or 90 days.', 'estasoft-handover-passport' ) );
		}
		$until = Clock::now()->modify( sprintf( '+%d days', $days ) )->format( 'Y-m-d H:i:s' );
		$this->findings->update( (int) $row['id'], array( 'state' => FindingRepository::SNOOZED, 'snoozed_until' => $until ) );
	}

	/**
	 * Accept a finding with a reason. It stays until the condition clears, and exports show the reason.
	 *
	 * @param int    $id     Finding ID.
	 * @param string $reason Why it is accepted. Must not hold a secret.
	 * @throws ValidationError When the reason is empty.
	 * @throws \Estasoft\HandoverPassport\Security\SecretFound When the reason looks like a secret.
	 */
	public function accept( int $id, string $reason ): void {
		$row    = $this->requireActive( $id );
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( '' === $reason ) {
			throw new ValidationError( __( 'Reason', 'estasoft-handover-passport' ), __( 'Say why this finding is accepted.', 'estasoft-handover-passport' ) );
		}
		$this->plugin->secretGuard()->assertClean( array( 'reason' => $reason ), __( 'Reason', 'estasoft-handover-passport' ) );
		$this->findings->update( (int) $row['id'], array( 'state' => FindingRepository::ACCEPTED, 'accepted_reason' => mb_substr( $reason, 0, 500 ), 'accepted_by' => get_current_user_id(), 'snoozed_until' => null ) );
		$presented = $this->get( (int) $row['id'] );
		$this->log->add( 'finding_accepted', 'finding', (int) $row['id'], (string) ( $presented['message'] ?? (string) $row['rule_id'] ), array(), array(), mb_substr( $reason, 0, 500 ) );
	}

	/**
	 * Put a snoozed or accepted finding back to open.
	 *
	 * @param int $id Finding ID.
	 * @throws ValidationError When the finding does not exist.
	 */
	public function reopen( int $id ): void {
		$row = $this->requireActive( $id );
		$this->findings->update( (int) $row['id'], array( 'state' => FindingRepository::OPEN, 'snoozed_until' => null, 'accepted_reason' => '', 'accepted_by' => 0 ) );
	}

	/**
	 * Switch a rule off with a reason that exports show (HLT-4).
	 *
	 * @param string $rule_id Rule ID.
	 * @param string $reason  Why.
	 * @throws ValidationError When the rule is unknown or the reason is empty.
	 * @throws \Estasoft\HandoverPassport\Security\SecretFound When the reason looks like a secret.
	 */
	public function disableRule( string $rule_id, string $reason ): void {
		if ( ! isset( RuleCatalog::all()[ $rule_id ] ) ) {
			throw new ValidationError( __( 'Rule', 'estasoft-handover-passport' ), __( 'That rule does not exist.', 'estasoft-handover-passport' ) );
		}
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( '' === $reason ) {
			throw new ValidationError( __( 'Reason', 'estasoft-handover-passport' ), __( 'Say why this rule is switched off.', 'estasoft-handover-passport' ) );
		}
		$this->plugin->secretGuard()->assertClean( array( 'reason' => $reason ), __( 'Reason', 'estasoft-handover-passport' ) );
		$disabled             = $this->disabledRules();
		$disabled[ $rule_id ] = mb_substr( $reason, 0, 500 );
		$this->plugin->settings()->set( 'disabled_rules', $disabled );
		$this->log->add( 'rule_disabled', 'rule', 0, $rule_id, array(), array(), mb_substr( $reason, 0, 500 ) );
		$this->queue();
	}

	/**
	 * Switch a rule back on.
	 *
	 * @param string $rule_id Rule ID.
	 */
	public function enableRule( string $rule_id ): void {
		$disabled = $this->disabledRules();
		if ( ! isset( $disabled[ $rule_id ] ) ) {
			return;
		}
		unset( $disabled[ $rule_id ] );
		$this->plugin->settings()->set( 'disabled_rules', $disabled );
		$this->log->add( 'rule_enabled', 'rule', 0, $rule_id );
		$this->queue();
	}

	/**
	 * State to show: a snooze that ended counts as open even before the next run.
	 *
	 * @param array<string, mixed> $row Stored finding.
	 */
	private function effectiveState( array $row ): string {
		if ( FindingRepository::SNOOZED === (string) $row['state'] && ! empty( $row['snoozed_until'] ) && (string) $row['snoozed_until'] <= Clock::sql() ) {
			return FindingRepository::OPEN;
		}
		return (string) $row['state'];
	}

	/**
	 * A finding that is not resolved.
	 *
	 * @param int $id Finding ID.
	 * @return array<string, mixed>
	 * @throws ValidationError When missing or already resolved.
	 */
	private function requireActive( int $id ): array {
		$row = $this->findings->find( $id );
		if ( null === $row || FindingRepository::RESOLVED === (string) $row['state'] ) {
			throw new ValidationError( __( 'Finding', 'estasoft-handover-passport' ), __( 'That finding no longer exists.', 'estasoft-handover-passport' ) );
		}
		return $row;
	}

	/**
	 * Add the rule's words and link to a stored finding.
	 *
	 * @param array<string, mixed> $row   Stored finding.
	 * @param Rule|null            $rule  Its rule, or null when the rule no longer exists.
	 * @param string               $state Effective state.
	 * @return array<string, mixed>
	 */
	private function present( array $row, ?Rule $rule, string $state ): array {
		$values = (array) $row['message_values'];
		return array(
			'id'              => (int) $row['id'],
			'rule_id'         => (string) $row['rule_id'],
			'severity'        => (string) $row['severity'],
			'severity_label'  => Level::label( (string) $row['severity'] ),
			'state'           => $state,
			'subject_type'    => (string) $row['subject_type'],
			'subject_id'      => (int) $row['subject_id'],
			'subject_key'     => (string) $row['subject_key'],
			'message'         => null === $rule ? __( 'This finding comes from a rule that is no longer available.', 'estasoft-handover-passport' ) : $rule->message( $values ),
			'rule_title'      => null === $rule ? (string) $row['rule_id'] : $rule->title,
			'handover'        => null !== $rule && $rule->handover,
			'screen'          => null === $rule ? array( 'page' => '', 'tab' => '' ) : $rule->screen,
			'first_seen'      => (string) $row['first_seen'],
			'last_seen'       => (string) $row['last_seen'],
			'snoozed_until'   => (string) ( $row['snoozed_until'] ?? '' ),
			'accepted_reason' => (string) ( $row['accepted_reason'] ?? '' ),
			'resolved_at'     => (string) ( $row['resolved_at'] ?? '' ),
		);
	}
}
