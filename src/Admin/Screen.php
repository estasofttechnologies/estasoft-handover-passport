<?php
/**
 * Base for a screen with tabs.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * A top-level Passport screen. Each tab is drawn by a method of the child class.
 */
abstract class Screen {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( protected Plugin $plugin ) {}

	/**
	 * Menu slug.
	 */
	abstract public function slug(): string;

	/**
	 * Menu and page title.
	 */
	abstract public function title(): string;

	/**
	 * Capability needed to open the screen.
	 */
	public function capability(): string {
		return Capabilities::VIEW;
	}

	/**
	 * Tabs: key => label. The first is the default.
	 *
	 * @return array<string, string>
	 */
	abstract protected function tabs(): array;

	/**
	 * Print the content of the current tab.
	 *
	 * @param string $tab Tab key.
	 */
	abstract protected function tab( string $tab ): void;

	/**
	 * Print the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to open this screen.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		$tabs    = $this->tabs();
		$current = Request::key( 'tab', (string) array_key_first( $tabs ) );
		if ( ! isset( $tabs[ $current ] ) ) {
			$current = (string) array_key_first( $tabs );
		}

		View::open( $this->title() );
		if ( count( $tabs ) > 1 ) {
			echo '<nav class="nav-tab-wrapper estasoft-hp-tabs" aria-label="' . esc_attr( $this->title() ) . '">';
			foreach ( $tabs as $key => $label ) {
				$url = add_query_arg( array( 'page' => $this->slug(), 'tab' => $key ), admin_url( 'admin.php' ) );
				echo '<a class="nav-tab' . ( $key === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $key === $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
			}
			echo '</nav>';
		}
		$this->tab( $current );
		View::close();
	}

	/**
	 * Query arguments that keep the tab, for the register view.
	 *
	 * @param string $tab Tab key.
	 * @return array<string, string>
	 */
	protected function keep( string $tab ): array {
		return array( 'tab' => $tab );
	}
}
