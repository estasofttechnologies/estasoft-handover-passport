<?php
/**
 * Look and wording of a pack (EXP-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export\Render;

defined( 'ABSPATH' ) || exit;

/**
 * The settings that change how a pack looks, checked once here so a renderer can trust them.
 */
final class PackStyle {

	public const A4     = 'A4';
	public const LETTER = 'Letter';

	/**
	 * Constructor. Use fromSettings() to build one from stored values.
	 *
	 * @param string $title         Title on the cover, or an empty string for the site name.
	 * @param string $cover_text    Words under the title.
	 * @param string $footer_text   Words in the footer.
	 * @param string $page_size     A4 or Letter.
	 * @param string $brand_color   Colour as #rrggbb.
	 * @param string $logo_data_uri Logo as a data address, or an empty string.
	 * @param bool   $credit        Whether to show the ESTASOFT TECHNOLOGIES credit line.
	 * @param string $language      Language tag such as "en" or "ar".
	 * @param string $direction     ltr or rtl, for the whole document.
	 */
	public function __construct(
		public readonly string $title = '',
		public readonly string $cover_text = '',
		public readonly string $footer_text = '',
		public readonly string $page_size = self::A4,
		public readonly string $brand_color = '#2f4858',
		public readonly string $logo_data_uri = '',
		public readonly bool $credit = false,
		public readonly string $language = 'en',
		public readonly string $direction = 'ltr'
	) {}

	/**
	 * Build the style from stored settings, falling back to safe values for anything that is not valid.
	 *
	 * @param array<string, mixed> $pack        The "pack" setting.
	 * @param string               $brand_color Brand colour from the Passport.
	 * @param string               $logo        Logo as a data address, or an empty string.
	 * @param string               $language    Language tag of the site.
	 * @param bool                 $rtl         Whether the site language reads right to left.
	 */
	public static function fromSettings( array $pack, string $brand_color = '', string $logo = '', string $language = 'en', bool $rtl = false ): self {
		$size  = (string) ( $pack['page_size'] ?? self::A4 );
		$color = 1 === preg_match( '/^#[0-9a-fA-F]{6}$/', $brand_color ) ? strtolower( $brand_color ) : '#2f4858';
		$tag   = 1 === preg_match( '/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})*$/', $language ) ? str_replace( '_', '-', $language ) : 'en';
		return new self(
			mb_substr( trim( (string) ( $pack['title'] ?? '' ) ), 0, 190 ),
			mb_substr( trim( (string) ( $pack['cover_text'] ?? '' ) ), 0, 1000 ),
			mb_substr( trim( (string) ( $pack['footer_text'] ?? '' ) ), 0, 300 ),
			self::LETTER === $size ? self::LETTER : self::A4,
			$color,
			str_starts_with( $logo, 'data:image/' ) ? $logo : '',
			! empty( $pack['credit'] ),
			$tag,
			$rtl ? 'rtl' : 'ltr'
		);
	}
}
