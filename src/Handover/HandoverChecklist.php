<?php
/**
 * The handover checklist (HND-1): rules marked Handover, ownership transfers, the last restore test and custom items.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Handover;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the list. A rule item is Done when the rule has no open finding, and a person can mark it Not applicable
 * with a reason. Transfers and the restore test show their own state. Custom items are set by hand.
 */
final class HandoverChecklist {

	public const DONE     = 'done';
	public const NOT_DONE = 'not_done';
	public const NA       = 'na';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Every item, in the order: rules, transfers, restore test, custom items.
	 *
	 * @return list<array{key: string, group: string, label: string, state: string, who: string, when: string, detail: string, id: int}>
	 */
	public function items(): array {
		return array_merge( $this->ruleItems(), $this->transferItems(), $this->restoreItem(), $this->customItems() );
	}

	/**
	 * How many items are Done out of those that apply.
	 *
	 * @return array{done: int, total: int}
	 */
	public function progress(): array {
		$done  = 0;
		$total = 0;
		foreach ( $this->items() as $item ) {
			if ( self::NA === $item['state'] ) {
				continue;
			}
			++$total;
			$done += self::DONE === $item['state'] ? 1 : 0;
		}
		return array( 'done' => $done, 'total' => $total );
	}

	/**
	 * Mark a rule item Not applicable, with a reason, or put it back to automatic.
	 *
	 * @param string $rule_id Rule ID such as HP-DOM-001.
	 * @param bool   $na      True for Not applicable, false to go back to automatic.
	 * @param string $reason  Why, required for Not applicable.
	 * @throws ValidationError When the rule is not on the list or no reason is given.
	 */
	public function setRuleNotApplicable( string $rule_id, bool $na, string $reason = '' ): void {
		$title = $this->ruleTitle( $rule_id );
		if ( '' === $title ) {
			throw new ValidationError( __( 'Rule', 'estasoft-handover-passport' ), __( 'That item is not on the checklist.', 'estasoft-handover-passport' ) );
		}
		$existing = $this->plugin->records()->items()->bySubject( Kinds::HANDSTATE, $rule_id );
		if ( ! $na ) {
			if ( null !== $existing ) {
				$this->plugin->records()->items()->delete( (int) $existing['id'] );
				$this->plugin->changeLog()->add( 'checklist_marked', 'passport', 0, $title, array( 'state' => self::NA ), array( 'state' => 'auto' ) );
			}
			return;
		}
		$reason = trim( $reason );
		if ( '' === $reason ) {
			throw new ValidationError( __( 'Reason', 'estasoft-handover-passport' ), __( 'Say why this does not apply.', 'estasoft-handover-passport' ) );
		}
		$user = wp_get_current_user();
		$this->plugin->records()->saveSystem(
			Kinds::HANDSTATE,
			$rule_id,
			$title,
			array( 'state' => self::NA, 'who' => (string) $user->display_name, 'when' => Clock::today(), 'note' => mb_substr( $reason, 0, 500 ) ),
			array(),
			false
		);
		$this->plugin->changeLog()->add( 'checklist_marked', 'passport', 0, $title, array( 'state' => 'auto' ), array( 'state' => self::NA ), $reason );
	}

	/**
	 * Add a custom item.
	 *
	 * @param string $text  What has to be done.
	 * @param string $owner Who does it.
	 * @param string $due   Due date or an empty string.
	 * @return array<string, mixed> The saved record.
	 * @throws ValidationError When the input is not accepted.
	 */
	public function addCustom( string $text, string $owner = '', string $due = '' ): array {
		return $this->plugin->records()->create( Kinds::CHECKLIST, array( 'text' => $text, 'owner' => $owner, 'due' => $due, 'state' => self::NOT_DONE ) );
	}

	/**
	 * Set the state of a custom item.
	 *
	 * @param int    $id    Record ID.
	 * @param string $state done, not_done or na.
	 * @throws ValidationError When the item or the state is not valid.
	 */
	public function setCustomState( int $id, string $state ): void {
		$record = $this->plugin->records()->get( $id, false );
		if ( null === $record || Kinds::CHECKLIST !== (string) $record['kind'] || ! array_key_exists( $state, Kinds::checklistStates() ) ) {
			throw new ValidationError( __( 'Item', 'estasoft-handover-passport' ), __( 'That item is not on the checklist.', 'estasoft-handover-passport' ) );
		}
		$this->plugin->records()->update( $id, array( 'state' => $state ) );
	}

	/**
	 * Items from the rules marked Handover.
	 *
	 * @return list<array{key: string, group: string, label: string, state: string, who: string, when: string, detail: string, id: int}>
	 */
	private function ruleItems(): array {
		$readiness = $this->plugin->findings()->readiness();
		$meta      = $this->plugin->findings()->meta();
		$checked   = '' === $meta['ran_at'] ? '' : substr( $meta['ran_at'], 0, 10 );
		$out       = array();
		foreach ( $readiness['rules'] as $rule ) {
			$id     = (string) $rule['id'];
			$manual = $this->plugin->records()->items()->bySubject( Kinds::HANDSTATE, $id );
			$data   = null === $manual || ! is_array( $manual['data'] ) ? array() : $manual['data'];
			if ( self::NA === (string) ( $data['state'] ?? '' ) ) {
				$out[] = $this->row( 'rule:' . $id, 'rule', $id . ' ' . (string) $rule['title'], self::NA, (string) ( $data['who'] ?? '' ), (string) ( $data['when'] ?? '' ), (string) ( $data['note'] ?? '' ) );
				continue;
			}
			$detail = $rule['ready'] ? '' : sprintf( /* translators: %d: number of open findings. */ _n( '%d open finding', '%d open findings', (int) $rule['open'], 'estasoft-handover-passport' ), (int) $rule['open'] );
			$out[]  = $this->row( 'rule:' . $id, 'rule', $id . ' ' . (string) $rule['title'], $rule['ready'] ? self::DONE : self::NOT_DONE, $rule['ready'] ? __( 'Checked automatically', 'estasoft-handover-passport' ) : '', $checked, $detail );
		}
		return $out;
	}

	/**
	 * Items from the transfer tracker (OWN-3).
	 *
	 * @return list<array{key: string, group: string, label: string, state: string, who: string, when: string, detail: string, id: int}>
	 */
	private function transferItems(): array {
		$out    = array();
		$states = Kinds::transferStates();
		foreach ( $this->plugin->records()->everything() as $record ) {
			$def = Kinds::get( (string) $record['kind'] );
			if ( empty( $def['ownership'] ) || ! empty( $record['sensitive'] ) ) {
				continue;
			}
			$owner  = (string) $record['legal_owner'];
			$status = (string) ( $record['fields']['transfer_status'] ?? '' );
			if ( ( 'client' === $owner || '' === $owner ) && '' === $status ) {
				continue;
			}
			$state = 'done' === $status ? self::DONE : ( 'not_needed' === $status ? self::NA : self::NOT_DONE );
			$label = sprintf( /* translators: %s: name of an account or asset. */ __( 'Transfer %s to the client', 'estasoft-handover-passport' ), (string) $record['title'] );
			$out[] = $this->row( 'transfer:' . (int) $record['id'], 'transfer', $label, $state, (string) ( $record['fields']['transfer_owner'] ?? '' ), (string) ( $record['fields']['transfer_date'] ?? '' ), (string) ( $states[ $status ] ?? __( 'No status yet', 'estasoft-handover-passport' ) ), (int) $record['id'] );
		}
		return $out;
	}

	/**
	 * The latest restore test (BKP-5): Done when one passed recently enough.
	 *
	 * @return list<array{key: string, group: string, label: string, state: string, who: string, when: string, detail: string, id: int}>
	 */
	private function restoreItem(): array {
		$tests = $this->plugin->restoreTests()->all();
		$pass  = null;
		foreach ( $tests as $test ) {
			if ( 'pass' === (string) $test['result'] ) {
				$pass = $test;
				break;
			}
		}
		$days  = (int) $this->plugin->settings()->get( 'thresholds.restore_test_days', 180 );
		$fresh = null !== $pass && ( strtotime( Clock::today() ) - strtotime( (string) $pass['test_date'] ) ) <= $days * DAY_IN_SECONDS;
		$label = __( 'A restore test has passed recently', 'estasoft-handover-passport' );
		if ( null === $pass ) {
			return array( $this->row( 'restore_test', 'restore', $label, self::NOT_DONE, '', '', __( 'No restore test has passed yet.', 'estasoft-handover-passport' ) ) );
		}
		return array( $this->row( 'restore_test', 'restore', $label, $fresh ? self::DONE : self::NOT_DONE, (string) $pass['who'], (string) $pass['test_date'], $fresh ? '' : __( 'The last passed test is older than the limit in Settings.', 'estasoft-handover-passport' ) ) );
	}

	/**
	 * Custom items.
	 *
	 * @return list<array{key: string, group: string, label: string, state: string, who: string, when: string, detail: string, id: int}>
	 */
	private function customItems(): array {
		$out = array();
		foreach ( $this->plugin->records()->listKind( Kinds::CHECKLIST, false ) as $record ) {
			$state = (string) ( $record['fields']['state'] ?? '' );
			$state = array_key_exists( $state, Kinds::checklistStates() ) ? $state : self::NOT_DONE;
			$due   = (string) ( $record['fields']['due'] ?? '' );
			$out[] = $this->row(
				'custom:' . (int) $record['id'],
				'custom',
				(string) $record['title'],
				$state,
				(string) ( $record['fields']['owner'] ?? '' ),
				self::DONE === $state ? substr( (string) $record['updated_at'], 0, 10 ) : '',
				'' !== $due && self::DONE !== $state ? sprintf( /* translators: %s: a date. */ __( 'Due %s', 'estasoft-handover-passport' ), $due ) : '',
				(int) $record['id']
			);
		}
		return $out;
	}

	/**
	 * One row.
	 *
	 * @param string $key    Stable key.
	 * @param string $group  rule, transfer, restore or custom.
	 * @param string $label  What it is.
	 * @param string $state  done, not_done or na.
	 * @param string $who    Who.
	 * @param string $when   Date.
	 * @param string $detail Extra words.
	 * @param int    $id     Record ID for transfers and custom items.
	 * @return array{key: string, group: string, label: string, state: string, who: string, when: string, detail: string, id: int}
	 */
	private function row( string $key, string $group, string $label, string $state, string $who, string $when, string $detail, int $id = 0 ): array {
		return array( 'key' => $key, 'group' => $group, 'label' => $label, 'state' => $state, 'who' => $who, 'when' => $when, 'detail' => $detail, 'id' => $id );
	}

	/**
	 * Title of a rule on the checklist, or an empty string.
	 *
	 * @param string $rule_id Rule ID.
	 */
	private function ruleTitle( string $rule_id ): string {
		foreach ( $this->plugin->findings()->readiness()['rules'] as $rule ) {
			if ( (string) $rule['id'] === $rule_id ) {
				return $rule_id . ' ' . (string) $rule['title'];
			}
		}
		return '';
	}
}
