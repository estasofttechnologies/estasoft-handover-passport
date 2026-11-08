<?php
/**
 * Capabilities (CAP-1 to CAP-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Security;

defined( 'ABSPATH' ) || exit;

/**
 * The five capabilities and the helpers that grant and check them.
 */
final class Capabilities {

	public const VIEW           = 'estasoft_hp_view';
	public const VIEW_SENSITIVE = 'estasoft_hp_view_sensitive';
	public const EDIT           = 'estasoft_hp_edit';
	public const EXPORT         = 'estasoft_hp_export';
	public const CONFIGURE      = 'estasoft_hp_configure';

	/**
	 * The five capability names. Unlike all() this does no translation, so it is safe to call before WordPress has
	 * loaded translations, for example while a site is set up at plugins_loaded.
	 *
	 * @return array<int, string>
	 */
	public static function keys(): array {
		return array( self::VIEW, self::VIEW_SENSITIVE, self::EDIT, self::EXPORT, self::CONFIGURE );
	}

	/**
	 * Every capability with a plain description.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			self::VIEW           => __( 'See the Passport screens and every record that is not Sensitive', 'estasoft-handover-passport' ),
			self::VIEW_SENSITIVE => __( 'See and edit Sensitive records (server paths, IP addresses, administrator and account emails)', 'estasoft-handover-passport' ),
			self::EDIT           => __( 'Add, change, verify and delete records, run scans and checks, run drills, add restore tests', 'estasoft-handover-passport' ),
			self::EXPORT         => __( 'Create snapshots, download exports, send emailed copies, record acknowledgements', 'estasoft-handover-passport' ),
			self::CONFIGURE      => __( 'Change settings, review intervals, rules, export profiles, delete snapshots, grant these capabilities to other roles', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Give all five capabilities to the Administrator role (CAP-1).
	 */
	public static function grantDefaults(): void {
		$role = get_role( 'administrator' );
		if ( null === $role ) {
			return;
		}
		foreach ( self::keys() as $cap ) {
			$role->add_cap( $cap );
		}
	}

	/**
	 * Apply the grants chosen in Settings (CAP-2). The Administrator role always keeps all five.
	 *
	 * @param array<string, array<int, string>> $grants Capabilities by role slug.
	 */
	public static function applyGrants( array $grants ): void {
		$known = self::keys();
		foreach ( wp_roles()->role_objects as $slug => $role ) {
			if ( 'administrator' === $slug ) {
				continue;
			}
			$wanted = isset( $grants[ $slug ] ) ? array_intersect( $known, (array) $grants[ $slug ] ) : array();
			foreach ( $known as $cap ) {
				if ( in_array( $cap, $wanted, true ) ) {
					$role->add_cap( $cap );
				} else {
					$role->remove_cap( $cap );
				}
			}
		}
		self::grantDefaults();
	}

	/**
	 * Remove the capabilities from every role (used by uninstall).
	 */
	public static function removeAll(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::keys() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Whether the current user holds a capability.
	 *
	 * @param string $cap One of the constants.
	 */
	public static function can( string $cap ): bool {
		return current_user_can( $cap );
	}

	/**
	 * Whether the current user may see Sensitive records.
	 */
	public static function canSeeSensitive(): bool {
		return current_user_can( self::VIEW_SENSITIVE );
	}
}
