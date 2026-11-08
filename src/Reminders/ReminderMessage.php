<?php
/**
 * The text of reminder emails (RMD-1, RMD-3, RMD-5). Plain text only.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Reminders;

defined( 'ABSPATH' ) || exit;

/**
 * Builds subjects and bodies from plain values. Nothing here reads the database, and nothing here prints a secret:
 * only a title, a kind, a date and an address of the record on this site.
 */
final class ReminderMessage {

	/**
	 * Single-line text: control characters become spaces, so a title can never add a mail header.
	 *
	 * @param string $text Any text.
	 */
	public static function line( string $text ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text ) );
	}

	/**
	 * "in 14 days", "tomorrow", "today" or "3 days ago".
	 *
	 * @param int $days_left Days from today to the date; negative once it has passed.
	 */
	public static function when( int $days_left ): string {
		if ( 0 === $days_left ) {
			return __( 'today', 'estasoft-handover-passport' );
		}
		if ( 1 === $days_left ) {
			return __( 'tomorrow', 'estasoft-handover-passport' );
		}
		if ( $days_left < 0 ) {
			/* translators: %d: number of days. */
			return sprintf( _n( '%d day ago', '%d days ago', abs( $days_left ), 'estasoft-handover-passport' ), abs( $days_left ) );
		}
		/* translators: %d: number of days. */
		return sprintf( _n( 'in %d day', 'in %d days', $days_left, 'estasoft-handover-passport' ), $days_left );
	}

	/**
	 * The email for one date.
	 *
	 * @param array{site: string, site_url: string, kind: string, title: string, sensitive: bool, date: string, days_left: int, link: string} $p Values.
	 * @return array{subject: string, body: string}
	 */
	public static function due( array $p ): array {
		$title = $p['sensitive'] ? __( 'A record marked Sensitive', 'estasoft-handover-passport' ) : self::line( $p['title'] );
		$when  = self::when( (int) $p['days_left'] );
		$kind  = self::line( $p['kind'] );

		$subject = sprintf(
			/* translators: 1: site name, 2: kind of record, 3: record title, 4: when, for example "in 14 days". */
			__( '[%1$s] %2$s: %3$s, %4$s', 'estasoft-handover-passport' ),
			self::line( $p['site'] ),
			$kind,
			$title,
			$when
		);

		$lines   = array();
		$lines[] = __( 'Hello,', 'estasoft-handover-passport' );
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: 1: site name, 2: site address. */
			__( 'This is a reminder from the Handover Passport of %1$s (%2$s).', 'estasoft-handover-passport' ),
			self::line( $p['site'] ),
			self::line( $p['site_url'] )
		);
		$lines[] = '';
		/* translators: %s: kind of record, for example Licence. */
		$lines[] = sprintf( __( 'What: %s', 'estasoft-handover-passport' ), $kind );
		/* translators: %s: record title. */
		$lines[] = sprintf( __( 'Name: %s', 'estasoft-handover-passport' ), $title );
		/* translators: 1: date, 2: when, for example "in 14 days". */
		$lines[] = sprintf( __( 'Date: %1$s (%2$s)', 'estasoft-handover-passport' ), self::line( $p['date'] ), $when );
		$lines[] = '';
		if ( '' !== $p['link'] ) {
			/* translators: %s: address of the record in the site's admin area. */
			$lines[] = sprintf( __( 'Open the record (you need to sign in to the site): %s', 'estasoft-handover-passport' ), self::line( $p['link'] ) );
			$lines[] = '';
		}
		$lines[] = __( 'You receive this because your address is on the reminder list for this site, or because you are listed as the account holder or payer of this record. The person who looks after the site can change this in Handover Passport, Settings, Reminders.', 'estasoft-handover-passport' );
		$lines[] = '';
		$lines[] = __( 'Estasoft Handover Passport', 'estasoft-handover-passport' );

		return array( 'subject' => $subject, 'body' => implode( "\n", $lines ) );
	}

	/**
	 * The weekly digest (RMD-3).
	 *
	 * @param array{site: string, site_url: string, dates: list<array{date: string, kind: string, title: string, days_left: int}>, needs_fixing: int, changes: int, since: string, overview: string, findings: string, history: string} $p Values.
	 * @return array{subject: string, body: string}
	 */
	public static function digest( array $p ): array {
		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Weekly summary from the Handover Passport', 'estasoft-handover-passport' ),
			self::line( $p['site'] )
		);

		$lines   = array();
		$lines[] = __( 'Hello,', 'estasoft-handover-passport' );
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: 1: site name, 2: site address. */
			__( 'This is the weekly summary for %1$s (%2$s).', 'estasoft-handover-passport' ),
			self::line( $p['site'] ),
			self::line( $p['site_url'] )
		);
		$lines[] = '';
		$lines[] = __( 'Dates in the next 30 days', 'estasoft-handover-passport' );
		if ( array() === $p['dates'] ) {
			$lines[] = __( '  None entered.', 'estasoft-handover-passport' );
		}
		foreach ( $p['dates'] as $row ) {
			$lines[] = sprintf(
				/* translators: 1: date, 2: kind of record, 3: record title, 4: when, for example "in 14 days". */
				__( '  %1$s  %2$s: %3$s (%4$s)', 'estasoft-handover-passport' ),
				self::line( $row['date'] ),
				self::line( $row['kind'] ),
				self::line( $row['title'] ),
				self::when( (int) $row['days_left'] )
			);
		}
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %d: number of findings. */
			_n( '%d finding needs fixing.', '%d findings need fixing.', (int) $p['needs_fixing'], 'estasoft-handover-passport' ),
			(int) $p['needs_fixing']
		);
		if ( '' !== $p['since'] ) {
			$lines[] = sprintf(
				/* translators: 1: number of changes, 2: date of the last summary. */
				_n( '%1$d change was recorded since %2$s.', '%1$d changes were recorded since %2$s.', (int) $p['changes'], 'estasoft-handover-passport' ),
				(int) $p['changes'],
				self::line( $p['since'] )
			);
		} else {
			$lines[] = sprintf(
				/* translators: %d: number of changes. */
				_n( '%d change is in the change log.', '%d changes are in the change log.', (int) $p['changes'], 'estasoft-handover-passport' ),
				(int) $p['changes']
			);
		}
		$lines[] = '';
		foreach ( array( 'overview', 'findings', 'history' ) as $key ) {
			if ( '' !== $p[ $key ] ) {
				$lines[] = self::line( $p[ $key ] );
			}
		}
		$lines[] = '';
		$lines[] = __( 'The person who looks after the site can switch this summary off in Handover Passport, Settings, Reminders.', 'estasoft-handover-passport' );
		$lines[] = '';
		$lines[] = __( 'Estasoft Handover Passport', 'estasoft-handover-passport' );

		return array( 'subject' => $subject, 'body' => implode( "\n", $lines ) );
	}

	/**
	 * The test email (RMD-5).
	 *
	 * @param array{site: string, site_url: string} $p Values.
	 * @return array{subject: string, body: string}
	 */
	public static function test( array $p ): array {
		return array(
			'subject' => sprintf(
				/* translators: %s: site name. */
				__( '[%s] Test email from the Handover Passport', 'estasoft-handover-passport' ),
				self::line( $p['site'] )
			),
			'body'    => implode(
				"\n",
				array(
					__( 'Hello,', 'estasoft-handover-passport' ),
					'',
					sprintf(
						/* translators: 1: site name, 2: site address. */
						__( 'This test email was sent from the Handover Passport of %1$s (%2$s). If you can read it, reminders from this site can reach this address.', 'estasoft-handover-passport' ),
						self::line( $p['site'] ),
						self::line( $p['site_url'] )
					),
					'',
					__( 'Estasoft Handover Passport', 'estasoft-handover-passport' ),
				)
			),
		);
	}
}
