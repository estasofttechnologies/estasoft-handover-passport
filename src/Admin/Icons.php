<?php
/**
 * The small line icons used on Passport screens. Each one is fixed markup written here, never built from input.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Inline icons. They carry no meaning on their own: a word always sits beside them.
 */
final class Icons {

	/**
	 * Icon shapes on a 24 by 24 grid, drawn as lines.
	 *
	 * @var array<string, string>
	 */
	private const SHAPES = array(
		'ok'       => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
		'error'    => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>',
		'warning'  => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
		'clock'    => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
		'minus'    => '<circle cx="12" cy="12" r="10"/><path d="M8 12h8"/>',
		'info'     => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
		'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
		'refresh'  => '<path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.5 9a9 9 0 0 1 14.9-3.4L23 10M1 14l4.6 4.4A9 9 0 0 0 20.5 15"/>',
		'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
		'archive'  => '<path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/>',
		'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
		'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
		'arrow'    => '<path d="M5 12h14M12 5l7 7-7 7"/>',
		'back'     => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
		'plus'     => '<path d="M12 5v14M5 12h14"/>',
		'globe'    => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/>',
		'chevron'  => '<path d="m6 9 6 6 6-6"/>',
		'lock'     => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
		'search'   => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
		'edit'     => '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
	);

	/**
	 * Markup of one icon. An unknown name gives an empty string.
	 *
	 * @param string $name Icon name.
	 * @param int    $size Width and height in pixels.
	 */
	public static function svg( string $name, int $size = 12 ): string {
		if ( ! isset( self::SHAPES[ $name ] ) ) {
			return '';
		}
		$class = in_array( $name, array( 'arrow', 'back' ), true ) ? ' class="flip"' : '';
		return '<svg' . $class . ' width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . self::SHAPES[ $name ] . '</svg>';
	}
}
