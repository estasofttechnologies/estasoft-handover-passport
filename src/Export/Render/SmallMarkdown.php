<?php
/**
 * The small Markdown subset used in the notes to the successor (HND-7).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Paragraphs, bullet lists, bold, italic, code and web links. The text is escaped before anything is turned into a tag,
 * so nothing the person typed can become markup (SEC-12).
 */
final class SmallMarkdown {

	/**
	 * Turn the text into HTML.
	 *
	 * @param string $text Text with the subset.
	 */
	public static function toHtml( string $text ): string {
		$text   = str_replace( array( "\r\n", "\r" ), "\n", trim( $text ) );
		$blocks = preg_split( '/\n{2,}/', $text ) ?: array();
		$out    = '';
		foreach ( $blocks as $block ) {
			$lines = explode( "\n", trim( $block ) );
			if ( '' === trim( $block ) ) {
				continue;
			}
			$is_list = true;
			foreach ( $lines as $line ) {
				$is_list = $is_list && 1 === preg_match( '/^\s*[-*]\s+\S/', $line );
			}
			if ( $is_list ) {
				$out .= '<ul>';
				foreach ( $lines as $line ) {
					$out .= '<li>' . self::inline( (string) preg_replace( '/^\s*[-*]\s+/', '', $line ) ) . '</li>';
				}
				$out .= '</ul>';
				continue;
			}
			$out .= '<p>' . implode( '<br>', array_map( array( self::class, 'inline' ), $lines ) ) . '</p>';
		}
		return $out;
	}

	/**
	 * Turn the text into plain lines, without the marks, for formats that have no styling.
	 *
	 * @param string $text Text with the subset.
	 */
	public static function toPlain( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", trim( $text ) );
		$text = (string) preg_replace( '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '$1 ($2)', $text );
		$text = (string) preg_replace( '/\*\*(.+?)\*\*/s', '$1', $text );
		$text = (string) preg_replace( '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '$1', $text );
		return (string) preg_replace( '/`([^`]+)`/', '$1', $text );
	}

	/**
	 * One line of inline marks.
	 *
	 * @param string $line Line.
	 */
	private static function inline( string $line ): string {
		$html = htmlspecialchars( $line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$html = (string) preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );
		$html = (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
		$html = (string) preg_replace( '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $html );
		return (string) preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)"\']+)\)/',
			static fn( array $m ): string => '<a href="' . $m[2] . '">' . $m[1] . '</a>',
			$html
		);
	}
}
