<?php
/**
 * Deactivation. Never deletes data.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Removes scheduled events only.
 */
final class Deactivator {

	/**
	 * Deactivation callback.
	 */
	public static function deactivate(): void {
		Schedule::clear();
	}
}
