<?php
/**
 * Who may do what on the command line.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Cli;

use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Export\ExportRefused;
use Estasoft\HandoverPassport\Export\Render\RenderFailed;
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
	 * Stop unless a named user may make packs. Used when the command stores something, so the change log names a person.
	 */
	public static function requireExporter(): void {
		if ( get_current_user_id() < 1 || ! Capabilities::can( Capabilities::EXPORT ) ) {
			\WP_CLI::error( 'This stores a snapshot, so name a user who may export, for example --user=admin. The change log records that person.' );
		}
	}

	/**
	 * Stop when --user names someone who may not make packs. Without --user the command runs as a task, which is how a host
	 * job calls it; such a run can never include Sensitive records.
	 */
	public static function allowExporter(): void {
		if ( get_current_user_id() > 0 && ! Capabilities::can( Capabilities::EXPORT ) ) {
			\WP_CLI::error( 'That user may not make packs. Name someone who holds the export capability, or leave out --user.' );
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
		} catch ( ExportRefused $error ) {
			\WP_CLI::error( $error->getMessage() );
		} catch ( RenderFailed $error ) {
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
