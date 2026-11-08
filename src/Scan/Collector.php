<?php
/**
 * Reads facts from WordPress and the server (SCN-1 to SCN-4, SCN-8, SCN-9, HST-5).
 *
 * Nothing here makes a request that leaves the server. It never reads or stores the
 * database password, salts, keys or any constant that holds a secret.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Collects one fact set. Each section is wrapped so a missing ability becomes a gap, not an error.
 */
class Collector {

	public const UPDATE_SEEN_OPTION = 'estasoft_hp_update_seen';

	/**
	 * Gaps found while collecting, by section.
	 *
	 * @var array<string, string>
	 */
	private array $gaps = array();

	/**
	 * Collect everything within a time budget.
	 *
	 * @param int $deadline Unix time after which slow sections stop (SCN-6).
	 * @return array<string, mixed>
	 */
	public function collect( int $deadline ): array {
		$this->gaps = array();

		$facts = array(
			'schema'     => 1,
			'taken_at'   => gmdate( 'Y-m-d H:i:s' ),
			'core'       => $this->guard( 'core', fn(): array => $this->core() ),
			'plugins'    => $this->guard( 'plugins', fn(): array => $this->plugins() ),
			'mu_plugins' => $this->guard( 'mu_plugins', fn(): array => $this->muPlugins() ),
			'dropins'    => $this->guard( 'dropins', fn(): array => $this->dropins() ),
			'themes'     => $this->guard( 'themes', fn(): array => $this->themes() ),
			'users'      => $this->guard( 'users', fn(): array => $this->users() ),
		);
		$facts['cron']      = $this->guard( 'cron', fn(): array => $this->cron( array_keys( $facts['plugins'] ) ) );
		$facts['resources'] = $this->guard( 'resources', fn(): array => $this->resources( $deadline ) );
		$facts['sensitive'] = $this->guard( 'sensitive', fn(): array => $this->sensitive() );
		$facts['gaps']      = $this->gaps;
		return $facts;
	}

	/**
	 * Run a section; on failure record why it is not available (SCN-9).
	 *
	 * @param string   $section  Section key.
	 * @param callable $callback Collector.
	 * @return array<string, mixed>
	 */
	private function guard( string $section, callable $callback ): array {
		try {
			$value = $callback();
			return is_array( $value ) ? $value : array();
		} catch ( \Throwable $e ) {
			$this->gaps[ $section ] = 'error';
			return array();
		}
	}

	/**
	 * Core and server facts (SCN-1).
	 *
	 * @return array<string, mixed>
	 */
	private function core(): array {
		global $wpdb, $wp_version;

		$db_version = '';
		$db_engine  = 'unknown';
		if ( $wpdb instanceof \wpdb ) {
			$db_version = (string) $wpdb->db_version();
			$info       = (string) $wpdb->db_server_info();
			$db_engine  = false !== stripos( $info, 'mariadb' ) ? 'MariaDB' : ( false !== stripos( $db_version, 'sqlite' ) || false !== stripos( $info, 'sqlite' ) ? 'SQLite' : 'MySQL' );
		}

		$extensions = array();
		foreach ( array( 'json', 'mbstring', 'curl', 'gd', 'imagick', 'openssl', 'zip', 'intl', 'xml', 'dom', 'exif', 'sodium', 'redis', 'opcache' ) as $name ) {
			$extensions[ $name ] = extension_loaded( $name );
		}

		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) : '';

		return array(
			'wp_version'          => (string) $wp_version,
			'site_url'            => (string) get_option( 'siteurl', '' ),
			'home_url'            => (string) get_option( 'home', '' ),
			'language'            => get_locale(),
			'timezone'            => wp_timezone_string(),
			'environment'         => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'permalink_structure' => (string) get_option( 'permalink_structure', '' ),
			'https'               => 0 === strpos( (string) get_option( 'siteurl', '' ), 'https://' ) && 0 === strpos( (string) get_option( 'home', '' ), 'https://' ),
			'debug'               => array(
				'WP_DEBUG'     => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'WP_DEBUG_LOG' => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
				'SCRIPT_DEBUG' => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
			),
			'cron_disabled'       => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'multisite'           => is_multisite(),
			'php_version'         => PHP_VERSION,
			'php_extensions'      => $extensions,
			'memory_limit'        => (string) ini_get( 'memory_limit' ),
			'wp_memory_limit'     => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : '',
			'upload_max'          => (string) ini_get( 'upload_max_filesize' ),
			'post_max'            => (string) ini_get( 'post_max_size' ),
			'max_execution_time'  => (string) ini_get( 'max_execution_time' ),
			'db_engine'           => $db_engine,
			'db_version'          => $db_version,
			'web_server'          => $server,
			'os_family'           => PHP_OS_FAMILY,
			'object_cache'        => wp_using_ext_object_cache(),
			'opcache'             => function_exists( 'opcache_get_status' ) && '1' === (string) ini_get( 'opcache.enable' ),
		);
	}

	/**
	 * Plugins (SCN-2), with update data WordPress already stored (no forced refresh).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$update   = get_site_transient( 'update_plugins' );
		$response = is_object( $update ) && isset( $update->response ) ? (array) $update->response : array();
		$no_update = is_object( $update ) && isset( $update->no_update ) ? (array) $update->no_update : array();
		$checked  = is_object( $update ) && isset( $update->checked ) ? (array) $update->checked : array();
		$active   = (array) get_option( 'active_plugins', array() );
		$network  = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$seen     = $this->updateSeen();

		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$slug   = false === strpos( $file, '/' ) ? basename( $file, '.php' ) : dirname( $file );
			$new    = isset( $response[ $file ] ) && isset( $response[ $file ]->new_version ) ? (string) $response[ $file ]->new_version : '';
			$known  = isset( $response[ $file ] ) || isset( $no_update[ $file ] );
			$wporg  = ( $known && $checked ) ? $this->isWpOrgEntry( $response[ $file ] ?? $no_update[ $file ] ) : null;
			$out[ $slug ] = array(
				'file'         => $file,
				'name'         => (string) $data['Name'],
				'version'      => (string) $data['Version'],
				'author'       => wp_strip_all_tags( (string) $data['AuthorName'] ),
				'author_uri'   => (string) $data['AuthorURI'],
				'active'       => in_array( $file, $active, true ) || in_array( $file, $network, true ),
				'network'      => in_array( $file, $network, true ),
				'update'       => $new,
				'update_since' => '' !== $new ? $this->markUpdate( $seen, 'plugin:' . $slug . ':' . $new ) : '',
				'wporg'        => $wporg,
			);
		}
		$this->saveUpdateSeen( $seen );
		ksort( $out );
		return $out;
	}

	/**
	 * Must-use plugins.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function muPlugins(): array {
		if ( ! function_exists( 'get_mu_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_mu_plugins() as $file => $data ) {
			$out[ (string) $file ] = array( 'name' => (string) $data['Name'], 'version' => (string) $data['Version'], 'author' => wp_strip_all_tags( (string) $data['AuthorName'] ) );
		}
		return $out;
	}

	/**
	 * Drop-ins such as object-cache.php.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function dropins(): array {
		if ( ! function_exists( 'get_dropins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_dropins() as $file => $data ) {
			$out[ (string) $file ] = array( 'name' => (string) $data['Name'] );
		}
		return $out;
	}

	/**
	 * Themes with parent, active state and stored update data.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function themes(): array {
		$update   = get_site_transient( 'update_themes' );
		$response = is_object( $update ) && isset( $update->response ) ? (array) $update->response : array();
		$no_update = is_object( $update ) && isset( $update->no_update ) ? (array) $update->no_update : array();
		$checked  = is_object( $update ) && isset( $update->checked ) ? (array) $update->checked : array();
		$active   = (string) get_stylesheet();
		$seen     = $this->updateSeen();

		$out = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$new   = isset( $response[ $slug ]['new_version'] ) ? (string) $response[ $slug ]['new_version'] : '';
			$known = isset( $response[ $slug ] ) || isset( $no_update[ $slug ] );
			$out[ (string) $slug ] = array(
				'name'         => (string) $theme->get( 'Name' ),
				'version'      => (string) $theme->get( 'Version' ),
				'author'       => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
				'author_uri'   => (string) $theme->get( 'AuthorURI' ),
				'active'       => (string) $slug === $active || (string) $slug === (string) get_template(),
				'parent'       => (string) $theme->get_template() !== (string) $slug ? (string) $theme->get_template() : '',
				'update'       => $new,
				'update_since' => '' !== $new ? $this->markUpdate( $seen, 'theme:' . $slug . ':' . $new ) : '',
				'wporg'        => ( $known && $checked ) ? true : ( $checked ? false : null ),
			);
		}
		$this->saveUpdateSeen( $seen );
		ksort( $out );
		return $out;
	}

	/**
	 * Recurring scheduled tasks, grouped by a guess at the owner (SCN-3, Verify).
	 *
	 * @param list<string> $plugin_slugs Slugs of installed plugins.
	 * @return list<array<string, mixed>>
	 */
	private function cron( array $plugin_slugs ): array {
		$cron = _get_cron_array();
		if ( ! is_array( $cron ) ) {
			return array();
		}
		$schedules = wp_get_schedules();
		$out       = array();
		foreach ( $cron as $time => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				foreach ( (array) $events as $event ) {
					if ( empty( $event['schedule'] ) ) {
						continue;
					}
					$key = (string) $hook;
					if ( isset( $out[ $key ] ) && $out[ $key ]['next_run'] <= (int) $time ) {
						continue;
					}
					$out[ $key ] = array(
						'hook'     => $key,
						'schedule' => (string) ( $schedules[ $event['schedule'] ]['display'] ?? $event['schedule'] ),
						'next_run' => (int) $time,
						'owner'    => $this->cronOwner( $key, $plugin_slugs ),
					);
				}
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	/**
	 * Guess the plugin that owns a hook from its name.
	 *
	 * @param string       $hook         Hook name.
	 * @param list<string> $plugin_slugs Installed plugin slugs.
	 */
	private function cronOwner( string $hook, array $plugin_slugs ): string {
		$core = array( 'wp_', 'delete_expired_transients', 'recovery_mode_', 'wp_scheduled_', 'publish_future_post', 'importer_scheduled_cleanup', 'update_network_counts' );
		foreach ( $core as $prefix ) {
			if ( str_starts_with( $hook, $prefix ) ) {
				return 'core';
			}
		}
		$normal = str_replace( '-', '_', strtolower( $hook ) );
		foreach ( $plugin_slugs as $slug ) {
			$token = str_replace( '-', '_', strtolower( $slug ) );
			if ( strlen( $token ) >= 4 && ( str_starts_with( $normal, $token ) || str_starts_with( $normal, explode( '_', $token )[0] . '_' ) && strlen( explode( '_', $token )[0] ) >= 5 ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Users summary (SCN-4).
	 *
	 * @return array<string, mixed>
	 */
	private function users(): array {
		$counts = count_users();
		$roles  = isset( $counts['avail_roles'] ) ? array_filter( (array) $counts['avail_roles'] ) : array();
		$admins = array();
		foreach ( get_users( array( 'role' => 'administrator', 'number' => 50, 'orderby' => 'ID' ) ) as $user ) {
			$admins[] = array(
				'id'         => (int) $user->ID,
				'name'       => (string) $user->display_name,
				'email'      => (string) $user->user_email,
				'registered' => (string) $user->user_registered,
			);
		}
		return array(
			'counts'         => $roles,
			'total'          => (int) ( $counts['total_users'] ?? 0 ),
			'admins'         => $admins,
			'has_admin_user' => false !== get_user_by( 'login', 'admin' ),
		);
	}

	/**
	 * Resource facts with capped queries (HST-5).
	 *
	 * @param int $deadline Unix time limit.
	 * @return array<string, mixed>
	 */
	private function resources( int $deadline ): array {
		$uploads = wp_get_upload_dir();
		$size    = null;
		$partial = false;
		if ( ! empty( $uploads['basedir'] ) && is_dir( (string) $uploads['basedir'] ) ) {
			$size = $this->directorySize( (string) $uploads['basedir'], min( $deadline, time() + 8 ), $partial );
		} else {
			$this->gaps['uploads_size'] = 'unavailable';
		}
		return array(
			'uploads_bytes'   => $size,
			'uploads_partial' => $partial,
			'db_bytes'        => $this->databaseSize(),
		);
	}

	/**
	 * Sum file sizes until the time limit is reached.
	 *
	 * @param string $path     Directory.
	 * @param int    $deadline Unix time limit.
	 * @param bool   $partial  Set to true when the limit stopped the walk.
	 */
	private function directorySize( string $path, int $deadline, bool &$partial ): int {
		$total = 0;
		try {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO ) );
			$count    = 0;
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$total += (int) $file->getSize();
				}
				if ( 0 === ( ++$count % 500 ) && time() >= $deadline ) {
					$partial = true;
					break;
				}
			}
		} catch ( \Throwable $e ) {
			$partial = true;
		}
		return $total;
	}

	/**
	 * Total size of this site's tables, or null when the server does not say.
	 */
	private function databaseSize(): ?int {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb || ! defined( 'DB_NAME' ) ) {
			return null;
		}
		$suppress = $wpdb->suppress_errors( true );
		$bytes    = $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s', DB_NAME ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only size query, run only during a scan.
		$wpdb->suppress_errors( $suppress );
		if ( null === $bytes || '' === $bytes ) {
			$this->gaps['db_size'] = 'unavailable';
			return null;
		}
		return (int) $bytes;
	}

	/**
	 * Facts that are Sensitive: the table prefix, paths and the server address.
	 *
	 * @return array<string, mixed>
	 */
	private function sensitive(): array {
		global $wpdb;
		$uploads = wp_get_upload_dir();
		return array(
			'table_prefix' => $wpdb instanceof \wpdb ? (string) $wpdb->prefix : '',
			'abspath'      => ABSPATH,
			'content_dir'  => defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
			'uploads_dir'  => (string) ( $uploads['basedir'] ?? '' ),
			'server_ip'    => isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_ADDR'] ) ) : '',
		);
	}

	/**
	 * Whether an update-list entry points at WordPress.org.
	 *
	 * @param mixed $entry Entry from the stored update data.
	 */
	private function isWpOrgEntry( $entry ): bool {
		$entry = (object) $entry;
		$id    = isset( $entry->id ) ? (string) $entry->id : '';
		$url   = isset( $entry->url ) ? (string) $entry->url : '';
		return str_starts_with( $id, 'w.org/' ) || str_contains( $url, 'wordpress.org' );
	}

	/**
	 * First-seen dates of available updates, kept so "available for more than 30 days" can be judged.
	 *
	 * @return array<string, string>
	 */
	private function updateSeen(): array {
		$stored = get_option( self::UPDATE_SEEN_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Note an update as seen and return the date it was first seen.
	 *
	 * @param array<string, string> $seen Seen map, changed in place.
	 * @param string                $key  Key for the update.
	 */
	private function markUpdate( array &$seen, string $key ): string {
		if ( ! isset( $seen[ $key ] ) ) {
			$seen[ $key ] = gmdate( 'Y-m-d' );
		}
		return $seen[ $key ];
	}

	/**
	 * Save the seen map, dropping entries for updates that are gone.
	 *
	 * @param array<string, string> $seen Seen map.
	 */
	private function saveUpdateSeen( array $seen ): void {
		update_option( self::UPDATE_SEEN_OPTION, array_slice( $seen, -200, null, true ), false );
	}
}
