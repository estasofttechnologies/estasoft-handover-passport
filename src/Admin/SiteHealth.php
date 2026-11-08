<?php
/**
 * Site Health tests and debug information (WPI-1, WPI-2).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Data\ItemRepository;
use Estasoft\HandoverPassport\Data\PeopleRepository;
use Estasoft\HandoverPassport\Data\Schema;
use Estasoft\HandoverPassport\Data\Tables;
use Estasoft\HandoverPassport\Findings\Level;
use Estasoft\HandoverPassport\Install\Schedule;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Two tests under Tools, Site Health, and a section of facts under Info. Nothing here holds a record's value.
 */
final class SiteHealth {

	/**
	 * Days without a verified record before the review test asks for attention.
	 */
	public const REVIEW_DAYS = 180;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Attach the filters.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'tests' ) );
		add_filter( 'debug_information', array( $this, 'debugInfo' ) );
	}

	/**
	 * Add the two direct tests.
	 *
	 * @param mixed $tests Tests from core.
	 * @return array<string, mixed>
	 */
	public function tests( $tests ): array {
		$tests                                 = is_array( $tests ) ? $tests : array();
		$tests['direct']['estasoft_hp_review'] = array(
			'label' => __( 'The Passport has been reviewed', 'estasoft-handover-passport' ),
			'test'  => array( $this, 'reviewTest' ),
		);
		$tests['direct']['estasoft_hp_scans']  = array(
			'label' => __( 'Passport scans are running', 'estasoft-handover-passport' ),
			'test'  => array( $this, 'scansTest' ),
		);
		return $tests;
	}

	/**
	 * Recommended when no record was verified for 180 days, or a Needs fixing finding is open.
	 *
	 * @return array<string, mixed>
	 */
	public function reviewTest(): array {
		$last  = $this->lastVerified();
		$needs = (int) ( $this->plugin->findings()->counts()[ Level::NEEDS ] ?? 0 );
		$days  = null === $last ? null : Dates::daysBetween( Dates::datePart( $last ), Clock::today() );

		$reasons = array();
		if ( null === $days ) {
			$reasons[] = __( 'No record has been verified yet.', 'estasoft-handover-passport' );
		} elseif ( $days > self::REVIEW_DAYS ) {
			$reasons[] = sprintf(
				/* translators: %d: number of days. */
				_n( 'No record has been verified for %d day.', 'No record has been verified for %d days.', $days, 'estasoft-handover-passport' ),
				$days
			);
		}
		if ( $needs > 0 ) {
			$reasons[] = sprintf(
				/* translators: %d: number of findings. */
				_n( '%d finding needs fixing.', '%d findings need fixing.', $needs, 'estasoft-handover-passport' ),
				$needs
			);
		}

		if ( array() === $reasons ) {
			return $this->result( 'good', __( 'The Passport has been reviewed', 'estasoft-handover-passport' ), __( 'A record was verified in the last 180 days and no finding needs fixing.', 'estasoft-handover-passport' ) );
		}
		return $this->result( 'recommended', __( 'The Passport needs a review', 'estasoft-handover-passport' ), implode( ' ', $reasons ), true );
	}

	/**
	 * Recommended when the last scan is older than the limit in Settings (48 hours by default), or there has been none.
	 *
	 * @return array<string, mixed>
	 */
	public function scansTest(): array {
		$hours = $this->plugin->scanService()->hoursSinceLast();
		$limit = (int) $this->plugin->settings()->get( 'thresholds.scan_stale_hours', 48 );
		$cron  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
			? ' ' . __( 'WordPress cron is switched off on this site, so a server job must call WordPress cron or run "wp estasoft-passport scan".', 'estasoft-handover-passport' )
			: '';

		if ( null === $hours ) {
			return $this->result( 'recommended', __( 'Passport has not scanned this site yet', 'estasoft-handover-passport' ), __( 'No scan has finished. Press Scan now on the Passport Overview.', 'estasoft-handover-passport' ) . $cron, true );
		}
		if ( $hours > $limit ) {
			return $this->result(
				'recommended',
				__( 'Passport scans are late', 'estasoft-handover-passport' ),
				sprintf(
					/* translators: 1: hours since the last scan, 2: limit in hours. */
					__( 'The last scan finished %1$d hours ago, which is more than the %2$d hours set in Settings.', 'estasoft-handover-passport' ),
					$hours,
					$limit
				) . $cron,
				true
			);
		}
		return $this->result( 'good', __( 'Passport scans are running', 'estasoft-handover-passport' ), __( 'The last scan finished within the limit set in Settings.', 'estasoft-handover-passport' ) );
	}

	/**
	 * Facts for Site Health, Info. Counts and states only; no record values (WPI-2).
	 *
	 * @param mixed $info Sections from core.
	 * @return array<string, mixed>
	 */
	public function debugInfo( $info ): array {
		global $wpdb;
		$info   = is_array( $info ) ? $info : array();
		$fields = array(
			'version'        => array( 'label' => __( 'Plugin version', 'estasoft-handover-passport' ), 'value' => ESTASOFT_HP_VERSION ),
			'schema_version' => array(
				'label' => __( 'Database version', 'estasoft-handover-passport' ),
				'value' => sprintf(
					/* translators: 1: stored version, 2: version this plugin expects. */
					__( '%1$s (this plugin expects %2$s)', 'estasoft-handover-passport' ),
					(string) get_option( \Estasoft\HandoverPassport\Install\Installer::DB_VERSION_OPTION, '0' ),
					(string) Schema::VERSION
				),
			),
		);
		foreach ( Tables::all() as $key => $table ) {
			$count                    = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed table names.
			$fields[ 'rows_' . $key ] = array(
				/* translators: %s: table key, for example items. */
				'label' => sprintf( __( 'Rows in %s', 'estasoft-handover-passport' ), $key ),
				'value' => null === $count ? __( 'Table not found', 'estasoft-handover-passport' ) : (string) (int) $count,
			);
		}

		$latest                     = $this->plugin->scanService()->latest();
		$fields['last_scan']        = array(
			'label' => __( 'Last scan', 'estasoft-handover-passport' ),
			'value' => null === $latest ? __( 'None yet', 'estasoft-handover-passport' ) : (string) $latest['started_at'] . ' UTC',
		);
		$fields['last_scan_result'] = array(
			'label' => __( 'Last scan result', 'estasoft-handover-passport' ),
			'value' => null === $latest ? '-' : (string) $latest['status'],
		);

		$cron_off            = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$next                = wp_next_scheduled( Schedule::SCAN );
		$fields['cron']      = array(
			'label' => __( 'WordPress cron', 'estasoft-handover-passport' ),
			'value' => $cron_off ? __( 'Switched off (DISABLE_WP_CRON)', 'estasoft-handover-passport' ) : __( 'On', 'estasoft-handover-passport' ),
		);
		$fields['next_scan'] = array(
			'label' => __( 'Next scheduled scan', 'estasoft-handover-passport' ),
			'value' => false === $next ? __( 'Not scheduled', 'estasoft-handover-passport' ) : gmdate( 'Y-m-d H:i', $next ) . ' UTC',
		);
		$fields['pdf']       = array(
			'label' => __( 'PDF library', 'estasoft-handover-passport' ),
			'value' => class_exists( '\\Mpdf\\Mpdf' ) ? __( 'Available', 'estasoft-handover-passport' ) : __( 'Not installed', 'estasoft-handover-passport' ),
		);

		$info['estasoft-handover-passport'] = array(
			'label'       => __( 'Estasoft Handover Passport', 'estasoft-handover-passport' ),
			'description' => __( 'Counts and states only. Nothing here comes from a record.', 'estasoft-handover-passport' ),
			'fields'      => $fields,
		);
		return $info;
	}

	/**
	 * The newest verification among records and people, UTC date-time, or null.
	 */
	private function lastVerified(): ?string {
		$dates = array_filter(
			array(
				( new ItemRepository() )->maxOf( 'verified_at' ),
				( new PeopleRepository() )->maxOf( 'verified_at' ),
			)
		);
		return array() === $dates ? null : max( $dates );
	}

	/**
	 * A test result in the shape Site Health expects.
	 *
	 * @param string $status      good or recommended.
	 * @param string $label       Headline.
	 * @param string $description One or two plain sentences.
	 * @param bool   $with_link   Whether to link to Passport (only for people who can open it).
	 * @return array<string, mixed>
	 */
	private function result( string $status, string $label, string $description, bool $with_link = false ): array {
		$result = array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array( 'label' => __( 'Passport', 'estasoft-handover-passport' ), 'color' => 'blue' ),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '',
			'test'        => 'good' === $status ? 'estasoft_hp_ok' : 'estasoft_hp_attention',
		);
		if ( $with_link && Capabilities::can( Capabilities::VIEW ) ) {
			$result['actions'] = '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . Admin::MENU_SLUG ) ) . '">' . esc_html__( 'Open Passport', 'estasoft-handover-passport' ) . '</a></p>';
		}
		return $result;
	}
}
