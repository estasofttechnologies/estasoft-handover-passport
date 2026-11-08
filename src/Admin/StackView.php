<?php
/**
 * The Stack: what the last scan found, with the notes people added (STK-1 to STK-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\LicenceStatus;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Scan\SupportDates;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Tabs: Core and server, Plugins, Themes, Must-use and drop-ins, Scheduled tasks.
 */
final class StackView {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Print the stack.
	 *
	 * @param string $page Menu slug.
	 */
	public function render( string $page ): void {
		$facts = $this->plugin->scanService()->latestFacts();
		if ( ! Capabilities::canSeeSensitive() ) {
			$facts = \Estasoft\HandoverPassport\Scan\ScanService::redact( $facts );
		}
		if ( array() === $facts ) {
			echo '<section class="card"><div class="bdy"><div class="empty">' . Icons::svg( 'search', 32 ) . '<h2>' . esc_html__( 'Nothing has been scanned yet', 'estasoft-handover-passport' ) . '</h2><p>' . esc_html__( 'Run a scan from the Overview screen to list the plugins, themes and server details.', 'estasoft-handover-passport' ) . '</p></div></div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is fixed markup.
			return;
		}

		$subs = array(
			'core'    => __( 'Core and server', 'estasoft-handover-passport' ),
			'plugins' => __( 'Plugins', 'estasoft-handover-passport' ),
			'themes'  => __( 'Themes', 'estasoft-handover-passport' ),
			'mu'      => __( 'Must-use and drop-ins', 'estasoft-handover-passport' ),
			'cron'    => __( 'Scheduled tasks', 'estasoft-handover-passport' ),
		);
		$sub  = Request::key( 'sub', 'plugins' );
		$sub  = isset( $subs[ $sub ] ) ? $sub : 'plugins';

		echo '<div class="row">';
		$this->segment( $subs, $sub, add_query_arg( array( 'page' => $page, 'tab' => 'stack' ), admin_url( 'admin.php' ) ), 'sub', __( 'Parts of the stack', 'estasoft-handover-passport' ) );
		echo '</div>';

		$base = add_query_arg( array( 'page' => $page, 'tab' => 'stack', 'sub' => $sub ), admin_url( 'admin.php' ) );
		switch ( $sub ) {
			case 'core':
				$this->core( (array) ( $facts['core'] ?? array() ), (array) ( $facts['resources'] ?? array() ), (array) ( $facts['sensitive'] ?? array() ) );
				break;
			case 'themes':
				$this->themes( (array) ( $facts['themes'] ?? array() ), $base );
				break;
			case 'mu':
				$this->muPlugins( (array) ( $facts['mu_plugins'] ?? array() ), (array) ( $facts['dropins'] ?? array() ) );
				break;
			case 'cron':
				$this->cron( (array) ( $facts['cron'] ?? array() ) );
				break;
			default:
				$this->plugins( (array) ( $facts['plugins'] ?? array() ), $base );
		}
	}

	/**
	 * Core and server facts.
	 *
	 * @param array<string, mixed> $core      Core facts.
	 * @param array<string, mixed> $resources Resource facts.
	 * @param array<string, mixed> $sensitive Sensitive facts, empty for viewers without the capability.
	 */
	private function core( array $core, array $resources, array $sensitive ): void {
		$support = new SupportDates();
		$php     = (string) ( $core['php_version'] ?? '' );
		$php_end = '' === $php ? null : $support->phpEnd( $php );
		$ext     = array();
		foreach ( (array) ( $core['php_extensions'] ?? array() ) as $name => $loaded ) {
			$ext[] = $name . ': ' . ( $loaded ? __( 'yes', 'estasoft-handover-passport' ) : __( 'no', 'estasoft-handover-passport' ) );
		}
		$debug = array();
		foreach ( (array) ( $core['debug'] ?? array() ) as $name => $on ) {
			$debug[] = $name . ': ' . ( $on ? __( 'on', 'estasoft-handover-passport' ) : __( 'off', 'estasoft-handover-passport' ) );
		}

		View::cardStart( __( 'WordPress', 'estasoft-handover-passport' ) );
		View::facts(
			array(
				__( 'Version', 'estasoft-handover-passport' )             => (string) ( $core['wp_version'] ?? '' ),
				__( 'Site address', 'estasoft-handover-passport' )        => (string) ( $core['site_url'] ?? '' ),
				__( 'Home address', 'estasoft-handover-passport' )        => (string) ( $core['home_url'] ?? '' ),
				__( 'Language', 'estasoft-handover-passport' )            => (string) ( $core['language'] ?? '' ),
				__( 'Time zone', 'estasoft-handover-passport' )           => (string) ( $core['timezone'] ?? '' ),
				__( 'Environment type', 'estasoft-handover-passport' )    => (string) ( $core['environment'] ?? '' ),
				__( 'Permalinks', 'estasoft-handover-passport' )          => '' === (string) ( $core['permalink_structure'] ?? '' ) ? __( 'Plain', 'estasoft-handover-passport' ) : (string) $core['permalink_structure'],
				__( 'HTTPS', 'estasoft-handover-passport' )               => View::yesNo( ! empty( $core['https'] ) ),
				__( 'Debug settings', 'estasoft-handover-passport' )      => implode( ', ', $debug ),
				__( 'WordPress cron switched off', 'estasoft-handover-passport' ) => View::yesNo( ! empty( $core['cron_disabled'] ) ),
				__( 'Multisite', 'estasoft-handover-passport' )           => View::yesNo( ! empty( $core['multisite'] ) ),
			)
		);
		View::cardEnd();
		View::cardStart( __( 'Server', 'estasoft-handover-passport' ) );
		View::facts(
			array(
				__( 'PHP', 'estasoft-handover-passport' ) => $php . ( null !== $php_end ? ' (' . sprintf(
					/* translators: %s: date. */
					__( 'security support ends %s', 'estasoft-handover-passport' ),
					Dates::displayDate( $php_end )
				) . ')' : '' ),
				__( 'PHP extensions', 'estasoft-handover-passport' ) => implode( ', ', $ext ),
				__( 'Memory limit', 'estasoft-handover-passport' ) => (string) ( $core['memory_limit'] ?? '' ),
				__( 'Upload limit', 'estasoft-handover-passport' ) => (string) ( $core['upload_max'] ?? '' ),
				__( 'Post limit', 'estasoft-handover-passport' ) => (string) ( $core['post_max'] ?? '' ),
				__( 'Execution time limit', 'estasoft-handover-passport' ) => (string) ( $core['max_execution_time'] ?? '' ),
				__( 'Database', 'estasoft-handover-passport' ) => trim( (string) ( $core['db_engine'] ?? '' ) . ' ' . (string) ( $core['db_version'] ?? '' ) ),
				__( 'Web server', 'estasoft-handover-passport' ) => (string) ( $core['web_server'] ?? '' ),
				__( 'Operating system', 'estasoft-handover-passport' ) => (string) ( $core['os_family'] ?? '' ),
				__( 'External object cache', 'estasoft-handover-passport' ) => View::yesNo( ! empty( $core['object_cache'] ) ),
				__( 'OPcache', 'estasoft-handover-passport' ) => View::yesNo( ! empty( $core['opcache'] ) ),
				__( 'Uploads folder size', 'estasoft-handover-passport' ) => View::size( isset( $resources['uploads_bytes'] ) ? (int) $resources['uploads_bytes'] : null ) . ( ! empty( $resources['uploads_partial'] ) ? ' ' . __( '(partial, the scan hit its time limit)', 'estasoft-handover-passport' ) : '' ),
				__( 'Database size', 'estasoft-handover-passport' ) => View::size( isset( $resources['db_bytes'] ) ? (int) $resources['db_bytes'] : null ),
			)
		);
		View::cardEnd();
		if ( array() !== $sensitive ) {
			View::cardStart( __( 'Sensitive details', 'estasoft-handover-passport' ), '<span class="tag sens">' . Icons::svg( 'lock', 12 ) . esc_html__( 'Sensitive', 'estasoft-handover-passport' ) . '</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is fixed markup.
			View::facts(
				array(
					__( 'Table prefix', 'estasoft-handover-passport' )   => (string) ( $sensitive['table_prefix'] ?? '' ),
					__( 'WordPress folder', 'estasoft-handover-passport' ) => (string) ( $sensitive['abspath'] ?? '' ),
					__( 'Content folder', 'estasoft-handover-passport' ) => (string) ( $sensitive['content_dir'] ?? '' ),
					__( 'Uploads folder', 'estasoft-handover-passport' ) => (string) ( $sensitive['uploads_dir'] ?? '' ),
					__( 'Server address', 'estasoft-handover-passport' ) => (string) ( $sensitive['server_ip'] ?? '' ),
				)
			);
			View::cardEnd();
		}
	}

	/**
	 * Plugins with filters, purpose, licence status and where each is from.
	 *
	 * @param array<string, array<string, mixed>> $plugins Plugins by slug.
	 * @param string                              $base    Screen URL for this sub-tab.
	 */
	private function plugins( array $plugins, string $base ): void {
		$filter  = Request::key( 'filter', 'all' );
		$filters = array(
			'all'      => __( 'All', 'estasoft-handover-passport' ),
			'active'   => __( 'Active', 'estasoft-handover-passport' ),
			'inactive' => __( 'Inactive', 'estasoft-handover-passport' ),
			'updates'  => __( 'Update available', 'estasoft-handover-passport' ),
			'outside'  => __( 'Not from WordPress.org', 'estasoft-handover-passport' ),
		);
		$filter  = isset( $filters[ $filter ] ) ? $filter : 'all';

		$inactive_since = $this->inactiveSince( 'plugin_deactivated' );
		$can_edit       = Capabilities::can( Capabilities::EDIT );
		$rows           = array();
		foreach ( $plugins as $slug => $plugin ) {
			$active  = ! empty( $plugin['active'] );
			$outside = array_key_exists( 'wporg', $plugin ) && false === $plugin['wporg'];
			if ( ( 'active' === $filter && ! $active ) || ( 'inactive' === $filter && $active ) || ( 'updates' === $filter && empty( $plugin['update'] ) ) || ( 'outside' === $filter && ! $outside ) ) {
				continue;
			}
			$rows[ (string) $slug ] = $plugin;
		}
		View::bareCardStart();
		echo '<div class="tb">';
		$this->segment( $filters, $filter, $base, 'filter', __( 'Show plugins that are', 'estasoft-handover-passport' ) );
		echo '</div>';
		if ( array() === $rows ) {
			echo '<div class="empty"><p>' . esc_html__( 'No plugins match this filter.', 'estasoft-handover-passport' ) . '</p></div>';
			View::cardEnd();
			return;
		}
		$this->tableStart( __( 'Plugins', 'estasoft-handover-passport' ), array( __( 'Plugin', 'estasoft-handover-passport' ), __( 'Version', 'estasoft-handover-passport' ), __( 'State', 'estasoft-handover-passport' ), __( 'Source', 'estasoft-handover-passport' ), __( 'Licence', 'estasoft-handover-passport' ), __( 'What it does here', 'estasoft-handover-passport' ) ), true );

		foreach ( $rows as $slug => $plugin ) {
			$note    = $this->plugin->records()->items()->bySubject( Kinds::STACKNOTE, 'plugin:' . strtolower( $slug ) );
			$outside = array_key_exists( 'wporg', $plugin ) && false === $plugin['wporg'];
			$name    = (string) $plugin['name'];

			$labels = array( __( 'Plugin', 'estasoft-handover-passport' ), __( 'Version', 'estasoft-handover-passport' ), __( 'State', 'estasoft-handover-passport' ), __( 'Source', 'estasoft-handover-passport' ), __( 'Licence', 'estasoft-handover-passport' ), __( 'What it does here', 'estasoft-handover-passport' ) );
			echo '<tr><td class="t1" data-label="' . esc_attr( $labels[0] ) . '"><b>' . esc_html( $name ) . '</b>';
			if ( ! empty( $plugin['update'] ) ) {
				echo '<span class="sub">' . esc_html(
					sprintf(
						/* translators: %s: version number. */
						__( 'Update to %s available', 'estasoft-handover-passport' ),
						(string) $plugin['update']
					)
				) . '</span>';
			}
			echo '</td><td data-label="' . esc_attr( $labels[1] ) . '">' . esc_html( (string) $plugin['version'] ) . '</td><td data-label="' . esc_attr( $labels[2] ) . '">';
			if ( ! empty( $plugin['active'] ) ) {
				echo View::badge( 'active', empty( $plugin['network'] ) ? __( 'Active', 'estasoft-handover-passport' ) : __( 'Network active', 'estasoft-handover-passport' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			} else {
				echo View::badge( 'unknown', __( 'Inactive', 'estasoft-handover-passport' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
				if ( isset( $inactive_since[ $name ] ) ) {
					echo '<span class="sub">' . esc_html(
						sprintf(
							/* translators: %s: date. */
							__( 'since %s. Consider removing it.', 'estasoft-handover-passport' ),
							Dates::displayDate( Dates::datePart( $inactive_since[ $name ] ) )
						)
					) . '</span>';
				}
			}
			echo '</td><td data-label="' . esc_attr( $labels[3] ) . '">' . esc_html( $outside ? __( 'Not from WordPress.org', 'estasoft-handover-passport' ) : ( true === ( $plugin['wporg'] ?? null ) ? __( 'WordPress.org', 'estasoft-handover-passport' ) : __( 'Unknown', 'estasoft-handover-passport' ) ) ) . '</td>';
			echo '<td data-label="' . esc_attr( $labels[4] ) . '">' . $this->licenceCell( strtolower( $slug ), $name, $outside, $can_edit ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- licenceCell() escapes its output.
			echo '<td data-label="' . esc_attr( $labels[5] ) . '">' . esc_html( null === $note ? '' : (string) ( $note['data']['what_it_does'] ?? '' ) );
			if ( null !== $note && 'yes' === ( $note['data']['custom_code'] ?? '' ) ) {
				echo ' ' . View::badge( 'draft', __( 'Custom code', 'estasoft-handover-passport' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			}
			echo '</td><td class="act" data-label="">';
			if ( $can_edit ) {
				echo '<a class="btn sm" href="' . esc_url( $this->noteLink( $base, 'plugin:' . $slug, $name, $note ) ) . '">' . esc_html__( 'Notes', 'estasoft-handover-passport' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		View::cardEnd();
	}

	/**
	 * Themes.
	 *
	 * @param array<string, array<string, mixed>> $themes Themes by slug.
	 * @param string                              $base   Screen URL.
	 */
	private function themes( array $themes, string $base ): void {
		$inactive_since = $this->inactiveSince( 'theme_switched' );
		$can_edit       = Capabilities::can( Capabilities::EDIT );
		$labels         = array( __( 'Theme', 'estasoft-handover-passport' ), __( 'Version', 'estasoft-handover-passport' ), __( 'State', 'estasoft-handover-passport' ), __( 'Parent', 'estasoft-handover-passport' ), __( 'Licence', 'estasoft-handover-passport' ), __( 'What it does here', 'estasoft-handover-passport' ) );
		View::bareCardStart();
		$this->tableStart( __( 'Themes', 'estasoft-handover-passport' ), $labels, true );
		foreach ( $themes as $slug => $theme ) {
			$note    = $this->plugin->records()->items()->bySubject( Kinds::STACKNOTE, 'theme:' . strtolower( (string) $slug ) );
			$outside = array_key_exists( 'wporg', $theme ) && false === $theme['wporg'];
			$custom  = '' !== (string) ( $theme['parent'] ?? '' ) || ( null !== $note && 'yes' === ( $note['data']['custom_code'] ?? '' ) );
			echo '<tr><td class="t1" data-label="' . esc_attr( $labels[0] ) . '"><b>' . esc_html( (string) $theme['name'] ) . '</b>';
			if ( ! empty( $theme['update'] ) ) {
				echo '<span class="sub">' . esc_html(
					sprintf(
						/* translators: %s: version number. */
						__( 'Update to %s available', 'estasoft-handover-passport' ),
						(string) $theme['update']
					)
				) . '</span>';
			}
			echo '</td><td data-label="' . esc_attr( $labels[1] ) . '">' . esc_html( (string) $theme['version'] ) . '</td><td data-label="' . esc_attr( $labels[2] ) . '">';
			echo View::badge( ! empty( $theme['active'] ) ? 'active' : 'unknown', ! empty( $theme['active'] ) ? __( 'Active', 'estasoft-handover-passport' ) : __( 'Inactive', 'estasoft-handover-passport' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			echo '</td><td data-label="' . esc_attr( $labels[3] ) . '">' . esc_html( (string) ( $theme['parent'] ?? '' ) );
			if ( $custom ) {
				echo ' ' . View::badge( 'draft', __( 'Custom code', 'estasoft-handover-passport' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			}
			echo '</td><td data-label="' . esc_attr( $labels[4] ) . '">' . $this->licenceCell( strtolower( (string) $slug ), (string) $theme['name'], $outside, $can_edit ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- licenceCell() escapes its output.
			echo '<td data-label="' . esc_attr( $labels[5] ) . '">' . esc_html( null === $note ? '' : (string) ( $note['data']['what_it_does'] ?? '' ) ) . '</td><td class="act" data-label="">';
			if ( $can_edit ) {
				echo '<a class="btn sm" href="' . esc_url( $this->noteLink( $base, 'theme:' . $slug, (string) $theme['name'], $note ) ) . '">' . esc_html__( 'Notes', 'estasoft-handover-passport' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		View::cardEnd();
	}

	/**
	 * Must-use plugins and drop-ins.
	 *
	 * @param array<string, array<string, mixed>> $mu      Must-use plugins.
	 * @param array<string, array<string, mixed>> $dropins Drop-ins.
	 */
	private function muPlugins( array $mu, array $dropins ): void {
		View::cardStart( __( 'Must-use plugins', 'estasoft-handover-passport' ), '', true );
		if ( array() === $mu ) {
			echo '<div class="empty"><p>' . esc_html__( 'None. Must-use plugins load before every other plugin and cannot be switched off from the Plugins screen.', 'estasoft-handover-passport' ) . '</p></div>';
		} else {
			echo '<ul class="lst">';
			foreach ( $mu as $file => $item ) {
				echo '<li><div class="gr"><b>' . esc_html( (string) ( ! empty( $item['name'] ) ? $item['name'] : $file ) ) . '</b> ' . esc_html( (string) $item['version'] ) . '<span class="sub t2 cap">' . esc_html( (string) $file ) . '</span></div>' . View::badge( 'draft', __( 'May hold custom code', 'estasoft-handover-passport' ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			}
			echo '</ul>';
		}
		View::cardEnd();
		View::cardStart( __( 'Drop-ins', 'estasoft-handover-passport' ), '', true );
		if ( array() === $dropins ) {
			echo '<div class="empty"><p>' . esc_html__( 'None.', 'estasoft-handover-passport' ) . '</p></div>';
		} else {
			echo '<ul class="lst">';
			foreach ( $dropins as $file => $item ) {
				echo '<li><div class="gr"><b>' . esc_html( (string) $file ) . '</b><span class="sub t2 cap">' . esc_html( (string) $item['name'] ) . '</span></div></li>';
			}
			echo '</ul>';
		}
		View::cardEnd();
	}

	/**
	 * Scheduled tasks grouped by owner (SCN-3).
	 *
	 * @param array<int, array<string, mixed>> $cron Tasks.
	 */
	private function cron( array $cron ): void {
		if ( array() === $cron ) {
			echo '<section class="card"><div class="bdy"><div class="empty"><h2>' . esc_html__( 'No scheduled tasks', 'estasoft-handover-passport' ) . '</h2><p>' . esc_html__( 'No recurring scheduled tasks were found.', 'estasoft-handover-passport' ) . '</p></div></div></section>';
			return;
		}
		$groups = array();
		foreach ( $cron as $task ) {
			$owner              = '' === (string) $task['owner'] ? __( 'Not known', 'estasoft-handover-passport' ) : ( 'core' === $task['owner'] ? __( 'WordPress core', 'estasoft-handover-passport' ) : (string) $task['owner'] );
			$groups[ $owner ][] = $task;
		}
		ksort( $groups );
		$labels = array( __( 'Task', 'estasoft-handover-passport' ), __( 'Owner (guess)', 'estasoft-handover-passport' ), __( 'Repeats', 'estasoft-handover-passport' ), __( 'Next run', 'estasoft-handover-passport' ) );
		View::bareCardStart();
		$this->tableStart( __( 'Scheduled tasks', 'estasoft-handover-passport' ), $labels );
		foreach ( $groups as $owner => $tasks ) {
			foreach ( $tasks as $task ) {
				echo '<tr><td class="t1" data-label="' . esc_attr( $labels[0] ) . '"><code>' . esc_html( (string) $task['hook'] ) . '</code></td><td data-label="' . esc_attr( $labels[1] ) . '">' . esc_html( (string) $owner ) . '</td><td data-label="' . esc_attr( $labels[2] ) . '">' . esc_html( (string) $task['schedule'] ) . '</td><td data-label="' . esc_attr( $labels[3] ) . '">' . esc_html( Dates::display( gmdate( 'Y-m-d H:i:s', (int) $task['next_run'] ), true ) ) . '</td></tr>';
			}
		}
		echo '</tbody></table></div>';
		View::cardEnd( '<span class="t2 cap">' . esc_html__( 'The owner is a guess from the name of the task.', 'estasoft-handover-passport' ) . '</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped here.
	}

	/**
	 * Licence status for a plugin or theme, or a link to add one (LIC-2, STK-3).
	 *
	 * @param string $slug     Folder name, lower case.
	 * @param string $name     Display name.
	 * @param bool   $outside  Whether it is not from WordPress.org.
	 * @param bool   $can_edit Whether the viewer may add records.
	 * @return string Escaped HTML.
	 */
	private function licenceCell( string $slug, string $name, bool $outside, bool $can_edit ): string {
		$licence = $this->plugin->records()->items()->bySubject( Kinds::LICENCE, $slug );
		if ( null !== $licence && empty( $licence['deleted_at'] ) ) {
			$data   = (array) $licence['data'];
			$status = LicenceStatus::compute( $data, Clock::today(), (int) $this->plugin->settings()->get( 'thresholds.licence_lead_days', 30 ) );
			return View::badge( $status, LicenceStatus::label( $status ) );
		}
		if ( $outside && $can_edit ) {
			$url = add_query_arg(
				array(
					'page'      => 'estasoft-passport-inventory',
					'tab'       => 'licences',
					'view'      => 'edit',
					'kind'      => Kinds::LICENCE,
					'id'        => 0,
					'prefill'   => $name,
					'init_slug' => $slug,
				),
				admin_url( 'admin.php' )
			);
			return '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Add a licence', 'estasoft-handover-passport' ) . '</a>';
		}
		return '<span class="estasoft-hp-muted">' . esc_html__( 'None recorded', 'estasoft-handover-passport' ) . '</span>';
	}

	/**
	 * Link to the notes form of a plugin or theme.
	 *
	 * @param string                    $base Screen URL.
	 * @param string                    $ref  plugin:slug or theme:slug.
	 * @param string                    $name Display name.
	 * @param array<string, mixed>|null $note Existing note record.
	 */
	private function noteLink( string $base, string $ref, string $name, ?array $note ): string {
		return add_query_arg(
			array(
				'view'      => 'edit',
				'kind'      => Kinds::STACKNOTE,
				'id'        => null === $note ? 0 : (int) $note['id'],
				'init_ref'  => $ref,
				'init_name' => $name,
			),
			$base
		);
	}

	/**
	 * When each item was last switched off, from the change log (STK-4).
	 *
	 * @param string $kind Change kind.
	 * @return array<string, string> Item name => UTC date-time.
	 */
	private function inactiveSince( string $kind ): array {
		$out = array();
		foreach ( $this->plugin->changeLog()->repo()->search( array( 'kind' => $kind ), 0 ) as $row ) {
			$label = (string) $row['subject_label'];
			if ( ! isset( $out[ $label ] ) ) {
				$out[ $label ] = (string) $row['changed_at']; // Newest first, so the first hit is the latest.
			}
		}
		return $out;
	}

	/**
	 * A row of links that read as one control: which part of a screen, or which filter, is on.
	 *
	 * @param array<string, string> $items   Key => label.
	 * @param string                $current Key that is on.
	 * @param string                $base    Address to add the choice to.
	 * @param string                $param   Query argument that carries the choice.
	 * @param string                $label   What the control is for, for assistive technology.
	 */
	private function segment( array $items, string $current, string $base, string $param, string $label ): void {
		echo '<nav class="seg" aria-label="' . esc_attr( $label ) . '">';
		foreach ( $items as $key => $text ) {
			echo '<a href="' . esc_url( add_query_arg( $param, (string) $key, $base ) ) . '"' . ( (string) $key === $current ? ' class="on" aria-current="page"' : '' ) . '>' . esc_html( $text ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Open a table with a caption and column headings. The last column is for actions, so it has no heading.
	 *
	 * @param string             $caption Words for assistive technology.
	 * @param array<int, string> $heads   Column headings.
	 * @param bool               $actions Whether a last column holds actions.
	 */
	private function tableStart( string $caption, array $heads, bool $actions = false ): void {
		echo '<div class="scr"><table class="tbl cards"><caption class="sr">' . esc_html( $caption ) . '</caption><thead><tr>';
		foreach ( $heads as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		if ( $actions ) {
			echo '<th scope="col"><span class="sr">' . esc_html__( 'Actions', 'estasoft-handover-passport' ) . '</span></th>';
		}
		echo '</tr></thead><tbody>';
	}
}
