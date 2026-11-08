<?php
/**
 * Finding levels (HLT-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Findings;

defined( 'ABSPATH' ) || exit;

/**
 * The three levels, from most to least urgent.
 */
final class Level {

	public const NEEDS = 'needs_fixing';
	public const WORTH = 'worth_fixing';
	public const INFO  = 'info';

	/**
	 * Levels in order of urgency.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array( self::NEEDS, self::WORTH, self::INFO );
	}

	/**
	 * Sort weight: lower is more urgent.
	 *
	 * @param string $level Level key.
	 */
	public static function weight( string $level ): int {
		$index = array_search( $level, self::all(), true );
		return false === $index ? count( self::all() ) : (int) $index;
	}

	/**
	 * Plain label.
	 *
	 * @param string $level Level key.
	 */
	public static function label( string $level ): string {
		switch ( $level ) {
			case self::NEEDS:
				return __( 'Needs fixing', 'estasoft-handover-passport' );
			case self::WORTH:
				return __( 'Worth fixing', 'estasoft-handover-passport' );
			default:
				return __( 'Info', 'estasoft-handover-passport' );
		}
	}

	/**
	 * Dash-case token for the command line.
	 *
	 * @param string $level Level key.
	 */
	public static function token( string $level ): string {
		return str_replace( '_', '-', $level );
	}
}
