<?php
/**
 * Overview: what Passport found on the last scan and what changed since (ADM-1, PRT-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\Freshness;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Findings\Level;
use Estasoft\HandoverPassport\Handover\SinceHandover;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Reminders\UpcomingDates;
use Estasoft\HandoverPassport\Scan\ScanService;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Setup\SetupService;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The first screen.
 */
final class OverviewScreen {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Print the screen.
	 */
	public function render(): void {
		if ( ! Capabilities::can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to view Passport.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}

		$passport = $this->plugin->passport()->all();
		$scans    = $this->plugin->scanService();
		$latest   = $scans->latest();
		$facts    = $scans->latestFacts();
		if ( ! Capabilities::canSeeSensitive() ) {
			$facts = ScanService::redact( $facts );
		}

		if ( array() === $facts ) {
			View::open( __( 'Overview', 'estasoft-handover-passport' ), null, __( 'Nothing has been recorded yet.', 'estasoft-handover-passport' ) );
			$this->resumeBar();
			$this->setupCard();
			$this->firstRun( $latest );
			View::close();
			return;
		}

		View::open( __( 'Overview', 'estasoft-handover-passport' ), null, $this->summary( $passport, $latest ), $this->headerActions( $passport ) );
		$this->resumeBar();
		$this->setupCard();
		$this->scanNote( $latest );
		$this->reminderStatus();
		$this->copyStatus();

		$service = $this->plugin->findings();
		echo '<div class="g g4">';
		$this->figures( $service );
		echo '</div><div class="g g21">';
		$this->findings( $service );
		echo '<div class="col" style="gap:16px">';
		$this->dates();
		$this->sinceHandover();
		echo '</div></div><div class="g g2">';
		$this->readiness();
		$this->freshness();
		$this->safety();
		$this->stack( $facts );
		echo '</div>';
		$this->recentChanges();
		View::close();
	}

	/**
	 * The line under the title: the site, who it is prepared for and when it was last scanned.
	 *
	 * @param array<string, mixed>      $passport Passport details.
	 * @param array<string, mixed>|null $latest   Latest scan row.
	 */
	private function summary( array $passport, ?array $latest ): string {
		$parts = array();
		if ( '' !== (string) $passport['site_name'] ) {
			$parts[] = (string) $passport['site_name'];
		}
		if ( '' !== (string) $passport['client_name'] ) {
			/* translators: %s: client name. */
			$parts[] = sprintf( __( 'Prepared for %s', 'estasoft-handover-passport' ), (string) $passport['client_name'] );
		}
		if ( null !== $latest && 'done' === (string) $latest['status'] ) {
			/* translators: %s: date and time of the last scan. */
			$parts[] = sprintf( __( 'Last scan %s', 'estasoft-handover-passport' ), Dates::display( (string) $latest['started_at'], true ) );
		} else {
			$parts[] = __( 'No scan has finished yet', 'estasoft-handover-passport' );
		}
		return implode( '. ', $parts ) . '.';
	}

	/**
	 * The buttons in the header: run a scan, export, and take a snapshot, which is the one filled button.
	 *
	 * @param array<string, mixed> $passport Passport details.
	 */
	private function headerActions( array $passport ): string {
		$out = View::badge( (string) $passport['status'], $this->plugin->passport()->statusLabel() );
		if ( Capabilities::can( Capabilities::EDIT ) ) {
			$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline"><input type="hidden" name="action" value="' . esc_attr( Admin::SCAN_ACTION ) . '">' . Form::nonceField( Admin::SCAN_ACTION ) . '<button type="submit" class="btn">' . Icons::svg( 'refresh', 16 ) . esc_html__( 'Run scan', 'estasoft-handover-passport' ) . '</button></form>';
		}
		if ( Capabilities::can( Capabilities::EXPORT ) ) {
			$out .= '<a class="btn" href="' . esc_url( add_query_arg( array( 'page' => HandoverScreen::SLUG, 'tab' => 'export' ), admin_url( 'admin.php' ) ) ) . '">' . Icons::svg( 'download', 16 ) . esc_html__( 'Export', 'estasoft-handover-passport' ) . '</a>';
			$out .= '<a class="btn pri" href="' . esc_url( add_query_arg( array( 'page' => HandoverScreen::SLUG, 'tab' => 'snapshots' ), admin_url( 'admin.php' ) ) ) . '">' . Icons::svg( 'archive', 16 ) . esc_html__( 'Take snapshot', 'estasoft-handover-passport' ) . '</a>';
		}
		return $out;
	}

	/**
	 * The state before the first scan: one card that says what a scan reads and offers the way in.
	 *
	 * @param array<string, mixed>|null $latest Latest scan row.
	 */
	private function firstRun( ?array $latest ): void {
		echo '<section class="card"><div class="bdy"><div class="empty"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M1 6v16l7-4 8 4 7-4V2l-7 4-8-4z"/><path d="M8 2v16M16 6v16"/></svg>';
		echo '<h2>' . esc_html__( 'Start your passport', 'estasoft-handover-passport' ) . '</h2>';
		echo '<p>' . esc_html__( 'No scan has finished yet. A scan finds your theme, plugins, PHP and database. You then add what a scan cannot know: licences, domains, who owns what and how to restore.', 'estasoft-handover-passport' ) . '</p>';
		if ( null !== $latest && 'failed' === (string) $latest['status'] ) {
			echo '<p class="estasoft-hp-error">' . esc_html__( 'The last scan did not finish. Nothing was changed.', 'estasoft-handover-passport' ) . '</p>';
		}
		if ( Capabilities::can( Capabilities::EDIT ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( Admin::SCAN_ACTION ) . '">' . Form::nonceField( Admin::SCAN_ACTION ) . '<button type="submit" class="btn pri lg">' . Icons::svg( 'refresh', 16 ) . esc_html__( 'Run the first scan', 'estasoft-handover-passport' ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup and a nonce field.
			echo '<a class="cap" href="' . esc_url( SetupScreen::url() ) . '">' . esc_html__( 'Open the setup guide', 'estasoft-handover-passport' ) . '</a>';
		}
		echo '</div></div></section>';
	}

	/**
	 * A bar that says where the setup guide stopped and offers to carry on. It shows only to people who may use the guide
	 * and only while a step is not done.
	 */
	private function resumeBar(): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			return;
		}
		$setup = $this->plugin->setup();
		$left  = $setup->remaining();
		if ( array() === $left ) {
			return;
		}
		$first = (string) reset( $left );
		$at    = (int) array_search( $first, SetupService::STEPS, true ) + 1;
		$words = esc_html(
			sprintf(
				/* translators: 1: step number, 2: number of steps, 3: step title. */
				__( 'You stopped at step %1$d of %2$d, %3$s.', 'estasoft-handover-passport' ),
				$at,
				count( SetupService::STEPS ),
				SetupScreen::copy()[ $first ]['title']
			)
		);
		echo '<div class="msg in" role="status">' . Icons::svg( 'info', 20 ) . '<div><b>' . esc_html__( 'Continue where you left off', 'estasoft-handover-passport' ) . '</b><span class="t2">' . $words . '</span></div><a class="btn sm" href="' . esc_url( SetupScreen::stepUrl( $first ) ) . '">' . esc_html__( 'Continue setup', 'estasoft-handover-passport' ) . Icons::svg( 'arrow', 16 ) . '</a></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is fixed markup and $words is escaped.
	}

	/**
	 * A bar when the last scan did not finish or is still running.
	 *
	 * @param array<string, mixed>|null $latest Latest scan row.
	 */
	private function scanNote( ?array $latest ): void {
		if ( null === $latest ) {
			return;
		}
		$state = (string) $latest['status'];
		if ( 'failed' === $state ) {
			echo '<div class="msg er" role="alert">' . Icons::svg( 'error', 16 ) . '<div><b>' . esc_html__( 'The last scan did not finish', 'estasoft-handover-passport' ) . '</b>' . esc_html__( 'Nothing was changed. Run the scan again.', 'estasoft-handover-passport' ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is fixed markup.
		} elseif ( 'running' === $state ) {
			echo '<div class="msg in" role="status">' . Icons::svg( 'clock', 16 ) . '<div><b>' . esc_html__( 'A scan is running', 'estasoft-handover-passport' ) . '</b>' . esc_html__( 'The figures below are from the scan before. Reload the page in a minute.', 'estasoft-handover-passport' ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is fixed markup.
		}
		$gaps = null === $latest ? array() : (array) ( ( $latest['facts']['gaps'] ?? array() ) );
		if ( array() !== $gaps ) {
			echo '<div class="msg in">' . Icons::svg( 'info', 16 ) . '<div>' . esc_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() returns fixed markup.
				sprintf(
					/* translators: %s: list of scan sections. */
					__( 'Not available on this server: %s', 'estasoft-handover-passport' ),
					implode( ', ', array_map( 'strval', array_keys( $gaps ) ) )
				)
			) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is fixed markup.
		}
	}

	/**
	 * The four figures: findings that need fixing, findings worth fixing, handover checks and the last restore test.
	 *
	 * @param \Estasoft\HandoverPassport\Findings\FindingService $service Finding service.
	 */
	private function figures( $service ): void {
		$counts = $service->counts();
		$ready  = $service->readiness();
		$today  = Clock::today();
		$last   = $this->plugin->restoreTests()->lastPass();
		$limit  = (int) $this->plugin->settings()->get( 'thresholds.restore_test_days', 180 );

		$open  = __( 'Open findings', 'estasoft-handover-passport' );
		$none  = __( 'None open', 'estasoft-handover-passport' );
		$needs = (int) $counts[ Level::NEEDS ];
		$worth = (int) $counts[ Level::WORTH ];
		echo View::kpi( (string) $needs, Level::label( Level::NEEDS ), View::badge( $needs > 0 ? Level::NEEDS : 'ok', $needs > 0 ? $open : $none ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kpi() and badge() escape their parts.
		echo View::kpi( (string) $worth, Level::label( Level::WORTH ), View::badge( $worth > 0 ? Level::WORTH : 'ok', $worth > 0 ? $open : $none ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kpi() and badge() escape their parts.

		if ( $ready['total'] > 0 ) {
			$words = sprintf(
				/* translators: 1: number of checks that are clear, 2: number of checks. */
				__( '%1$d of %2$d handover checks are clear', 'estasoft-handover-passport' ),
				$ready['ready'],
				$ready['total']
			);
			echo View::kpi( sprintf( /* translators: 1: checks clear, 2: checks. */ __( '%1$d of %2$d', 'estasoft-handover-passport' ), $ready['ready'], $ready['total'] ), __( 'Handover checks', 'estasoft-handover-passport' ), View::bar( $ready['ready'], $ready['total'], $words ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kpi() and bar() escape their parts.
		} else {
			echo View::kpi( '0', __( 'Handover checks', 'estasoft-handover-passport' ), View::badge( 'unknown', __( 'Nothing to check yet', 'estasoft-handover-passport' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kpi() and badge() escape their parts.
		}

		if ( null === $last ) {
			echo View::kpi( '0', __( 'Restore tests that passed', 'estasoft-handover-passport' ), View::badge( 'unknown', __( 'None logged', 'estasoft-handover-passport' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kpi() and badge() escape their parts.
		} else {
			$days = max( 0, Dates::daysBetween( $last, $today ) );
			$late = $days > $limit;
			$card = View::kpi(
				/* translators: %d: number of days. */
				sprintf( _n( '%d day', '%d days', $days, 'estasoft-handover-passport' ), $days ),
				__( 'Since the last passed restore test', 'estasoft-handover-passport' ),
				View::badge(
					$late ? 'due' : 'ok',
					sprintf(
						/* translators: %d: target number of days. */
						$late ? __( 'Overdue, target %d days', 'estasoft-handover-passport' ) : __( 'Within the target of %d days', 'estasoft-handover-passport' ),
						$limit
					)
				)
			);
			echo $card; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kpi() and badge() escape their parts.
		}
	}

	/**
	 * How much changed since the last handover that somebody acknowledged (HND-8). Nothing is printed before one exists.
	 */
	private function sinceHandover(): void {
		$since = ( new SinceHandover( $this->plugin ) )->get();
		if ( null === $since ) {
			return;
		}
		$on   = '' === $since['acknowledged_on'] ? '' : Dates::displayDate( $since['acknowledged_on'] );
		$link = '<a class="btn sm" href="' . esc_url( add_query_arg( array( 'page' => HandoverScreen::SLUG, 'tab' => 'compare', 'a' => $since['id'], 'b' => 'live' ), admin_url( 'admin.php' ) ) ) . '">' . Icons::svg( 'arrow', 16 ) . esc_html__( 'See the differences', 'estasoft-handover-passport' ) . '</a>';
		View::cardStart( __( 'Since the handover', 'estasoft-handover-passport' ), $link );
		if ( 0 === $since['total'] && ! $since['notes_changed'] ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: name of the snapshot. */
					__( 'No record has changed since the snapshot "%s" was acknowledged.', 'estasoft-handover-passport' ),
					$since['name']
				)
			) . '</p>';
		} else {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: number of records, 2: name of the snapshot, 3: date it was acknowledged. */
					_n( '%1$d record differs from the snapshot "%2$s", acknowledged on %3$s.', '%1$d records differ from the snapshot "%2$s", acknowledged on %3$s.', $since['total'], 'estasoft-handover-passport' ),
					$since['total'],
					$since['name'],
					'' === $on ? __( 'an unrecorded date', 'estasoft-handover-passport' ) : $on
				)
			) . '</p><p class="t2 cap" style="margin-top:4px">' . esc_html(
				sprintf(
					/* translators: 1: records added, 2: records removed, 3: records changed. */
					__( 'Added: %1$d. Removed: %2$d. Changed: %3$d.', 'estasoft-handover-passport' ),
					$since['added'],
					$since['removed'],
					$since['changed']
				)
			) . ( $since['notes_changed'] ? ' ' . esc_html__( 'The notes for the successor have changed too.', 'estasoft-handover-passport' ) : '' ) . '</p>';
		}
		echo '<p class="t2 cap" style="margin-top:4px">' . esc_html__( 'Records marked Sensitive are not counted here.', 'estasoft-handover-passport' ) . '</p>';
		View::cardEnd();
	}

	/**
	 * Steps of the setup guide that are not done, as things to do (ONB-3).
	 */
	private function setupCard(): void {
		$left = array();
		foreach ( $this->plugin->setup()->steps() as $key => $info ) {
			if ( SetupService::DONE !== $info['state'] ) {
				$left[ $key ] = $info['state'];
			}
		}
		if ( array() === $left ) {
			return;
		}
		$copy     = SetupScreen::copy();
		$can_edit = Capabilities::can( Capabilities::EDIT );
		View::cardStart( __( 'Finish setting up', 'estasoft-handover-passport' ), '', true );
		echo '<ul class="lst">';
		foreach ( $left as $key => $state ) {
			$title = $copy[ $key ]['title'];
			$words = SetupService::SKIPPED === $state ? __( 'Skipped', 'estasoft-handover-passport' ) : __( 'To do', 'estasoft-handover-passport' );
			echo '<li><div class="gr">' . ( $can_edit ? '<a href="' . esc_url( SetupScreen::stepUrl( $key ) ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</div>' . View::badge( 'setup-' . $state, $words ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
		}
		echo '</ul>';
		View::cardEnd();
	}

	/**
	 * Open findings by level and the five most urgent (HLT-3).
	 *
	 * @param \Estasoft\HandoverPassport\Findings\FindingService $service Finding service.
	 */
	private function findings( $service ): void {
		$counts = $service->counts();
		$top    = $service->top( 5 );
		$links  = new FindingLinks( $this->plugin );
		$page   = add_query_arg( array( 'page' => FindingsScreen::SLUG, 'tab' => 'open' ), admin_url( 'admin.php' ) );
		$meta   = $service->meta();

		$notes = '';
		if ( '' !== $meta['ran_at'] ) {
			$notes .= '<span class="t2 cap">' . esc_html(
				sprintf(
					/* translators: %s: date and time. */
					__( 'Rules last checked %s.', 'estasoft-handover-passport' ),
					Dates::display( $meta['ran_at'], true )
				)
			) . '</span>';
		}
		if ( array() !== $meta['not_checked'] ) {
			$notes .= '<span class="t2 cap">' . esc_html(
				sprintf(
					/* translators: %d: number of rules. */
					_n( '%d rule could not be checked because the data it needs is missing. It is not counted as passed.', '%d rules could not be checked because the data they need is missing. They are not counted as passed.', count( $meta['not_checked'] ), 'estasoft-handover-passport' ),
					count( $meta['not_checked'] )
				)
			) . '</span>';
		}
		$notes .= '<span class="sp1"></span><a href="' . esc_url( add_query_arg( 'severity', Level::INFO, $page ) ) . '">' . View::badge( Level::INFO, Level::label( Level::INFO ) ) . ' <strong>' . esc_html( (string) $counts[ Level::INFO ] ) . '</strong></a>';

		View::cardStart( __( 'Most urgent', 'estasoft-handover-passport' ), '<a class="btn sm" href="' . esc_url( $page ) . '">' . Icons::svg( 'arrow', 16 ) . esc_html__( 'See all findings', 'estasoft-handover-passport' ) . '</a>', true );
		if ( array() === $top ) {
			echo '<div class="empty">' . Icons::svg( 'ok', 32 ) . '<h2>' . esc_html__( 'No open findings', 'estasoft-handover-passport' ) . '</h2><p>' . esc_html__( 'No open findings on the data the rules could read.', 'estasoft-handover-passport' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is fixed markup.
			View::cardEnd( $notes );
			return;
		}
		echo '<ul class="lst">';
		foreach ( $top as $finding ) {
			echo '<li>' . View::badge( (string) $finding['severity'], (string) $finding['severity_label'] ) . '<div class="gr"><b>' . esc_html( (string) $finding['message'] ) . '</b><div class="t2 cap">' . esc_html( (string) $finding['rule_title'] ) . '</div></div><a class="btn sm" href="' . esc_url( $links->url( $finding ) ) . '">' . esc_html( $links->label( $finding ) ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
		}
		echo '</ul>';
		View::cardEnd( $notes );
	}

	/**
	 * Readiness for a handover: n of m, with each missing item linked (PRT-7). It is a count, never a score.
	 */
	private function readiness(): void {
		$ready = $this->plugin->findings()->readiness();
		View::cardStart( __( 'Ready for a handover', 'estasoft-handover-passport' ) );
		if ( 0 === $ready['total'] ) {
			echo '<p>' . esc_html__( 'Nothing can be checked yet. Scan the site and add records first.', 'estasoft-handover-passport' ) . '</p>';
			View::cardEnd();
			return;
		}
		echo '<p><strong>' . esc_html(
			sprintf(
				/* translators: 1: number of checks that are clear, 2: number of checks. */
				__( '%1$d of %2$d handover checks are clear.', 'estasoft-handover-passport' ),
				$ready['ready'],
				$ready['total']
			)
		) . '</strong></p>';
		$missing = array_values( array_filter( $ready['rules'], static fn( array $rule ): bool => ! $rule['ready'] ) );
		if ( array() === $missing ) {
			echo '<p>' . esc_html__( 'Every handover check the rules can run is clear.', 'estasoft-handover-passport' ) . '</p>';
			View::cardEnd();
			return;
		}
		echo '<ul class="estasoft-hp-readiness">';
		foreach ( $missing as $rule ) {
			$screen = (array) $rule['screen'];
			$url    = add_query_arg( array_filter( array( 'page' => (string) ( $screen['page'] ?? Admin::MENU_SLUG ), 'tab' => (string) ( $screen['tab'] ?? '' ) ) ), admin_url( 'admin.php' ) );
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( (string) $rule['title'] ) . '</a> <span class="t2">(' . esc_html( (string) $rule['id'] ) . ')</span></li>';
		}
		echo '</ul>';
		View::cardEnd();
	}

	/**
	 * Renewals and expiries in the next 90 days.
	 */
	private function dates(): void {
		$today = Clock::today();
		$can   = Capabilities::canSeeSensitive();
		$lead  = (int) $this->plugin->settings()->get( 'thresholds.licence_lead_days', 30 );
		$rows  = array();
		foreach ( ( new UpcomingDates( $this->plugin->records() ) )->between( $today, Dates::addDays( $today, 90 ) ) as $date ) {
			if ( $date['sensitive'] && ! $can ) {
				continue;
			}
			$rows[] = array( $date['date'], $date['kind_label'], $date['title'] );
		}

		$footer = '';
		if ( array() !== $rows && Capabilities::can( Capabilities::EXPORT ) ) {
			$footer = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline"><input type="hidden" name="action" value="' . esc_attr( ExportView::ACTION ) . '"><input type="hidden" name="pack" value="technical"><input type="hidden" name="format" value="ics"><input type="hidden" name="return" value="' . esc_url( add_query_arg( array( 'page' => Admin::MENU_SLUG ), admin_url( 'admin.php' ) ) ) . '">' . Form::nonceField( ExportView::ACTION ) . '<button type="submit" class="btn sm">' . Icons::svg( 'download', 16 ) . esc_html__( 'Download every date as a calendar file (.ics)', 'estasoft-handover-passport' ) . '</button></form><span class="t2 cap">' . esc_html__( 'Titles and dates only. Records marked Sensitive are left out.', 'estasoft-handover-passport' ) . '</span>';
		}

		View::cardStart( __( 'Next 90 days', 'estasoft-handover-passport' ), '', array() !== $rows );
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No renewal or expiry date is entered for the next 90 days.', 'estasoft-handover-passport' ) . '</p>';
			View::cardEnd();
			return;
		}
		echo '<ul class="lst">';
		foreach ( array_slice( $rows, 0, 8 ) as $row ) {
			$days = Dates::daysBetween( $today, $row[0] );
			/* translators: %d: number of days. */
			$in = 0 === $days ? __( 'today', 'estasoft-handover-passport' ) : sprintf( _n( 'in %d day', 'in %d days', $days, 'estasoft-handover-passport' ), $days );
			echo '<li><span class="t2">' . Icons::svg( 'calendar', 16 ) . '</span><div class="gr"><b>' . esc_html( $row[2] ) . ' <span class="t2 cap">' . esc_html( $row[1] ) . '</span></b><div class="t2 cap">' . esc_html( Dates::displayDate( $row[0] ) . ', ' . $in ) . '</div></div>' . ( $days <= $lead ? View::badge( 'renews_soon', __( 'Soon', 'estasoft-handover-passport' ) ) : View::badge( 'later', __( 'Later', 'estasoft-handover-passport' ) ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons and badge() are fixed or escaped.
		}
		echo '</ul>';
		if ( count( $rows ) > 8 ) {
			$footer = '<span class="t2 cap">' . esc_html(
				sprintf(
					/* translators: 1: number shown, 2: number of dates. */
					__( 'Showing %1$d of %2$d dates. The calendar file lists every one.', 'estasoft-handover-passport' ),
					8,
					count( $rows )
				)
			) . '</span>' . $footer;
		}
		View::cardEnd( $footer );
	}

	/**
	 * Says so when reminders are off, paused, or may not be arriving (RMD-5, IMP-4). Nothing is printed when all is well.
	 */
	private function reminderStatus(): void {
		$service = $this->plugin->reminders();
		$health  = $service->health();
		$lines   = array();
		if ( ! $service->enabled() ) {
			$lines[] = __( 'Reminders are switched off in Settings, so no date is emailed.', 'estasoft-handover-passport' );
		} elseif ( $this->plugin->copyGuard()->paused() ) {
			$lines[] = __( 'Reminders are paused on this address until the question at the top of the page is answered.', 'estasoft-handover-passport' );
		}
		if ( $health['failed_days'] > 0 || false === $health['test_ok'] ) {
			$lines[] = __( 'The last reminder email or test email could not be sent. Reminders depend on working site email. Send a test email in Settings, Reminders, to check.', 'estasoft-handover-passport' );
		}
		if ( array() === $lines ) {
			return;
		}
		$button = Capabilities::can( Capabilities::CONFIGURE ) ? '<a class="btn sm" href="' . esc_url( add_query_arg( array( 'page' => SettingsScreen::SLUG, 'tab' => 'reminders' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Open reminder settings', 'estasoft-handover-passport' ) . '</a>' : '';
		echo '<div class="msg wn">' . Icons::svg( 'warning', 16 ) . '<div><b>' . esc_html__( 'Reminders', 'estasoft-handover-passport' ) . '</b>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is fixed markup.
		foreach ( $lines as $line ) {
			echo '<p>' . esc_html( $line ) . '</p>';
		}
		echo '</div>' . $button . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
	}

	/**
	 * Says so when the emailed copy of the Emergency sheet could not be sent (EXP-10). Nothing is printed when all is well.
	 */
	private function copyStatus(): void {
		$warning = $this->plugin->emailedCopy()->warning();
		if ( '' === $warning ) {
			return;
		}
		$button = Capabilities::can( Capabilities::CONFIGURE ) ? '<a class="btn sm" href="' . esc_url( add_query_arg( array( 'page' => SettingsScreen::SLUG, 'tab' => 'privacy' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Open the copy settings', 'estasoft-handover-passport' ) . '</a>' : '';
		echo '<div class="msg wn">' . Icons::svg( 'warning', 16 ) . '<div><b>' . esc_html__( 'Emailed copy', 'estasoft-handover-passport' ) . '</b>' . esc_html( $warning ) . '</div>' . $button . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
	}

	/**
	 * How many records were verified recently, are due for a check, or are stale.
	 */
	private function freshness(): void {
		$counts = array( Freshness::VERIFIED => 0, Freshness::DUE => 0, Freshness::STALE => 0 );
		foreach ( $this->plugin->records()->everything() as $record ) {
			if ( in_array( (string) $record['kind'], Kinds::registerKinds(), true ) && isset( $counts[ (string) $record['freshness'] ] ) ) {
				++$counts[ (string) $record['freshness'] ];
			}
		}
		View::cardStart( __( 'How current the records are', 'estasoft-handover-passport' ) );
		if ( 0 === array_sum( $counts ) ) {
			echo '<p>' . esc_html__( 'No records have been entered yet.', 'estasoft-handover-passport' ) . '</p>';
			View::cardEnd();
			return;
		}
		echo '<div class="estasoft-hp-summary-counts">';
		foreach ( $counts as $state => $count ) {
			echo '<span>' . View::badge( $state, Freshness::label( $state ) ) . ' <strong>' . esc_html( (string) $count ) . '</strong></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
		}
		echo '</div><p class="description">' . esc_html__( 'Verified means someone confirmed the record is still right within its review period. Press Verify on a record after checking it.', 'estasoft-handover-passport' ) . '</p>';
		View::cardEnd();
	}

	/**
	 * Restore test and runbook facts.
	 */
	private function safety(): void {
		$last      = $this->plugin->restoreTests()->lastPass();
		$runbooks  = $this->plugin->runbooks()->all();
		$published = count( array_filter( $runbooks, static fn( array $runbook ): bool => 'published' === $runbook['status'] ) );
		View::cardStart( __( 'Safety nets', 'estasoft-handover-passport' ), '<a class="btn sm" href="' . esc_url( add_query_arg( array( 'page' => SafetyScreen::SLUG, 'tab' => 'tests' ), admin_url( 'admin.php' ) ) ) . '">' . Icons::svg( 'arrow', 16 ) . esc_html__( 'Open Safety nets', 'estasoft-handover-passport' ) . '</a>' );
		View::facts(
			array(
				__( 'Last restore test that passed', 'estasoft-handover-passport' ) => null === $last ? __( 'None logged', 'estasoft-handover-passport' ) : Dates::displayDate( $last ),
				__( 'Runbooks', 'estasoft-handover-passport' )                      => sprintf(
					/* translators: 1: number published, 2: number of runbooks. */
					__( '%1$d published of %2$d', 'estasoft-handover-passport' ),
					$published,
					count( $runbooks )
				),
			)
		);
		View::cardEnd();
	}

	/**
	 * Server and stack summary.
	 *
	 * @param array<string, mixed> $facts Facts.
	 */
	private function stack( array $facts ): void {
		$core    = (array) ( $facts['core'] ?? array() );
		$plugins = (array) ( $facts['plugins'] ?? array() );
		$themes  = (array) ( $facts['themes'] ?? array() );
		$active  = count( array_filter( $plugins, static fn( $p ): bool => ! empty( $p['active'] ) ) );
		$updates = count( array_filter( $plugins, static fn( $p ): bool => ! empty( $p['update'] ) ) );
		$theme   = '';
		foreach ( $themes as $item ) {
			if ( ! empty( $item['active'] ) ) {
				$theme = trim( (string) ( $item['name'] ?? '' ) . ' ' . (string) ( $item['version'] ?? '' ) );
				break;
			}
		}

		View::cardStart( __( 'Stack', 'estasoft-handover-passport' ), '<a class="btn sm" href="' . esc_url( add_query_arg( array( 'page' => InventoryScreen::SLUG, 'tab' => 'stack' ), admin_url( 'admin.php' ) ) ) . '">' . Icons::svg( 'arrow', 16 ) . esc_html__( 'Open Stack', 'estasoft-handover-passport' ) . '</a>' );
		View::facts(
			array(
				__( 'WordPress', 'estasoft-handover-passport' ) => (string) ( $core['wp_version'] ?? '' ),
				__( 'PHP', 'estasoft-handover-passport' ) => (string) ( $core['php_version'] ?? '' ),
				__( 'Database', 'estasoft-handover-passport' ) => trim( (string) ( $core['db_engine'] ?? '' ) . ' ' . (string) ( $core['db_version'] ?? '' ) ),
				__( 'Web server', 'estasoft-handover-passport' ) => (string) ( $core['web_server'] ?? '' ),
				__( 'Environment', 'estasoft-handover-passport' ) => (string) ( $core['environment'] ?? '' ),
				__( 'Site address', 'estasoft-handover-passport' ) => (string) ( $core['site_url'] ?? '' ),
				__( 'HTTPS', 'estasoft-handover-passport' ) => View::yesNo( ! empty( $core['https'] ) ),
				__( 'Active theme', 'estasoft-handover-passport' ) => $theme,
				__( 'Plugins', 'estasoft-handover-passport' ) => sprintf(
					/* translators: 1: number active, 2: number installed. */
					__( '%1$d active of %2$d installed', 'estasoft-handover-passport' ),
					$active,
					count( $plugins )
				),
				__( 'Plugin updates waiting', 'estasoft-handover-passport' ) => (string) $updates,
			)
		);
		View::cardEnd();
	}

	/**
	 * The ten latest changes.
	 */
	private function recentChanges(): void {
		$log  = $this->plugin->changeLog();
		$rows = $log->forViewer( $log->repo()->search( array(), 10 ), Capabilities::canSeeSensitive() );
		View::cardStart( __( 'Recent changes', 'estasoft-handover-passport' ), '<a class="btn sm" href="' . esc_url( add_query_arg( array( 'page' => HistoryScreen::SLUG, 'tab' => 'changes' ), admin_url( 'admin.php' ) ) ) . '">' . Icons::svg( 'arrow', 16 ) . esc_html__( 'Open change log', 'estasoft-handover-passport' ) . '</a>', array() !== $rows );
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'Nothing has changed since the first scan.', 'estasoft-handover-passport' ) . '</p>';
			View::cardEnd();
			return;
		}
		echo '<div class="scr"><table class="tbl"><thead><tr><th scope="col">' . esc_html__( 'When', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'What', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Who', 'estasoft-handover-passport' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td class="nw">' . esc_html( Dates::display( (string) $row['changed_at'], true ) ) . '</td><td>' . esc_html( ChangeText::describe( $row ) ) . '</td><td>' . esc_html( (string) $row['actor_label'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		View::cardEnd();
	}
}
