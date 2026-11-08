<?php
/**
 * Sensitive records and fields (PRT-5, CAP-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what a person without the view_sensitive capability, a Client pack or a log may see.
 */
final class Sensitivity {

	/**
	 * Field keys flagged Sensitive in a kind's schema.
	 *
	 * @param string $kind Kind key.
	 * @return array<int, string>
	 */
	public static function fieldKeys( string $kind ): array {
		$def  = Kinds::get( $kind );
		$keys = array();
		foreach ( (array) ( $def['fields'] ?? array() ) as $key => $field ) {
			if ( ! empty( $field['sensitive'] ) ) {
				$keys[] = (string) $key;
			}
		}
		return $keys;
	}

	/**
	 * Remove the values of Sensitive fields from a field map.
	 *
	 * @param string               $kind   Kind key.
	 * @param array<string, mixed> $fields Field values.
	 * @return array<string, mixed>
	 */
	public static function stripFields( string $kind, array $fields ): array {
		foreach ( self::fieldKeys( $kind ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$fields[ $key ] = '';
			}
		}
		return $fields;
	}

	/**
	 * Apply the viewer's rights to a hydrated record.
	 *
	 * A Sensitive record becomes a placeholder. A record with Sensitive fields loses those values.
	 *
	 * @param array<string, mixed> $record   Hydrated record.
	 * @param bool                 $can_view Whether the viewer holds the view_sensitive capability.
	 * @return array<string, mixed>
	 */
	public static function forViewer( array $record, bool $can_view ): array {
		if ( $can_view ) {
			return $record;
		}
		if ( ! empty( $record['sensitive'] ) ) {
			return array(
				'id'        => $record['id'],
				'kind'      => $record['kind'],
				'title'     => __( 'Sensitive record, hidden', 'estasoft-handover-passport' ),
				'hidden'    => true,
				'sensitive' => true,
				'fields'    => array(),
				'owners'    => array(),
			);
		}
		$record['fields'] = self::stripFields( (string) $record['kind'], (array) ( $record['fields'] ?? array() ) );
		return $record;
	}

	/**
	 * Whether a record may enter a Client pack, Technical pack or Emergency sheet without a confirmation (EXP-4).
	 *
	 * @param array<string, mixed> $record Hydrated record.
	 */
	public static function isSensitive( array $record ): bool {
		return ! empty( $record['sensitive'] );
	}
}
