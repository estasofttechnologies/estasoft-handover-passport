<?php
/**
 * The export profiles that ship, and the copies people make (EXP-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of profiles. A copy keeps the rules of the profile it was made from and only changes its chapters.
 */
final class Profiles {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $custom Copies from Settings: key to array(label, base, sections).
	 */
	public function __construct( private array $custom = array() ) {}

	/**
	 * Every chapter a pack can hold, with a plain name.
	 *
	 * @return array<string, string>
	 */
	public static function chapters(): array {
		return array(
			'passport'           => __( 'About this site', 'estasoft-handover-passport' ),
			'people'             => __( 'People', 'estasoft-handover-passport' ),
			'stack'              => __( 'Stack', 'estasoft-handover-passport' ),
			'domains'            => __( 'Domains', 'estasoft-handover-passport' ),
			'dns'                => __( 'DNS zones', 'estasoft-handover-passport' ),
			'hosting'            => __( 'Hosting and environments', 'estasoft-handover-passport' ),
			'licences'           => __( 'Licences and subscriptions', 'estasoft-handover-passport' ),
			'services'           => __( 'Third-party services', 'estasoft-handover-passport' ),
			'backups'            => __( 'Backups', 'estasoft-handover-passport' ),
			'urls'               => __( 'Critical addresses', 'estasoft-handover-passport' ),
			'access'             => __( 'Access', 'estasoft-handover-passport' ),
			'assets'             => __( 'Other assets', 'estasoft-handover-passport' ),
			'stack_notes'        => __( 'Stack notes', 'estasoft-handover-passport' ),
			'runbooks'           => __( 'Runbooks', 'estasoft-handover-passport' ),
			'tests'              => __( 'Restore tests', 'estasoft-handover-passport' ),
			'transfers'          => __( 'Ownership transfers', 'estasoft-handover-passport' ),
			'incidents'          => __( 'Incidents', 'estasoft-handover-passport' ),
			'checklist'          => __( 'Handover checklist', 'estasoft-handover-passport' ),
			'findings'           => __( 'Findings', 'estasoft-handover-passport' ),
			'emergency_contacts' => __( 'Who to call', 'estasoft-handover-passport' ),
			'emergency_hosting'  => __( 'Host', 'estasoft-handover-passport' ),
			'emergency_domains'  => __( 'Domain and DNS', 'estasoft-handover-passport' ),
			'emergency_backups'  => __( 'Backups and restore', 'estasoft-handover-passport' ),
			'emergency_runbooks' => __( 'Runbooks for an emergency', 'estasoft-handover-passport' ),
			'emergency_urls'     => __( 'Addresses to check first', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * The four profiles that ship.
	 *
	 * @return array<string, Profile>
	 */
	public static function builtin(): array {
		$common = array( 'passport', 'people', 'domains', 'hosting', 'licences', 'services', 'backups', 'urls', 'runbooks', 'tests', 'transfers', 'checklist' );
		$deep   = array( 'passport', 'people', 'stack', 'domains', 'dns', 'hosting', 'licences', 'services', 'backups', 'urls', 'access', 'assets', 'stack_notes', 'runbooks', 'tests', 'incidents', 'transfers', 'checklist', 'findings' );
		return array(
			Profile::CLIENT    => new Profile( Profile::CLIENT, __( 'Client pack', 'estasoft-handover-passport' ), __( 'What the site owner needs and may see. Records are included only when marked visible to the client.', 'estasoft-handover-passport' ), $common, Profile::CLIENT ),
			Profile::TECHNICAL => new Profile( Profile::TECHNICAL, __( 'Technical pack', 'estasoft-handover-passport' ), __( 'Everything that is not Sensitive, for the next maintainer.', 'estasoft-handover-passport' ), $deep, Profile::TECHNICAL ),
			Profile::FULL      => new Profile( Profile::FULL, __( 'Full internal', 'estasoft-handover-passport' ), __( 'Everything, including Sensitive records and fields, for the agency\'s own files. Needs the Sensitive capability.', 'estasoft-handover-passport' ), $deep, Profile::FULL ),
			Profile::EMERGENCY => new Profile( Profile::EMERGENCY, __( 'Emergency sheet', 'estasoft-handover-passport' ), __( 'One page: who to call, who holds the accounts, where the backups are and what to check first.', 'estasoft-handover-passport' ), array( 'emergency_contacts', 'emergency_hosting', 'emergency_domains', 'emergency_backups', 'emergency_runbooks', 'emergency_urls' ), Profile::EMERGENCY ),
		);
	}

	/**
	 * Every profile, shipped and copied.
	 *
	 * @return array<string, Profile>
	 */
	public function all(): array {
		$all = self::builtin();
		foreach ( $this->custom as $key => $copy ) {
			$key  = sanitize_key( (string) $key );
			$base = is_array( $copy ) ? (string) ( $copy['base'] ?? '' ) : '';
			if ( '' === $key || isset( $all[ $key ] ) || ! is_array( $copy ) || ! isset( $all[ $base ] ) ) {
				continue;
			}
			$chapters = array_values( array_intersect( array_keys( self::chapters() ), array_map( 'strval', (array) ( $copy['sections'] ?? array() ) ) ) );
			if ( array() === $chapters ) {
				continue;
			}
			$all[ $key ] = new Profile( $key, (string) ( $copy['label'] ?? $key ), (string) ( $copy['description'] ?? $all[ $base ]->description ), $chapters, $all[ $base ]->audience, false );
		}
		return $all;
	}

	/**
	 * One profile, or null when the key is unknown.
	 *
	 * @param string $key Profile key.
	 */
	public function get( string $key ): ?Profile {
		return $this->all()[ $key ] ?? null;
	}
}
