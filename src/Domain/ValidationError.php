<?php
/**
 * A field value that cannot be accepted.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Domain;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Names the field and says what to fix. It never repeats the submitted value (SEC-7).
 */
final class ValidationError extends RuntimeException {

	/**
	 * Field label.
	 *
	 * @var string
	 */
	private string $field;

	/**
	 * Constructor.
	 *
	 * @param string $field   Field label.
	 * @param string $message What to fix.
	 */
	public function __construct( string $field, string $message ) {
		$this->field = $field;
		parent::__construct( $field . ': ' . $message );
	}

	/**
	 * Field label.
	 */
	public function field(): string {
		return $this->field;
	}
}
