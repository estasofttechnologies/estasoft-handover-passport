<?php
/**
 * Support end dates for PHP and WordPress versions (STK-6, FND-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Scan;

use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Looks up the shipped table.
 */
class SupportDates {

	/**
	 * Table contents.
	 *
	 * @var array<string, mixed>
	 */
	private array $table;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|null $table Table override for tests; null loads the shipped file.
	 */
	public function __construct( ?array $table = null ) {
		if ( null === $table ) {
			$file  = ESTASOFT_HP_DIR . 'data/support-dates.php';
			$table = is_readable( $file ) ? (array) include $file : array();
		}
		$this->table = $table;
	}

	/**
	 * Last day of security support for a PHP version, or null when the branch is unknown.
	 *
	 * @param string $version Full version such as 8.1.27.
	 */
	public function phpEnd( string $version ): ?string {
		return $this->lookup( 'php', $version );
	}

	/**
	 * Last day of support for a WordPress version, or null when unknown.
	 *
	 * @param string $version Full version such as 6.4.5.
	 */
	public function wordpressEnd( string $version ): ?string {
		return $this->lookup( 'wordpress', $version );
	}

	/**
	 * Table version number.
	 */
	public function version(): int {
		return (int) ( $this->table['version'] ?? 0 );
	}

	/**
	 * Date the table was last checked against the sources.
	 */
	public function reviewed(): string {
		return (string) ( $this->table['reviewed'] ?? '' );
	}

	/**
	 * Whether the table is more than 12 months old (FND-6).
	 *
	 * @param string $today Today as Y-m-d.
	 */
	public function isOld( string $today ): bool {
		$reviewed = $this->reviewed();
		return ! Dates::isDate( $reviewed ) || Dates::daysBetween( $reviewed, $today ) > 365;
	}

	/**
	 * Find the row for a major.minor branch.
	 *
	 * @param string $group   "php" or "wordpress".
	 * @param string $version Full version.
	 */
	private function lookup( string $group, string $version ): ?string {
		if ( 1 !== preg_match( '/^(\d+)\.(\d+)/', $version, $m ) ) {
			return null;
		}
		$rows = (array) ( $this->table[ $group ] ?? array() );
		$date = $rows[ $m[1] . '.' . $m[2] ] ?? null;
		return is_string( $date ) && Dates::isDate( $date ) ? $date : null;
	}
}
