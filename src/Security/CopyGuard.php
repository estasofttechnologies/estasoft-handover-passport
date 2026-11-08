<?php
/**
 * Copy guard (IMP-4). A copy of the database must not act as the original.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers the host the Passport was set up on. When the same data turns up on another host, reminders, emailed
 * copies and scheduled checks pause until a person says whether the site moved or this is a copy.
 */
class CopyGuard {

	public const HOST_OPTION  = 'estasoft_hp_site_host';
	public const STATE_OPTION = 'estasoft_hp_copy_guard';

	public const MOVED = 'moved';
	public const COPY  = 'copy';

	/**
	 * The host this site answers to now, lower case.
	 */
	public function currentHost(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * The host stored when the Passport was set up.
	 */
	public function storedHost(): string {
		return strtolower( (string) get_option( self::HOST_OPTION, '' ) );
	}

	/**
	 * Remember the host the first time, and notice a different one afterwards. Safe to call often.
	 */
	public function check(): void {
		$current = $this->currentHost();
		if ( '' === $current ) {
			return;
		}
		$stored = $this->storedHost();
		if ( '' === $stored ) {
			update_option( self::HOST_OPTION, $current, false );
			return;
		}
		if ( $stored === $current ) {
			if ( array() !== $this->state() ) {
				delete_option( self::STATE_OPTION );
			}
			return;
		}
		$state = $this->state();
		if ( ( $state['to_host'] ?? '' ) === $current ) {
			return; // Already asked, or already answered, for this host.
		}
		update_option(
			self::STATE_OPTION,
			array(
				'pending' => true,
				'from'    => (string) ( $state['from'] ?? $stored ),
				'to'      => home_url(),
				'to_host' => $current,
				'mode'    => '',
			),
			false
		);
		do_action( 'estasoft_hp_site_host_changed', $stored, $current );
	}

	/**
	 * Whether this site is running on a host other than the one the Passport was set up on, answered or not.
	 * It does not depend on a scan having run, so a copy cannot send mail in the hours before its first scan.
	 */
	public function paused(): bool {
		$stored  = $this->storedHost();
		$current = $this->currentHost();
		return '' !== $stored && '' !== $current && $stored !== $current;
	}

	/**
	 * Whether the question is still open (for the banner and the finding).
	 */
	public function pending(): bool {
		return $this->paused() && ! empty( $this->state()['pending'] );
	}

	/**
	 * The stored state.
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		return (array) get_option( self::STATE_OPTION, array() );
	}

	/**
	 * The answer to the question.
	 *
	 * @param string $answer MOVED to keep everything and take the new address, COPY to mark this as a copy.
	 * @return bool Whether the answer was accepted.
	 */
	public function answer( string $answer ): bool {
		$current = $this->currentHost();
		if ( '' === $current ) {
			return false;
		}
		if ( self::MOVED === $answer ) {
			update_option( self::HOST_OPTION, $current, false );
			delete_option( self::STATE_OPTION );
			do_action( 'estasoft_hp_site_moved', $current );
			return true;
		}
		if ( self::COPY === $answer ) {
			$state            = $this->state();
			$state['pending'] = false;
			$state['mode']    = self::COPY;
			$state['to']      = home_url();
			$state['to_host'] = $current;
			update_option( self::STATE_OPTION, $state, false );
			return true;
		}
		return false;
	}

	/**
	 * Whether this site was marked as a copy of another.
	 */
	public function isMarkedCopy(): bool {
		return $this->paused() && self::COPY === ( $this->state()['mode'] ?? '' );
	}
}
