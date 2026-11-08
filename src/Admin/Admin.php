<?php
/**
 * Admin menu, assets, notices and form handlers (ADM-1, ADM-3, ADM-7, CAP-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Findings\Level;
use Estasoft\HandoverPassport\Install\Upgrader;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Attaches the Passport screens to the admin.
 */
final class Admin {

	public const MENU_SLUG      = 'estasoft-passport';
	public const SCAN_ACTION    = 'estasoft_hp_scan_now';
	public const DISMISS_ACTION = 'estasoft_hp_dismiss';
	public const COPY_ACTION    = 'estasoft_hp_copy_answer';
	public const DISMISS_META   = 'estasoft_hp_dismissed';

	/**
	 * Hook suffixes of the screens that belong to Passport, used to load assets only there (WPI-8).
	 *
	 * @var array<int, string>
	 */
	private array $screen_hooks = array();

	/**
	 * Screens that handle their own form posts.
	 *
	 * @var array<int, Screen>
	 */
	private array $screens = array();

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
		add_filter( 'admin_body_class', array( $this, 'bodyClass' ) );
		add_filter( 'parent_file', array( $this, 'parentFile' ) );
		add_filter( 'submenu_file', array( $this, 'submenuFile' ) );
		add_action( 'network_admin_notices', array( new NetworkNotice(), 'show' ) );
		add_action( 'admin_post_' . self::SCAN_ACTION, array( $this, 'handleScan' ) );
		add_filter( 'plugin_action_links_' . ESTASOFT_HP_BASENAME, array( $this, 'actionLinks' ) );
		add_filter( 'plugin_row_meta', array( $this, 'rowMeta' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'welcomeRedirect' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handleDismiss' ) );
		add_action( 'admin_post_' . self::COPY_ACTION, array( $this, 'handleCopyAnswer' ) );

		( new Actions( $this->plugin ) )->register();
		( new PrivacyPolicy() )->register();
		( new HistoryScreen( $this->plugin ) )->register();
		( new FindingsScreen( $this->plugin ) )->register();
		( new SettingsScreen( $this->plugin ) )->register();
		( new SafetyScreen( $this->plugin ) )->register();
		( new HandoverScreen( $this->plugin ) )->register();
		( new SetupScreen( $this->plugin ) )->register();
		( new DashboardWidget( $this->plugin ) )->register();
		( new QuickAdd() )->register();
	}

	/**
	 * The sections shown in the Passport bar and in the menu, in that order: slug => words. Only sections the person
	 * may open are listed.
	 *
	 * @return array<string, string>
	 */
	public static function sections(): array {
		$all = array(
			self::MENU_SLUG       => array( __( 'Overview', 'estasoft-handover-passport' ), Capabilities::VIEW ),
			InventoryScreen::SLUG => array( __( 'Inventory', 'estasoft-handover-passport' ), Capabilities::VIEW ),
			SafetyScreen::SLUG    => array( __( 'Safety nets', 'estasoft-handover-passport' ), Capabilities::VIEW ),
			PeopleScreen::SLUG    => array( __( 'People and access', 'estasoft-handover-passport' ), Capabilities::VIEW ),
			HistoryScreen::SLUG   => array( __( 'History', 'estasoft-handover-passport' ), Capabilities::VIEW ),
			FindingsScreen::SLUG  => array( __( 'Findings', 'estasoft-handover-passport' ), Capabilities::VIEW ),
			HandoverScreen::SLUG  => array( __( 'Handover', 'estasoft-handover-passport' ), Capabilities::VIEW ),
			SettingsScreen::SLUG  => array( __( 'Settings', 'estasoft-handover-passport' ), Capabilities::CONFIGURE ),
		);
		$out = array();
		foreach ( $all as $slug => $info ) {
			if ( current_user_can( $info[1] ) ) {
				$out[ $slug ] = $info[0];
			}
		}
		return $out;
	}

	/**
	 * Register the menu. A screen is listed only for people who hold its capability.
	 */
	public function menu(): void {
		$overview = new OverviewScreen( $this->plugin );

		$top                  = (string) add_menu_page(
			__( 'Passport', 'estasoft-handover-passport' ),
			__( 'Passport', 'estasoft-handover-passport' ),
			Capabilities::VIEW,
			self::MENU_SLUG,
			array( $overview, 'render' ),
			ESTASOFT_HP_URL . 'assets/images/passport-icon.png',
			80
		);
		$this->screen_hooks[] = $top;
		$sub                  = (string) add_submenu_page(
			self::MENU_SLUG,
			__( 'Overview', 'estasoft-handover-passport' ),
			__( 'Overview', 'estasoft-handover-passport' ),
			Capabilities::VIEW,
			self::MENU_SLUG,
			array( $overview, 'render' )
		);
		$this->screen_hooks[] = $sub;
		Help::attach( $sub, self::MENU_SLUG );

		$this->screens = array(
			new InventoryScreen( $this->plugin ),
			new SafetyScreen( $this->plugin ),
			new PeopleScreen( $this->plugin ),
			new HistoryScreen( $this->plugin ),
			new FindingsScreen( $this->plugin ),
			new HandoverScreen( $this->plugin ),
			new SettingsScreen( $this->plugin ),
		);
		foreach ( $this->screens as $screen ) {
			$title = $screen->title();
			if ( FindingsScreen::SLUG === $screen->slug() && Capabilities::can( Capabilities::VIEW ) ) {
				$title .= $this->needsFixingBubble();
			}
			$hook                 = (string) add_submenu_page(
				self::MENU_SLUG,
				$screen->title(),
				$title,
				$screen->capability(),
				$screen->slug(),
				array( $screen, 'render' )
			);
			$this->screen_hooks[] = $hook;
			Help::attach( $hook, $screen->slug() );
		}

		// The setup guide is reached from the Overview and from the welcome page, so it has no menu item of its own.
		$setup                = new SetupScreen( $this->plugin );
		$this->screens[]      = $setup;
		$hook                 = (string) add_submenu_page(
			'',
			$setup->title(),
			$setup->title(),
			$setup->capability(),
			$setup->slug(),
			array( $setup, 'render' )
		);
		$this->screen_hooks[] = $hook;
		Help::attach( $hook, $setup->slug() );
	}

	/**
	 * The count of findings that need fixing, as a small bubble beside Findings in the menu. It is kept for a minute so the
	 * menu does not run the rules on every page.
	 */
	private function needsFixingBubble(): string {
		$count = get_transient( 'estasoft_hp_menu_count' );
		if ( false === $count ) {
			$count = (int) ( $this->plugin->findings()->counts()[ Level::NEEDS ] ?? 0 );
			set_transient( 'estasoft_hp_menu_count', $count, MINUTE_IN_SECONDS );
		}
		$count = (int) $count;
		if ( $count < 1 ) {
			return '';
		}
		return ' <span class="update-plugins count-' . $count . '"><span class="plugin-count" aria-hidden="true">' . number_format_i18n( $count ) . '</span><span class="screen-reader-text">' . esc_html(
			sprintf(
				/* translators: %s: number of findings. */
				_n( '%s finding needs fixing', '%s findings need fixing', $count, 'estasoft-handover-passport' ),
				number_format_i18n( $count )
			)
		) . '</span></span>';
	}

	/**
	 * Mark the page so the stylesheet can use the whole width of the content area on Passport screens.
	 *
	 * @param string $classes Space-separated classes.
	 */
	public function bodyClass( $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null !== $screen && in_array( $screen->id, $this->screen_hooks, true ) ) {
			return trim( (string) $classes . ' estasoft-hp-screen' );
		}
		return (string) $classes;
	}

	/**
	 * Keep the Passport menu open on the setup guide, which has no menu item.
	 *
	 * @param string|null $parent_file Parent file.
	 */
	public function parentFile( $parent_file ): string {
		return SetupScreen::SLUG === Request::key( 'page' ) ? self::MENU_SLUG : (string) $parent_file;
	}

	/**
	 * Highlight Overview while the setup guide is open.
	 *
	 * @param string|null $file Submenu file.
	 */
	public function submenuFile( $file ): string {
		return SetupScreen::SLUG === Request::key( 'page' ) ? self::MENU_SLUG : (string) $file;
	}

	/**
	 * Load the stylesheet and script on Passport screens only.
	 *
	 * @param string $hook Current screen hook suffix.
	 */
	public function assets( $hook ): void {
		// The menu icon is drawn on every admin page, so it is sized here and not in the screen stylesheet.
		if ( Capabilities::can( Capabilities::VIEW ) ) {
			wp_register_style( 'estasoft-hp-menu', false, array(), ESTASOFT_HP_VERSION );
			wp_enqueue_style( 'estasoft-hp-menu' );
			wp_add_inline_style( 'estasoft-hp-menu', '#adminmenu .toplevel_page_' . self::MENU_SLUG . ' .wp-menu-image img{width:16px;height:16px;padding:9px 0;border-radius:3px;opacity:1;box-sizing:content-box}' );
		}
		if ( ! in_array( (string) $hook, $this->screen_hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'estasoft-hp-admin', ESTASOFT_HP_URL . 'assets/css/admin.css', array(), ESTASOFT_HP_VERSION );
		wp_enqueue_script( 'estasoft-hp-admin', ESTASOFT_HP_URL . 'assets/js/admin.js', array(), ESTASOFT_HP_VERSION, true );
		if ( str_ends_with( (string) $hook, '_page_' . SetupScreen::SLUG ) && current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}
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
			$this->notice(
				'upgrade_error',
				'notice-error',
				sprintf(
					/* translators: %s: short error code. */
					__( 'Passport could not finish an update of its data. Your records were not changed. Code: %s', 'estasoft-handover-passport' ),
					$error
				)
			);
		}

		$this->copyNotice();

		$hours = $this->plugin->scanService()->hoursSinceLast();
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && ( null === $hours || $hours > (int) $this->plugin->settings()->get( 'thresholds.scan_stale_hours', 48 ) ) ) {
			$this->notice( 'cron_off', 'notice-warning', __( 'WordPress cron is switched off and the last scan is old. Ask your host to run WordPress cron from the server, or run "wp estasoft-passport scan" on a schedule.', 'estasoft-handover-passport' ) );
		}
	}

	/**
	 * The copy guard question (IMP-4). It stays until someone answers, because reminders are paused until then.
	 */
	private function copyNotice(): void {
		$guard = $this->plugin->copyGuard();
		if ( $guard->isMarkedCopy() ) {
			$this->notice( 'copy_marked', 'notice-info', __( 'This site is marked as a copy. Reminders, emailed copies and scheduled checks are off here, so they are not sent a second time.', 'estasoft-handover-passport' ) );
			return;
		}
		if ( ! $guard->pending() ) {
			return;
		}
		$state = $guard->state();
		echo '<div class="notice notice-warning estasoft-hp-copy-notice" role="alert"><p><strong>' . esc_html__( 'Is this a copy of the site?', 'estasoft-handover-passport' ) . '</strong> ';
		echo esc_html(
			sprintf(
				/* translators: 1: address the Passport was set up on, 2: address of this site now. */
				__( 'Passport was set up at %1$s and this site is now at %2$s. Reminders, emailed copies and scheduled checks are paused until you say which it is.', 'estasoft-handover-passport' ),
				(string) ( $state['from'] ?? '' ),
				(string) ( $state['to'] ?? '' )
			)
		) . '</p>';
		if ( Capabilities::can( Capabilities::CONFIGURE ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline-form"><input type="hidden" name="action" value="' . esc_attr( self::COPY_ACTION ) . '">';
			Form::nonce( self::COPY_ACTION );
			echo '<p><button type="submit" name="answer" value="moved" class="button button-primary">' . esc_html__( 'This site moved', 'estasoft-handover-passport' ) . '</button> ';
			echo '<button type="submit" name="answer" value="copy" class="button">' . esc_html__( 'This is a copy', 'estasoft-handover-passport' ) . '</button></p>';
			echo '<p class="description">' . esc_html__( 'Moved keeps everything and takes the new address. Copy keeps reminders off on this site.', 'estasoft-handover-passport' ) . '</p></form>';
		} else {
			echo '<p>' . esc_html__( 'Ask someone who can change Passport settings to answer.', 'estasoft-handover-passport' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Save the answer to the copy question.
	 */
	public function handleCopyAnswer(): void {
		if ( ! Capabilities::can( Capabilities::CONFIGURE ) ) {
			wp_die( esc_html__( 'You do not have permission to change settings.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::COPY_ACTION );
		$answer = sanitize_key( Request::post( 'answer' ) );
		$done   = $this->plugin->copyGuard()->answer( $answer );
		$notice = ! $done ? 'denied' : ( 'moved' === $answer ? 'copy_moved' : 'copy_marked' );
		if ( $done ) {
			$this->plugin->changeLog()->add( 'copy_answered', 'site', 0, home_url(), array(), array( 'answer' => $answer ) );
		}
		wp_safe_redirect( add_query_arg( 'estasoft_hp_notice', $notice, admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * Print a notice that can be dismissed and stays dismissed for that person (ADM-6).
	 *
	 * @param string $id    Notice ID.
	 * @param string $css_class Notice class.
	 * @param string $text  Message.
	 */
	private function notice( string $id, string $css_class, string $text ): void {
		$dismissed = (array) get_user_meta( get_current_user_id(), self::DISMISS_META, true );
		if ( in_array( $id, $dismissed, true ) ) {
			return;
		}
		$url = wp_nonce_url( add_query_arg( array( 'action' => self::DISMISS_ACTION, 'notice' => $id ), admin_url( 'admin-post.php' ) ), self::DISMISS_ACTION );
		echo '<div class="notice ' . esc_attr( $css_class ) . '"><p>' . esc_html( $text ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Dismiss', 'estasoft-handover-passport' ) . '</a></p></div>';
	}

	/**
	 * Remember that a person dismissed a notice.
	 */
	public function handleDismiss(): void {
		if ( ! Capabilities::can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::DISMISS_ACTION );
		$id        = Request::key( 'notice' );
		$dismissed = array_filter( (array) get_user_meta( get_current_user_id(), self::DISMISS_META, true ) );
		if ( '' !== $id && ! in_array( $id, $dismissed, true ) ) {
			$dismissed[] = $id;
			update_user_meta( get_current_user_id(), self::DISMISS_META, array_values( $dismissed ) );
		}
		$referer = wp_get_referer();
		wp_safe_redirect( false === $referer ? admin_url( 'admin.php?page=' . self::MENU_SLUG ) : $referer );
		exit;
	}

	/**
	 * Run a scan from the Scan now button.
	 */
	public function handleScan(): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to run a scan.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SCAN_ACTION );

		$result = $this->plugin->scanService()->runOnDemand( 'manual' );
		$notice = array( 'done' => 'scan_done', 'busy' => 'scan_busy', 'wait' => 'scan_wait' )[ $result['status'] ] ?? 'scan_failed';
		$args   = array( 'estasoft_hp_notice' => $notice );
		if ( 'wait' === $result['status'] ) {
			$args['estasoft_hp_wait'] = (int) ( $result['wait'] ?? 60 );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
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
		if ( Capabilities::can( Capabilities::EDIT ) && ! $this->plugin->setup()->complete() ) {
			wp_safe_redirect( SetupScreen::url() );
			exit;
		}
		if ( Capabilities::can( Capabilities::VIEW ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
			exit;
		}
	}
}
