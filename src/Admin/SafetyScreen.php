<?php
/**
 * Safety nets: backups, restore tests, runbooks and critical addresses (ADM-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Services\RestoreTestService;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The Safety nets screen.
 */
final class SafetyScreen extends Screen {

	public const SLUG = 'estasoft-passport-safety';

	/**
	 * Attach the form handlers of the tabs.
	 */
	public function register(): void {
		( new RestoreTestView( $this->plugin ) )->register();
		( new RunbookView( $this->plugin ) )->register();
		( new RestoreChecklistView( $this->plugin ) )->register();
		( new DrillView( $this->plugin ) )->register();
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
		return __( 'Safety nets', 'estasoft-handover-passport' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tabs(): array {
		return array(
			'backups'  => __( 'Backups', 'estasoft-handover-passport' ),
			'tests'    => __( 'Restore tests', 'estasoft-handover-passport' ),
			'runbooks' => __( 'Runbooks', 'estasoft-handover-passport' ),
			'urls'     => __( 'Critical URLs', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function hiddenTabs(): array {
		return array( 'tests' => 'backups' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function describe( string $tab ): string {
		if ( ! $this->onList() ) {
			return '';
		}
		switch ( $tab ) {
			case 'tests':
				return __( 'A backup is only known to work when someone has restored it. Log each test, with what was restored and how long it took.', 'estasoft-handover-passport' );
			case 'runbooks':
				return __( 'Written steps someone can follow when something breaks, without asking the person who built the site.', 'estasoft-handover-passport' );
			case 'urls':
				return __( 'The pages that must answer after a restore. Passport asks each one from this server when you press the button.', 'estasoft-handover-passport' );
			default:
				$count = count( $this->plugin->records()->listKind( Kinds::BACKUP, Capabilities::canSeeSensitive() ) );
				return sprintf(
					/* translators: %d: number of backup routines. */
					_n( '%d backup routine. Passport records it; it does not run it.', '%d backup routines. Passport records them; it does not run them.', $count, 'estasoft-handover-passport' ),
					$count
				);
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function action( string $tab ): ?array {
		if ( ! Capabilities::can( Capabilities::EDIT ) || ! $this->onList() ) {
			return null;
		}
		switch ( $tab ) {
			case 'backups':
				return array(
					'label' => __( 'Log a restore test', 'estasoft-handover-passport' ),
					'url'   => add_query_arg( array( 'page' => self::SLUG, 'tab' => 'tests', 'log' => '1' ), admin_url( 'admin.php' ) ) . '#estasoft-hp-log-test',
				);
			case 'urls':
				return $this->addAction( Kinds::URL, $tab );
			default:
				return null;
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function extra( string $tab ): string {
		if ( 'backups' !== $tab || ! $this->onList() || ! Capabilities::can( Capabilities::EDIT ) ) {
			return '';
		}
		$add = $this->addAction( Kinds::BACKUP, $tab );
		return null === $add ? '' : '<a class="btn" href="' . esc_url( $add['url'] ) . '">' . Icons::svg( 'plus', 16 ) . esc_html( $add['label'] ) . '</a>';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function tab( string $tab ): void {
		switch ( $tab ) {
			case 'tests':
				( new RestoreTestView( $this->plugin ) )->render( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'tests' ), admin_url( 'admin.php' ) ) );
				break;
			case 'runbooks':
				( new RunbookView( $this->plugin ) )->render();
				break;
			case 'urls':
				$this->urls( $tab );
				break;
			default:
				$this->backups( $tab );
		}
	}

	/**
	 * The backups, a reminder when nobody has tested a restore for too long, and the latest restore tests.
	 *
	 * @param string $tab Tab key.
	 */
	private function backups( string $tab ): void {
		$editing = in_array( Request::key( 'view' ), array( 'edit', 'delete' ), true );
		if ( ! $editing ) {
			$this->restoreNote();
		}
		( new RegisterView( $this->plugin ) )->render( Kinds::BACKUP, self::SLUG, $this->keep( $tab ), true );
		if ( ! $editing ) {
			$this->latestTests();
		}
	}

	/**
	 * A warning bar when no restore test has passed within the target, or none was ever logged.
	 */
	private function restoreNote(): void {
		$limit = (int) $this->plugin->settings()->get( 'thresholds.restore_test_days', 180 );
		$last  = $this->plugin->restoreTests()->lastPass();
		$days  = null === $last ? null : max( 0, Dates::daysBetween( $last, Clock::today() ) );
		if ( null !== $days && $days <= $limit ) {
			return;
		}
		$title = null === $days
			? __( 'No restore test has been logged', 'estasoft-handover-passport' )
			: sprintf(
				/* translators: %d: number of days. */
				_n( 'No restore test has passed for %d day', 'No restore test has passed for %d days', $days, 'estasoft-handover-passport' ),
				$days
			);
		$text = null === $days
			? sprintf(
				/* translators: %d: target in days. */
				__( 'The target is %d days. A backup that was never restored is only a hope.', 'estasoft-handover-passport' ),
				$limit
			)
			: sprintf(
				/* translators: 1: date of the last passed test, 2: target in days. */
				__( 'The last passed test was on %1$s. The target is %2$d days. A backup that was never restored is only a hope.', 'estasoft-handover-passport' ),
				Dates::displayDate( $last ),
				$limit
			);
		echo '<div class="msg wn" role="status">' . Icons::svg( 'warning', 20 ) . '<div><b>' . esc_html( $title ) . '</b>' . esc_html( $text ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() returns fixed markup.
		if ( Capabilities::can( Capabilities::EDIT ) ) {
			echo '<a class="btn sm" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'tests', 'log' => '1' ), admin_url( 'admin.php' ) ) . '#estasoft-hp-log-test' ) . '">' . esc_html__( 'Log a test', 'estasoft-handover-passport' ) . '</a>';
		}
		echo '</div>';
	}

	/**
	 * The three most recent restore tests, with a link to the whole log.
	 */
	private function latestTests(): void {
		$tests = array_slice( $this->plugin->restoreTests()->all(), 0, 3 );
		if ( array() === $tests ) {
			return;
		}
		$backups = array();
		foreach ( $this->plugin->records()->listKind( Kinds::BACKUP, Capabilities::canSeeSensitive() ) as $backup ) {
			if ( empty( $backup['hidden'] ) ) {
				$backups[ (int) $backup['id'] ] = (string) $backup['title'];
			}
		}
		View::cardStart(
			__( 'Restore tests', 'estasoft-handover-passport' ),
			'<a class="btn sm" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'tests' ), admin_url( 'admin.php' ) ) ) . '">' . Icons::svg( 'arrow', 16 ) . esc_html__( 'Open the log', 'estasoft-handover-passport' ) . '</a>',
			true
		);
		echo '<div class="scr"><table class="tbl cards"><caption class="screen-reader-text">' . esc_html__( 'Restore tests', 'estasoft-handover-passport' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Date', 'estasoft-handover-passport' ), __( 'What was restored', 'estasoft-handover-passport' ), __( 'Where', 'estasoft-handover-passport' ), __( 'Result', 'estasoft-handover-passport' ), __( 'Time', 'estasoft-handover-passport' ), __( 'By', 'estasoft-handover-passport' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $tests as $test ) {
			$minutes = (int) $test['minutes'];
			echo '<tr><td class="t1" data-label="' . esc_attr__( 'Date', 'estasoft-handover-passport' ) . '">' . esc_html( Dates::displayDate( (string) $test['test_date'] ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'What was restored', 'estasoft-handover-passport' ) . '">' . esc_html( $backups[ (int) ( $test['backup_item_id'] ?? 0 ) ] ?? ( RestoreTestService::scopes()[ (string) $test['scope'] ] ?? '' ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Where', 'estasoft-handover-passport' ) . '">' . esc_html( RestoreTestService::places()[ (string) $test['place'] ] ?? '' ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Result', 'estasoft-handover-passport' ) . '">' . View::badge( 'result-' . (string) $test['result'], RestoreTestService::results()[ (string) $test['result'] ] ?? '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			echo '<td data-label="' . esc_attr__( 'Time', 'estasoft-handover-passport' ) . '">' . esc_html( $minutes > 0 ? sprintf( /* translators: %d: minutes. */ __( '%d min', 'estasoft-handover-passport' ), $minutes ) : '' ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'By', 'estasoft-handover-passport' ) . '">' . esc_html( (string) $test['who'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		View::cardEnd();
	}

	/**
	 * The addresses that must work after a restore, with a button that checks them all.
	 *
	 * @param string $tab Tab key.
	 */
	private function urls( string $tab ): void {
		$editing = in_array( Request::key( 'view' ), array( 'edit', 'delete' ), true );
		if ( ! $editing ) {
			$button = '';
			if ( Capabilities::can( Capabilities::EDIT ) ) {
				$register = new RegisterView( $this->plugin );
				$base     = add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) );
				ob_start();
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
				$register->hidden( 'check_urls', 'item', 0, $base, Kinds::URL );
				echo '<button type="submit" class="btn sm">' . esc_html__( 'Check all now', 'estasoft-handover-passport' ) . '</button></form>';
				$button = (string) ob_get_clean();
			}
			View::cardStart( __( 'Check the addresses', 'estasoft-handover-passport' ), $button );
			echo '<p>' . esc_html__( 'The pages that must answer after a restore: the home page, the login, the checkout. Passport asks each one, from this server, when you press the button. It is not an uptime monitor and does not watch the site between visits.', 'estasoft-handover-passport' ) . '</p>';
			$weekly = $this->plugin->weeklyUrls();
			if ( $weekly->enabled() ) {
				$last = $weekly->last();
				echo '<p>' . esc_html__( 'The weekly check is on: every Sunday morning the addresses are asked again, and one email goes to the reminder recipients if any fails.', 'estasoft-handover-passport' ) . ' ' . esc_html(
					'' === $last['at']
						? __( 'It has not run yet.', 'estasoft-handover-passport' )
						: sprintf(
							/* translators: 1: date, 2: number of addresses that failed. */
							_n( 'Last run: %1$s, %2$d address failed.', 'Last run: %1$s, %2$d addresses failed.', $last['failed'], 'estasoft-handover-passport' ),
							Dates::display( $last['at'], true ),
							$last['failed']
						)
				) . '</p>';
			}
			View::cardEnd();
		}
		( new RegisterView( $this->plugin ) )->render( Kinds::URL, self::SLUG, $this->keep( $tab ), true );
		( new RestoreChecklistView( $this->plugin ) )->render( add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) ) );
	}
}
