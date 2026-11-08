<?php
/**
 * Base for a screen with tabs.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\Kinds;
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
	 * The line under the title for a tab. Empty for none.
	 *
	 * @param string $tab Tab key.
	 */
	protected function describe( string $tab ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Screens that use the tab override this method.
		return '';
	}

	/**
	 * The main action in the page header for a tab, drawn as the one filled button. Null for none.
	 *
	 * @param string $tab Tab key.
	 * @return array{label: string, url: string}|null
	 */
	protected function action( string $tab ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Screens that use the tab override this method.
		return null;
	}

	/**
	 * Extra buttons in the page header, drawn before the main action. Markup built from escaped parts, or an empty string.
	 *
	 * @param string $tab Tab key.
	 */
	protected function extra( string $tab ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Screens that use the tab override this method.
		return '';
	}

	/**
	 * Tabs that are reached from a link on another tab and so do not appear in the row of sections: key => the tab shown as current.
	 *
	 * @return array<string, string>
	 */
	protected function hiddenTabs(): array {
		return array();
	}

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

		View::open( $this->title(), $this->action( $current ), $this->describe( $current ), $this->extra( $current ) );
		$hidden         = $this->hiddenTabs();
		$shown          = array_diff_key( $tabs, $hidden );
		$current_in_row = $hidden[ $current ] ?? $current;
		if ( count( $shown ) > 1 ) {
			echo '<nav class="sub-tabs" aria-label="' . esc_attr__( 'Sections', 'estasoft-handover-passport' ) . '">';
			foreach ( $shown as $key => $label ) {
				$url = add_query_arg( array( 'page' => $this->slug(), 'tab' => $key ), admin_url( 'admin.php' ) );
				echo '<a' . ( $key === $current_in_row ? ' class="on" aria-current="page"' : '' ) . ' href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			}
			echo '</nav>';
		}
		$this->tab( $current );
		View::close();
	}

	/**
	 * Whether the screen shows a list rather than the form to edit or delete a record.
	 */
	protected function onList(): bool {
		return ! in_array( Request::key( 'view' ), array( 'edit', 'delete', 'offboard' ), true );
	}

	/**
	 * The Add button for a register that fills a whole tab, or null when the viewer may not add.
	 *
	 * @param string $kind Kind key.
	 * @param string $tab  Tab key.
	 * @return array{label: string, url: string}|null
	 */
	protected function addAction( string $kind, string $tab ): ?array {
		$def = Kinds::get( $kind );
		if ( null === $def || ! $this->onList() || ! Capabilities::can( Capabilities::EDIT ) ) {
			return null;
		}
		$base = add_query_arg( array( 'page' => $this->slug() ) + $this->keep( $tab ), admin_url( 'admin.php' ) );
		return array(
			'label' => RegisterView::addLabel( $def ),
			'url'   => add_query_arg( array( 'view' => 'edit', 'kind' => $kind, 'id' => 0 ), $base ),
		);
	}

	/**
	 * A line that counts a register's records, for example "6 licences".
	 *
	 * @param string $kind Kind key.
	 */
	protected function countLine( string $kind ): string {
		$def = Kinds::get( $kind );
		if ( null === $def ) {
			return '';
		}
		$count = count( $this->plugin->records()->listKind( $kind, Capabilities::canSeeSensitive() ) );
		return sprintf(
			/* translators: 1: number, 2: name of a kind of record in lower case, for example "6 licences". */
			__( '%1$d %2$s.', 'estasoft-handover-passport' ),
			$count,
			1 === $count ? mb_strtolower( (string) $def['label'] ) : mb_strtolower( (string) $def['plural'] )
		);
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
