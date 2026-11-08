<?php
/**
 * Placeholders in runbook text, such as {site_url} and {registrar} (RUN-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export;

use Estasoft\HandoverPassport\Domain\Kinds;

defined( 'ABSPATH' ) || exit;

/**
 * Fills placeholders from the Passport when a runbook is shown or put into a pack, so the steps stay current.
 */
final class Placeholders {

	/**
	 * The placeholders people can write, with what each means.
	 *
	 * @return array<string, string>
	 */
	public static function names(): array {
		return array(
			'site_url'          => __( 'The address of the site', 'estasoft-handover-passport' ),
			'site_name'         => __( 'The name of the site', 'estasoft-handover-passport' ),
			'owner_name'        => __( 'The site owner', 'estasoft-handover-passport' ),
			'maintainer_name'   => __( 'The technical maintainer', 'estasoft-handover-passport' ),
			'emergency_contact' => __( 'The first emergency contact', 'estasoft-handover-passport' ),
			'domain'            => __( 'The main domain', 'estasoft-handover-passport' ),
			'registrar'         => __( 'The registrar of the main domain', 'estasoft-handover-passport' ),
			'dns_provider'      => __( 'The DNS provider', 'estasoft-handover-passport' ),
			'host_name'         => __( 'The host', 'estasoft-handover-passport' ),
			'backup_location'   => __( 'Where the backups are stored', 'estasoft-handover-passport' ),
			'last_restore_test' => __( 'The date of the last restore test that passed', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Values from the Passport. A Sensitive record never supplies one. In a pack that follows the sharing flags, a
	 * person whose name is not shared gives no name.
	 *
	 * @param ExportData   $data    Passport data.
	 * @param Profile|null $profile Pack the text goes into, or null when shown on screen.
	 * @return array<string, string>
	 */
	public static function values( ExportData $data, ?Profile $profile = null ): array {
		$records = array_values( array_filter( $data->records, static fn( array $r ): bool => empty( $r['sensitive'] ) ) );
		$first   = static function ( string $kind, string $key, ?callable $where = null ) use ( $records ): string {
			foreach ( $records as $record ) {
				if ( $kind !== (string) $record['kind'] || ( null !== $where && ! $where( $record ) ) ) {
					continue;
				}
				$value = $record['fields'][ $key ] ?? '';
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					return trim( (string) $value );
				}
			}
			return '';
		};
		$named   = static function ( string $role ) use ( $data, $profile ): string {
			foreach ( (array) ( $data->roles[ $role ] ?? array() ) as $id ) {
				$person = $data->person( (int) $id );
				if ( null === $person ) {
					continue;
				}
				if ( null !== $profile && $profile->usesSharingFlags() && empty( $person['share_flags']['name'] ) ) {
					continue;
				}
				return (string) $person['name'];
			}
			return '';
		};
		$primary = static fn( array $r ): bool => 'primary' === (string) ( $r['fields']['role'] ?? '' );
		$domain  = $first( Kinds::DOMAIN, 'domain', $primary );

		return array(
			'site_url'          => (string) ( $data->site['url'] ?? '' ),
			'site_name'         => (string) ( $data->site['name'] ?? '' ),
			'owner_name'        => '' !== $named( 'site_owner' ) ? $named( 'site_owner' ) : (string) ( $data->site['client'] ?? '' ),
			'maintainer_name'   => $named( 'technical_maintainer' ),
			'emergency_contact' => $named( 'emergency_contact' ),
			'domain'            => '' !== $domain ? $domain : $first( Kinds::DOMAIN, 'domain' ),
			'registrar'         => '' !== $first( Kinds::DOMAIN, 'registrar', $primary ) ? $first( Kinds::DOMAIN, 'registrar', $primary ) : $first( Kinds::DOMAIN, 'registrar' ),
			'dns_provider'      => $first( Kinds::LAYER, 'provider', static fn( array $r ): bool => 'dns' === (string) ( $r['fields']['layer'] ?? '' ) ),
			'host_name'         => $first( Kinds::HOST, 'provider' ),
			'backup_location'   => $first( Kinds::BACKUP, 'stored_at' ),
			'last_restore_test' => $data->lastPass(),
		);
	}

	/**
	 * Put the values into a text. A placeholder that is known but has no value shows as "[name: not recorded]", so
	 * a gap is visible. A name that is not a placeholder is left as it is.
	 *
	 * @param string                $text   Text with placeholders.
	 * @param array<string, string> $values Values from values().
	 */
	public static function fill( string $text, array $values ): string {
		if ( false === strpos( $text, '{' ) ) {
			return $text;
		}
		return (string) preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static function ( array $found ) use ( $values ): string {
				$name = $found[1];
				if ( ! array_key_exists( $name, self::names() ) ) {
					return $found[0];
				}
				$value = $values[ $name ] ?? '';
				/* translators: %s: name of a placeholder such as registrar. */
				return '' !== $value ? $value : sprintf( __( '[%s: not recorded]', 'estasoft-handover-passport' ), $name );
			},
			$text
		);
	}
}
