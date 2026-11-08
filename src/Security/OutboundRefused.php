<?php
/**
 * Thrown when an own-site check is refused by the outbound guard (SEC-8).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Security;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * A short, user-readable refusal with a stable code.
 */
final class OutboundRefused extends RuntimeException {

	/**
	 * Stable machine code.
	 *
	 * @var string
	 */
	private string $code_id;

	/**
	 * Constructor.
	 *
	 * @param string $code_id Machine code, for example "other_host".
	 * @param string $message Short message for the person.
	 */
	public function __construct( string $code_id, string $message ) {
		$this->code_id = $code_id;
		parent::__construct( $message );
	}

	/**
	 * Machine code.
	 */
	public function codeId(): string {
		return $this->code_id;
	}
}
