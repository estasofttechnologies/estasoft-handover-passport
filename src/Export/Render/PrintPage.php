<?php
/**
 * Pages made to be printed: a runbook, or the after-restore checklist (RUN-7, URL-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export\Render;

use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Large type, one runbook per page, a box to tick beside each step and no interface around it. The page is plain HTML with
 * no script and no outside address, so it prints the same whoever opens it and nothing leaves the browser.
 */
final class PrintPage {

	/**
	 * The whole page around some body text.
	 *
	 * @param string    $title Page title.
	 * @param string    $body  Markup made by this class.
	 * @param PackStyle $style Style.
	 */
	public static function document( string $title, string $body, PackStyle $style ): string {
		return '<!DOCTYPE html><html lang="' . self::esc( $style->language ) . '" dir="' . self::esc( $style->direction ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">'
			. '<meta name="generator" content="Estasoft Handover Passport">'
			. '<title>' . self::esc( $title ) . '</title><style>' . self::stylesheet( $style ) . '</style></head><body>'
			. '<p class="noprint help">' . self::esc( __( 'Use your browser\'s Print command. Choose Save as PDF to keep a copy. This page makes no outside requests.', 'estasoft-handover-passport' ) ) . '</p>'
			. $body . '</body></html>';
	}

	/**
	 * One runbook on its own page.
	 *
	 * @param array<string, mixed> $book       A runbook from RunbookPresenter.
	 * @param PackStyle            $style      Style.
	 * @param string               $site       Name of the site.
	 * @param string               $printed_at Date and time of printing, in UTC.
	 */
	public static function runbook( array $book, PackStyle $style, string $site, string $printed_at ): string {
		$out  = '<section class="book">' . self::head( $style, $site );
		$out .= '<h1>' . self::text( (string) $book['title'] ) . ( ! empty( $book['critical'] ) ? ' <span class="tag">' . self::esc( __( 'Critical', 'estasoft-handover-passport' ) ) . '</span>' : '' ) . '</h1>';
		$out .= '<p class="meta">' . self::esc( implode( '  ·  ', array_filter( self::meta( $book ), static fn( string $part ): bool => '' !== $part ) ) ) . '</p>';
		if ( 'published' !== (string) $book['status'] ) {
			$out .= '<p class="warn">' . self::esc( __( 'Draft. This runbook has not been published yet, so it may be incomplete.', 'estasoft-handover-passport' ) ) . '</p>';
		}
		if ( empty( $book['is_current'] ) ) {
			$out .= '<p class="warn">' . self::esc( __( 'This is an earlier version. Check the Passport for the current one.', 'estasoft-handover-passport' ) ) . '</p>';
		}
		if ( array() !== (array) $book['gaps'] ) {
			$out .= '<p class="warn">' . self::esc( sprintf( /* translators: %s: names of details such as registrar. */ __( 'The Passport does not hold these details yet: %s. Write them in by hand where you see "not recorded".', 'estasoft-handover-passport' ), implode( ', ', (array) $book['gaps'] ) ) ) . '</p>';
		}
		foreach ( array( 'purpose' => __( 'What it is for', 'estasoft-handover-passport' ), 'when_to_use' => __( 'When to use it', 'estasoft-handover-passport' ) ) as $key => $label ) {
			if ( '' !== (string) $book[ $key ] ) {
				$out .= '<h2>' . self::esc( $label ) . '</h2><p>' . self::text( (string) $book[ $key ] ) . '</p>';
			}
		}
		if ( array() !== (array) $book['prerequisites'] ) {
			$out .= '<h2>' . self::esc( __( 'Before you start', 'estasoft-handover-passport' ) ) . '</h2><ul class="ticks">';
			foreach ( (array) $book['prerequisites'] as $row ) {
				$out .= '<li><span class="box"></span> ' . self::text( (string) $row['what'] ) . ( '' !== (string) $row['who'] ? ' <span class="muted">(' . self::text( (string) $row['who'] ) . ')</span>' : '' ) . '</li>';
			}
			$out .= '</ul>';
		}
		$out .= '<h2>' . self::esc( __( 'Steps', 'estasoft-handover-passport' ) ) . '</h2>';
		if ( array() === (array) $book['steps'] ) {
			$out .= '<p class="muted">' . self::esc( __( 'This runbook has no steps yet.', 'estasoft-handover-passport' ) ) . '</p>';
		} else {
			$out .= '<ol class="steps">';
			foreach ( (array) $book['steps'] as $step ) {
				$out .= '<li' . ( ! empty( $step['warning'] ) ? ' class="care"' : '' ) . '><span class="box"></span> ';
				if ( ! empty( $step['warning'] ) ) {
					$out .= '<strong>' . self::esc( __( 'Careful:', 'estasoft-handover-passport' ) ) . '</strong> ';
				}
				$out .= self::text( (string) $step['text'] );
				$bits = array();
				if ( '' !== (string) $step['who'] ) {
					$bits[] = (string) $step['who'];
				}
				if ( (int) $step['minutes'] > 0 ) {
					/* translators: %d: minutes. */
					$bits[] = sprintf( _n( '%d minute', '%d minutes', (int) $step['minutes'], 'estasoft-handover-passport' ), (int) $step['minutes'] );
				}
				if ( '' !== (string) $step['link'] ) {
					$bits[] = (string) $step['link'];
				}
				if ( array() !== $bits ) {
					$out .= '<br><span class="muted small">' . self::esc( implode( '  ·  ', $bits ) ) . '</span>';
				}
				$out .= '</li>';
			}
			$out .= '</ol>';
		}
		if ( array() !== (array) $book['verification'] ) {
			$out .= '<h2>' . self::esc( __( 'How to check it worked', 'estasoft-handover-passport' ) ) . '</h2><ul class="ticks">';
			foreach ( (array) $book['verification'] as $line ) {
				$out .= '<li><span class="box"></span> ' . self::text( (string) $line ) . '</li>';
			}
			$out .= '</ul>';
		}
		if ( '' !== (string) $book['rollback_text'] ) {
			$out .= '<h2>' . self::esc( __( 'If it goes wrong', 'estasoft-handover-passport' ) ) . '</h2><p>' . self::text( (string) $book['rollback_text'] ) . '</p>';
		}
		$out .= '<h2>' . self::esc( __( 'Notes', 'estasoft-handover-passport' ) ) . '</h2><div class="lines"></div>';
		$foot = sprintf(
			/* translators: 1: site name, 2: runbook title, 3: version number, 4: date and time. */
			__( '%1$s  ·  %2$s  ·  version %3$d  ·  printed %4$s UTC. This is a paper copy; the Passport holds the current version.', 'estasoft-handover-passport' ),
			$site,
			(string) $book['title'],
			(int) $book['version'],
			Dates::display( $printed_at, true )
		);
		return $out . '<p class="foot">' . self::esc( $foot ) . ( '' !== $style->footer_text ? '<br>' . self::esc( $style->footer_text ) : '' ) . '</p></section>';
	}

	/**
	 * A checklist on its own page, with a box to tick, a line for the result and a line for who checked.
	 *
	 * @param string                       $title      Heading.
	 * @param string                       $intro      A sentence under the heading.
	 * @param list<array<string, string>>  $items      Items with label, detail and kind.
	 * @param PackStyle                    $style      Style.
	 * @param string                       $site       Name of the site.
	 * @param string                       $printed_at Date and time of printing, in UTC.
	 */
	public static function checklist( string $title, string $intro, array $items, PackStyle $style, string $site, string $printed_at ): string {
		$out  = '<section class="book">' . self::head( $style, $site ) . '<h1>' . self::text( $title ) . '</h1>';
		$out .= '<p>' . self::text( $intro ) . '</p>';
		if ( array() === $items ) {
			$out .= '<p class="muted">' . self::esc( __( 'Nothing to check yet. Add the pages that must work after a restore under Critical addresses.', 'estasoft-handover-passport' ) ) . '</p>';
		} else {
			$out .= '<table class="check"><thead><tr><th scope="col">' . self::esc( __( 'Done', 'estasoft-handover-passport' ) ) . '</th><th scope="col">' . self::esc( __( 'Check', 'estasoft-handover-passport' ) ) . '</th><th scope="col">' . self::esc( __( 'Result or note', 'estasoft-handover-passport' ) ) . '</th></tr></thead><tbody>';
			foreach ( $items as $item ) {
				$out .= '<tr><td class="c"><span class="box"></span></td><td>' . self::text( (string) $item['label'] ) . ( '' !== (string) ( $item['detail'] ?? '' ) ? '<br><span class="muted small">' . self::text( (string) $item['detail'] ) . '</span>' : '' ) . '</td><td></td></tr>';
			}
			$out .= '</tbody></table>';
		}
		$out .= '<p class="sign">' . self::esc( __( 'Checked by', 'estasoft-handover-passport' ) ) . ': ______________________ &nbsp; ' . self::esc( __( 'Date', 'estasoft-handover-passport' ) ) . ': ______________</p>';
		$foot = sprintf(
			/* translators: 1: site name, 2: date and time. */
			__( '%1$s  ·  printed %2$s UTC.', 'estasoft-handover-passport' ),
			$site,
			Dates::display( $printed_at, true )
		);
		return $out . '<p class="foot">' . self::esc( $foot ) . ( '' !== $style->footer_text ? '<br>' . self::esc( $style->footer_text ) : '' ) . '</p></section>';
	}

	/**
	 * The line above a page: logo and site name.
	 *
	 * @param PackStyle $style Style.
	 * @param string    $site  Name of the site.
	 */
	private static function head( PackStyle $style, string $site ): string {
		$logo = '' !== $style->logo_data_uri && 0 === strpos( $style->logo_data_uri, 'data:image/' ) ? '<img class="logo" src="' . self::esc( $style->logo_data_uri ) . '" alt=""> ' : '';
		return '<p class="site">' . $logo . self::esc( $site ) . '</p>';
	}

	/**
	 * Owner, time and version, for the line under the title.
	 *
	 * @param array<string, mixed> $book Runbook.
	 * @return list<string>
	 */
	private static function meta( array $book ): array {
		return array(
			'' !== (string) $book['owner'] ? sprintf( /* translators: %s: role such as Technical maintainer. */ __( 'Done by: %s', 'estasoft-handover-passport' ), (string) $book['owner'] ) : '',
			(int) $book['estimated_minutes'] > 0 ? sprintf( /* translators: %d: minutes. */ _n( 'About %d minute', 'About %d minutes', (int) $book['estimated_minutes'], 'estasoft-handover-passport' ), (int) $book['estimated_minutes'] ) : '',
			sprintf( /* translators: %d: version number. */ __( 'Version %d', 'estasoft-handover-passport' ), (int) $book['version'] ),
			'' !== (string) $book['last_reviewed'] ? sprintf( /* translators: %s: date. */ __( 'Reviewed %s', 'estasoft-handover-passport' ), Dates::displayDate( (string) $book['last_reviewed'] ) ) : __( 'Never reviewed', 'estasoft-handover-passport' ),
			'' !== (string) $book['last_rehearsed'] ? sprintf( /* translators: %s: date. */ __( 'Rehearsed %s', 'estasoft-handover-passport' ), Dates::displayDate( (string) $book['last_rehearsed'] ) ) : __( 'Never rehearsed', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * The style sheet. Text is large on purpose: this is read under pressure.
	 *
	 * @param PackStyle $style Style.
	 */
	private static function stylesheet( PackStyle $style ): string {
		$brand = 1 === preg_match( '/^#[0-9a-f]{6}$/i', $style->brand_color ) ? $style->brand_color : '#2f4858';
		$start = 'rtl' === $style->direction ? 'right' : 'left';
		return 'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;font-size:15pt;line-height:1.5;color:#111;margin:0 auto;max-width:820px;padding:18px}'
			. 'h1{font-size:27pt;line-height:1.2;color:' . $brand . ';margin:6pt 0}h2{font-size:17pt;color:' . $brand . ';border-bottom:2px solid ' . $brand . ';margin:20pt 0 6pt;padding-bottom:2pt}'
			. 'p{margin:6pt 0}.site{font-size:11pt;color:#444;margin:0}.logo{max-height:36pt;max-width:140pt;vertical-align:middle}'
			. '.meta,.muted{color:#444}.small{font-size:11pt}.tag{font-size:11pt;background:#fbe9e7;color:#8a1c0c;border:1px solid #c97a6d;padding:0 5pt;vertical-align:middle}'
			. '.warn{border:2px solid #8a1c0c;background:#fff6f4;padding:6pt 10pt;font-size:13pt}'
			. 'ol.steps,ul.ticks{list-style:none;margin:0;padding:0}ol.steps{counter-reset:s}ol.steps li,ul.ticks li{margin:0 0 9pt;padding-' . $start . ':0}'
			. 'ol.steps li{counter-increment:s}ol.steps li::before{content:counter(s) ".";font-weight:bold;margin-' . ( 'left' === $start ? 'right' : 'left' ) . ':6pt}'
			. 'li.care{border-' . $start . ':6px solid #8a1c0c;padding-' . $start . ':8pt}'
			. '.box{display:inline-block;width:15pt;height:15pt;border:2px solid #111;vertical-align:-2pt;margin-' . ( 'left' === $start ? 'right' : 'left' ) . ':6pt}'
			. 'table.check{border-collapse:collapse;width:100%;margin:8pt 0}table.check th,table.check td{border:1px solid #888;padding:7pt 8pt;text-align:' . $start . ';vertical-align:top}table.check th{background:#eee}table.check td.c{width:42pt;text-align:center}table.check tr{page-break-inside:avoid}'
			. '.lines{height:110pt;background-image:repeating-linear-gradient(transparent,transparent 25pt,#aaa 25pt,#aaa 26pt)}'
			. '.sign{margin-top:22pt}.foot{margin-top:18pt;font-size:9.5pt;color:#555;border-top:1px solid #bbb;padding-top:5pt}'
			. '.help{background:#eef1f4;padding:6pt 10pt;font-size:11pt}'
			. '.book{page-break-after:always}.book:last-of-type{page-break-after:auto}'
			. '@media print{.noprint{display:none}body{max-width:none;padding:0}h2{page-break-after:avoid}ol.steps li,ul.ticks li{page-break-inside:avoid}}'
			. '@page{size:' . ( PackStyle::LETTER === $style->page_size ? 'Letter' : 'A4' ) . ';margin:15mm}';
	}

	/**
	 * Text from a user, escaped, with new lines kept and the reading direction chosen by the text itself.
	 *
	 * @param string $text Text.
	 */
	private static function text( string $text ): string {
		return '<span dir="auto">' . nl2br( self::esc( $text ), false ) . '</span>';
	}

	/**
	 * Escape for HTML.
	 *
	 * @param string $text Text.
	 */
	private static function esc( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
