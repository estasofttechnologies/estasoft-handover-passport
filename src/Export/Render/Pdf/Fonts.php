<?php
/**
 * Fonts for the PDF (EXP-2).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export\Render\Pdf;

defined( 'ABSPATH' ) || exit;

/**
 * DejaVu Sans ships with the plugin and covers Latin, Cyrillic, Greek, Hebrew and Arabic. A font for Chinese, Japanese
 * or Korean is too large to ship, so a site that needs one puts a TrueType file in the fonts folder under uploads and
 * the PDF takes the missing characters from it.
 */
final class Fonts {

	public const FOLDER = 'estasoft-handover-passport-fonts';

	/**
	 * Folder with the fonts that ship.
	 */
	public static function bundledDir(): string {
		return ESTASOFT_HP_DIR . 'lib/pdf/fonts';
	}

	/**
	 * Folder where a site can add fonts, which may not exist.
	 */
	public static function extraDir(): string {
		$uploads = wp_upload_dir( null, false );
		$dir     = empty( $uploads['basedir'] ) ? '' : rtrim( (string) $uploads['basedir'], '/\\' ) . '/' . self::FOLDER;
		/**
		 * Filters the folder that holds extra fonts for the PDF.
		 *
		 * @param string $dir Folder path.
		 */
		return (string) apply_filters( 'estasoft_hp_pdf_fonts_dir', $dir );
	}

	/**
	 * Extra fonts found in the folder, by family key.
	 *
	 * @return array<string, string> Family key to file name.
	 */
	public static function extras(): array {
		$dir = self::extraDir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return array();
		}
		$out = array();
		foreach ( glob( $dir . '/*.[tT][tT][fF]' ) ?: array() as $path ) {
			$name = basename( $path );
			$key  = 'extra' . strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '', pathinfo( $name, PATHINFO_FILENAME ) ) );
			if ( 'extra' !== $key && ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $name;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Whether a font for Chinese, Japanese or Korean text was added.
	 */
	public static function hasExtras(): bool {
		return array() !== self::extras();
	}

	/**
	 * Whether the text holds characters of a script that the shipped font does not cover.
	 *
	 * @param string $text Text.
	 */
	public static function needsExtraFont( string $text ): bool {
		return 1 === preg_match( '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text );
	}

	/**
	 * The font settings for the library.
	 *
	 * @return array{dirs: list<string>, fontdata: array<string, array<string, mixed>>, backups: list<string>}
	 */
	public static function settings(): array {
		$fontdata = array( 'dejavusans' => array( 'R' => 'DejaVuSans.ttf', 'B' => 'DejaVuSans-Bold.ttf', 'useOTL' => 0xFF, 'useKashida' => 75 ) );
		$dirs     = array( self::bundledDir() );
		$backups  = array( 'dejavusans' );
		$extras   = self::extras();
		if ( array() !== $extras ) {
			$dirs[] = self::extraDir();
			foreach ( $extras as $key => $file ) {
				$fontdata[ $key ] = array( 'R' => $file );
				$backups[]        = $key;
			}
		}
		return array( 'dirs' => $dirs, 'fontdata' => $fontdata, 'backups' => $backups );
	}
}
