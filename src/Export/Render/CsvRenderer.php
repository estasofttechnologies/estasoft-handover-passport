<?php
/**
 * CSV pack: one file per register, zipped (EXP-6, SEC-11).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export\Render;

use Estasoft\HandoverPassport\Export\Display;
use Estasoft\HandoverPassport\Support\Str;
use Estasoft\HandoverPassport\Support\TempFiles;

defined( 'ABSPATH' ) || exit;

/**
 * Each group becomes a file with the field labels as its first row. A cell that starts with a character a spreadsheet
 * reads as a formula gets a quote in front, so it is read as text.
 */
final class CsvRenderer implements Renderer {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'csv';
	}

	/**
	 * {@inheritDoc}
	 */
	public function extension(): string {
		return 'zip';
	}

	/**
	 * {@inheritDoc}
	 */
	public function mime(): string {
		return 'application/zip';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws RenderFailed When the file cannot be made.
	 */
	public function render( array $content, PackStyle $style, string $hash, string $label ): string {
		$files = $this->files( $content, $style, $hash, $label );
		if ( ! class_exists( \ZipArchive::class ) ) {
			throw new RenderFailed( __( 'This server cannot make zip files. Download the JSON, HTML or Markdown pack instead.', 'estasoft-handover-passport' ) );
		}
		$path = TempFiles::create( '.zip' );
		if ( '' === $path ) {
			throw new RenderFailed( __( 'No safe place to build the file could be made. Download the JSON, HTML or Markdown pack instead.', 'estasoft-handover-passport' ) );
		}
		try {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $path, \ZipArchive::OVERWRITE ) ) {
				throw new RenderFailed( __( 'The zip file could not be made. Download the JSON, HTML or Markdown pack instead.', 'estasoft-handover-passport' ) );
			}
			foreach ( $files as $name => $body ) {
				$zip->addFromString( $name, $body );
			}
			if ( ! $zip->close() ) {
				throw new RenderFailed( __( 'The zip file could not be written. Download the JSON, HTML or Markdown pack instead.', 'estasoft-handover-passport' ) );
			}
			$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file this plugin just made in its private folder.
			if ( false === $bytes ) {
				throw new RenderFailed( __( 'The zip file could not be read. Download the JSON, HTML or Markdown pack instead.', 'estasoft-handover-passport' ) );
			}
			return $bytes;
		} finally {
			TempFiles::discard( $path );
		}
	}

	/**
	 * The files that go in the zip, by name.
	 *
	 * @param array<string, mixed> $content Pack content.
	 * @param PackStyle            $style   Style.
	 * @param string               $hash    Hash of the content.
	 * @param string               $label   Name shown in the readme.
	 * @return array<string, string>
	 */
	public function files( array $content, PackStyle $style, string $hash, string $label ): array {
		$files = array( 'README.txt' => $this->readme( $content, $style, $hash, $label ) );
		$n     = 0;
		foreach ( Layout::sections( $content ) as $section ) {
			foreach ( (array) $section['groups'] as $group ) {
				++$n;
				$name           = sprintf( '%02d-%s-%s.csv', $n, $this->slug( (string) $section['key'] ), $this->slug( (string) $group['key'] ) );
				$files[ $name ] = $this->csv( $group );
			}
		}
		if ( '' !== trim( (string) ( $content['notes'] ?? '' ) ) ) {
			$files['notes-from-the-sender.txt'] = SmallMarkdown::toPlain( (string) $content['notes'] ) . "\n";
		}
		return $files;
	}

	/**
	 * One group as CSV text, with a byte order mark so spreadsheet programs read it as UTF-8.
	 *
	 * @param array<string, mixed> $group Group.
	 */
	private function csv( array $group ): string {
		$fields = (array) ( $group['fields'] ?? array() );
		$head   = array( __( 'Name', 'estasoft-handover-passport' ) );
		foreach ( $fields as $def ) {
			$head[] = (string) $def['label'];
		}
		$head[] = __( 'Sensitive record', 'estasoft-handover-passport' );
		$rows   = array( $head );
		foreach ( (array) $group['items'] as $item ) {
			$row = array( (string) $item['title'] );
			foreach ( $fields as $def ) {
				$row[] = $this->value( $def, $item['values'][ $def['key'] ] ?? '' );
			}
			$row[]  = ! empty( $item['sensitive'] ) ? __( 'Yes', 'estasoft-handover-passport' ) : '';
			$rows[] = $row;
		}
		$text = '';
		foreach ( $rows as $row ) {
			$text .= Str::csvLine( array_map( array( self::class, 'safe' ), $row ) );
		}
		return "\xEF\xBB\xBF" . $text;
	}

	/**
	 * A value as one cell: lists joined with a semicolon, dates in ISO form so a spreadsheet can sort them.
	 *
	 * @param array<string, mixed> $def   Field definition.
	 * @param mixed                $value Value.
	 */
	private function value( array $def, $value ): string {
		if ( Display::isEmpty( $value ) ) {
			return '';
		}
		if ( Layout::isList( $def ) ) {
			return implode( '; ', Layout::lines( $def, $value ) );
		}
		if ( 'date' === (string) $def['type'] || 'int' === (string) $def['type'] ) {
			return trim( Display::text( array( 'type' => 'text' ), $value ) );
		}
		return trim( Display::text( $def, $value ) );
	}

	/**
	 * Keep a spreadsheet from reading a cell as a formula (SEC-11).
	 *
	 * @param string $cell Cell text.
	 */
	public static function safe( string $cell ): string {
		if ( '' !== $cell && false !== strpos( "=+-@\t\r\n", $cell[0] ) ) {
			return "'" . $cell;
		}
		return $cell;
	}

	/**
	 * The readme in the zip: what the files are, the fingerprint and the Sensitive records.
	 *
	 * @param array<string, mixed> $content Pack content.
	 * @param PackStyle            $style   Style.
	 * @param string               $hash    Hash.
	 * @param string               $label   Name.
	 */
	private function readme( array $content, PackStyle $style, string $hash, string $label ): string {
		$lines   = array();
		$lines[] = Layout::title( $content, $style );
		$lines[] = (string) ( $content['profile']['label'] ?? '' ) . ' - ' . __( 'Handover Passport', 'estasoft-handover-passport' );
		$lines[] = '';
		$lines[] = __( 'One file for each register. The first row of each file holds the field names. Open them as UTF-8.', 'estasoft-handover-passport' );
		$lines[] = '';
		$lines[] = Layout::sensitiveSentence( $content );
		foreach ( (array) ( $content['sensitive_included'] ?? array() ) as $entry ) {
			$lines[] = '- ' . (string) $entry['title'];
		}
		foreach ( (array) ( $content['left_out'] ?? array() ) as $note ) {
			$lines[] = (string) $note;
		}
		$lines[] = '';
		$lines[] = HtmlRenderer::footerLine( $content, $style, $hash, $label );
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * A safe part of a file name.
	 *
	 * @param string $text Text.
	 */
	private function slug( string $text ): string {
		$slug = strtolower( (string) preg_replace( '/[^a-zA-Z0-9]+/', '-', $text ) );
		$slug = trim( $slug, '-' );
		return '' === $slug ? 'data' : substr( $slug, 0, 40 );
	}
}
