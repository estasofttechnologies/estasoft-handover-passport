<?php
/**
 * The change log as a CSV file (CHG-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Support\Str;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of the change log as text a spreadsheet opens. A cell that starts with a character a spreadsheet reads as a
 * formula gets a quote in front, so it is read as text (SEC-11). Rows are expected to have been through
 * ChangeLog::forViewer() already, so nothing Sensitive reaches this class for someone who may not see it.
 */
final class ChangeCsv {

	/**
	 * The most rows one file holds. More than this is refused, so the person narrows the filter instead of getting a cut-off file.
	 */
	public const LIMIT = 10000;

	/**
	 * Build the file.
	 *
	 * @param array<int, array<string, mixed>> $rows Change-log rows, in the order they should appear.
	 * @return string UTF-8 text with a byte order mark so spreadsheet programs read it as UTF-8.
	 */
	public static function build( array $rows ): string {
		$lines = array( array( __( 'When (UTC)', 'estasoft-handover-passport' ), __( 'What', 'estasoft-handover-passport' ), __( 'Kind', 'estasoft-handover-passport' ), __( 'Who', 'estasoft-handover-passport' ), __( 'Source', 'estasoft-handover-passport' ), __( 'Note', 'estasoft-handover-passport' ) ) );
		foreach ( $rows as $row ) {
			$lines[] = array(
				(string) $row['changed_at'],
				ChangeText::describe( $row ),
				(string) $row['kind'],
				(string) $row['actor_label'],
				(string) $row['source'],
				(string) $row['note'],
			);
		}
		$text = '';
		foreach ( $lines as $line ) {
			$text .= Str::csvLine( array_map( array( Str::class, 'csvCell' ), array_map( 'strval', $line ) ) );
		}
		return "\xEF\xBB\xBF" . $text;
	}
}
