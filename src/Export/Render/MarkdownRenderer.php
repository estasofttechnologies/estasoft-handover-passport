<?php
/**
 * Markdown pack (EXP-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export\Render;

use Estasoft\HandoverPassport\Export\Display;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Plain text with light marks. Characters that a Markdown reader could turn into markup or a table break are escaped.
 */
final class MarkdownRenderer implements Renderer {

	public function key(): string {
		return 'md';
	}

	public function extension(): string {
		return 'md';
	}

	public function mime(): string {
		return 'text/markdown; charset=utf-8';
	}

	public function render( array $content, PackStyle $style, string $hash, string $label ): string {
		$site  = (array) ( $content['site'] ?? array() );
		$lines = array( '# ' . $this->inline( Layout::title( $content, $style ) ), '' );
		$lines[] = '*' . $this->inline( (string) ( $content['profile']['label'] ?? '' ) ) . ' · ' . $this->inline( __( 'Handover Passport', 'estasoft-handover-passport' ) ) . '*';
		$lines[] = '';
		foreach ( array( __( 'Site', 'estasoft-handover-passport' ) => $site['name'] ?? '', __( 'Address', 'estasoft-handover-passport' ) => $site['url'] ?? '', __( 'Client', 'estasoft-handover-passport' ) => $site['client'] ?? '' ) as $name => $value ) {
			if ( '' !== (string) $value ) {
				$lines[] = '- **' . $this->inline( $name ) . ':** ' . $this->inline( (string) $value );
			}
		}
		if ( '' !== (string) ( $content['generated_at'] ?? '' ) ) {
			$lines[] = '- **' . $this->inline( __( 'Made on', 'estasoft-handover-passport' ) ) . ':** ' . $this->inline( Dates::display( (string) $content['generated_at'], true ) . ' UTC' );
		}
		$lines[] = '';
		foreach ( array( (string) ( $site['purpose'] ?? '' ), $style->cover_text ) as $paragraph ) {
			if ( '' !== $paragraph ) {
				$lines[] = $this->block( $paragraph );
				$lines[] = '';
			}
		}
		if ( '' !== trim( (string) ( $content['notes'] ?? '' ) ) ) {
			$lines[] = '## ' . $this->inline( __( 'Notes from the sender', 'estasoft-handover-passport' ) );
			$lines[] = '';
			$lines[] = $this->notes( (string) $content['notes'] );
			$lines[] = '';
		}
		foreach ( Layout::sections( $content ) as $section ) {
			$lines[] = '## ' . $this->inline( (string) $section['title'] );
			$lines[] = '';
			if ( ! empty( $section['intro'] ) ) {
				$lines[] = $this->block( (string) $section['intro'] );
				$lines[] = '';
			}
			foreach ( (array) ( $section['facts'] ?? array() ) as $fact ) {
				$lines[] = '- **' . $this->inline( (string) $fact['label'] ) . ':** ' . $this->inline( (string) $fact['value'] );
			}
			if ( ! empty( $section['facts'] ) ) {
				$lines[] = '';
			}
			$groups = (array) $section['groups'];
			foreach ( $groups as $group ) {
				if ( count( $groups ) > 1 ) {
					$lines[] = '### ' . $this->inline( (string) $group['title'] );
					$lines[] = '';
				}
				foreach ( $this->group( $group ) as $line ) {
					$lines[] = $line;
				}
				$lines[] = '';
			}
		}
		foreach ( (array) ( $content['left_out'] ?? array() ) as $note ) {
			$lines[] = '- ' . $this->inline( (string) $note );
		}
		$lines[] = '## ' . $this->inline( __( 'Sensitive records in this pack', 'estasoft-handover-passport' ) );
		$lines[] = '';
		$lines[] = $this->inline( Layout::sensitiveSentence( $content ) );
		foreach ( (array) ( $content['sensitive_included'] ?? array() ) as $entry ) {
			$lines[] = '- ' . $this->inline( (string) $entry['title'] );
		}
		$lines[] = '';
		$lines[] = '---';
		$lines[] = $this->inline( HtmlRenderer::footerLine( $content, $style, $hash, $label ) );
		if ( $style->credit ) {
			$lines[] = '';
			$lines[] = $this->inline( __( 'Made with Estasoft Handover Passport by ESTASOFT TECHNOLOGIES', 'estasoft-handover-passport' ) );
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * One group as a table, or as a list of items when it is wide.
	 *
	 * @param array<string, mixed> $group Group.
	 * @return list<string>
	 */
	private function group( array $group ): array {
		$columns = Layout::columns( $group );
		$items   = (array) $group['items'];
		$out     = array();
		if ( Layout::asTable( $columns ) ) {
			$head  = array( __( 'Name', 'estasoft-handover-passport' ) );
			foreach ( $columns as $def ) {
				$head[] = (string) $def['label'];
			}
			$out[] = '| ' . implode( ' | ', array_map( array( $this, 'cell' ), $head ) ) . ' |';
			$out[] = '|' . str_repeat( ' --- |', count( $head ) );
			foreach ( $items as $item ) {
				$row = array( (string) $item['title'] . ( ! empty( $item['sensitive'] ) ? ' (' . __( 'Sensitive', 'estasoft-handover-passport' ) . ')' : '' ) );
				foreach ( $columns as $def ) {
					$row[] = Layout::cell( $def, $item['values'][ $def['key'] ] ?? '' );
				}
				$out[] = '| ' . implode( ' | ', array_map( array( $this, 'cell' ), $row ) ) . ' |';
			}
			return $out;
		}
		foreach ( $items as $item ) {
			$out[] = '#### ' . $this->inline( (string) $item['title'] ) . ( ! empty( $item['sensitive'] ) ? ' (' . $this->inline( __( 'Sensitive', 'estasoft-handover-passport' ) ) . ')' : '' );
			$out[] = '';
			foreach ( $columns as $def ) {
				$value = $item['values'][ $def['key'] ] ?? '';
				if ( Display::isEmpty( $value ) ) {
					continue;
				}
				if ( Layout::isList( $def ) || 'textarea' === (string) $def['type'] ) {
					$out[] = '- **' . $this->inline( (string) $def['label'] ) . ':**';
					foreach ( Layout::lines( $def, $value ) as $line ) {
						$out[] = '  - ' . $this->inline( $line );
					}
				} else {
					$out[] = '- **' . $this->inline( (string) $def['label'] ) . ':** ' . $this->inline( Layout::cell( $def, $value ) );
				}
			}
			$out[] = '';
		}
		return $out;
	}

	/**
	 * Text for one table cell: marks escaped, line breaks as <br>.
	 *
	 * @param string $text Text.
	 */
	private function cell( string $text ): string {
		return str_replace( array( "\r\n", "\r", "\n" ), '<br>', $this->inline( $text ) );
	}

	/**
	 * Text on one line with the marks that could change its meaning escaped.
	 *
	 * @param string $text Text.
	 */
	private function inline( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
		return (string) preg_replace( '/([\\\\`*_\[\]|#])/', '\\\\$1', $text );
	}

	/**
	 * A paragraph, with its line breaks kept.
	 *
	 * @param string $text Text.
	 */
	private function block( string $text ): string {
		return implode( "  \n", array_map( array( $this, 'inline' ), explode( "\n", str_replace( "\r\n", "\n", $text ) ) ) );
	}

	/**
	 * Notes from the sender. They are already in the small Markdown subset, so the marks stay, but tags are escaped.
	 *
	 * @param string $text Notes.
	 */
	private function notes( string $text ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), str_replace( array( "\r\n", "\r" ), "\n", trim( $text ) ) );
	}
}
