<?php
/**
 * PDF pack made on the server with the bundled library (EXP-2, EXP-11, SEC-10, SEC-12).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export\Render;

use Estasoft\HandoverPassport\Export\Render\Pdf\Fonts;
use Estasoft\HandoverPassport\Export\Render\Pdf\NoNetwork;
use Estasoft\HandoverPassport\Export\Render\Pdf\PluginFilesOnly;
use Estasoft\HandoverPassport\Support\TempFiles;

defined( 'ABSPATH' ) || exit;

/**
 * The page comes from HtmlRenderer in its PDF form and is written to the library one piece at a time. The library may
 * not use the network or read files outside the plugin, works in a private folder that is deleted afterwards, and stops
 * when it runs short of memory or time instead of taking the site down.
 */
final class PdfRenderer implements Renderer {

	/**
	 * Share of the memory limit the PDF may use before it stops.
	 */
	private const MEMORY_SHARE = 0.8;

	/**
	 * Seconds the PDF may take when the server sets no limit of its own.
	 */
	private const DEFAULT_SECONDS = 60;

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'pdf';
	}

	/**
	 * {@inheritDoc}
	 */
	public function extension(): string {
		return 'pdf';
	}

	/**
	 * {@inheritDoc}
	 */
	public function mime(): string {
		return 'application/pdf';
	}

	/**
	 * Why a PDF cannot be made here, or an empty string when it can.
	 */
	public static function unavailable(): string {
		if ( ! extension_loaded( 'gd' ) || ! extension_loaded( 'mbstring' ) ) {
			return __( 'The PHP extensions GD and mbstring are needed and one is missing.', 'estasoft-handover-passport' );
		}
		if ( ! is_file( ESTASOFT_HP_DIR . 'lib/pdf/autoload.php' ) || ! is_file( Fonts::bundledDir() . '/DejaVuSans.ttf' ) ) {
			return __( 'The PDF library is not in this copy of the plugin.', 'estasoft-handover-passport' );
		}
		if ( '' === TempFiles::dir() ) {
			return __( 'There is no safe place to build the file: the uploads folder cannot be written.', 'estasoft-handover-passport' );
		}
		return '';
	}

	/**
	 * Things the person should know about the PDF of this content.
	 *
	 * @param array<string, mixed> $content Pack content.
	 * @return array<int, string>
	 */
	public static function warnings( array $content ): array {
		$text = (string) json_encode( $content, JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Only searched, never output.
		if ( Fonts::needsExtraFont( $text ) && ! Fonts::hasExtras() ) {
			return array( __( 'This pack holds Chinese, Japanese or Korean text. The PDF shows empty boxes for it because no font for those scripts is installed. Add a TrueType font to the fonts folder described in the readme, or use the HTML pack.', 'estasoft-handover-passport' ) );
		}
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws RenderFailed When the file cannot be made.
	 */
	public function render( array $content, PackStyle $style, string $hash, string $label ): string {
		$why = self::unavailable();
		if ( '' !== $why ) {
			throw new RenderFailed( $why . ' ' . $this->fallback() );
		}
		$work = TempFiles::createDir();
		if ( '' === $work ) {
			throw new RenderFailed( __( 'There is no safe place to build the file.', 'estasoft-handover-passport' ) . ' ' . $this->fallback() );
		}
		$started    = microtime( true );
		$old_errors = error_reporting(); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Only read, to put it back afterwards.
		// The library is written for older PHP versions and may report deprecations; they must not end up in the file.
		error_reporting( $old_errors & ~( E_DEPRECATED | E_USER_DEPRECATED ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Library notices must not corrupt the file.
		try {
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'admin' );
			}
			require_once ESTASOFT_HP_DIR . 'lib/pdf/autoload.php';
			return $this->build( $content, $style, $hash, $label, $work, $started );
		} catch ( RenderFailed $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			throw new RenderFailed( __( 'The PDF library stopped before the file was finished.', 'estasoft-handover-passport' ) . ' ' . $this->fallback(), 0, $e );
		} finally {
			error_reporting( $old_errors ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Put back what was changed.
			TempFiles::discardDir( $work );
		}
	}

	/**
	 * Write the document.
	 *
	 * @param array<string, mixed> $content Pack content.
	 * @param PackStyle            $style   Style.
	 * @param string               $hash    SHA-256 of the content.
	 * @param string               $label   Name in the footer.
	 * @param string               $work    Private working folder.
	 * @param float                $started When the work began.
	 * @throws RenderFailed When the budget runs out.
	 */
	private function build( array $content, PackStyle $style, string $hash, string $label, string $work, float $started ): string {
		$fonts    = Fonts::settings();
		$html     = new HtmlRenderer( HtmlRenderer::MODE_PDF );
		$one_page = HtmlRenderer::isOnePage( $content );
		$margin   = $one_page ? 12 : 16;
		$config   = array(
			'mode'                       => 'utf-8',
			'format'                     => PackStyle::LETTER === $style->page_size ? 'Letter' : 'A4',
			'tempDir'                    => $work,
			'fontDir'                    => $fonts['dirs'],
			'fontdata'                   => $fonts['fontdata'],
			'default_font'               => 'dejavusans',
			'default_font_size'          => $one_page ? 9 : 10,
			'backupSubsFont'             => $fonts['backups'],
			'useSubstitutions'           => true,
			'autoScriptToLang'           => true,
			'autoLangToFont'             => false,
			'allow_output_buffering'     => false,
			'margin_left'                => $margin,
			'margin_right'               => $margin,
			'margin_top'                 => $margin,
			'margin_bottom'              => $margin + 6,
			'margin_footer'              => 6,
			'shrink_tables_to_fit'       => 1,
			'showImageErrors'            => false,
			'enableImports'              => false,
			'allowAnnotationFiles'       => false,
			'curlAllowUnsafeSslRequests' => false,
			'debug'                      => false,
			'debugfonts'                 => false,
			'whitelistStreamWrappers'    => array( 'file' ),
		);
		/**
		 * Filters the settings given to the PDF library. A filter cannot turn off the limits on the network and on
		 * the files the library may read; they are set after it runs.
		 *
		 * @param array<string, mixed> $config Library settings.
		 * @param array<string, mixed> $content Pack content.
		 */
		$config                  = (array) apply_filters( 'estasoft_hp_pdf_config', $config, $content );
		$config['tempDir']       = $work;
		$config['enableImports'] = false;

		$container = new \Estasoft\HandoverPassport\Vendor\Mpdf\Container\SimpleContainer(
			array(
				'httpClient'         => new NoNetwork(),
				'localContentLoader' => new PluginFilesOnly( array( ESTASOFT_HP_DIR . 'lib/pdf', $fonts['dirs'][ count( $fonts['dirs'] ) - 1 ] ) ),
			)
		);
		$mpdf      = new \Estasoft\HandoverPassport\Vendor\Mpdf\Mpdf( $config, $container );
		$mpdf->SetTitle( Layout::title( $content, $style ) );
		$mpdf->SetAuthor( (string) ( $content['site']['name'] ?? '' ) );
		$mpdf->SetSubject( (string) ( $content['profile']['label'] ?? '' ) );
		$mpdf->SetCreator( 'Estasoft Handover Passport' );
		$mpdf->SetCompression( true );
		$mpdf->SetDisplayMode( 'fullpage' );
		if ( 'rtl' === $style->direction ) {
			$mpdf->SetDirectionality( 'rtl' );
		}
		$mpdf->SetHTMLFooter( $this->footer( HtmlRenderer::footerLine( $content, $style, $hash, $label ) . ( $style->credit ? '  ·  ' . __( 'Made with Estasoft Handover Passport by ESTASOFT TECHNOLOGIES', 'estasoft-handover-passport' ) : '' ), $style ) );

		$mpdf->WriteHTML( $html->stylesheet( $style, $one_page ), \Estasoft\HandoverPassport\Vendor\Mpdf\HTMLParserMode::HEADER_CSS );
		$limit     = $this->memoryBudget();
		$with_toc  = ! $one_page && count( Layout::sections( $content ) ) > 1;
		$toc_added = false;
		foreach ( $html->parts( $content, $style, $hash, $label ) as $part ) {
			// The contents list goes after the cover and any notes, in front of the first chapter.
			if ( $with_toc && ! $toc_added && str_starts_with( $part, '<div class="section' ) ) {
				$mpdf->TOCpagebreakByArray(
					array(
						'links'       => true,
						'toc-preHTML' => '<h2>' . htmlspecialchars( __( 'Contents', 'estasoft-handover-passport' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</h2>',
					)
				);
				$toc_added = true;
			}
			$mpdf->WriteHTML( $part, \Estasoft\HandoverPassport\Vendor\Mpdf\HTMLParserMode::HTML_BODY );
			$this->checkBudget( $started, $limit );
		}
		return (string) $mpdf->Output( '', \Estasoft\HandoverPassport\Vendor\Mpdf\Output\Destination::STRING_RETURN );
	}

	/**
	 * The footer of every page: the line from the pack, and the page number at the other end.
	 *
	 * @param string    $line  Footer line.
	 * @param PackStyle $style Style.
	 */
	private function footer( string $line, PackStyle $style ): string {
		$line  = htmlspecialchars( $line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$start = 'rtl' === $style->direction ? 'right' : 'left';
		$end   = 'rtl' === $style->direction ? 'left' : 'right';
		return '<table width="100%" style="font-size:7.5pt;color:#57606a;border-top:0.3mm solid #c9d1d9"><tr><td style="text-align:' . $start . '">' . $line . '</td><td style="text-align:' . $end . ';width:18mm">{PAGENO} / {nbpg}</td></tr></table>';
	}

	/**
	 * The memory the PDF may use, in bytes, or 0 when the server sets no limit.
	 */
	private function memoryBudget(): int {
		$limit = (string) ini_get( 'memory_limit' );
		if ( '' === $limit || '-1' === $limit ) {
			return 0;
		}
		$bytes = function_exists( 'wp_convert_hr_to_bytes' ) ? (int) wp_convert_hr_to_bytes( $limit ) : (int) $limit;
		return $bytes > 0 ? (int) ( $bytes * self::MEMORY_SHARE ) : 0;
	}

	/**
	 * Stop when the memory or the time is nearly used up (EXP-11).
	 *
	 * @param float $started When the work began.
	 * @param int   $memory  Memory budget in bytes, 0 for none.
	 * @throws RenderFailed When a budget is spent.
	 */
	private function checkBudget( float $started, int $memory ): void {
		$max_time = (int) ini_get( 'max_execution_time' );
		$seconds  = $max_time > 0 ? max( 10, (int) ( $max_time * 0.8 ) ) : self::DEFAULT_SECONDS;
		if ( ( 0 < $memory && memory_get_usage( true ) > $memory ) || microtime( true ) - $started > $seconds ) {
			throw new RenderFailed( __( 'This Passport is too large to turn into a PDF within the memory and time this server allows.', 'estasoft-handover-passport' ) . ' ' . $this->fallback() );
		}
	}

	/**
	 * The sentence that follows every failure.
	 */
	private function fallback(): string {
		return __( 'Open the print view and choose Save as PDF in your browser, or download the HTML pack.', 'estasoft-handover-passport' );
	}
}
