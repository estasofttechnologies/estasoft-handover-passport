<?php
/**
 * Compares two packs field by field (HND-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Handover;

use Estasoft\HandoverPassport\Export\Display;

defined( 'ABSPATH' ) || exit;

/**
 * Lists what was added, removed and changed between two contents. The values of Sensitive records and Sensitive
 * fields are never shown: the field is only reported as changed.
 */
final class Comparer {

	/**
	 * Compare an older content with a newer one.
	 *
	 * @param array<string, mixed> $old Older content.
	 * @param array<string, mixed> $new Newer content.
	 * @return array{added: list<array<string, string>>, removed: list<array<string, string>>, changed: list<array<string, mixed>>, unchanged: int, notes_changed: bool}
	 */
	public static function diff( array $old, array $new ): array {
		$before = self::index( $old );
		$after  = self::index( $new );

		$added   = array();
		$removed = array();
		$changed = array();
		$same    = 0;

		foreach ( $after as $key => $item ) {
			if ( ! isset( $before[ $key ] ) ) {
				$added[] = array( 'section' => $item['section'], 'ref' => $item['ref'], 'title' => $item['masked'] ? '' : $item['title'] );
				continue;
			}
			$fields = self::changedFields( $before[ $key ], $item );
			if ( array() === $fields ) {
				++$same;
				continue;
			}
			$changed[] = array( 'section' => $item['section'], 'ref' => $item['ref'], 'title' => $item['masked'] ? '' : $item['title'], 'fields' => $fields );
		}
		foreach ( $before as $key => $item ) {
			if ( ! isset( $after[ $key ] ) ) {
				$removed[] = array( 'section' => $item['section'], 'ref' => $item['ref'], 'title' => $item['masked'] ? '' : $item['title'] );
			}
		}
		return array(
			'added'         => $added,
			'removed'       => $removed,
			'changed'       => $changed,
			'unchanged'     => $same,
			'notes_changed' => (string) ( $old['notes'] ?? '' ) !== (string) ( $new['notes'] ?? '' ),
		);
	}

	/**
	 * Remove the Sensitive records from a content, so it can be compared by someone who may not see them.
	 *
	 * @param array<string, mixed> $content Content.
	 * @return array{content: array<string, mixed>, removed: int}
	 */
	public static function withoutSensitive( array $content ): array {
		$removed = 0;
		foreach ( (array) ( $content['sections'] ?? array() ) as $s => $section ) {
			foreach ( (array) $section['groups'] as $g => $group ) {
				$kept = array();
				foreach ( (array) $group['items'] as $item ) {
					if ( ! empty( $item['sensitive'] ) ) {
						++$removed;
						continue;
					}
					$kept[] = $item;
				}
				$content['sections'][ $s ]['groups'][ $g ]['items'] = $kept;
			}
		}
		return array( 'content' => $content, 'removed' => $removed );
	}

	/**
	 * Items keyed by section, group and reference, each with its field definitions.
	 *
	 * @param array<string, mixed> $content Content.
	 * @return array<string, array<string, mixed>>
	 */
	private static function index( array $content ): array {
		$out = array();
		foreach ( (array) ( $content['sections'] ?? array() ) as $section ) {
			foreach ( (array) ( $section['groups'] ?? array() ) as $group ) {
				$defs = array();
				foreach ( (array) ( $group['fields'] ?? array() ) as $def ) {
					$defs[ (string) $def['key'] ] = $def;
				}
				foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
					$key         = (string) $section['key'] . '|' . (string) $group['key'] . '|' . (string) $item['ref'];
					$out[ $key ] = array(
						'section' => (string) $section['title'],
						'ref'     => (string) $item['ref'],
						'title'   => (string) $item['title'],
						'masked'  => ! empty( $item['sensitive'] ),
						'values'  => (array) ( $item['values'] ?? array() ),
						'defs'    => $defs,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Fields whose value differs.
	 *
	 * @param array<string, mixed> $old Older item.
	 * @param array<string, mixed> $new Newer item.
	 * @return list<array<string, mixed>>
	 */
	private static function changedFields( array $old, array $new ): array {
		$out  = array();
		$keys = array_unique( array_merge( array_keys( $old['values'] ), array_keys( $new['values'] ) ) );
		foreach ( $keys as $key ) {
			$a = $old['values'][ $key ] ?? '';
			$b = $new['values'][ $key ] ?? '';
			if ( self::same( $a, $b ) ) {
				continue;
			}
			$def    = $new['defs'][ $key ] ?? $old['defs'][ $key ] ?? array( 'key' => $key, 'label' => $key, 'type' => 'text' );
			$masked = $old['masked'] || $new['masked'] || ! empty( $def['sensitive'] );
			$out[]  = array(
				'key'    => (string) $key,
				'label'  => (string) $def['label'],
				'masked' => $masked,
				'before' => $masked ? '' : Display::text( $def, $a ),
				'after'  => $masked ? '' : Display::text( $def, $b ),
			);
		}
		return $out;
	}

	/**
	 * Whether two stored values are the same, ignoring the difference between an empty text and an empty list.
	 *
	 * @param mixed $a First.
	 * @param mixed $b Second.
	 */
	private static function same( $a, $b ): bool {
		if ( Display::isEmpty( $a ) && Display::isEmpty( $b ) ) {
			return true;
		}
		return $a === $b || ( is_scalar( $a ) && is_scalar( $b ) && (string) $a === (string) $b );
	}
}
