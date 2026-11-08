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
			'urls'     => __( 'Critical addresses', 'estasoft-handover-passport' ),
		);
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
				( new RegisterView( $this->plugin ) )->render( Kinds::BACKUP, self::SLUG, $this->keep( $tab ) );
		}
	}

	/**
	 * The addresses that must work after a restore, with a button that checks them all.
	 *
	 * @param string $tab Tab key.
	 */
	private function urls( string $tab ): void {
		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Critical addresses', 'estasoft-handover-passport' ) . '</h2>';
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
		if ( Capabilities::can( Capabilities::EDIT ) ) {
			$register = new RegisterView( $this->plugin );
			$base     = add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$register->hidden( 'check_urls', 'item', 0, $base, Kinds::URL );
			echo '<button type="submit" class="button">' . esc_html__( 'Check all now', 'estasoft-handover-passport' ) . '</button></form>';
		}
		echo '</div>';
		( new RegisterView( $this->plugin ) )->render( Kinds::URL, self::SLUG, $this->keep( $tab ) );
		( new RestoreChecklistView( $this->plugin ) )->render( add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) ) );
	}
}
