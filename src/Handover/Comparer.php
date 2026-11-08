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
	 * @param array<string, mixed> $older Older content.
	 * @param array<string, mixed> $newer Newer content.
	 * @return array{added: array<int, array<string, string>>, removed: array<int, array<string, string>>, changed: array<int, array<string, mixed>>, unchanged: int, notes_changed: bool}
	 *                                   Each entry also holds the key of its chapter in "chapter".
	 */
	public static function diff( array $older, array $newer ): array {
		$before = self::index( $older );
		$after  = self::index( $newer );

		$added   = array();
		$removed = array();
		$changed = array();
		$same    = 0;

		foreach ( $after as $key => $item ) {
			if ( ! isset( $before[ $key ] ) ) {
				$added[] = array( 'section' => $item['section'], 'chapter' => $item['chapter'], 'ref' => $item['ref'], 'title' => $item['masked'] ? '' : $item['title'] );
				continue;
			}
			$fields = self::changedFields( $before[ $key ], $item );
			if ( array() === $fields ) {
				++$same;
				continue;
			}
			$changed[] = array( 'section' => $item['section'], 'chapter' => $item['chapter'], 'ref' => $item['ref'], 'title' => $item['masked'] ? '' : $item['title'], 'fields' => $fields );
		}
		foreach ( $before as $key => $item ) {
			if ( ! isset( $after[ $key ] ) ) {
				$removed[] = array( 'section' => $item['section'], 'chapter' => $item['chapter'], 'ref' => $item['ref'], 'title' => $item['masked'] ? '' : $item['title'] );
			}
		}
		return array(
			'added'         => $added,
			'removed'       => $removed,
			'changed'       => $changed,
			'unchanged'     => $same,
			'notes_changed' => (string) ( $older['notes'] ?? '' ) !== (string) ( $newer['notes'] ?? '' ),
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
						'chapter' => (string) $section['key'],
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
	 * @param array<string, mixed> $older Older item.
	 * @param array<string, mixed> $newer Newer item.
	 * @return array<int, array<string, mixed>>
	 */
	private static function changedFields( array $older, array $newer ): array {
		$out  = array();
		$keys = array_unique( array_merge( array_keys( $older['values'] ), array_keys( $newer['values'] ) ) );
		foreach ( $keys as $key ) {
			$a = $older['values'][ $key ] ?? '';
			$b = $newer['values'][ $key ] ?? '';
			if ( self::same( $a, $b ) ) {
				continue;
			}
			$def    = $newer['defs'][ $key ] ?? $older['defs'][ $key ] ?? array( 'key' => $key, 'label' => $key, 'type' => 'text' );
			$masked = $older['masked'] || $newer['masked'] || ! empty( $def['sensitive'] );
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
