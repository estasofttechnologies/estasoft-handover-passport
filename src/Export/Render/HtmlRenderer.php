<?php
/**
 * HTML pack: one file with inline styles (EXP-6), the print view (EXP-7) and the page the PDF is made from (EXP-2).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export\Render;

use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Every piece of text goes through esc() before it reaches the page, and the page holds no script, no remote
 * address and no form (SEC-12).
 */
final class HtmlRenderer implements Renderer {

	public const MODE_FILE  = 'file';
	public const MODE_PRINT = 'print';
	public const MODE_PDF   = 'pdf';

	/**
	 * Whether the pack being written is the one-page sheet.
	 *
	 * @var bool
	 */
	private bool $one_page = false;

	/**
	 * Constructor.
	 *
	 * @param string $mode MODE_FILE for a download, MODE_PRINT for the print view, MODE_PDF for the PDF library.
	 */
	public function __construct( private string $mode = self::MODE_FILE ) {}

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'html';
	}

	/**
	 * {@inheritDoc}
	 */
	public function extension(): string {
		return 'html';
	}

	/**
	 * {@inheritDoc}
	 */
	public function mime(): string {
		return 'text/html; charset=utf-8';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $content, PackStyle $style, string $hash, string $label ): string {
		$title        = Layout::title( $content, $style );
		$one_page     = self::isOnePage( $content );
		$body         = implode( '', $this->parts( $content, $style, $hash, $label ) );
		$print_button = '';
		if ( self::MODE_PRINT === $this->mode ) {
			$print_button = '<p class="noprint muted">' . $this->esc( __( 'Use your browser\'s Print command and choose Save as PDF to keep a copy.', 'estasoft-handover-passport' ) ) . '</p>';
		}
		return '<!DOCTYPE html><html lang="' . $this->esc( $style->language ) . '" dir="' . $this->esc( $style->direction ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="generator" content="Estasoft Handover Passport">'
			. '<title>' . $this->esc( $title ) . '</title><style>' . $this->stylesheet( $style, $one_page ) . '</style></head><body' . ( $one_page ? ' class="onepage"' : '' ) . '>'
			. $print_button . $body . '</body></html>';
	}

	/**
	 * The body in pieces: the cover, the notes, the contents list, each chapter, then the closing lists. The PDF is
	 * written one piece at a time so it can stop within its memory and time budget (EXP-11).
	 *
	 * @param array<string, mixed> $content Pack content.
	 * @param PackStyle            $style   Style.
	 * @param string               $hash    SHA-256 of the canonical content.
	 * @param string               $label   Snapshot name or pack name.
	 * @return array<int, string>
	 */
	public function parts( array $content, PackStyle $style, string $hash, string $label ): array {
		$this->one_page = self::isOnePage( $content );
		$parts          = array( $this->cover( $content, $style, Layout::title( $content, $style ) ) );
		foreach ( array( $this->notes( $content, $style ), $this->contents( $content, $style ) ) as $part ) {
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}
		foreach ( Layout::sections( $content ) as $section ) {
			$parts[] = $this->section( $section, $style );
		}
		foreach ( array( $this->leftOut( $content, $style ), $this->sensitiveList( $content, $style ) ) as $part ) {
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}
		if ( self::MODE_PDF !== $this->mode ) {
			$parts[] = '<div class="foot">' . $this->esc( self::footerLine( $content, $style, $hash, $label ) ) . $this->credit( $style ) . '</div>';
		}
		return $parts;
	}

	/**
	 * Whether the pack is the one-page Emergency sheet.
	 *
	 * @param array<string, mixed> $content Pack content.
	 */
	public static function isOnePage( array $content ): bool {
		return 'emergency' === (string) ( $content['profile']['audience'] ?? '' ) || 'emergency' === (string) ( $content['profile']['key'] ?? '' );
	}

	/**
	 * The line shown at the foot of the file and of every PDF page.
	 *
	 * @param array<string, mixed> $content Pack content.
	 * @param PackStyle            $style   Style.
	 * @param string               $hash    SHA-256 of the canonical content.
	 * @param string               $label   Snapshot name or pack name.
	 */
	public static function footerLine( array $content, PackStyle $style, string $hash, string $label ): string {
		$parts = array();
		if ( '' !== $style->footer_text ) {
			$parts[] = $style->footer_text;
		}
		$parts[] = $label;
		$parts[] = Dates::display( (string) ( $content['generated_at'] ?? '' ), true ) . ' UTC';
		/* translators: %s: the first characters of the SHA-256 hash of the pack. */
		$parts[] = sprintf( __( 'Fingerprint %s', 'estasoft-handover-passport' ), substr( $hash, 0, 16 ) );
		return implode( '  ·  ', array_filter( $parts, static fn( string $p ): bool => '' !== $p ) );
	}

	/**
	 * The credit line, only when it was switched on.
	 *
	 * @param PackStyle $style Style.
	 */
	public function credit( PackStyle $style ): string {
		return $style->credit ? '<br>' . $this->esc( __( 'Made with Estasoft Handover Passport by ESTASOFT TECHNOLOGIES', 'estasoft-handover-passport' ) ) : '';
	}

	/**
	 * Cover: logo, title, kind of pack, site and date.
	 *
	 * @param array<string, mixed> $content Pack content.
	 * @param PackStyle            $style   Style.
	 * @param string               $title   Title.
	 */
	private function cover( array $content, PackStyle $style, string $title ): string {
		$site = (array) ( $content['site'] ?? array() );
		$out  = '<div class="cover">';
		if ( '' !== $style->logo_data_uri ) {
			$out .= '<p><img class="logo" src="' . $this->esc( $style->logo_data_uri ) . '" alt=""></p>';
		}
		$out .= '<h1' . $this->dir( $title, $style ) . '>' . $this->esc( $title ) . '</h1>';
		$out .= '<p class="sub">' . $this->esc( (string) ( $content['profile']['label'] ?? '' ) ) . ' · ' . $this->esc( __( 'Handover Passport', 'estasoft-handover-passport' ) ) . '</p>';
		$rows = array(
			__( 'Site', 'estasoft-handover-passport' )    => (string) ( $site['name'] ?? '' ),
			__( 'Address', 'estasoft-handover-passport' ) => (string) ( $site['url'] ?? '' ),
			__( 'Client', 'estasoft-handover-passport' )  => (string) ( $site['client'] ?? '' ),
			__( 'Made on', 'estasoft-handover-passport' ) => '' === (string) ( $content['generated_at'] ?? '' ) ? '' : Dates::display( (string) $content['generated_at'], true ) . ' UTC',
		);
		$out .= '<table class="facts">';
		foreach ( $rows as $name => $value ) {
			if ( '' !== $value ) {
				$out .= '<tr><th>' . $this->esc( $name ) . '</th><td' . $this->dir( $value, $style ) . '>' . $this->esc( $value ) . '</td></tr>';
			}
		}
		$out .= '</table>';
		if ( '' !== (string) ( $site['purpose'] ?? '' ) ) {
			$out .= '<p' . $this->dir( (string) $site['purpose'], $style ) . '>' . nl2br( $this->esc( (string) $site['purpose'] ), false ) . '</p>';
		}
		if ( '' !== $style->cover_text ) {
			$out .= '<p class="cover-text"' . $this->dir( $style->cover_text, $style ) . '>' . nl2br( $this->esc( $style->cover_text ), false ) . '</p>';
		}
		return $out . '</div>';
	}

	/**
	 * Notes to the successor (HND-7).
	 *
	 * @param array<string, mixed> $content Pack content.
	 * @param PackStyle            $style   Style.
	 */
	private function notes( array $content, PackStyle $style ): string {
		$notes = (string) ( $content['notes'] ?? '' );
		if ( '' === trim( $notes ) ) {
			return '';
		}
		return '<div class="section notes">' . $this->heading( __( 'Notes from the sender', 'estasoft-handover-passport' ), 'notes', $style ) . '<div' . $this->dir( $notes, $style ) . '>' . SmallMarkdown::toHtml( $notes ) . '</div></div>';
	}

	/**
	 * A list of the chapters with links to them. The PDF library makes its own.
	 *
	 * @param array<string, mixed> $content Pack content.
	 * @param PackStyle            $style   Style.
	 */
	private function contents( array $content, PackStyle $style ): string {
		$sections = Layout::sections( $content );
		if ( self::MODE_PDF === $this->mode || count( $sections ) < 3 || 'emergency' === (string) ( $content['profile']['key'] ?? '' ) ) {
			return '';
		}
		$out = '<div class="contents noprint-break"><h2>' . $this->esc( __( 'Contents', 'estasoft-handover-passport' ) ) . '</h2><ol>';
		foreach ( $sections as $section ) {
			$out .= '<li><a href="#s-' . $this->esc( (string) $section['key'] ) . '">' . $this->esc( (string) $section['title'] ) . '</a></li>';
		}
		return $out . '</ol></div>';
	}

	/**
	 * One chapter.
	 *
	 * @param array<string, mixed> $section Chapter.
	 * @param PackStyle            $style   Style.
	 */
	private function section( array $section, PackStyle $style ): string {
		$out = '<div class="section" id="s-' . $this->esc( (string) $section['key'] ) . '">' . $this->heading( (string) $section['title'], (string) $section['key'], $style );
		if ( ! empty( $section['intro'] ) ) {
			$out .= '<p class="muted">' . $this->esc( (string) $section['intro'] ) . '</p>';
		}
		foreach ( (array) ( $section['facts'] ?? array() ) as $fact ) {
			$out .= '<p><strong>' . $this->esc( (string) $fact['label'] ) . ':</strong> ' . $this->esc( (string) $fact['value'] ) . '</p>';
		}
		$groups = (array) $section['groups'];
		foreach ( $groups as $group ) {
			$out .= $this->group( $group, $style, count( $groups ) > 1 );
		}
		return $out . '</div>';
	}

	/**
	 * A heading. In the PDF it also writes a line of the contents.
	 *
	 * @param string    $text  Text.
	 * @param string    $key   Key, for the link.
	 * @param PackStyle $style Style.
	 */
	private function heading( string $text, string $key, PackStyle $style ): string {
		$toc = self::MODE_PDF === $this->mode ? '<tocentry content="' . $this->esc( $text ) . '" level="0" />' : '';
		return $toc . '<h2' . $this->dir( $text, $style ) . '>' . $this->esc( $text ) . '</h2>';
	}

	/**
	 * A group as a table or as cards.
	 *
	 * @param array<string, mixed> $group      Group.
	 * @param PackStyle            $style      Style.
	 * @param bool                 $with_title Whether to show the group title.
	 */
	private function group( array $group, PackStyle $style, bool $with_title ): string {
		$columns = Layout::columns( $group );
		$items   = (array) $group['items'];
		$out     = $with_title ? '<h3' . $this->dir( (string) $group['title'], $style ) . '>' . $this->esc( (string) $group['title'] ) . '</h3>' : '';
		if ( Layout::asTable( $columns ) ) {
			return $out . $this->table( $columns, $items, $style );
		}
		foreach ( $items as $item ) {
			$out .= $this->card( $columns, $item, $style );
		}
		return $out;
	}

	/**
	 * Items as a table, the title first.
	 *
	 * @param array<int, array<string, mixed>> $columns Columns.
	 * @param array<int, array<string, mixed>> $items   Items.
	 * @param PackStyle                        $style   Style.
	 */
	private function table( array $columns, array $items, PackStyle $style ): string {
		$out = '<table class="grid"><thead><tr><th>' . $this->esc( __( 'Name', 'estasoft-handover-passport' ) ) . '</th>';
		foreach ( $columns as $def ) {
			$out .= '<th>' . $this->esc( (string) $def['label'] ) . '</th>';
		}
		$out .= '</tr></thead><tbody>';
		foreach ( $items as $item ) {
			$out .= '<tr><td' . $this->dir( (string) $item['title'], $style ) . '><strong>' . $this->esc( (string) $item['title'] ) . '</strong>' . $this->tag( $item ) . '</td>';
			foreach ( $columns as $def ) {
				$text = Layout::cell( $def, $item['values'][ $def['key'] ] ?? '' );
				$out .= '<td' . $this->dir( $text, $style ) . '>' . $this->esc( $text ) . '</td>';
			}
			$out .= '</tr>';
		}
		return $out . '</tbody></table>';
	}

	/**
	 * One item as a card of labels and values.
	 *
	 * @param array<int, array<string, mixed>> $columns Columns.
	 * @param array<string, mixed>             $item    Item.
	 * @param PackStyle                        $style   Style.
	 */
	private function card( array $columns, array $item, PackStyle $style ): string {
		$out = '<div class="card"><h3' . $this->dir( (string) $item['title'], $style ) . '>' . $this->esc( (string) $item['title'] ) . $this->tag( $item ) . '</h3><table class="kv">';
		foreach ( $columns as $def ) {
			$value = $item['values'][ $def['key'] ] ?? '';
			if ( \Estasoft\HandoverPassport\Export\Display::isEmpty( $value ) ) {
				continue;
			}
			$out .= '<tr><th>' . $this->esc( (string) $def['label'] ) . '</th><td>';
			if ( Layout::isList( $def ) || 'textarea' === (string) $def['type'] ) {
				$lines = Layout::lines( $def, $value );
				if ( Layout::isList( $def ) ) {
					$out .= '<ul>';
					foreach ( $lines as $line ) {
						$out .= '<li' . $this->dir( $line, $style ) . '>' . $this->esc( $line ) . '</li>';
					}
					$out .= '</ul>';
				} else {
					foreach ( $lines as $line ) {
						$out .= '<p' . $this->dir( $line, $style ) . '>' . $this->esc( $line ) . '</p>';
					}
				}
			} else {
				$text = Layout::cell( $def, $value );
				$out .= '<span' . $this->dir( $text, $style ) . '>' . $this->esc( $text ) . '</span>';
			}
			$out .= '</td></tr>';
		}
		return $out . '</table></div>';
	}

	/**
	 * The label on a Sensitive record.
	 *
	 * @param array<string, mixed> $item Item.
	 */
	private function tag( array $item ): string {
		return ! empty( $item['sensitive'] ) ? ' <span class="tag">' . $this->esc( __( 'Sensitive', 'estasoft-handover-passport' ) ) . '</span>' : '';
	}

	/**
	 * What was shortened to fit the page (EXP-1).
	 *
	 * @param array<string, mixed> $content Pack content.
	 * @param PackStyle            $style   Style.
	 */
	private function leftOut( array $content, PackStyle $style ): string {
		$notes = (array) ( $content['left_out'] ?? array() );
		if ( array() === $notes ) {
			return '';
		}
		$out = '<div class="section"><h3>' . $this->esc( __( 'Left off this page', 'estasoft-handover-passport' ) ) . '</h3><ul>';
		foreach ( $notes as $note ) {
			$out .= '<li>' . $this->esc( (string) $note ) . '</li>';
		}
		return $out . '</ul></div>';
	}

	/**
	 * The closing list of Sensitive records, by title only (EXP-4).
	 *
	 * @param array<string, mixed> $content Pack content.
	 * @param PackStyle            $style   Style.
	 */
	private function sensitiveList( array $content, PackStyle $style ): string {
		$list = (array) ( $content['sensitive_included'] ?? array() );
		$out  = '<div class="section' . ( array() === $list ? '' : ' notice' ) . '"><h3>' . $this->esc( __( 'Sensitive records in this pack', 'estasoft-handover-passport' ) ) . '</h3><p>' . $this->esc( Layout::sensitiveSentence( $content ) ) . '</p>';
		if ( array() !== $list ) {
			$out .= '<ul>';
			foreach ( $list as $entry ) {
				$out .= '<li' . $this->dir( (string) $entry['title'], $style ) . '>' . $this->esc( (string) $entry['title'] ) . '</li>';
			}
			$out .= '</ul>';
		}
		return $out . '</div>';
	}

	/**
	 * A dir attribute when the text runs the other way from the page.
	 *
	 * @param string    $text  Text.
	 * @param PackStyle $style Style.
	 */
	private function dir( string $text, PackStyle $style ): string {
		$way = Layout::direction( $text );
		return $way !== $style->direction ? ' dir="' . $way . '"' : '';
	}

	/**
	 * Escape text for HTML.
	 *
	 * @param string $text Text.
	 */
	private function esc( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * The style sheet. The colour was checked as #rrggbb when the style was made.
	 *
	 * @param PackStyle $style    Style.
	 * @param bool      $one_page Whether the page is the one-page Emergency sheet.
	 */
	public function stylesheet( PackStyle $style, bool $one_page ): string {
		$brand = 1 === preg_match( '/^#[0-9a-f]{6}$/i', $style->brand_color ) ? $style->brand_color : '#2f4858';
		$font  = self::MODE_PDF === $this->mode ? '"DejaVu Sans", sans-serif' : 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
		$size  = $one_page ? '9pt' : '10.5pt';
		$start = 'rtl' === $style->direction ? 'right' : 'left';
		$css   = 'body{font-family:' . $font . ';font-size:' . $size . ';color:#1b1f23;line-height:1.45;margin:0 auto;max-width:' . ( self::MODE_FILE === $this->mode ? '900px;padding:24px' : 'none' ) . '}'
			. 'h1{font-size:24pt;color:' . $brand . ';margin:0 0 4pt}'
			. 'h2{font-size:' . ( $one_page ? '11pt' : '15pt' ) . ';color:' . $brand . ';border-bottom:2px solid ' . $brand . ';padding-bottom:3pt;margin:' . ( $one_page ? '10pt 0 4pt' : '22pt 0 8pt' ) . '}'
			. 'h3{font-size:' . ( $one_page ? '9.5pt' : '11.5pt' ) . ';margin:' . ( $one_page ? '6pt 0 2pt' : '12pt 0 4pt' ) . '}'
			. 'p{margin:4pt 0}ul,ol{margin:3pt 0;padding-' . $start . ':18pt}'
			. '.sub{color:#57606a;font-size:12pt;margin:0 0 10pt}.muted{color:#57606a}'
			. '.logo{max-height:60pt;max-width:200pt}'
			. 'table{border-collapse:collapse;width:100%;margin:5pt 0}'
			. 'table.grid th,table.grid td{border:1px solid #c9d1d9;padding:' . ( $one_page ? '2pt 4pt' : '4pt 6pt' ) . ';vertical-align:top;text-align:' . $start . ';font-size:' . ( $one_page ? '8.5pt' : '9.5pt' ) . '}'
			. 'table.grid th{background:#eef1f4}'
			. 'table.facts{width:' . ( self::MODE_PDF === $this->mode ? '100%' : 'auto' ) . '}table.facts th,table.facts td,table.kv th,table.kv td{padding:2pt 8pt 2pt 0;vertical-align:top;text-align:' . $start . ';border:0}'
			. 'table.facts th,table.kv th{color:#57606a;font-weight:normal;width:30%}'
			. '.card{border:1px solid #c9d1d9;padding:6pt 8pt;margin:6pt 0;page-break-inside:avoid}.card h3{margin:0 0 4pt}'
			. '.tag{background:#fbe9e7;color:#8a1c0c;border:1px solid #e6a79c;padding:0 4pt;font-size:8pt;font-weight:normal}'
			. '.notice{border:1px solid #e6a79c;background:#fff6f4;padding:6pt 10pt;margin-top:14pt}'
			. '.cover{margin-bottom:' . ( $one_page ? '6pt' : '18pt' ) . '}.cover-text{font-size:11pt}'
			. '.foot{margin-top:24pt;border-top:1px solid #c9d1d9;padding-top:6pt;font-size:8.5pt;color:#57606a}'
			. 'a{color:' . $brand . '}'
			. ( self::MODE_PDF === $this->mode ? 'h2,h3{page-break-after:avoid}' : '' )
			. '@media print{.noprint{display:none}a{color:inherit;text-decoration:none}tr{page-break-inside:avoid}h2,h3{page-break-after:avoid}body{max-width:none;padding:0}}'
			. ( self::MODE_PDF === $this->mode ? '' : '@page{size:' . ( PackStyle::LETTER === $style->page_size ? 'Letter' : 'A4' ) . ';margin:16mm}' );
		return $css;
	}
}
