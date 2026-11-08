<?php
/**
 * What the person asked for when making a pack (EXP-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Export;

defined( 'ABSPATH' ) || exit;

/**
 * The rights of the person and the Sensitive records they ticked.
 */
final class ExportOptions {

	/**
	 * Constructor.
	 *
	 * @param bool      $may_see_sensitive Whether the person holds the Sensitive capability.
	 * @param list<int> $include_ids       Sensitive records the person ticked and confirmed.
	 * @param int       $user_id           Person making the pack, 0 for a task.
	 */
	public function __construct(
		public readonly bool $may_see_sensitive = false,
		public readonly array $include_ids = array(),
		public readonly int $user_id = 0
	) {}

	/**
	 * Whether a Sensitive record may go into a pack with this profile.
	 *
	 * @param Profile $profile Profile.
	 * @param int     $id      Record ID.
	 */
	public function allowsSensitiveRecord( Profile $profile, int $id ): bool {
		if ( ! $this->may_see_sensitive ) {
			return false;
		}
		return Profile::FULL === $profile->audience || in_array( $id, $this->include_ids, true );
	}

	/**
	 * Whether the Sensitive fields of ordinary records may show: only in the Full internal pack, by someone who may.
	 *
	 * @param Profile $profile Profile.
	 */
	public function allowsSensitiveFields( Profile $profile ): bool {
		return $this->may_see_sensitive && Profile::FULL === $profile->audience;
	}
}
