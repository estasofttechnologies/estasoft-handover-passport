<?php
/**
 * One output format for a pack (EXP-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Turns pack content into the bytes of one file. A renderer reads the content it is given and nothing else, so the
 * rules about Sensitive values have already been applied before it runs.
 */
interface Renderer {

	/**
	 * Short key, such as "html" or "csv".
	 */
	public function key(): string;

	/**
	 * File extension without the dot.
	 */
	public function extension(): string;

	/**
	 * Media type sent with a download.
	 */
	public function mime(): string;

	/**
	 * Make the file.
	 *
	 * @param array<string, mixed> $content Pack content from the content builder.
	 * @param PackStyle            $style   Look and wording.
	 * @param string               $hash    SHA-256 of the canonical content, shown in the footer of formats that have one.
	 * @param string               $label   Name shown in the footer: the snapshot name, or the name of the pack.
	 * @return string File bytes.
	 * @throws RenderFailed When the file cannot be made.
	 */
	public function render( array $content, PackStyle $style, string $hash, string $label ): string;
}
