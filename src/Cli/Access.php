<?php
/**
 * Who may do what on the command line.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Cli;

use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Security\SecretFound;

defined( 'ABSPATH' ) || exit;

/**
 * The command line has no signed-in person unless --user is given, so the commands that change the Passport ask for one.
 * That way the change log names who made the change. Reading needs no user, and without one nothing Sensitive is shown.
 */
final class Access {

	/**
	 * Stop unless the current user may change the Passport.
	 */
	public static function requireEditor(): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			\WP_CLI::error( 'This changes the Passport, so name a user who may edit it, for example --user=admin. The change log records that person.' );
		}
	}

	/**
	 * Run a change and turn a refusal into a plain error. The submitted value is never repeated.
	 *
	 * @template T
	 * @param callable():T $change The change to make.
	 * @return T
	 */
	public static function attempt( callable $change ): mixed {
		try {
			return $change();
		} catch ( ValidationError $error ) {
			\WP_CLI::error( $error->getMessage() );
		} catch ( SecretFound $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
		return null;
	}

	/**
	 * Whether Sensitive details are shown: only when --user names someone who holds that capability.
	 */
	public static function seesSensitive(): bool {
		return Capabilities::canSeeSensitive();
	}
}
