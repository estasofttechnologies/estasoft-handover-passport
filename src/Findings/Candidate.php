<?php
/**
 * One thing a rule found (HLT-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Findings;

defined( 'ABSPATH' ) || exit;

/**
 * A rule's result before it is matched with the stored findings.
 */
final class Candidate {

	/**
	 * Constructor.
	 *
	 * @param string               $subject_type Kind of subject: site, item, person, plugin, theme, user, runbook or snapshot.
	 * @param int                  $subject_id   Row ID, or 0.
	 * @param string               $subject_key  Stable key when there is no row ID (a slug, a host).
	 * @param string               $level        Level key.
	 * @param array<string, mixed> $values       Named values for the message.
	 */
	public function __construct(
		public readonly string $subject_type,
		public readonly int $subject_id,
		public readonly string $subject_key,
		public readonly string $level,
		public readonly array $values = array()
	) {}

	/**
	 * A finding about the whole site.
	 *
	 * @param string               $level  Level key.
	 * @param array<string, mixed> $values Message values.
	 */
	public static function site( string $level, array $values = array() ): self {
		return new self( 'site', 0, '', $level, $values );
	}

	/**
	 * A finding about a register record.
	 *
	 * @param int                  $id     Record ID.
	 * @param string               $level  Level key.
	 * @param array<string, mixed> $values Message values.
	 */
	public static function item( int $id, string $level, array $values = array() ): self {
		return new self( 'item', $id, '', $level, $values );
	}

	/**
	 * A finding about something known by name, such as a plugin.
	 *
	 * @param string               $type   Subject type.
	 * @param string               $key    Stable key.
	 * @param string               $level  Level key.
	 * @param array<string, mixed> $values Message values.
	 */
	public static function keyed( string $type, string $key, string $level, array $values = array() ): self {
		return new self( $type, 0, $key, $level, $values );
	}

	/**
	 * A finding about a row in another table.
	 *
	 * @param string               $type   Subject type.
	 * @param int                  $id     Row ID.
	 * @param string               $level  Level key.
	 * @param array<string, mixed> $values Message values.
	 */
	public static function row( string $type, int $id, string $level, array $values = array() ): self {
		return new self( $type, $id, '', $level, $values );
	}

	/**
	 * Identity used to match a candidate with a stored finding.
	 *
	 * @param string $rule_id Rule ID.
	 */
	public function identity( string $rule_id ): string {
		return $rule_id . '|' . $this->subject_type . '|' . $this->subject_id . '|' . $this->subject_key;
	}
}
