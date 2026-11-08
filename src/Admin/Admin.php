<?php
/**
 * Admin menu, assets, notices and form handlers (ADM-1, ADM-3, ADM-7, CAP-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Install\Upgrader;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Attaches the Passport screens to the admin.
 */
final class Admin {

	public const MENU_SLUG = 'estasoft-passport';
	public const SCAN_ACTION = 'estasoft_hp_scan_now';

	/**
	 * Hook suffixes of the screens that belong to Passport, used to load assets only there (WPI-8).
	 *
	 * @var list<string>
	 */
	private array $screen_hooks = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_post_' . self::SCAN_ACTION, array( $this, 'handleScan' ) );
		add_filter( 'plugin_action_links_' . ESTASOFT_HP_BASENAME, array( $this, 'actionLinks' ) );
		add_filter( 'plugin_row_meta', array( $this, 'rowMeta' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'welcomeRedirect' ) );
	}

	/**
	 * Register the menu. A screen is listed only for people who hold its capability.
	 */
	public function menu(): void {
		$overview = new OverviewScreen( $this->plugin );

		$this->screen_hooks[] = (string) add_menu_page(
			__( 'Passport', 'estasoft-handover-passport' ),
			__( 'Passport', 'estasoft-handover-passport' ),
			Capabilities::VIEW,
			self::MENU_SLUG,
			array( $overview, 'render' ),
			'dashicons-clipboard',
			80
		);
		$this->screen_hooks[] = (string) add_submenu_page(
			self::MENU_SLUG,
			__( 'Overview', 'estasoft-handover-passport' ),
			__( 'Overview', 'estasoft-handover-passport' ),
			Capabilities::VIEW,
			self::MENU_SLUG,
			array( $overview, 'render' )
		);
	}

	/**
	 * Load the stylesheet and script on Passport screens only.
	 *
	 * @param string $hook Current screen hook suffix.
	 */
	public function assets( $hook ): void {
		if ( ! in_array( (string) $hook, $this->screen_hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'estasoft-hp-admin', ESTASOFT_HP_URL . 'assets/css/admin.css', array(), ESTASOFT_HP_VERSION );
		wp_enqueue_script( 'estasoft-hp-admin', ESTASOFT_HP_URL . 'assets/js/admin.js', array(), ESTASOFT_HP_VERSION, true );
	}

	/**
	 * Notices, shown only on Passport screens (ADM-6, WPI-3, WPI-6).
	 */
	public function notices(): void {
		$screen = get_current_screen();
		if ( null === $screen || ! in_array( $screen->id, $this->screen_hooks, true ) || ! Capabilities::can( Capabilities::VIEW ) ) {
			return;
		}

		$error = (string) get_option( Upgrader::ERROR_OPTION, '' );
		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html(
				sprintf(
					/* translators: %s: short error code. */
					__( 'Passport could not finish an update of its data. Your records were not changed. Code: %s', 'estasoft-handover-passport' ),
					$error
				)
			) . '</p></div>';
		}

		$hours = $this->plugin->scanService()->hoursSinceLast();
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && ( null === $hours || $hours > (int) $this->plugin->settings()->get( 'thresholds.scan_stale_hours', 48 ) ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'WordPress cron is switched off and the last scan is old. Ask your host to run WordPress cron from the server, or run "wp estasoft-passport scan" on a schedule.', 'estasoft-handover-passport' ) . '</p></div>';
		}
	}

	/**
	 * Run a scan from the Scan now button.
	 */
	public function handleScan(): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to run a scan.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SCAN_ACTION );

		$result = $this->plugin->scanService()->run( 'manual' );
		$notice = 'done' === $result['status'] ? 'scan_done' : ( 'busy' === $result['status'] ? 'scan_busy' : 'scan_failed' );
		wp_safe_redirect( add_query_arg( 'estasoft_hp_notice', $notice, admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * Links on the Plugins screen (ADM-7).
	 *
	 * @param array<int|string, string> $links Existing links.
	 * @return array<int|string, string>
	 */
	public function actionLinks( $links ): array {
		$links = is_array( $links ) ? $links : array();
		if ( Capabilities::can( Capabilities::VIEW ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">' . esc_html__( 'Open Passport', 'estasoft-handover-passport' ) . '</a>' );
		}
		return $links;
	}

	/**
	 * Documentation link in the plugin row (ADM-7).
	 *
	 * @param array<int|string, string> $meta Existing meta links.
	 * @param string                    $file Plugin file.
	 * @return array<int|string, string>
	 */
	public function rowMeta( $meta, $file ): array {
		$meta = is_array( $meta ) ? $meta : array();
		if ( ESTASOFT_HP_BASENAME === $file ) {
			$meta[] = '<a href="' . esc_url( 'https://estasoft.com/' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Documentation', 'estasoft-handover-passport' ) . '</a>';
		}
		return $meta;
	}

	/**
	 * One redirect to Passport after a person activates the plugin in a browser (ONB-2).
	 */
	public function welcomeRedirect(): void {
		if ( ! get_transient( \Estasoft\HandoverPassport\Install\Activator::REDIRECT_TRANSIENT ) || wp_doing_ajax() ) {
			return;
		}
		delete_transient( \Estasoft\HandoverPassport\Install\Activator::REDIRECT_TRANSIENT );
		if ( Capabilities::can( Capabilities::VIEW ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
			exit;
		}
	}
}
