<?php
/**
 * Helpers shared by the rule groups.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Findings\Rules;

use Estasoft\HandoverPassport\Admin\Admin;
use Estasoft\HandoverPassport\Admin\HistoryScreen;
use Estasoft\HandoverPassport\Admin\InventoryScreen;
use Estasoft\HandoverPassport\Admin\PeopleScreen;
use Estasoft\HandoverPassport\Admin\SafetyScreen;
use Estasoft\HandoverPassport\Admin\SettingsScreen;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Screen links and small formatters for rules.
 */
abstract class Base {

	/**
	 * Link to a tab of Inventory.
	 *
	 * @param string $tab Tab key.
	 * @return array{page: string, tab: string}
	 */
	protected static function inventory( string $tab ): array {
		return array( 'page' => InventoryScreen::SLUG, 'tab' => $tab );
	}

	/**
	 * Link to a tab of Safety nets.
	 *
	 * @param string $tab Tab key.
	 * @return array{page: string, tab: string}
	 */
	protected static function safety( string $tab ): array {
		return array( 'page' => SafetyScreen::SLUG, 'tab' => $tab );
	}

	/**
	 * Link to a tab of People and access.
	 *
	 * @param string $tab Tab key.
	 * @return array{page: string, tab: string}
	 */
	protected static function people( string $tab ): array {
		return array( 'page' => PeopleScreen::SLUG, 'tab' => $tab );
	}

	/**
	 * Link to the Overview.
	 *
	 * @return array{page: string, tab: string}
	 */
	protected static function overview(): array {
		return array( 'page' => Admin::MENU_SLUG, 'tab' => '' );
	}

	/**
	 * Link to Settings.
	 *
	 * @param string $tab Tab key.
	 * @return array{page: string, tab: string}
	 */
	protected static function settings( string $tab = 'general' ): array {
		return array( 'page' => SettingsScreen::SLUG, 'tab' => $tab );
	}

	/**
	 * Link to History.
	 *
	 * @return array{page: string, tab: string}
	 */
	protected static function history(): array {
		return array( 'page' => HistoryScreen::SLUG, 'tab' => 'changes' );
	}

	/**
	 * A date written for people.
	 *
	 * @param mixed $date Y-m-d date.
	 */
	protected static function when( $date ): string {
		return Dates::displayDate( is_string( $date ) ? $date : '' );
	}

	/**
	 * Days from a date to another, or null when either is not a date.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 */
	protected static function days( string $from, string $to ): ?int {
		return ( Dates::isDate( $from ) && Dates::isDate( $to ) ) ? Dates::daysBetween( $from, $to ) : null;
	}

	/**
	 * A record's own name for messages.
	 *
	 * @param array<string, mixed> $record Hydrated record.
	 */
	protected static function nameOf( array $record ): string {
		return '' !== (string) $record['title'] ? (string) $record['title'] : __( 'Unnamed record', 'estasoft-handover-passport' );
	}

	/**
	 * Major and minor part of a version, such as 8.1.
	 *
	 * @param string $version Full version.
	 */
	protected static function branch( string $version ): string {
		return implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
	}
}
