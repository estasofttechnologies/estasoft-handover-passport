<?php
/**
 * Daily clean-up: removes soft-deleted records for good and trims old history (DAT-6, PRV-6, CHG-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Services;

use Estasoft\HandoverPassport\Data\ChangeRepository;
use Estasoft\HandoverPassport\Data\FindingRepository;
use Estasoft\HandoverPassport\Data\ItemRepository;
use Estasoft\HandoverPassport\Data\LinkRepository;
use Estasoft\HandoverPassport\Data\PeopleRepository;
use Estasoft\HandoverPassport\Data\ReminderRepository;
use Estasoft\HandoverPassport\Settings\Settings;
use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the retention settings. Safe to run more than once.
 */
final class Retention {

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings Settings.
	 * @param ItemRepository   $items    Items.
	 * @param PeopleRepository $people   People.
	 * @param LinkRepository   $links    Links.
	 * @param ChangeRepository $changes  Change log.
	 * @param FindingRepository $findings Findings.
	 * @param ReminderRepository $reminders Sent reminders.
	 */
	public function __construct(
		private Settings $settings,
		private ItemRepository $items,
		private PeopleRepository $people,
		private LinkRepository $links,
		private ChangeRepository $changes,
		private FindingRepository $findings,
		private ReminderRepository $reminders
	) {}

	/**
	 * Run the clean-up.
	 */
	public function run(): void {
		$now          = Clock::now();
		$deleted_days = max( 1, (int) $this->settings->get( 'retention.deleted_days', 30 ) );
		$cutoff       = $now->modify( sprintf( '-%d days', $deleted_days ) )->format( 'Y-m-d H:i:s' );

		foreach ( $this->items->purgeDeletedBefore( $cutoff ) as $id ) {
			$this->links->forget( 'item', $id );
		}
		foreach ( $this->people->purgeDeletedBefore( $cutoff ) as $id ) {
			$this->links->forget( 'person', $id );
		}

		$months = max( 1, (int) $this->settings->get( 'retention.changes_months', 24 ) );
		$this->changes->purgeBefore( $now->modify( sprintf( '-%d months', $months ) )->format( 'Y-m-d H:i:s' ) );

		$findings_months = max( 1, (int) $this->settings->get( 'retention.findings_months', 12 ) );
		$this->findings->purgeResolvedBefore( $now->modify( sprintf( '-%d months', $findings_months ) )->format( 'Y-m-d H:i:s' ) );

		$reminder_months = max( 1, (int) $this->settings->get( 'retention.reminders_months', 12 ) );
		$this->reminders->purgeBefore( $now->modify( sprintf( '-%d months', $reminder_months ) )->format( 'Y-m-d H:i:s' ) );

		/**
		 * Fires after the daily clean-up so later parts of the plugin can trim their own data.
		 *
		 * @param Settings $settings Settings.
		 */
		do_action( 'estasoft_hp_retention_run', $this->settings );
	}
}
