<?php
/**
 * One export profile: which chapters a pack holds and who it is for (EXP-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable description of a pack.
 */
final class Profile {

	public const CLIENT    = 'client';
	public const TECHNICAL = 'technical';
	public const FULL      = 'full';
	public const EMERGENCY = 'emergency';

	/**
	 * Constructor.
	 *
	 * @param string             $key         Stable key, such as "client".
	 * @param string             $label       Name shown to people.
	 * @param string             $description What the pack is for.
	 * @param array<int, string> $sections    Chapter keys in order.
	 * @param string             $audience    Which rules decide what is shown: client, emergency, technical or full.
	 * @param bool               $builtin     False for a copy made in Settings.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly string $description,
		public readonly array $sections,
		public readonly string $audience,
		public readonly bool $builtin = true
	) {}

	/**
	 * Whether making this pack needs the Sensitive capability. Only the Full internal pack does; the others leave
	 * Sensitive records out unless a person ticks one (EXP-4), which needs the capability too.
	 */
	public function needsSensitiveCapability(): bool {
		return self::FULL === $this->audience;
	}

	/**
	 * Whether the pack is made for the client or for anyone in an emergency, so a record must be marked visible there.
	 */
	public function usesVisibilityFlags(): bool {
		return in_array( $this->audience, array( self::CLIENT, self::EMERGENCY ), true );
	}

	/**
	 * Whether a person's own sharing flags decide which of their details appear (PRV-7).
	 */
	public function usesSharingFlags(): bool {
		return $this->usesVisibilityFlags();
	}

	/**
	 * Whether the notes to the successor go in (HND-7).
	 */
	public function includesNotes(): bool {
		return self::EMERGENCY !== $this->audience;
	}

	/**
	 * Whether the pack is one page and is shortened to fit (EXP-1).
	 */
	public function isOnePage(): bool {
		return self::EMERGENCY === $this->audience;
	}
}
