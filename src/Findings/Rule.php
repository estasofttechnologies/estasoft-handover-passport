<?php
/**
 * A findings rule (FND-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Findings;

use Closure;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable description of one rule: its ID, level, area, trigger, message, fix link and switch.
 *
 * A check returns a list of candidates (possibly empty), or null when the data it needs is missing,
 * which the engine reports as "Not checked" instead of "passed" (FND-3).
 */
final class Rule {

	/**
	 * Constructor.
	 *
	 * @param string      $id        Stable ID such as HP-STK-001.
	 * @param string      $area      Area key (stack, licences, domains, ...).
	 * @param string      $level     Highest level the rule can raise.
	 * @param bool        $handover  Whether the rule counts in the readiness and handover checklists.
	 * @param string      $title     Short plain name for the rules list.
	 * @param array       $screen    Screen that fixes it: page slug and tab.
	 * @param Closure     $check     fn( Context ): ?list<Candidate>.
	 * @param Closure     $message   fn( array $values ): string.
	 * @param string|null $threshold Settings key under "thresholds" that the rule reads, or null.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $area,
		public readonly string $level,
		public readonly bool $handover,
		public readonly string $title,
		public readonly array $screen,
		private Closure $check,
		private Closure $message,
		public readonly ?string $threshold = null
	) {}

	/**
	 * Run the check.
	 *
	 * @param Context $context Read-only view of the Passport.
	 * @return list<Candidate>|null Null when the rule cannot run.
	 */
	public function evaluate( Context $context ): ?array {
		$result = ( $this->check )( $context );
		return is_array( $result ) ? array_values( $result ) : null;
	}

	/**
	 * The message for a finding of this rule.
	 *
	 * @param array<string, mixed> $values Stored message values.
	 */
	public function message( array $values ): string {
		return (string) ( $this->message )( $values );
	}
}
