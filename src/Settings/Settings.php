<?php
/**
 * Plugin settings stored in one non-autoloaded option.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, merges and saves settings. Defaults are the Assumed values of spec section 19.3.
 */
class Settings {

	public const OPTION = 'estasoft_hp_settings';

	/**
	 * Cached merged settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'review_days'        => array(
				'stack'    => 30,
				'licences' => 90,
				'domains'  => 90,
				'hosting'  => 180,
				'backups'  => 90,
				'people'   => 180,
				'access'   => 90,
				'runbooks' => 180,
				'services' => 180,
				'other'    => 180,
			),
			'due_days'           => 14,
			'scan_time'          => '03:00',
			'scan_limit'         => 20,
			'reminders_enabled'  => true,
			'reminder_days'      => array( 60, 30, 14, 7, 1, 0 ),
			'reminder_emails'    => array(),
			'digest_enabled'     => false,
			'backup_confirm'     => 30,
			'access_review_days' => 90,
			'retention'          => array(
				'changes_months'   => 24,
				'findings_months'  => 12,
				'reminders_months' => 12,
				'exports_months'   => 12,
				'deleted_days'     => 30,
			),
			'import_undo_days'   => 7,
			'emailed_copy'       => array(
				'enabled'     => false,
				'addresses'   => array(),
				'on_snapshot' => true,
				'monthly'     => true,
			),
			'url_check_weekly'   => false,
			'dashboard_widget'   => false,
			'calendar_feed'      => false,
			'pack'               => array(
				'title'       => '',
				'cover_text'  => '',
				'footer_text' => '',
				'page_size'   => 'A4',
				'credit'      => false,
				'language'    => '',
			),
			'recovery'           => array(
				'data_loss_hours' => null,
				'downtime_hours'  => null,
			),
			'thresholds'         => array(
				'eol_warn_days'       => 90,
				'update_age_days'     => 30,
				'licence_warn_days'   => 60,
				'licence_lead_days'   => 30,
				'domain_warn_days'    => 30,
				'ssl_warn_days'       => 21,
				'hosting_warn_days'   => 30,
				'restore_test_days'   => 180,
				'backup_confirm_days' => 45,
				'scan_stale_hours'    => 48,
				'access_review_days'  => 90,
			),
			'disabled_rules'     => array(),
			'profiles'           => array(),
			'capability_grants'  => array(),
			'delete_on_uninstall' => false,
			'dismissed_notices'  => array(),
		);
	}

	/**
	 * All settings merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = self::merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return $this->cache;
	}

	/**
	 * Read one value by dotted path.
	 *
	 * @param string $path    For example "retention.changes_months".
	 * @param mixed  $default Value when the path is missing.
	 * @return mixed
	 */
	public function get( string $path, $default = null ) {
		$value = $this->all();
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default;
			}
			$value = $value[ $segment ];
		}
		return $value;
	}

	/**
	 * Save a partial set of settings over the current ones.
	 *
	 * @param array<string, mixed> $changes Partial settings, already sanitised.
	 */
	public function update( array $changes ): void {
		$merged = self::merge( $this->all(), $changes );
		update_option( self::OPTION, $merged, false );
		$this->cache = $merged;
	}

	/**
	 * Replace one top-level setting outright, without merging (used for lists and grants).
	 *
	 * @param string $key   Top-level key.
	 * @param mixed  $value New value.
	 */
	public function set( string $key, $value ): void {
		$all         = $this->all();
		$all[ $key ] = $value;
		update_option( self::OPTION, $all, false );
		$this->cache = $all;
	}

	/**
	 * Forget the cached copy.
	 */
	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * Review interval in days for a section (PRT-4, filter estasoft_hp_review_days).
	 *
	 * @param string $section Section key.
	 */
	public function reviewDays( string $section ): int {
		$days = (int) $this->get( 'review_days.' . $section, (int) $this->get( 'review_days.other', 180 ) );
		$days = (int) apply_filters( 'estasoft_hp_review_days', $days, $section );
		return max( 1, $days );
	}

	/**
	 * Merge arrays recursively; lists are replaced, maps are merged.
	 *
	 * @param array<mixed> $base    Base values.
	 * @param array<mixed> $changes Overrides.
	 * @return array<mixed>
	 */
	public static function merge( array $base, array $changes ): array {
		foreach ( $changes as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! array_is_list( $value ) && ! array_is_list( $base[ $key ] ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}
}
