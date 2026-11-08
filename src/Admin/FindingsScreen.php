<?php
/**
 * Findings: facts about the Passport that need a person (HLT-1 to HLT-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Data\FindingRepository;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Findings\Level;
use Estasoft\HandoverPassport\Findings\RuleCatalog;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Lists findings by state and lets people snooze, accept or reopen them.
 */
final class FindingsScreen extends Screen {

	public const SLUG        = 'estasoft-passport-findings';
	public const ACTION      = 'estasoft_hp_finding';
	public const RULE_ACTION = 'estasoft_hp_rule';
	public const CHECK       = 'estasoft_hp_check_rules';

	/**
	 * Attach the form handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handleFinding' ) );
		add_action( 'admin_post_' . self::RULE_ACTION, array( $this, 'handleRule' ) );
		add_action( 'admin_post_' . self::CHECK, array( $this, 'handleCheck' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Findings', 'estasoft-handover-passport' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tabs(): array {
		return array(
			'open'     => __( 'Open', 'estasoft-handover-passport' ),
			'snoozed'  => __( 'Snoozed', 'estasoft-handover-passport' ),
			'accepted' => __( 'Accepted', 'estasoft-handover-passport' ),
			'resolved' => __( 'Resolved', 'estasoft-handover-passport' ),
			'rules'    => __( 'Rules', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function describe( string $tab ): string {
		if ( 'rules' === $tab ) {
			return __( 'The rules that are checked, with a switch for each. A rule that is off needs a reason.', 'estasoft-handover-passport' );
		}
		if ( FindingRepository::OPEN !== $tab ) {
			return __( 'Each finding states a fact and a date. It clears by itself when the fact stops being true.', 'estasoft-handover-passport' );
		}
		$counts = $this->plugin->findings()->counts();
		$open   = (int) array_sum( array_map( 'intval', $counts ) );
		return sprintf(
			/* translators: 1: number of open findings, 2: number of rules. */
			_n( '%1$d open finding from %2$d rules. A scan runs after each daily check.', '%1$d open findings from %2$d rules. A scan runs after each daily check.', $open, 'estasoft-handover-passport' ),
			$open,
			count( RuleCatalog::all() )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function extra( string $tab ): string {
		if ( ! Capabilities::can( Capabilities::EDIT ) || 'rules' === $tab ) {
			return '';
		}
		ob_start();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
		Form::nonce( self::CHECK );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::CHECK ) . '"><input type="hidden" name="return" value="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) ) ) . '">';
		echo '<button type="submit" class="btn pri">' . Icons::svg( 'refresh', 16 ) . esc_html__( 'Run check now', 'estasoft-handover-passport' ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() returns fixed markup.
		return (string) ob_get_clean();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tab( string $tab ): void {
		if ( 'rules' === $tab ) {
			$this->rules();
			return;
		}
		$this->list( $tab );
	}

	/**
	 * A list of findings in one state.
	 *
	 * @param string $state open, snoozed, accepted or resolved.
	 */
	private function list( string $state ): void {
		$service  = $this->plugin->findings();
		$severity = Request::key( 'severity' );
		$all      = $service->list( array( 'state' => $state, 'severity' => in_array( $severity, Level::all(), true ) ? $severity : '' ) );
		if ( FindingRepository::RESOLVED === $state ) {
			usort( $all, static fn( array $a, array $b ): int => strcmp( (string) $b['resolved_at'], (string) $a['resolved_at'] ) );
			$all = array_slice( $all, 0, 100 );
		}
		$links = new FindingLinks( $this->plugin );

		if ( FindingRepository::OPEN === $state ) {
			$meta = $service->meta();
			if ( array() !== $meta['not_checked'] ) {
				echo '<div class="msg in">' . Icons::svg( 'info', 16 ) . '<div>' . esc_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() returns fixed markup.
					sprintf(
						/* translators: %d: number of rules. */
						_n( '%d rule could not be checked yet because the data it needs is missing. It is not counted as passed.', '%d rules could not be checked yet because the data they need is missing. They are not counted as passed.', count( $meta['not_checked'] ), 'estasoft-handover-passport' ),
						count( $meta['not_checked'] )
					)
				) . ' <a href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'rules' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'See the rules', 'estasoft-handover-passport' ) . '</a></div></div>';
			}
		}

		if ( array() === $all ) {
			echo '<div class="estasoft-hp-empty"><p>' . esc_html( $this->emptyText( $state ) ) . '</p></div>';
			return;
		}

		echo '<div class="estasoft-hp-register" data-estasoft-wrap>';
		$this->toolbar( $all, $links, FindingRepository::OPEN === $state );
		if ( FindingRepository::OPEN === $state ) {
			foreach ( Level::all() as $level ) {
				$group = array_values( array_filter( $all, static fn( array $f ): bool => (string) $f['severity'] === $level ) );
				if ( array() === $group ) {
					continue;
				}
				$this->group( Level::label( $level ) . ' (' . count( $group ) . ')', $group, $state, $links );
			}
		} else {
			$this->group( '' === $severity ? '' : Level::label( $severity ), $all, $state, $links );
		}
		echo '</div>';
	}

	/**
	 * The search box and the Level and Where filters. They narrow what is on the page; nothing is sent.
	 *
	 * @param array<int, array<string, mixed>> $all   Findings on the page.
	 * @param FindingLinks                     $links Link helper.
	 * @param bool                             $level Whether to offer the Level filter.
	 */
	private function toolbar( array $all, FindingLinks $links, bool $level ): void {
		$where = array();
		foreach ( $all as $finding ) {
			$name = $links->where( $finding );
			if ( '' !== $name ) {
				$where[ sanitize_title( $name ) ] = $name;
			}
		}
		asort( $where );
		echo '<div class="tb"><label class="screen-reader-text" for="estasoft-hp-find-findings">' . esc_html__( 'Search findings', 'estasoft-handover-passport' ) . '</label>';
		echo '<input class="fld" type="search" id="estasoft-hp-find-findings" data-estasoft-find placeholder="' . esc_attr__( 'Search findings', 'estasoft-handover-passport' ) . '" autocomplete="off">';
		if ( $level ) {
			echo '<label class="screen-reader-text" for="estasoft-hp-find-level">' . esc_html__( 'Level', 'estasoft-handover-passport' ) . '</label>';
			echo '<select class="fld" id="estasoft-hp-find-level" data-estasoft-filter="level"><option value="">' . esc_html__( 'All levels', 'estasoft-handover-passport' ) . '</option>';
			foreach ( Level::all() as $key ) {
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( Level::label( $key ) ) . '</option>';
			}
			echo '</select>';
		}
		if ( count( $where ) > 1 ) {
			echo '<label class="screen-reader-text" for="estasoft-hp-find-where">' . esc_html__( 'Where', 'estasoft-handover-passport' ) . '</label>';
			echo '<select class="fld" id="estasoft-hp-find-where" data-estasoft-filter="where"><option value="">' . esc_html__( 'Everywhere', 'estasoft-handover-passport' ) . '</option>';
			foreach ( $where as $key => $name ) {
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $name ) . '</option>';
			}
			echo '</select>';
		}
		echo '</div>';
	}

	/**
	 * One card with a table of findings.
	 *
	 * @param string                           $title    Card title, or an empty string for none.
	 * @param array<int, array<string, mixed>> $findings Findings.
	 * @param string                           $state    State.
	 * @param FindingLinks                     $links    Link helper.
	 */
	private function group( string $title, array $findings, string $state, FindingLinks $links ): void {
		$can_edit = Capabilities::can( Capabilities::EDIT );
		echo '<section class="card flat">';
		if ( '' !== $title ) {
			echo '<div class="hd"><h2>' . esc_html( $title ) . '</h2></div>';
		}
		echo '<div class="bdy"><div class="scr"><table class="tbl cards estasoft-hp-table estasoft-hp-findings" data-estasoft-list><caption class="screen-reader-text">' . esc_html( '' !== $title ? $title : __( 'Findings', 'estasoft-handover-passport' ) ) . '</caption><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Level', 'estasoft-handover-passport' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Finding', 'estasoft-handover-passport' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Where', 'estasoft-handover-passport' ) . '</th>';
		echo '<th scope="col">' . esc_html( FindingRepository::RESOLVED === $state ? __( 'Resolved on', 'estasoft-handover-passport' ) : __( 'First seen', 'estasoft-handover-passport' ) ) . '</th>';
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'estasoft-handover-passport' ) . '</span></th>';
		echo '</tr></thead><tbody>';
		foreach ( $findings as $finding ) {
			$where = $links->where( $finding );
			echo '<tr data-f-level="|' . esc_attr( (string) $finding['severity'] ) . '|" data-f-where="|' . esc_attr( sanitize_title( $where ) ) . '|">';
			echo '<td class="t1" data-label="' . esc_attr__( 'Level', 'estasoft-handover-passport' ) . '">' . View::badge( (string) $finding['severity'], (string) $finding['severity_label'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			echo '<td data-label="' . esc_attr__( 'Finding', 'estasoft-handover-passport' ) . '"><b>' . esc_html( (string) $finding['message'] ) . '</b><span class="sub">' . esc_html( (string) $finding['rule_id'] . ' · ' . (string) $finding['rule_title'] ) . '</span>';
			if ( FindingRepository::SNOOZED === $state && '' !== (string) $finding['snoozed_until'] ) {
				echo '<span class="sub">' . esc_html(
					sprintf(
						/* translators: %s: date. */
						__( 'Snoozed until %s', 'estasoft-handover-passport' ),
						Dates::display( (string) $finding['snoozed_until'] )
					)
				) . '</span>';
			}
			if ( FindingRepository::ACCEPTED === $state ) {
				echo '<span class="sub">' . esc_html(
					sprintf(
						/* translators: %s: reason given when the finding was accepted. */
						__( 'Accepted: %s', 'estasoft-handover-passport' ),
						(string) $finding['accepted_reason']
					)
				) . '</span>';
			}
			echo '</td><td data-label="' . esc_attr__( 'Where', 'estasoft-handover-passport' ) . '">' . esc_html( $where ) . '</td>';
			echo '<td data-label="' . esc_attr( FindingRepository::RESOLVED === $state ? __( 'Resolved on', 'estasoft-handover-passport' ) : __( 'First seen', 'estasoft-handover-passport' ) ) . '">' . esc_html( Dates::display( FindingRepository::RESOLVED === $state ? (string) $finding['resolved_at'] : (string) $finding['first_seen'] ) ) . '</td><td class="act estasoft-hp-actions" data-label="">';
			if ( FindingRepository::RESOLVED !== $state ) {
				echo '<a class="btn sm" href="' . esc_url( $links->url( $finding ) ) . '">' . esc_html( '' === $where ? __( 'Go and fix', 'estasoft-handover-passport' ) : __( 'Open', 'estasoft-handover-passport' ) ) . '</a> ';
			}
			if ( $can_edit ) {
				$this->tools( $finding, $state );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div></div></section>';
	}

	/**
	 * Snooze, accept and reopen controls for one finding.
	 *
	 * @param array<string, mixed> $finding Presented finding.
	 * @param string               $state   Current state.
	 */
	private function tools( array $finding, string $state ): void {
		$action = esc_url( admin_url( 'admin-post.php' ) );
		$id     = (int) $finding['id'];
		if ( FindingRepository::OPEN === $state ) {
			echo '<details class="estasoft-hp-finding__tools"><summary class="btn sm">' . Icons::svg( 'clock', 16 ) . esc_html__( 'Snooze or accept', 'estasoft-handover-passport' ) . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() returns fixed markup.
			echo '<form method="post" action="' . $action . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			$this->hidden( $id );
			echo '<label for="estasoft-hp-days-' . esc_attr( (string) $id ) . '">' . esc_html__( 'Hide for', 'estasoft-handover-passport' ) . '</label>';
			echo '<select id="estasoft-hp-days-' . esc_attr( (string) $id ) . '" name="days">';
			foreach ( array( 7, 30, 90 ) as $days ) {
				/* translators: %d: number of days. */
				echo '<option value="' . esc_attr( (string) $days ) . '">' . esc_html( sprintf( _n( '%d day', '%d days', $days, 'estasoft-handover-passport' ), $days ) ) . '</option>';
			}
			echo '</select> <button type="submit" name="op" value="snooze" class="btn">' . esc_html__( 'Snooze', 'estasoft-handover-passport' ) . '</button>';
			echo '<p><label for="estasoft-hp-reason-' . esc_attr( (string) $id ) . '">' . esc_html__( 'Or accept it, and say why', 'estasoft-handover-passport' ) . '</label>';
			echo '<input type="text" class="widefat" maxlength="500" id="estasoft-hp-reason-' . esc_attr( (string) $id ) . '" name="reason" autocomplete="off"></p>';
			echo '<button type="submit" name="op" value="accept" class="btn">' . esc_html__( 'Accept with this reason', 'estasoft-handover-passport' ) . '</button>';
			echo '</form></details>';
			return;
		}
		if ( in_array( $state, array( FindingRepository::SNOOZED, FindingRepository::ACCEPTED ), true ) ) {
			echo '<form method="post" action="' . $action . '" class="estasoft-hp-inline">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			$this->hidden( $id );
			echo '<button type="submit" name="op" value="reopen" class="btn sm">' . esc_html__( 'Reopen', 'estasoft-handover-passport' ) . '</button></form>';
		}
	}

	/**
	 * Hidden fields shared by the finding forms.
	 *
	 * @param int $id Finding ID.
	 */
	private function hidden( int $id ): void {
		Form::nonce( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		echo '<input type="hidden" name="return" value="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => Request::key( 'tab', 'open' ) ), admin_url( 'admin.php' ) ) ) . '">';
	}

	/**
	 * Text for an empty list.
	 *
	 * @param string $state State.
	 */
	private function emptyText( string $state ): string {
		switch ( $state ) {
			case FindingRepository::SNOOZED:
				return __( 'Nothing is snoozed.', 'estasoft-handover-passport' );
			case FindingRepository::ACCEPTED:
				return __( 'Nothing has been accepted. Accepted findings stay here with their reason, and exports show them.', 'estasoft-handover-passport' );
			case FindingRepository::RESOLVED:
				return __( 'Nothing has been resolved yet.', 'estasoft-handover-passport' );
			default:
				return __( 'No open findings. This means the rules found nothing to report on the data they could read. It is not a statement that the site is in good shape.', 'estasoft-handover-passport' );
		}
	}

	/**
	 * The rules and their switches.
	 */
	private function rules(): void {
		$service   = $this->plugin->findings();
		$disabled  = $service->disabledRules();
		$not_check = $service->meta()['not_checked'];
		$can_conf  = Capabilities::can( Capabilities::CONFIGURE );
		$support   = new \Estasoft\HandoverPassport\Scan\SupportDates();

		echo '<p class="description">' . esc_html__( 'Every rule has a fixed ID. Thresholds can be changed in Settings. A rule that is switched off keeps its reason, and exports show it.', 'estasoft-handover-passport' ) . '</p>';
		if ( $support->isOld( Clock::today() ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html(
				sprintf(
					/* translators: %s: date the support-date table was last checked. */
					__( 'The table of PHP and WordPress support dates was last checked on %s, more than 12 months ago. Update the plugin to refresh it.', 'estasoft-handover-passport' ),
					Dates::displayDate( $support->reviewed() )
				)
			) . '</p></div>';
		}

		echo '<table class="widefat striped estasoft-hp-table"><thead><tr>';
		foreach ( array( __( 'Rule', 'estasoft-handover-passport' ), __( 'What it looks for', 'estasoft-handover-passport' ), __( 'Highest level', 'estasoft-handover-passport' ), __( 'Counts in handover', 'estasoft-handover-passport' ), __( 'Status', 'estasoft-handover-passport' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( RuleCatalog::all() as $rule ) {
			echo '<tr><td><code>' . esc_html( $rule->id ) . '</code></td><td>' . esc_html( $rule->title ) . '</td><td>' . View::badge( $rule->level, Level::label( $rule->level ) ) . '</td><td>' . esc_html( View::yesNo( $rule->handover ) ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			if ( isset( $disabled[ $rule->id ] ) ) {
				echo esc_html__( 'Switched off', 'estasoft-handover-passport' ) . ': ' . esc_html( $disabled[ $rule->id ] );
				if ( $can_conf ) {
					$this->ruleForm( $rule->id, 'enable' );
				}
			} else {
				echo in_array( $rule->id, $not_check, true ) ? esc_html__( 'Not checked yet: data is missing', 'estasoft-handover-passport' ) : esc_html__( 'On', 'estasoft-handover-passport' );
				if ( $can_conf ) {
					$this->ruleForm( $rule->id, 'disable' );
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Switch form for one rule.
	 *
	 * @param string $rule_id Rule ID.
	 * @param string $op      enable or disable.
	 */
	private function ruleForm( string $rule_id, string $op ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-finding__tools">';
		Form::nonce( self::RULE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::RULE_ACTION ) . '"><input type="hidden" name="rule" value="' . esc_attr( $rule_id ) . '">';
		if ( 'disable' === $op ) {
			echo '<details><summary>' . esc_html__( 'Switch off', 'estasoft-handover-passport' ) . '</summary><label for="estasoft-hp-off-' . esc_attr( $rule_id ) . '">' . esc_html__( 'Reason', 'estasoft-handover-passport' ) . '</label><input type="text" class="widefat" maxlength="500" id="estasoft-hp-off-' . esc_attr( $rule_id ) . '" name="reason" autocomplete="off"> <button type="submit" name="op" value="disable" class="button button-small">' . esc_html__( 'Switch off', 'estasoft-handover-passport' ) . '</button></details>';
		} else {
			echo ' <button type="submit" name="op" value="enable" class="button button-small">' . esc_html__( 'Switch on', 'estasoft-handover-passport' ) . '</button>';
		}
		echo '</form>';
	}

	/**
	 * Redirect back with a notice.
	 *
	 * @param string $notice Notice key.
	 */
	private function back( string $notice ): void {
		$raw  = Request::postedUrl( 'return' );
		$back = ( '' !== $raw && 0 === strpos( $raw, admin_url( 'admin.php' ) ) ) ? $raw : add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) );
		wp_safe_redirect( add_query_arg( 'estasoft_hp_notice', $notice, $back ) );
		exit;
	}

	/**
	 * Snooze, accept or reopen.
	 */
	public function handleFinding(): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$service = $this->plugin->findings();
		$id      = absint( Request::post( 'id' ) );
		$op      = Request::post( 'op' );
		try {
			if ( 'snooze' === $op ) {
				$service->snooze( $id, absint( Request::post( 'days' ) ) );
				$notice = 'finding_snoozed';
			} elseif ( 'accept' === $op ) {
				$service->accept( $id, Request::postedTextarea( 'reason' ) );
				$notice = 'finding_accepted';
			} elseif ( 'reopen' === $op ) {
				$service->reopen( $id );
				$notice = 'finding_reopened';
			} else {
				$notice = 'denied';
			}
		} catch ( SecretFound $e ) {
			$notice = 'finding_secret';
		} catch ( ValidationError $e ) {
			$notice = 'accept' === $op ? 'finding_reason' : 'finding_gone';
		}
		$this->back( $notice );
	}

	/**
	 * Switch a rule on or off.
	 */
	public function handleRule(): void {
		if ( ! Capabilities::can( Capabilities::CONFIGURE ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::RULE_ACTION );
		$service = $this->plugin->findings();
		$rule    = strtoupper( Request::post( 'rule' ) );
		try {
			if ( 'disable' === Request::post( 'op' ) ) {
				$service->disableRule( $rule, Request::post( 'reason' ) );
				$notice = 'rule_off';
			} else {
				$service->enableRule( $rule );
				$notice = 'rule_on';
			}
		} catch ( SecretFound $e ) {
			$notice = 'finding_secret';
		} catch ( ValidationError $e ) {
			$notice = 'finding_reason';
		}
		$this->back( $notice );
	}

	/**
	 * Run every rule now.
	 */
	public function handleCheck(): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::CHECK );
		$this->plugin->findings()->run( true );
		$this->back( 'rules_checked' );
	}
}
