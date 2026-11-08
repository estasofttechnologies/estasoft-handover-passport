<?php
/**
 * Settings: review intervals, scan, thresholds, retention, who can do what, and removal (ADM-1, CAP-2, PRV-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Install\Schedule;
use Estasoft\HandoverPassport\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The Settings screen. Everything here needs the configure capability.
 */
final class SettingsScreen extends Screen {

	public const SLUG   = 'estasoft-passport-settings';
	public const ACTION = 'estasoft_hp_save_settings';

	/**
	 * Attach the save handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handleSave' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Settings', 'estasoft-handover-passport' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function capability(): string {
		return Capabilities::CONFIGURE;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tabs(): array {
		return array(
			'general' => __( 'General', 'estasoft-handover-passport' ),
			'access'  => __( 'Who can do what', 'estasoft-handover-passport' ),
			'privacy' => __( 'Privacy and data', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Review sections and their labels.
	 *
	 * @return array<string, string>
	 */
	public static function sections(): array {
		return array(
			'stack'    => __( 'Stack', 'estasoft-handover-passport' ),
			'licences' => __( 'Licences', 'estasoft-handover-passport' ),
			'domains'  => __( 'Domains', 'estasoft-handover-passport' ),
			'hosting'  => __( 'Hosting', 'estasoft-handover-passport' ),
			'services' => __( 'Services', 'estasoft-handover-passport' ),
			'backups'  => __( 'Backups', 'estasoft-handover-passport' ),
			'people'   => __( 'People', 'estasoft-handover-passport' ),
			'access'   => __( 'Access', 'estasoft-handover-passport' ),
			'runbooks' => __( 'Runbooks', 'estasoft-handover-passport' ),
			'other'    => __( 'Other records', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function tab( string $tab ): void {
		$settings = $this->plugin->settings();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( self::ACTION );

		if ( 'access' === $tab ) {
			$this->accessTab( $settings->get( 'capability_grants', array() ) );
		} elseif ( 'privacy' === $tab ) {
			$this->privacyTab( (bool) $settings->get( 'delete_on_uninstall', false ), (array) $settings->get( 'retention', array() ) );
		} else {
			$this->generalTab( $settings );
		}
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save settings', 'estasoft-handover-passport' ) . '</button></p></form>';
	}

	/**
	 * Review intervals, scan and thresholds.
	 *
	 * @param \Estasoft\HandoverPassport\Settings\Settings $settings Settings.
	 */
	private function generalTab( $settings ): void {
		echo '<fieldset class="estasoft-hp-fieldset"><legend>' . esc_html__( 'How often records are reviewed', 'estasoft-handover-passport' ) . '</legend>';
		echo '<p class="description">' . esc_html__( 'After this many days a record is Due, and after the due window it is Stale. A record can override this on its own form.', 'estasoft-handover-passport' ) . '</p>';
		foreach ( self::sections() as $key => $label ) {
			Form::row( 'review_days_' . $key, array( 'type' => 'int', 'label' => $label ), $settings->get( 'review_days.' . $key, 90 ) );
		}
		Form::row( 'due_days', array( 'type' => 'int', 'label' => __( 'Days a record stays Due before it is Stale', 'estasoft-handover-passport' ) ), $settings->get( 'due_days', 14 ) );
		echo '</fieldset>';

		echo '<fieldset class="estasoft-hp-fieldset"><legend>' . esc_html__( 'Scan', 'estasoft-handover-passport' ) . '</legend>';
		Form::row( 'scan_time', array( 'type' => 'text', 'label' => __( 'Daily scan time (site time, HH:MM)', 'estasoft-handover-passport' ), 'max' => 5 ), $settings->get( 'scan_time', '03:00' ) );
		Form::row( 'scan_limit', array( 'type' => 'int', 'label' => __( 'Longest a scan may run (seconds)', 'estasoft-handover-passport' ), 'help' => __( 'Between 5 and 120.', 'estasoft-handover-passport' ) ), $settings->get( 'scan_limit', 20 ) );
		echo '</fieldset>';

		echo '<fieldset class="estasoft-hp-fieldset"><legend>' . esc_html__( 'Lead times', 'estasoft-handover-passport' ) . '</legend>';
		Form::row( 'licence_lead_days', array( 'type' => 'int', 'label' => __( 'A licence "renews soon" this many days before its date', 'estasoft-handover-passport' ) ), $settings->get( 'thresholds.licence_lead_days', 30 ) );
		Form::row( 'backup_confirm', array( 'type' => 'int', 'label' => __( 'A backup should be confirmed every (days)', 'estasoft-handover-passport' ) ), $settings->get( 'backup_confirm', 30 ) );
		Form::row( 'access_review_days', array( 'type' => 'int', 'label' => __( 'Access entries should be reviewed every (days)', 'estasoft-handover-passport' ) ), $settings->get( 'access_review_days', 90 ) );
		echo '</fieldset>';
	}

	/**
	 * Capability grid (CAP-2).
	 *
	 * @param mixed $grants Current grants by role.
	 */
	private function accessTab( $grants ): void {
		$grants = is_array( $grants ) ? $grants : array();
		echo '<p>' . esc_html__( 'Administrators always have all five capabilities. Tick what other roles may do. No new role is created.', 'estasoft-handover-passport' ) . '</p>';
		echo '<table class="widefat striped estasoft-hp-table"><thead><tr><th scope="col">' . esc_html__( 'Role', 'estasoft-handover-passport' ) . '</th>';
		$caps = Capabilities::all();
		foreach ( array_keys( $caps ) as $cap ) {
			echo '<th scope="col">' . esc_html( $this->capName( $cap ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			if ( 'administrator' === $slug ) {
				continue;
			}
			echo '<tr><th scope="row">' . esc_html( translate_user_role( $name ) ) . '</th>';
			foreach ( array_keys( $caps ) as $cap ) {
				$on = isset( $grants[ $slug ] ) && in_array( $cap, (array) $grants[ $slug ], true );
				echo '<td><label><span class="screen-reader-text">' . esc_html( sprintf(
					/* translators: 1: role name, 2: capability name. */
					__( '%1$s: %2$s', 'estasoft-handover-passport' ),
					translate_user_role( $name ),
					$this->capName( $cap )
				) ) . '</span><input type="checkbox" name="grants[' . esc_attr( (string) $slug ) . '][]" value="' . esc_attr( $cap ) . '"' . checked( $on, true, false ) . '></label></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a fixed attribute.
			}
			echo '</tr>';
		}
		echo '</tbody></table><dl class="estasoft-hp-defs">';
		foreach ( $caps as $cap => $text ) {
			echo '<dt>' . esc_html( $this->capName( $cap ) ) . '</dt><dd>' . esc_html( $text ) . '</dd>';
		}
		echo '</dl>';
	}

	/**
	 * Short name of a capability.
	 *
	 * @param string $cap Capability.
	 */
	private function capName( string $cap ): string {
		$names = array(
			Capabilities::VIEW           => __( 'View', 'estasoft-handover-passport' ),
			Capabilities::VIEW_SENSITIVE => __( 'View Sensitive', 'estasoft-handover-passport' ),
			Capabilities::EDIT           => __( 'Edit', 'estasoft-handover-passport' ),
			Capabilities::EXPORT         => __( 'Export', 'estasoft-handover-passport' ),
			Capabilities::CONFIGURE      => __( 'Configure', 'estasoft-handover-passport' ),
		);
		return $names[ $cap ] ?? $cap;
	}

	/**
	 * What is stored, retention and removal (PRV-1, PRV-5).
	 *
	 * @param bool                 $delete    Whether data is removed on uninstall.
	 * @param array<string, mixed> $retention Retention settings.
	 */
	private function privacyTab( bool $delete, array $retention ): void {
		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Personal data held', 'estasoft-handover-passport' ) . '</h2><ul class="estasoft-hp-list">';
		foreach ( PrivacyPolicy::stored() as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul><p>' . esc_html__( 'Nothing is sent to ESTASOFT TECHNOLOGIES or to any other outside party. Scans read local data only.', 'estasoft-handover-passport' ) . '</p></div>';

		echo '<fieldset class="estasoft-hp-fieldset"><legend>' . esc_html__( 'How long things are kept', 'estasoft-handover-passport' ) . '</legend>';
		Form::row( 'ret_changes_months', array( 'type' => 'int', 'label' => __( 'Change log (months)', 'estasoft-handover-passport' ) ), $retention['changes_months'] ?? 24 );
		Form::row( 'ret_deleted_days', array( 'type' => 'int', 'label' => __( 'Deleted records are kept for (days)', 'estasoft-handover-passport' ), 'help' => __( 'After this they are removed for good.', 'estasoft-handover-passport' ) ), $retention['deleted_days'] ?? 30 );
		echo '</fieldset>';

		echo '<fieldset class="estasoft-hp-fieldset"><legend>' . esc_html__( 'When the plugin is deleted', 'estasoft-handover-passport' ) . '</legend>';
		Form::checkbox( 'delete_on_uninstall', __( 'Delete everything when the plugin is deleted', 'estasoft-handover-passport' ), $delete, __( 'Off by default. When off, deleting the plugin keeps the records, so a client record is never lost by accident.', 'estasoft-handover-passport' ) );
		echo '</fieldset>';
	}

	/**
	 * Save settings.
	 */
	public function handleSave(): void {
		if ( ! Capabilities::can( Capabilities::CONFIGURE ) ) {
			wp_die( esc_html__( 'You do not have permission to change settings.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		$tab      = Request::post( 'tab' );
		$settings = $this->plugin->settings();
		$int      = static fn( string $name, int $min, int $max, int $fallback ): int => '' !== Request::post( $name ) ? max( $min, min( $max, absint( Request::post( $name ) ) ) ) : $fallback;
		$changed  = array();

		if ( 'access' === $tab ) {
			$raw    = isset( $_POST['grants'] ) && is_array( $_POST['grants'] ) ? wp_unslash( $_POST['grants'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked above; each value is matched against fixed lists below.
			$grants = array();
			$roles  = array_keys( wp_roles()->get_names() );
			foreach ( $raw as $role => $caps ) {
				$role = sanitize_key( (string) $role );
				if ( 'administrator' === $role || ! in_array( $role, $roles, true ) ) {
					continue;
				}
				$grants[ $role ] = array_values( array_intersect( array_keys( Capabilities::all() ), array_map( 'sanitize_key', array_map( 'strval', array_filter( (array) $caps, 'is_scalar' ) ) ) ) );
			}
			$settings->set( 'capability_grants', $grants );
			Capabilities::applyGrants( $grants );
			$changed[] = __( 'who can do what', 'estasoft-handover-passport' );
		} elseif ( 'privacy' === $tab ) {
			$settings->set( 'delete_on_uninstall', '1' === Request::post( 'delete_on_uninstall' ) );
			$settings->update(
				array(
					'retention' => array(
						'changes_months' => $int( 'ret_changes_months', 1, 120, 24 ),
						'deleted_days'   => $int( 'ret_deleted_days', 1, 365, 30 ),
					),
				)
			);
			$changed[] = __( 'privacy and data', 'estasoft-handover-passport' );
		} else {
			$review = array();
			foreach ( array_keys( self::sections() ) as $key ) {
				$review[ $key ] = $int( 'review_days_' . $key, 1, 3650, (int) $settings->get( 'review_days.' . $key, 90 ) );
			}
			$time = Request::post( 'scan_time' );
			$time = 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : (string) $settings->get( 'scan_time', '03:00' );
			if ( $time !== (string) $settings->get( 'scan_time', '03:00' ) ) {
				Schedule::rescheduleScan( $time );
			}
			$settings->update(
				array(
					'review_days'        => $review,
					'due_days'           => $int( 'due_days', 0, 365, 14 ),
					'scan_time'          => $time,
					'scan_limit'         => $int( 'scan_limit', 5, 120, 20 ),
					'backup_confirm'     => $int( 'backup_confirm', 1, 365, 30 ),
					'access_review_days' => $int( 'access_review_days', 1, 3650, 90 ),
					'thresholds'         => array( 'licence_lead_days' => $int( 'licence_lead_days', 0, 365, 30 ) ),
				)
			);
			$changed[] = __( 'review intervals and scan', 'estasoft-handover-passport' );
		}

		$this->plugin->changeLog()->add( 'settings_changed', 'settings', 0, __( 'Settings', 'estasoft-handover-passport' ), array(), array(), implode( ', ', $changed ), 'wordpress' );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ?: 'general', 'estasoft_hp_notice' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
