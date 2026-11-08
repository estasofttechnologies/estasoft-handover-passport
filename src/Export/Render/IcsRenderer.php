<?php
/**
 * Calendar pack in iCalendar format: dated items only (EXP-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export\Render;

use Estasoft\HandoverPassport\Support\Dates;
use Estasoft\HandoverPassport\Support\Str;

defined( 'ABSPATH' ) || exit;

/**
 * One all-day event for each item that has a date: renewals, expiries and reviews. The text is escaped and the lines
 * folded as RFC 5545 asks.
 */
final class IcsRenderer implements Renderer {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'ics';
	}

	/**
	 * {@inheritDoc}
	 */
	public function extension(): string {
		return 'ics';
	}

	/**
	 * {@inheritDoc}
	 */
	public function mime(): string {
		return 'text/calendar; charset=utf-8';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $content, PackStyle $style, string $hash, string $label ): string {
		$host  = (string) wp_parse_url( (string) ( $content['site']['url'] ?? '' ), PHP_URL_HOST );
		$host  = '' === $host ? 'estasoft-handover-passport.invalid' : $host;
		$stamp = $this->stamp( (string) ( $content['generated_at'] ?? '' ) );
		$site  = (string) ( $content['site']['name'] ?? '' );
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//ESTASOFT TECHNOLOGIES//Estasoft Handover Passport//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . $this->escape( '' !== $site ? $site . ' - ' . __( 'dates', 'estasoft-handover-passport' ) : __( 'Handover Passport dates', 'estasoft-handover-passport' ) ),
		);
		foreach ( Layout::sections( $content ) as $section ) {
			foreach ( (array) $section['groups'] as $group ) {
				foreach ( (array) $group['items'] as $item ) {
					$date = (string) ( $item['date'] ?? '' );
					if ( ! Dates::isDate( $date ) ) {
						continue;
					}
					$what    = '' !== (string) ( $item['date_label'] ?? '' ) ? (string) $item['title'] . ' - ' . (string) $item['date_label'] : (string) $item['title'];
					$lines[] = 'BEGIN:VEVENT';
					$lines[] = 'UID:' . sha1( (string) $section['key'] . '|' . (string) $item['ref'] . '|' . $date ) . '@' . $host;
					$lines[] = 'DTSTAMP:' . $stamp;
					$lines[] = 'DTSTART;VALUE=DATE:' . str_replace( '-', '', $date );
					$lines[] = 'DTEND;VALUE=DATE:' . str_replace( '-', '', Dates::addDays( $date, 1 ) );
					$lines[] = 'SUMMARY:' . $this->escape( $what );
					$lines[] = 'DESCRIPTION:' . $this->escape( (string) $section['title'] . ( '' !== $site ? ', ' . $site : '' ) );
					$lines[] = 'TRANSP:TRANSPARENT';
					$lines[] = 'END:VEVENT';
				}
			}
		}
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", array_map( array( $this, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * Time stamp in the form a calendar reads, in UTC.
	 *
	 * @param string $sql UTC date-time as stored.
	 */
	private function stamp( string $sql ): string {
		$time = strtotime( $sql . ' UTC' );
		return gmdate( 'Ymd\THis\Z', false === $time ? 0 : $time );
	}

	/**
	 * Escape a text value.
	 *
	 * @param string $text Text.
	 */
	private function escape( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\;', '\,', '\n' ), $text );
	}

	/**
	 * Fold a line at 75 bytes without cutting a character in two.
	 *
	 * @param string $line Line.
	 */
	private function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out   = '';
		$chunk = '';
		$limit = 75;
		foreach ( Str::split( '//u', $line, PREG_SPLIT_NO_EMPTY ) as $char ) {
			if ( strlen( $chunk ) + strlen( $char ) > $limit ) {
				$out  .= $chunk . "\r\n ";
				$chunk = '';
				$limit = 74;
			}
			$chunk .= $char;
		}
		return $out . $chunk;
	}
}
