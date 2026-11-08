<?php
/**
 * Copies of an export profile with their own chapters (EXP-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export;

use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the copies in Settings. A copy has the rules of the profile it was made from, so a copy of the Client pack can
 * never show more than the Client pack shows; only the chapters change. The caller checks the capability.
 */
final class PackCopies {

	public const MAX = 20;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Chapters a copy of a profile may hold: the Emergency sheet takes only its own, every other profile takes the rest.
	 *
	 * @param string $base_key Key of a shipped profile.
	 * @return array<string, string> Chapter key to name.
	 */
	public static function chaptersFor( string $base_key ): array {
		$emergency = Profile::EMERGENCY === $base_key;
		return array_filter(
			Profiles::chapters(),
			static fn( string $key ): bool => ( 0 === strpos( $key, 'emergency_' ) ) === $emergency,
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Make a copy.
	 *
	 * @param string             $base_key Key of a shipped profile.
	 * @param string             $label    Name of the copy.
	 * @param array<int, string> $sections Chapter keys.
	 * @return string Key of the new profile.
	 * @throws ValidationError When the input is not accepted.
	 */
	public function create( string $base_key, string $label, array $sections ): string {
		$builtin = Profiles::builtin();
		if ( ! isset( $builtin[ $base_key ] ) ) {
			throw new ValidationError( __( 'Copy of', 'estasoft-handover-passport' ), __( 'Pick one of the listed packs.', 'estasoft-handover-passport' ) );
		}
		$label = trim( (string) preg_replace( '/\s+/', ' ', sanitize_text_field( $label ) ) );
		if ( '' === $label || mb_strlen( $label ) > 60 ) {
			throw new ValidationError( __( 'Name', 'estasoft-handover-passport' ), __( 'Give the copy a name of up to 60 characters.', 'estasoft-handover-passport' ) );
		}
		$this->plugin->secretGuard()->assertClean( $label, __( 'Name', 'estasoft-handover-passport' ) );
		$allowed = array_keys( self::chaptersFor( $base_key ) );
		$chosen  = array_values( array_intersect( $allowed, array_map( 'strval', $sections ) ) );
		if ( array() === $chosen ) {
			throw new ValidationError( __( 'Chapters', 'estasoft-handover-passport' ), __( 'Choose at least one chapter.', 'estasoft-handover-passport' ) );
		}
		$copies = $this->all();
		if ( count( $copies ) >= self::MAX ) {
			throw new ValidationError( __( 'Name', 'estasoft-handover-passport' ), __( 'There are already 20 copies. Delete one first.', 'estasoft-handover-passport' ) );
		}
		foreach ( $copies as $copy ) {
			if ( 0 === strcasecmp( (string) ( $copy['label'] ?? '' ), $label ) ) {
				throw new ValidationError( __( 'Name', 'estasoft-handover-passport' ), __( 'A copy with that name exists already.', 'estasoft-handover-passport' ) );
			}
		}
		$key            = 'copy-' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
		$copies[ $key ] = array(
			'base'        => $base_key,
			'label'       => $label,
			'description' => $builtin[ $base_key ]->description,
			'sections'    => $chosen,
		);
		$this->plugin->settings()->set( 'profiles', $copies );
		$this->plugin->changeLog()->add( 'settings_changed', 'settings', 0, $label, array(), array(), sprintf( /* translators: %s: name of the copy. */ __( 'Pack copy made: %s', 'estasoft-handover-passport' ), $label ) );
		return $key;
	}

	/**
	 * Delete a copy. Snapshots made from it stay as they are.
	 *
	 * @param string $key Key of the copy.
	 * @throws ValidationError When there is no such copy.
	 */
	public function delete( string $key ): void {
		$copies = $this->all();
		if ( ! isset( $copies[ $key ] ) ) {
			throw new ValidationError( __( 'Copy', 'estasoft-handover-passport' ), __( 'That copy does not exist.', 'estasoft-handover-passport' ) );
		}
		$label = (string) ( $copies[ $key ]['label'] ?? $key );
		unset( $copies[ $key ] );
		$this->plugin->settings()->set( 'profiles', $copies );
		$this->plugin->changeLog()->add( 'settings_changed', 'settings', 0, $label, array(), array(), sprintf( /* translators: %s: name of the copy. */ __( 'Pack copy deleted: %s', 'estasoft-handover-passport' ), $label ) );
	}

	/**
	 * The copies as stored.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		return array_filter( (array) $this->plugin->settings()->get( 'profiles', array() ), 'is_array' );
	}
}
