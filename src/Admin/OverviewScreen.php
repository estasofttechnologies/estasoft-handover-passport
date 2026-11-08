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
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Reminders\UpcomingDates;
use Estasoft\HandoverPassport\Scan\ScanService;
use Estasoft\HandoverPassport\Security\Capabilities;
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

		View::open( (string) ( '' !== $passport['site_name'] ? $passport['site_name'] : __( 'Passport', 'estasoft-handover-passport' ) ) );

		echo '<p class="estasoft-hp-lede">' . View::badge( (string) $passport['status'], $this->plugin->passport()->statusLabel() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
		if ( '' !== (string) $passport['client_name'] ) {
			echo ' ' . esc_html(
				sprintf(
					/* translators: %s: client name. */
					__( 'Prepared for %s', 'estasoft-handover-passport' ),
					(string) $passport['client_name']
				)
			);
		}
		echo '</p>';

		$this->scanBox( $latest );
		$this->setupCard();

		if ( array() === $facts ) {
			echo '<p>' . esc_html__( 'No scan has finished yet. Press Scan now to record this site.', 'estasoft-handover-passport' ) . '</p>';
			View::close();
			return;
		}

		$this->findings();
		$this->readiness();
		$this->dates();
		$this->reminderStatus();
		$this->freshness();
		$this->safety();
		$this->stack( $facts );
		$this->recentChanges();
		View::close();
	}

	/**
	 * Steps of the setup guide that are not done, as things to do (ONB-3).
	 */
	private function setupCard(): void {
		$left = array();
		foreach ( $this->plugin->setup()->steps() as $key => $info ) {
			if ( \Estasoft\HandoverPassport\Setup\SetupService::DONE !== $info['state'] ) {
				$left[ $key ] = $info['state'];
			}
		}
		if ( array() === $left ) {
			return;
		}
		$copy     = SetupScreen::copy();
		$can_edit = Capabilities::can( Capabilities::EDIT );
		echo '<div class="estasoft-hp-card estasoft-hp-setup-card"><h2>' . esc_html__( 'Finish setting up', 'estasoft-handover-passport' ) . '</h2><ul>';
		foreach ( $left as $key => $state ) {
			$title = $copy[ $key ]['title'];
			$words = \Estasoft\HandoverPassport\Setup\SetupService::SKIPPED === $state ? __( 'Skipped', 'estasoft-handover-passport' ) : __( 'To do', 'estasoft-handover-passport' );
			echo '<li>' . ( $can_edit ? '<a href="' . esc_url( SetupScreen::url( 'estasoft-hp-step-' . $key ) ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . ' ' . View::badge( 'setup-' . $state, $words ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
		}
		echo '</ul></div>';
	}

	/**
	 * The scan status box with the Scan now button.
	 *
	 * @param array<string, mixed>|null $latest Latest scan row.
	 */
	private function scanBox( ?array $latest ): void {
		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Last scan', 'estasoft-handover-passport' ) . '</h2>';
		if ( null === $latest ) {
			echo '<p>' . esc_html__( 'This site has not been scanned yet.', 'estasoft-handover-passport' ) . '</p>';
		} else {
			$state = (string) $latest['status'];
			$words = array(
				'done'    => __( 'Finished', 'estasoft-handover-passport' ),
				'running' => __( 'Running', 'estasoft-handover-passport' ),
				'failed'  => __( 'Did not finish', 'estasoft-handover-passport' ),
			);
			echo '<p>' . View::badge( $state, $words[ $state ] ?? $state ) . ' ' . esc_html( Dates::display( (string) $latest['started_at'], true ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			$gaps = (array) ( ( $latest['facts']['gaps'] ?? array() ) );
			if ( array() !== $gaps ) {
				echo '<p class="estasoft-hp-muted">' . esc_html(
					sprintf(
						/* translators: %s: list of scan sections. */
						__( 'Not available on this server: %s', 'estasoft-handover-passport' ),
						implode( ', ', array_map( 'strval', array_keys( $gaps ) ) )
					)
				) . '</p>';
			}
		}
		if ( Capabilities::can( Capabilities::EDIT ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( Admin::SCAN_ACTION ) . '">';
			Form::nonce( Admin::SCAN_ACTION );
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Scan now', 'estasoft-handover-passport' ) . '</button>';
			echo '</form>';
		}
		echo '</div>';
	}

	/**
	 * Open findings by level and the five most urgent (HLT-3).
	 */
	private function findings(): void {
		$service = $this->plugin->findings();
		$counts  = $service->counts();
		$top     = $service->top( 5 );
		$links   = new FindingLinks( $this->plugin );
		$page    = add_query_arg( array( 'page' => FindingsScreen::SLUG, 'tab' => 'open' ), admin_url( 'admin.php' ) );

		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Findings', 'estasoft-handover-passport' ) . '</h2>';
		echo '<div class="estasoft-hp-summary-counts">';
		foreach ( Level::all() as $level ) {
			echo '<a href="' . esc_url( add_query_arg( 'severity', $level, $page ) ) . '">' . View::badge( $level, Level::label( $level ) ) . ' <strong>' . esc_html( (string) $counts[ $level ] ) . '</strong></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
		}
		echo '</div>';
		$meta = $service->meta();
		if ( '' !== $meta['ran_at'] ) {
			echo '<p class="estasoft-hp-muted">' . esc_html(
				sprintf(
					/* translators: %s: date and time. */
					__( 'Rules last checked %s.', 'estasoft-handover-passport' ),
					Dates::display( $meta['ran_at'], true )
				)
			) . '</p>';
		}
		if ( array() !== $meta['not_checked'] ) {
			echo '<p class="estasoft-hp-muted">' . esc_html(
				sprintf(
					/* translators: %d: number of rules. */
					_n( '%d rule could not be checked because the data it needs is missing. It is not counted as passed.', '%d rules could not be checked because the data they need is missing. They are not counted as passed.', count( $meta['not_checked'] ), 'estasoft-handover-passport' ),
					count( $meta['not_checked'] )
				)
			) . '</p>';
		}
		if ( array() === $top ) {
			echo '<p>' . esc_html__( 'No open findings on the data the rules could read.', 'estasoft-handover-passport' ) . '</p></div>';
			return;
		}
		echo '<h3>' . esc_html__( 'Most urgent', 'estasoft-handover-passport' ) . '</h3><ul class="estasoft-hp-urgent">';
		foreach ( $top as $finding ) {
			echo '<li>' . View::badge( (string) $finding['severity'], (string) $finding['severity_label'] ) . ' ' . esc_html( (string) $finding['message'] ) . ' <a href="' . esc_url( $links->url( $finding ) ) . '">' . esc_html__( 'Go and fix', 'estasoft-handover-passport' ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
		}
		echo '</ul><p><a href="' . esc_url( $page ) . '">' . esc_html__( 'All findings', 'estasoft-handover-passport' ) . '</a></p></div>';
	}

	/**
	 * Readiness for a handover: n of m, with each missing item linked (PRT-7). It is a count, never a score.
	 */
	private function readiness(): void {
		$ready = $this->plugin->findings()->readiness();
		echo '<div class="estasoft-hp-card estasoft-hp-readiness"><h2>' . esc_html__( 'Ready for a handover', 'estasoft-handover-passport' ) . '</h2>';
		if ( 0 === $ready['total'] ) {
			echo '<p>' . esc_html__( 'Nothing can be checked yet. Scan the site and add records first.', 'estasoft-handover-passport' ) . '</p></div>';
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
			echo '<p>' . esc_html__( 'Every handover check the rules can run is clear.', 'estasoft-handover-passport' ) . '</p></div>';
			return;
		}
		echo '<ul>';
		foreach ( $missing as $rule ) {
			$screen = (array) $rule['screen'];
			$url    = add_query_arg( array_filter( array( 'page' => (string) ( $screen['page'] ?? Admin::MENU_SLUG ), 'tab' => (string) ( $screen['tab'] ?? '' ) ) ), admin_url( 'admin.php' ) );
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( (string) $rule['title'] ) . '</a> <span class="estasoft-hp-muted">(' . esc_html( (string) $rule['id'] ) . ')</span></li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Renewals and expiries in the next 90 days.
	 */
	private function dates(): void {
		$today = Clock::today();
		$can   = Capabilities::canSeeSensitive();
		$rows  = array();
		foreach ( ( new UpcomingDates( $this->plugin->records() ) )->between( $today, Dates::addDays( $today, 90 ) ) as $date ) {
			if ( $date['sensitive'] && ! $can ) {
				continue;
			}
			$rows[] = array( $date['date'], $date['kind_label'], $date['title'] );
		}

		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Next 90 days', 'estasoft-handover-passport' ) . '</h2>';
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No renewal or expiry date is entered for the next 90 days.', 'estasoft-handover-passport' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Date', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'What', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'In', 'estasoft-handover-passport' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $rows, 0, 25 ) as $row ) {
			$days = Dates::daysBetween( $today, $row[0] );
			/* translators: %d: number of days. */
			$in = 0 === $days ? __( 'Today', 'estasoft-handover-passport' ) : sprintf( _n( '%d day', '%d days', $days, 'estasoft-handover-passport' ), $days );
			echo '<tr><td>' . esc_html( Dates::displayDate( $row[0] ) ) . '</td><td>' . esc_html( $row[2] ) . ' <span class="estasoft-hp-muted">' . esc_html( $row[1] ) . '</span></td><td>' . esc_html( $in ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
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
		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Reminders', 'estasoft-handover-passport' ) . '</h2>';
		foreach ( $lines as $line ) {
			echo '<p>' . esc_html( $line ) . '</p>';
		}
		if ( Capabilities::can( Capabilities::CONFIGURE ) ) {
			echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'page' => SettingsScreen::SLUG, 'tab' => 'reminders' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Open reminder settings', 'estasoft-handover-passport' ) . '</a></p>';
		}
		echo '</div>';
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
		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'How current the records are', 'estasoft-handover-passport' ) . '</h2>';
		if ( 0 === array_sum( $counts ) ) {
			echo '<p>' . esc_html__( 'No records have been entered yet.', 'estasoft-handover-passport' ) . '</p></div>';
			return;
		}
		echo '<div class="estasoft-hp-summary-counts">';
		foreach ( $counts as $state => $count ) {
			echo '<span>' . View::badge( $state, Freshness::label( $state ) ) . ' <strong>' . esc_html( (string) $count ) . '</strong></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
		}
		echo '</div><p class="description">' . esc_html__( 'Verified means someone confirmed the record is still right within its review period. Press Verify on a record after checking it.', 'estasoft-handover-passport' ) . '</p></div>';
	}

	/**
	 * Restore test and runbook facts.
	 */
	private function safety(): void {
		$last      = $this->plugin->restoreTests()->lastPass();
		$runbooks  = $this->plugin->runbooks()->all();
		$published = count( array_filter( $runbooks, static fn( array $runbook ): bool => 'published' === $runbook['status'] ) );
		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Safety nets', 'estasoft-handover-passport' ) . '</h2>';
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
		echo '<p><a href="' . esc_url( add_query_arg( array( 'page' => SafetyScreen::SLUG, 'tab' => 'tests' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Open Safety nets', 'estasoft-handover-passport' ) . '</a></p></div>';
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

		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Stack', 'estasoft-handover-passport' ) . '</h2>';
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
		echo '</div>';
	}

	/**
	 * The ten latest changes.
	 */
	private function recentChanges(): void {
		$log  = $this->plugin->changeLog();
		$rows = $log->forViewer( $log->repo()->search( array(), 10 ), Capabilities::canSeeSensitive() );
		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Recent changes', 'estasoft-handover-passport' ) . '</h2>';
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'Nothing has changed since the first scan.', 'estasoft-handover-passport' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'When', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'What', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Who', 'estasoft-handover-passport' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( Dates::display( (string) $row['changed_at'], true ) ) . '</td><td>' . esc_html( ChangeText::describe( $row ) ) . '</td><td>' . esc_html( (string) $row['actor_label'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
