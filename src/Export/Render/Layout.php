<?php
/**
 * Decides how the groups of a pack are laid out, so every format shows the same things.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export\Render;

use Estasoft\HandoverPassport\Export\Display;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers over the content arrays.
 */
final class Layout {

	/**
	 * The most columns a table can have before the items read better as cards.
	 */
	private const MAX_TABLE_COLUMNS = 5;

	/**
	 * Field types that need room and so always make a group into cards.
	 */
	private const WIDE_TYPES = array( 'textarea', 'lines', 'links' );

	/**
	 * The fields of a group worth showing: those at least one item fills in, leaving out a field that only repeats
	 * the title of every item.
	 *
	 * @param array<string, mixed> $group Group.
	 * @return list<array<string, mixed>>
	 */
	public static function columns( array $group ): array {
		$out = array();
		foreach ( (array) ( $group['fields'] ?? array() ) as $def ) {
			$key    = (string) $def['key'];
			$filled = false;
			$echo   = true;
			foreach ( (array) $group['items'] as $item ) {
				$value = $item['values'][ $key ] ?? '';
				if ( Display::isEmpty( $value ) ) {
					continue;
				}
				$filled = true;
				$echo   = $echo && self::cell( $def, $value ) === (string) $item['title'];
			}
			if ( $filled && ! $echo ) {
				$out[] = $def;
			}
		}
		return $out;
	}

	/**
	 * Whether a group is shown as a table.
	 *
	 * @param list<array<string, mixed>> $columns Columns from columns().
	 */
	public static function asTable( array $columns ): bool {
		if ( count( $columns ) > self::MAX_TABLE_COLUMNS ) {
			return false;
		}
		foreach ( $columns as $def ) {
			if ( in_array( (string) $def['type'], self::WIDE_TYPES, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Text for one value, for people to read.
	 *
	 * @param array<string, mixed> $def   Field definition.
	 * @param mixed                $value Value.
	 */
	public static function cell( array $def, $value ): string {
		$type = (string) ( $def['type'] ?? 'text' );
		if ( 'date' === $type && is_string( $value ) && Dates::isDate( $value ) ) {
			return Dates::displayDate( $value );
		}
		return trim( Display::text( $def, $value ) );
	}

	/**
	 * A value as lines, for fields that read as a list or a paragraph.
	 *
	 * @param array<string, mixed> $def   Field definition.
	 * @param mixed                $value Value.
	 * @return list<string>
	 */
	public static function lines( array $def, $value ): array {
		return Display::lines( $def, $value );
	}

	/**
	 * Whether a field is shown as a list.
	 *
	 * @param array<string, mixed> $def Field definition.
	 */
	public static function isList( array $def ): bool {
		return in_array( (string) ( $def['type'] ?? '' ), array( 'lines', 'links' ), true );
	}

	/**
	 * The way text runs, from its first letter: "rtl" for Arabic, Hebrew and other right-to-left scripts, else "ltr".
	 *
	 * @param string $text Text.
	 */
	public static function direction( string $text ): string {
		if ( 1 === preg_match( '/\p{L}/u', $text, $m ) ) {
			return 1 === preg_match( '/^[\p{Arabic}\p{Hebrew}\p{Syriac}\p{Thaana}\p{Nko}]$/u', $m[0] ) ? 'rtl' : 'ltr';
		}
		return 'ltr';
	}

	/**
	 * The sections that have something to show.
	 *
	 * @param array<string, mixed> $content Pack content.
	 * @return list<array<string, mixed>>
	 */
	public static function sections( array $content ): array {
		return array_values( (array) ( $content['sections'] ?? array() ) );
	}

	/**
	 * Title of the document: the pack title from Settings, or the site name.
	 *
	 * @param array<string, mixed> $content Pack content.
	 * @param PackStyle            $style   Style.
	 */
	public static function title( array $content, PackStyle $style ): string {
		if ( '' !== $style->title ) {
			return $style->title;
		}
		$name = (string) ( $content['site']['name'] ?? '' );
		return '' !== $name ? $name : __( 'Handover Passport', 'estasoft-handover-passport' );
	}

	/**
	 * The sentence that opens the closing list of Sensitive records.
	 *
	 * @param array<string, mixed> $content Pack content.
	 */
	public static function sensitiveSentence( array $content ): string {
		$list = (array) ( $content['sensitive_included'] ?? array() );
		if ( array() === $list ) {
			return __( 'This pack includes no Sensitive records.', 'estasoft-handover-passport' );
		}
		return _n( 'This pack includes this Sensitive record, added on purpose:', 'This pack includes these Sensitive records, added on purpose:', count( $list ), 'estasoft-handover-passport' );
	}
}
