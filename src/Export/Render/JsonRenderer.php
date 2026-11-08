<?php
/**
 * JSON pack with a schema version (EXP-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export\Render;

use Estasoft\HandoverPassport\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * The same content a snapshot stores, with the keys in the same order, written so a person can read it. The look and
 * wording settings do not change it. Another site can import it (IMP-1).
 */
final class JsonRenderer implements Renderer {

	/**
	 * {@inheritDoc}
	 */
	public function key(): string {
		return 'json';
	}

	/**
	 * {@inheritDoc}
	 */
	public function extension(): string {
		return 'json';
	}

	/**
	 * {@inheritDoc}
	 */
	public function mime(): string {
		return 'application/json; charset=utf-8';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws RenderFailed When the file cannot be made.
	 */
	public function render( array $content, PackStyle $style, string $hash, string $label ): string {
		$sorted = Json::decode( Json::canonical( $content ) );
		$text   = json_encode( $sorted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- A pretty file for people; wp_json_encode cannot keep the exact flags.
		if ( false === $text ) {
			throw new RenderFailed( __( 'The content could not be written as JSON.', 'estasoft-handover-passport' ) );
		}
		return $text . "\n";
	}
}
