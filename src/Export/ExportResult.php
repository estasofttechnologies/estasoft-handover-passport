<?php
/**
 * A finished pack: the bytes and what to call the file (EXP-2, EXP-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result of making a pack.
 */
final class ExportResult {

	/**
	 * Constructor.
	 *
	 * @param string             $bytes    File content.
	 * @param string             $filename File name for a download.
	 * @param string             $mime     Media type.
	 * @param string             $format   Format key, such as "pdf" or "print".
	 * @param string             $profile  Profile key.
	 * @param int                $snapshot Snapshot ID, or 0 for the live Passport.
	 * @param array<int, string> $warnings Things the person should know about this file.
	 */
	public function __construct(
		public readonly string $bytes,
		public readonly string $filename,
		public readonly string $mime,
		public readonly string $format,
		public readonly string $profile,
		public readonly int $snapshot = 0,
		public readonly array $warnings = array()
	) {}

	/**
	 * Size in bytes.
	 */
	public function size(): int {
		return strlen( $this->bytes );
	}

	/**
	 * SHA-256 of the file.
	 */
	public function hash(): string {
		return hash( 'sha256', $this->bytes );
	}
}
