<?php
/**
 * Makes packs in every format and keeps the export log (EXP-2, EXP-6, EXP-7, EXP-9).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export;

use Estasoft\HandoverPassport\Data\ExportRepository;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Export\Render\CsvRenderer;
use Estasoft\HandoverPassport\Export\Render\HtmlRenderer;
use Estasoft\HandoverPassport\Export\Render\IcsRenderer;
use Estasoft\HandoverPassport\Export\Render\JsonRenderer;
use Estasoft\HandoverPassport\Export\Render\MarkdownRenderer;
use Estasoft\HandoverPassport\Export\Render\PackStyle;
use Estasoft\HandoverPassport\Export\Render\PdfRenderer;
use Estasoft\HandoverPassport\Export\Render\Renderer;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * One place that turns the Passport, or a snapshot of it, into a file. It checks nothing about who may export; the
 * screen, the command and the route do that. What a pack may hold is decided in ContentBuilder.
 */
final class ExportService {

	/**
	 * The print view is logged like a format but is not one to download.
	 */
	public const PRINT = 'print';

	/**
	 * Largest logo that goes into a pack, in bytes.
	 */
	private const LOGO_MAX_BYTES = 1048576;

	/**
	 * Constructor.
	 *
	 * @param Plugin           $plugin Container.
	 * @param ExportRepository $log    Export log.
	 */
	public function __construct( private Plugin $plugin, private ExportRepository $log ) {}

	/**
	 * The formats a pack can be made in.
	 *
	 * @return array<string, array{label: string, extension: string, mime: string}>
	 */
	public static function formats(): array {
		return array(
			'pdf'  => array( 'label' => __( 'PDF', 'estasoft-handover-passport' ), 'extension' => 'pdf', 'mime' => 'application/pdf' ),
			'html' => array( 'label' => __( 'HTML (one file)', 'estasoft-handover-passport' ), 'extension' => 'html', 'mime' => 'text/html; charset=utf-8' ),
			'md'   => array( 'label' => __( 'Markdown', 'estasoft-handover-passport' ), 'extension' => 'md', 'mime' => 'text/markdown; charset=utf-8' ),
			'json' => array( 'label' => __( 'JSON', 'estasoft-handover-passport' ), 'extension' => 'json', 'mime' => 'application/json; charset=utf-8' ),
			'csv'  => array( 'label' => __( 'CSV (zipped, one file per register)', 'estasoft-handover-passport' ), 'extension' => 'zip', 'mime' => 'application/zip' ),
			'ics'  => array( 'label' => __( 'Calendar (iCalendar)', 'estasoft-handover-passport' ), 'extension' => 'ics', 'mime' => 'text/calendar; charset=utf-8' ),
		);
	}

	/**
	 * The export profiles, shipped and copied.
	 */
	public function profiles(): Profiles {
		return new Profiles( (array) $this->plugin->settings()->get( 'profiles', array() ) );
	}

	/**
	 * Make the pack of the Passport as it is now.
	 *
	 * @param string        $profile_key Profile key.
	 * @param string        $format      Format key or "print".
	 * @param ExportOptions $options     Rights and ticked Sensitive records.
	 * @param bool          $emailed     Whether the file is sent by email, which the export log notes.
	 * @throws ValidationError When the profile or format is not known.
	 * @throws ExportRefused   When the person may not make this pack.
	 * @throws \Estasoft\HandoverPassport\Security\SecretFound When a value looks like a secret.
	 * @throws Render\RenderFailed When the file cannot be made.
	 */
	public function make( string $profile_key, string $format, ExportOptions $options, bool $emailed = false ): ExportResult {
		$profile = $this->profile( $profile_key );
		$this->assertFormat( $format );
		$version = defined( 'ESTASOFT_HP_VERSION' ) ? (string) ESTASOFT_HP_VERSION : '';
		$content = ( new ContentBuilder( $this->plugin->secretGuard(), $version ) )->build( $profile, ( new ExportDataLoader( $this->plugin ) )->load(), $options );
		return $this->deliver( $content, hash( 'sha256', Json::canonical( $content ) ), $profile->label, $profile->key, 0, $format, $options, $emailed );
	}

	/**
	 * Make a file from a stored snapshot. The snapshot is read as it was stored; its hash is checked first, so a file is
	 * never made from content that no longer matches it.
	 *
	 * @param int           $snapshot_id Snapshot ID.
	 * @param string        $format      Format key or "print".
	 * @param ExportOptions $options     Rights of the person.
	 * @throws ValidationError When the snapshot or format is not known.
	 * @throws ExportRefused   When the person may not see it or it no longer matches its hash.
	 * @throws Render\RenderFailed When the file cannot be made.
	 */
	public function makeFromSnapshot( int $snapshot_id, string $format, ExportOptions $options ): ExportResult {
		$this->assertFormat( $format );
		$row = $this->plugin->snapshots()->get( $snapshot_id );
		if ( null === $row ) {
			throw new ValidationError( __( 'Snapshot', 'estasoft-handover-passport' ), __( 'That snapshot does not exist.', 'estasoft-handover-passport' ) );
		}
		$stored = (string) $row['content'];
		if ( ! hash_equals( (string) $row['hash'], hash( 'sha256', $stored ) ) ) {
			throw new ExportRefused( __( 'The stored content of this snapshot no longer matches its hash, so no file is made from it. Open Verify for details.', 'estasoft-handover-passport' ) );
		}
		$content = Json::decode( $stored );
		if ( array() === $content ) {
			throw new ExportRefused( __( 'This snapshot cannot be read.', 'estasoft-handover-passport' ) );
		}
		if ( array() !== (array) ( $content['sensitive_included'] ?? array() ) && ! $options->may_see_sensitive ) {
			throw new ExportRefused( __( 'This snapshot includes Sensitive records. You need the Sensitive capability to export it.', 'estasoft-handover-passport' ) );
		}
		return $this->deliver( $content, (string) $row['hash'], (string) $row['name'], (string) $row['profile_key'], $snapshot_id, $format, $options );
	}

	/**
	 * Turn content into a file, log it and tell the hooks.
	 *
	 * @param array<string, mixed> $content  Pack content.
	 * @param string               $hash     Hash of the content.
	 * @param string               $label    Name shown in the footer.
	 * @param string               $profile  Profile key.
	 * @param int                  $snapshot Snapshot ID or 0.
	 * @param string               $format   Format key or "print".
	 * @param ExportOptions        $options  Rights.
	 * @param bool                 $emailed  Whether the file is sent by email.
	 * @throws Render\RenderFailed When the file cannot be made.
	 */
	private function deliver( array $content, string $hash, string $label, string $profile, int $snapshot, string $format, ExportOptions $options, bool $emailed = false ): ExportResult {
		$style = $this->style();
		if ( self::PRINT === $format ) {
			$renderer  = new HtmlRenderer( HtmlRenderer::MODE_PRINT );
			$extension = 'html';
			$mime      = 'text/html; charset=utf-8';
		} else {
			$renderer  = $this->renderer( $format );
			$extension = $renderer->extension();
			$mime      = self::formats()[ $format ]['mime'];
		}
		$bytes    = $renderer->render( $content, $style, $hash, $label );
		$warnings = 'pdf' === $format ? PdfRenderer::warnings( $content ) : array();
		$name     = $this->filename( $content, $label, $profile, $snapshot, $extension );
		$result   = new ExportResult( $bytes, $name, $mime, $format, $profile, $snapshot, $warnings );
		$this->log->insert(
			array(
				'exported_at' => Clock::sql(),
				'user_id'     => $options->user_id,
				'snapshot_id' => $snapshot,
				'profile'     => $profile,
				'format'      => $format,
				'size'        => $result->size(),
				'hash'        => $result->hash(),
				'emailed'     => $emailed ? 1 : 0,
			)
		);
		/**
		 * Fires after a pack was made. The file itself is not passed on.
		 *
		 * @param string $profile  Profile key.
		 * @param string $format   Format key.
		 * @param int    $snapshot Snapshot ID, 0 for the live Passport.
		 * @param int    $user_id  Person who made it.
		 */
		do_action( 'estasoft_hp_export_created', $profile, $format, $snapshot, $options->user_id );
		return $result;
	}

	/**
	 * Put a file that is not a pack, such as the change log as CSV, in the export log (EXP-9).
	 *
	 * @param string $profile Short key for what the file holds, for example change_log.
	 * @param string $format  Format key.
	 * @param string $bytes   The file.
	 * @param int    $user_id Person who made it.
	 */
	public function logFile( string $profile, string $format, string $bytes, int $user_id ): void {
		$this->log->insert(
			array(
				'exported_at' => Clock::sql(),
				'user_id'     => $user_id,
				'snapshot_id' => 0,
				'profile'     => $profile,
				'format'      => $format,
				'size'        => strlen( $bytes ),
				'hash'        => hash( 'sha256', $bytes ),
				'emailed'     => 0,
			)
		);
	}

	/**
	 * Export log entries of the last months, newest first (EXP-9).
	 *
	 * @param int $user_id Only this person's entries, or 0 for everyone's.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $user_id = 0 ): array {
		$months = max( 1, (int) $this->plugin->settings()->get( 'retention.exports_months', 12 ) );
		$from   = Clock::now()->modify( '-' . $months . ' months' )->format( 'Y-m-d H:i:s' );
		$equals = $user_id > 0 ? array( 'user_id' => $user_id ) : array();
		return $this->log->query( $equals, 'exported_at', 'DESC', 200, 0, 'exported_at >= %s', array( $from ) );
	}

	/**
	 * The look of the packs, from Settings and the Passport.
	 */
	public function style(): PackStyle {
		$pack     = (array) $this->plugin->settings()->get( 'pack', array() );
		$passport = $this->plugin->passport();
		$language = '' !== (string) ( $pack['language'] ?? '' ) ? (string) $pack['language'] : (string) get_bloginfo( 'language' );
		$rtl      = '' !== (string) ( $pack['language'] ?? '' ) ? self::isRtlLanguage( $language ) : is_rtl();
		return PackStyle::fromSettings( $pack, (string) $passport->get( 'brand_color', '' ), $this->logoDataUri( (int) $passport->get( 'logo_id', 0 ) ), $language, $rtl );
	}

	/**
	 * Whether a language tag is written right to left.
	 *
	 * @param string $tag Language tag such as "ar" or "he-IL".
	 */
	public static function isRtlLanguage( string $tag ): bool {
		$base = strtolower( (string) strtok( str_replace( '_', '-', $tag ), '-' ) );
		return in_array( $base, array( 'ar', 'he', 'fa', 'ur', 'ps', 'sd', 'ug', 'yi', 'dv', 'ckb', 'azb' ), true );
	}

	/**
	 * The logo as a data address, or an empty string when there is none or it cannot be used. Only PNG, JPEG and GIF
	 * files up to 1 MB are used, and the file is read from the media library, never from a web address.
	 *
	 * @param int $attachment_id Media library ID.
	 */
	private function logoDataUri( int $attachment_id ): string {
		if ( $attachment_id < 1 ) {
			return '';
		}
		$path = get_attached_file( $attachment_id );
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) || ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/gif' ), true ) ) {
			return '';
		}
		$size = filesize( $path );
		if ( false === $size || $size < 1 || $size > self::LOGO_MAX_BYTES ) {
			return '';
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file from the media library, read from disk.
		if ( false === $bytes || false === getimagesizefromstring( $bytes ) ) {
			return '';
		}
		return 'data:' . $mime . ';base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A data address for an image inside the file.
	}

	/**
	 * A file name that is safe on every system: ASCII letters, digits and hyphens.
	 *
	 * @param array<string, mixed> $content   Pack content.
	 * @param string               $label     Snapshot or pack name.
	 * @param string               $profile   Profile key.
	 * @param int                  $snapshot  Snapshot ID.
	 * @param string               $extension File extension.
	 */
	private function filename( array $content, string $label, string $profile, int $snapshot, string $extension ): string {
		$site = self::slug( (string) ( $content['site']['name'] ?? '' ), 'passport' );
		$date = substr( (string) ( $content['generated_at'] ?? Clock::sql() ), 0, 10 );
		$name = $snapshot > 0
			? $site . '-' . self::slug( $label, 'snapshot' ) . '-' . self::slug( $profile, 'pack' )
			: $site . '-' . self::slug( $profile, 'pack' ) . '-' . $date;
		/**
		 * Filters the name of a downloaded pack, without the extension.
		 *
		 * @param string $name     File name.
		 * @param string $profile  Profile key.
		 * @param int    $snapshot Snapshot ID, 0 for the live Passport.
		 */
		$name = (string) apply_filters( 'estasoft_hp_export_filename', $name, $profile, $snapshot );
		$name = self::slug( $name, 'passport' );
		return $name . '.' . $extension;
	}

	/**
	 * Lower-case ASCII slug, or a fallback when nothing is left.
	 *
	 * @param string $text     Text.
	 * @param string $fallback Used when the text holds no Latin letters or digits.
	 */
	private static function slug( string $text, string $fallback ): string {
		$text = strtolower( remove_accents( $text ) );
		$text = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $text ), '-' );
		return '' === $text ? $fallback : substr( $text, 0, 60 );
	}

	/**
	 * The profile for a key.
	 *
	 * @param string $key Profile key.
	 * @throws ValidationError When it is not a known profile.
	 */
	private function profile( string $key ): Profile {
		$profile = $this->profiles()->get( $key );
		if ( null === $profile ) {
			throw new ValidationError( __( 'Pack', 'estasoft-handover-passport' ), __( 'Pick one of the listed packs.', 'estasoft-handover-passport' ) );
		}
		return $profile;
	}

	/**
	 * Check a format key.
	 *
	 * @param string $format Format key or "print".
	 * @throws ValidationError When it is not a known format.
	 */
	private function assertFormat( string $format ): void {
		if ( self::PRINT !== $format && ! isset( self::formats()[ $format ] ) ) {
			throw new ValidationError( __( 'Format', 'estasoft-handover-passport' ), __( 'Pick one of the listed formats.', 'estasoft-handover-passport' ) );
		}
	}

	/**
	 * The renderer for a format.
	 *
	 * @param string $format Format key.
	 */
	private function renderer( string $format ): Renderer {
		return match ( $format ) {
			'pdf'  => new PdfRenderer(),
			'html' => new HtmlRenderer( HtmlRenderer::MODE_FILE ),
			'md'   => new MarkdownRenderer(),
			'json' => new JsonRenderer(),
			'csv'  => new CsvRenderer(),
			default => new IcsRenderer(),
		};
	}
}
