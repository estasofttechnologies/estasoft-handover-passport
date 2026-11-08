<?php
/**
 * Overview: what Passport found on the last scan and what changed since (ADM-1, PRT-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Scan\ScanService;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The first screen.
 */
final class OverviewScreen {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Print the screen.
	 */
	public function render(): void {
		if ( ! Capabilities::can( Capabilities::VIEW ) ) {
			wp_die( esc_html__( 'You do not have permission to view Passport.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}

		$passport = $this->plugin->passport()->all();
		$scans    = $this->plugin->scanService();
		$latest   = $scans->latest();
		$facts    = $scans->latestFacts();
		if ( ! Capabilities::canSeeSensitive() ) {
			$facts = ScanService::redact( $facts );
		}

		View::open( (string) ( '' !== $passport['site_name'] ? $passport['site_name'] : __( 'Passport', 'estasoft-handover-passport' ) ) );

		echo '<p class="estasoft-hp-lede">' . View::badge( (string) $passport['status'], $this->plugin->passport()->statusLabel() );
		if ( '' !== (string) $passport['client_name'] ) {
			echo ' ' . esc_html(
				sprintf(
					/* translators: %s: client name. */
					__( 'Prepared for %s', 'estasoft-handover-passport' ),
					(string) $passport['client_name']
				)
			);
		}
		echo '</p>';

		$this->scanBox( $latest );

		if ( array() === $facts ) {
			echo '<p>' . esc_html__( 'No scan has finished yet. Press Scan now to record this site.', 'estasoft-handover-passport' ) . '</p>';
			View::close();
			return;
		}

		$this->stack( $facts );
		$this->recentChanges();
		View::close();
	}

	/**
	 * The scan status box with the Scan now button.
	 *
	 * @param array<string, mixed>|null $latest Latest scan row.
	 */
	private function scanBox( ?array $latest ): void {
		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Last scan', 'estasoft-handover-passport' ) . '</h2>';
		if ( null === $latest ) {
			echo '<p>' . esc_html__( 'This site has not been scanned yet.', 'estasoft-handover-passport' ) . '</p>';
		} else {
			$state = (string) $latest['status'];
			$words = array(
				'done'    => __( 'Finished', 'estasoft-handover-passport' ),
				'running' => __( 'Running', 'estasoft-handover-passport' ),
				'failed'  => __( 'Did not finish', 'estasoft-handover-passport' ),
			);
			echo '<p>' . View::badge( $state, $words[ $state ] ?? $state ) . ' ' . esc_html( Dates::display( (string) $latest['started_at'], true ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			$gaps = (array) ( ( $latest['facts']['gaps'] ?? array() ) );
			if ( array() !== $gaps ) {
				echo '<p class="estasoft-hp-muted">' . esc_html(
					sprintf(
						/* translators: %s: list of scan sections. */
						__( 'Not available on this server: %s', 'estasoft-handover-passport' ),
						implode( ', ', array_map( 'strval', array_keys( $gaps ) ) )
					)
				) . '</p>';
			}
		}
		if ( Capabilities::can( Capabilities::EDIT ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( Admin::SCAN_ACTION ) . '">';
			wp_nonce_field( Admin::SCAN_ACTION );
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Scan now', 'estasoft-handover-passport' ) . '</button>';
			echo '</form>';
		}
		echo '</div>';
	}

	/**
	 * Server and stack summary.
	 *
	 * @param array<string, mixed> $facts Facts.
	 */
	private function stack( array $facts ): void {
		$core    = (array) ( $facts['core'] ?? array() );
		$plugins = (array) ( $facts['plugins'] ?? array() );
		$themes  = (array) ( $facts['themes'] ?? array() );
		$active  = count( array_filter( $plugins, static fn( $p ): bool => ! empty( $p['active'] ) ) );
		$updates = count( array_filter( $plugins, static fn( $p ): bool => ! empty( $p['update'] ) ) );
		$theme   = '';
		foreach ( $themes as $item ) {
			if ( ! empty( $item['active'] ) ) {
				$theme = trim( (string) ( $item['name'] ?? '' ) . ' ' . (string) ( $item['version'] ?? '' ) );
				break;
			}
		}

		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Stack', 'estasoft-handover-passport' ) . '</h2>';
		View::facts(
			array(
				__( 'WordPress', 'estasoft-handover-passport' )      => (string) ( $core['wp_version'] ?? '' ),
				__( 'PHP', 'estasoft-handover-passport' )            => (string) ( $core['php_version'] ?? '' ),
				__( 'Database', 'estasoft-handover-passport' )       => trim( (string) ( $core['db_engine'] ?? '' ) . ' ' . (string) ( $core['db_version'] ?? '' ) ),
				__( 'Web server', 'estasoft-handover-passport' )     => (string) ( $core['web_server'] ?? '' ),
				__( 'Environment', 'estasoft-handover-passport' )    => (string) ( $core['environment'] ?? '' ),
				__( 'Site address', 'estasoft-handover-passport' )   => (string) ( $core['site_url'] ?? '' ),
				__( 'HTTPS', 'estasoft-handover-passport' )          => View::yesNo( ! empty( $core['https'] ) ),
				__( 'Active theme', 'estasoft-handover-passport' )   => $theme,
				__( 'Plugins', 'estasoft-handover-passport' )        => sprintf(
					/* translators: 1: number active, 2: number installed. */
					__( '%1$d active of %2$d installed', 'estasoft-handover-passport' ),
					$active,
					count( $plugins )
				),
				__( 'Plugin updates waiting', 'estasoft-handover-passport' ) => (string) $updates,
			)
		);
		echo '</div>';
	}

	/**
	 * The ten latest changes.
	 */
	private function recentChanges(): void {
		$rows = $this->plugin->changeLog()->repo()->search( array(), 10 );
		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Recent changes', 'estasoft-handover-passport' ) . '</h2>';
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'Nothing has changed since the first scan.', 'estasoft-handover-passport' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'When', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'What', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Who', 'estasoft-handover-passport' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( Dates::display( (string) $row['changed_at'], true ) ) . '</td><td>' . esc_html( $this->describe( $row ) ) . '</td><td>' . esc_html( (string) $row['actor_label'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * One line for a change-log row.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private function describe( array $row ): string {
		$label  = (string) $row['subject_label'];
		$before = (array) $row['before_data'];
		$after  = (array) $row['after_data'];
		switch ( (string) $row['kind'] ) {
			case 'plugin_updated':
			case 'theme_updated':
				/* translators: 1: name, 2: old version, 3: new version. */
				return sprintf( __( '%1$s updated from %2$s to %3$s', 'estasoft-handover-passport' ), $label, (string) ( $before['version'] ?? '' ), (string) ( $after['version'] ?? '' ) );
			case 'plugin_added':
			case 'theme_added':
				/* translators: %s: name. */
				return sprintf( __( '%s added', 'estasoft-handover-passport' ), $label );
			case 'plugin_removed':
			case 'theme_removed':
				/* translators: %s: name. */
				return sprintf( __( '%s removed', 'estasoft-handover-passport' ), $label );
			case 'plugin_activated':
				/* translators: %s: name. */
				return sprintf( __( '%s switched on', 'estasoft-handover-passport' ), $label );
			case 'plugin_deactivated':
				/* translators: %s: name. */
				return sprintf( __( '%s switched off', 'estasoft-handover-passport' ), $label );
			case 'theme_switched':
				/* translators: %s: theme name. */
				return sprintf( __( 'Theme changed to %s', 'estasoft-handover-passport' ), $label );
			case 'administrator_added':
				/* translators: %s: person's name. */
				return sprintf( __( '%s became an administrator', 'estasoft-handover-passport' ), $label );
			case 'wordpress_version_changed':
			case 'php_version_changed':
			case 'site_address_changed':
				/* translators: 1: what changed, 2: old value, 3: new value. */
				return sprintf( __( '%1$s changed from %2$s to %3$s', 'estasoft-handover-passport' ), $label, (string) ( $before['value'] ?? '' ), (string) ( $after['value'] ?? '' ) );
			case 'record_added':
				/* translators: %s: record title. */
				return sprintf( __( 'Added: %s', 'estasoft-handover-passport' ), $label );
			case 'record_edited':
				/* translators: %s: record title. */
				return sprintf( __( 'Edited: %s', 'estasoft-handover-passport' ), $label );
			case 'record_deleted':
				/* translators: %s: record title. */
				return sprintf( __( 'Deleted: %s', 'estasoft-handover-passport' ), $label );
			case 'record_restored':
				/* translators: %s: record title. */
				return sprintf( __( 'Restored: %s', 'estasoft-handover-passport' ), $label );
			case 'record_verified':
				/* translators: %s: record title. */
				return sprintf( __( 'Verified: %s', 'estasoft-handover-passport' ), $label );
			default:
				return $label;
		}
	}
}
