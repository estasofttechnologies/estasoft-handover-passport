<?php
/**
 * Thrown when text looks like a secret (ACC-3, SEC-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Security;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the field name and pattern id, never the matched text (SEC-7).
 */
final class SecretFound extends RuntimeException {

	/**
	 * Dotted path of the field that failed.
	 *
	 * @var string
	 */
	private string $field;

	/**
	 * Identifier of the pattern that matched.
	 *
	 * @var string
	 */
	private string $pattern_id;

	/**
	 * Constructor.
	 *
	 * @param string $field      Field path.
	 * @param string $pattern_id Pattern id.
	 */
	public function __construct( string $field, string $pattern_id ) {
		$this->field      = $field;
		$this->pattern_id = $pattern_id;
		parent::__construct(
			sprintf(
				/* translators: %s: field label. */
				__( 'The "%s" field looks like a secret. Passport never stores secrets. Store where the secret lives instead, for example the item name in your password manager.', 'estasoft-handover-passport' ),
				$field
			)
		);
	}

	/**
	 * Field path.
	 */
	public function field(): string {
		return $this->field;
	}

	/**
	 * Pattern id.
	 */
	public function patternId(): string {
		return $this->pattern_id;
	}
}
