<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Passport keeps its data unless the person switched on "Delete everything when the plugin is
 * deleted" in Settings. That setting is off by default, so deleting the plugin never destroys
 * a client's record by accident (PRV-8).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete a folder and everything in it.
 *
 * @param string $dir Folder path.
 */
function estasoft_hp_remove_folder( string $dir ): void {
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		$path = $dir . '/' . $name;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			estasoft_hp_remove_folder( $path );
		} else {
			wp_delete_file( $path );
		}
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Our own folder, at uninstall.
}

/**
 * Remove the data of the current site.
 */
function estasoft_hp_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'estasoft_hp_settings', array() );
	if ( empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}

	$tables = array( 'scans', 'items', 'people', 'links', 'runbooks', 'runbook_versions', 'tests', 'changes', 'findings', 'reminders', 'snapshots', 'exports' );
	foreach ( $tables as $table ) {
		$name = $wpdb->prefix . 'estasoft_hp_' . $table;
		$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed table names from the list above.
	}

	$options = array(
		'estasoft_hp_settings',
		'estasoft_hp_passport',
		'estasoft_hp_site_host',
		'estasoft_hp_db_version',
		'estasoft_hp_upgrade_error',
		'estasoft_hp_upgrade_lock',
		'estasoft_hp_scan_lock',
		'estasoft_hp_update_seen',
		'estasoft_hp_findings_meta',
		'estasoft_hp_snapshot_check',
		'estasoft_hp_reminder_health',
		'estasoft_hp_copy_guard',
		'estasoft_hp_digest_last',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Anything else this plugin saved in the options table: short-lived messages, form state and rate limits.
	$like = $wpdb->esc_like( 'estasoft_hp_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $like, '\_transient\_' . $like, '\_transient\_timeout\_' . $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own prefix; the options table name comes from WordPress.

	// The notices a person dismissed.
	delete_metadata( 'user', 0, 'estasoft_hp_dismissed', '', true );

	// The private working folder for files that are being made.
	$uploads = wp_upload_dir( null, false );
	if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
		estasoft_hp_remove_folder( rtrim( (string) $uploads['basedir'], '/\\' ) . '/estasoft-handover-passport-tmp' );
	}

	foreach ( array( 'estasoft_hp_daily_scan', 'estasoft_hp_daily_reminders', 'estasoft_hp_daily_retention', 'estasoft_hp_weekly_digest', 'estasoft_hp_weekly_urls', 'estasoft_hp_monthly_copy', 'estasoft_hp_event_scan' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	foreach ( array( 'estasoft_hp_view', 'estasoft_hp_view_sensitive', 'estasoft_hp_edit', 'estasoft_hp_export', 'estasoft_hp_configure' ) as $cap ) {
		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( $cap );
		}
	}
}

/**
 * Remove the data of every site on a network, or of the one site.
 */
function estasoft_hp_uninstall(): void {
	if ( ! is_multisite() ) {
		estasoft_hp_uninstall_site();
		return;
	}
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $estasoft_hp_site_id ) {
		switch_to_blog( (int) $estasoft_hp_site_id );
		estasoft_hp_uninstall_site();
		restore_current_blog();
	}
}

estasoft_hp_uninstall();
