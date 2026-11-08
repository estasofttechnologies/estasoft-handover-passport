<?php
/**
 * The optional summary on the WordPress Dashboard (RMD-6, ADM-9).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Findings\Level;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Reminders\UpcomingDates;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * A widget with the next five dates and the number of findings that need fixing. It exists only when a person with the
 * configure capability switched it on, and only people who may view the Passport see it. Records marked Sensitive are
 * left out of the dates unless the viewer holds the Sensitive capability.
 */
final class DashboardWidget {

	public const ID = 'estasoft_hp_dashboard';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Attach to the Dashboard.
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add' ) );
	}

	/**
	 * Add the widget when it is switched on and the person may view the Passport.
	 */
	public function add(): void {
		if ( ! (bool) $this->plugin->settings()->get( 'dashboard_widget', false ) || ! Capabilities::can( Capabilities::VIEW ) ) {
			return;
		}
		wp_add_dashboard_widget( self::ID, __( 'Passport', 'estasoft-handover-passport' ), array( $this, 'render' ) );
	}

	/**
	 * Print the widget.
	 */
	public function render(): void {
		if ( ! Capabilities::can( Capabilities::VIEW ) ) {
			return;
		}
		$overview = admin_url( 'admin.php?page=' . Admin::MENU_SLUG );
		$counts   = $this->plugin->findings()->counts();
		$needs    = (int) ( $counts[ Level::NEEDS ] ?? 0 );

		echo '<p>';
		if ( $needs > 0 ) {
			echo '<a href="' . esc_url( add_query_arg( array( 'page' => FindingsScreen::SLUG, 'tab' => 'open', 'severity' => Level::NEEDS ), admin_url( 'admin.php' ) ) ) . '">' . esc_html(
				sprintf(
					/* translators: %d: number of findings. */
					_n( '%d finding needs fixing', '%d findings need fixing', $needs, 'estasoft-handover-passport' ),
					$needs
				)
			) . '</a>';
		} else {
			echo esc_html__( 'No finding needs fixing.', 'estasoft-handover-passport' );
		}
		echo '</p>';

		$today = Clock::today();
		$can   = Capabilities::canSeeSensitive();
		$rows  = array();
		foreach ( ( new UpcomingDates( $this->plugin->records() ) )->between( $today, Dates::addDays( $today, 365 ) ) as $date ) {
			if ( $date['sensitive'] && ! $can ) {
				continue;
			}
			$rows[] = $date;
			if ( count( $rows ) >= 5 ) {
				break;
			}
		}
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No renewal or expiry date is entered for the next year.', 'estasoft-handover-passport' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $rows as $date ) {
				echo '<li>' . esc_html( Dates::displayDate( $date['date'] ) ) . ' &ndash; ' . esc_html( $date['title'] ) . ' <span class="description">(' . esc_html( $date['kind_label'] ) . ')</span></li>';
			}
			echo '</ul>';
		}
		echo '<p><a href="' . esc_url( $overview ) . '">' . esc_html__( 'Open the Passport', 'estasoft-handover-passport' ) . '</a></p>';
	}
}
