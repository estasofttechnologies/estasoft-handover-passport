<?php
/**
 * Records changes as they happen, with the person who made them (CHG-2).
 *
 * The scan catches what happens outside WordPress, such as files changed over SFTP. Labels
 * here match the scan's labels, so a change is logged once, not twice.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Changes;

use Estasoft\HandoverPassport\Install\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to WordPress events.
 */
class ChangeCapture {

	/**
	 * Versions seen just before an update replaced the files, keyed by "type:file".
	 *
	 * @var array<string, string>
	 */
	private array $versions_before = array();

	/**
	 * Users already logged as new administrators in this request, so one event is not logged twice.
	 *
	 * @var array<int, bool>
	 */
	private array $admins_logged = array();

	/**
	 * Constructor.
	 *
	 * @param ChangeLog $log Change log.
	 */
	public function __construct( private ChangeLog $log ) {}

	/**
	 * Attach the listeners.
	 */
	public function register(): void {
		add_action( 'activated_plugin', array( $this, 'pluginActivated' ), 10, 1 );
		add_action( 'deactivated_plugin', array( $this, 'pluginDeactivated' ), 10, 1 );
		add_filter( 'upgrader_pre_install', array( $this, 'rememberVersion' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'upgraded' ), 10, 2 );
		add_action( 'switch_theme', array( $this, 'themeSwitched' ), 10, 3 );
		add_action( 'user_register', array( $this, 'userRegistered' ), 10, 1 );
		add_action( 'set_user_role', array( $this, 'roleSet' ), 10, 3 );
		add_action( 'update_option_siteurl', array( $this, 'siteAddressChanged' ), 10, 2 );
		add_action( 'update_option_home', array( $this, 'homeAddressChanged' ), 10, 2 );
	}

	/**
	 * A plugin was switched on.
	 *
	 * @param string $file Plugin file relative to the plugins folder.
	 */
	public function pluginActivated( $file ): void {
		if ( ESTASOFT_HP_BASENAME === (string) $file ) {
			return; // Switching Passport on is the start of the record, not a change to it.
		}
		$this->log->add( 'plugin_activated', 'plugin', 0, $this->pluginName( (string) $file ), array( 'active' => false ), array( 'active' => true ) );
		Schedule::queueScan();
	}

	/**
	 * A plugin was switched off.
	 *
	 * @param string $file Plugin file relative to the plugins folder.
	 */
	public function pluginDeactivated( $file ): void {
		$this->log->add( 'plugin_deactivated', 'plugin', 0, $this->pluginName( (string) $file ), array( 'active' => true ), array( 'active' => false ) );
		Schedule::queueScan();
	}

	/**
	 * Remember the version in place before an update or install replaces it.
	 *
	 * @param mixed                $response   Passed through unchanged.
	 * @param array<string, mixed> $hook_extra What is being upgraded.
	 * @return mixed
	 */
	public function rememberVersion( $response, $hook_extra = array() ) {
		if ( is_array( $hook_extra ) ) {
			if ( ! empty( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
				$this->versions_before[ 'plugin:' . $hook_extra['plugin'] ] = $this->pluginData( $hook_extra['plugin'] )['Version'] ?? '';
			} elseif ( ! empty( $hook_extra['theme'] ) && is_string( $hook_extra['theme'] ) ) {
				$theme = wp_get_theme( $hook_extra['theme'] );
				$this->versions_before[ 'theme:' . $hook_extra['theme'] ] = $theme->exists() ? (string) $theme->get( 'Version' ) : '';
			}
		}
		return $response;
	}

	/**
	 * Updates and installs finished. Core updates are left to the scan.
	 *
	 * @param mixed                $upgrader Upgrader instance, not used.
	 * @param array<string, mixed> $extra    What was done.
	 */
	public function upgraded( $upgrader, $extra = array() ): void {
		if ( ! is_array( $extra ) || empty( $extra['type'] ) || empty( $extra['action'] ) ) {
			return;
		}
		$type   = (string) $extra['type'];
		$action = (string) $extra['action'];
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || ! in_array( $action, array( 'update', 'install' ), true ) ) {
			return;
		}

		$items = array();
		if ( 'plugin' === $type ) {
			$items = ! empty( $extra['plugins'] ) ? (array) $extra['plugins'] : ( ! empty( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );
		} else {
			$items = ! empty( $extra['themes'] ) ? (array) $extra['themes'] : ( ! empty( $extra['theme'] ) ? array( $extra['theme'] ) : array() );
		}

		foreach ( $items as $item ) {
			$item = (string) $item;
			if ( '' === $item ) {
				continue;
			}
			if ( 'plugin' === $type ) {
				$data = $this->pluginData( $item );
				$name = (string) ( $data['Name'] ?? $item );
				$now  = (string) ( $data['Version'] ?? '' );
			} else {
				$theme = wp_get_theme( $item );
				$name  = $theme->exists() ? (string) $theme->get( 'Name' ) : $item;
				$now   = $theme->exists() ? (string) $theme->get( 'Version' ) : '';
			}
			$was = $this->versions_before[ $type . ':' . $item ] ?? '';

			if ( 'update' === $action && '' !== $was && $was !== $now ) {
				$this->log->add( $type . '_updated', $type, 0, $name, array( 'version' => $was ), array( 'version' => $now ) );
			} elseif ( 'install' === $action && '' === $was ) {
				$this->log->add( $type . '_added', $type, 0, $name, array(), array( 'version' => $now ) );
			}
		}
		Schedule::queueScan();
	}

	/**
	 * The active theme changed.
	 *
	 * @param string          $new_name  New theme name.
	 * @param \WP_Theme       $new_theme New theme.
	 * @param \WP_Theme|false $old_theme Old theme.
	 */
	public function themeSwitched( $new_name, $new_theme = null, $old_theme = false ): void {
		$old = $old_theme instanceof \WP_Theme ? (string) $old_theme->get_stylesheet() : '';
		$new = $new_theme instanceof \WP_Theme ? (string) $new_theme->get_stylesheet() : '';
		$this->log->add( 'theme_switched', 'theme', 0, (string) $new_name, array( 'theme' => $old ), array( 'theme' => $new ) );
		Schedule::queueScan();
	}

	/**
	 * A user was created. Only administrators are logged.
	 *
	 * @param int $user_id User ID.
	 */
	public function userRegistered( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( $user instanceof \WP_User && in_array( 'administrator', (array) $user->roles, true ) && empty( $this->admins_logged[ (int) $user->ID ] ) ) {
			$this->admins_logged[ (int) $user->ID ] = true;
			$this->log->add( 'administrator_added', 'user', (int) $user->ID, (string) $user->display_name );
			Schedule::queueScan();
		}
	}

	/**
	 * A user's role changed. Only a change to administrator is logged.
	 *
	 * @param int                $user_id   User ID.
	 * @param string             $role      New role.
	 * @param array<int, string> $old_roles Previous roles.
	 */
	public function roleSet( $user_id, $role, $old_roles = array() ): void {
		if ( 'administrator' !== $role || in_array( 'administrator', (array) $old_roles, true ) || ! empty( $this->admins_logged[ (int) $user_id ] ) ) {
			return;
		}
		$this->admins_logged[ (int) $user_id ] = true;
		$user                                  = get_userdata( (int) $user_id );
		if ( $user instanceof \WP_User ) {
			$this->log->add( 'administrator_added', 'user', (int) $user->ID, (string) $user->display_name, array( 'roles' => array_values( (array) $old_roles ) ), array( 'roles' => array( 'administrator' ) ) );
			Schedule::queueScan();
		}
	}

	/**
	 * The WordPress address changed.
	 *
	 * @param mixed $older Old value.
	 * @param mixed $newer New value.
	 */
	public function siteAddressChanged( $older, $newer ): void {
		$this->log->add( 'site_address_changed', 'core', 0, 'Site address', array( 'value' => (string) $older ), array( 'value' => (string) $newer ) );
		Schedule::queueScan();
	}

	/**
	 * The site address changed.
	 *
	 * @param mixed $older Old value.
	 * @param mixed $newer New value.
	 */
	public function homeAddressChanged( $older, $newer ): void {
		$this->log->add( 'site_address_changed', 'core', 0, 'Home address', array( 'value' => (string) $older ), array( 'value' => (string) $newer ) );
		Schedule::queueScan();
	}

	/**
	 * Header data of a plugin file.
	 *
	 * @param string $file Plugin file relative to the plugins folder.
	 * @return array<string, mixed>
	 */
	private function pluginData( string $file ): array {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$path = WP_PLUGIN_DIR . '/' . $file;
		return is_readable( $path ) ? get_plugin_data( $path, false, false ) : array();
	}

	/**
	 * Display name of a plugin, or its file name when it cannot be read.
	 *
	 * @param string $file Plugin file relative to the plugins folder.
	 */
	private function pluginName( string $file ): string {
		$data = $this->pluginData( $file );
		return '' !== (string) ( $data['Name'] ?? '' ) ? (string) $data['Name'] : $file;
	}
}
