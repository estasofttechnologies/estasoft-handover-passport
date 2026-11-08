<?php
/**
 * The warning that comes before a Sensitive record goes into a file (EXP-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * A person ticks Sensitive records on a form; nothing is made until they have read a page that names each record and
 * pressed Confirm. The request waits for that, for ten minutes, in a copy that belongs to the person.
 */
final class SensitiveConfirm {

	private const TTL = 600;

	/**
	 * Sensitive record IDs ticked on the form that was just posted. Empty when the person may not see Sensitive records.
	 *
	 * @return list<int>
	 */
	public static function postedIds(): array {
		if ( ! Capabilities::canSeeSensitive() ) {
			return array();
		}
		$raw = isset( $_POST['sensitive'] ) && is_array( $_POST['sensitive'] ) ? wp_unslash( $_POST['sensitive'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is checked by the caller; every value is cast to a whole number.
		$ids = array();
		foreach ( $raw as $value ) {
			if ( is_scalar( $value ) && absint( (string) $value ) > 0 ) {
				$ids[] = absint( (string) $value );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Keep a request until the person confirms it.
	 *
	 * @param string               $action  The form that will finish it.
	 * @param array<string, mixed> $payload What to do: fields and the ticked IDs.
	 */
	public static function hold( string $action, array $payload ): void {
		set_transient( self::key(), array( 'action' => $action, 'payload' => $payload ), self::TTL );
	}

	/**
	 * Take the held request once, if it belongs to this form.
	 *
	 * @param string $action The form.
	 * @return array<string, mixed>|null
	 */
	public static function claim( string $action ): ?array {
		$held = get_transient( self::key() );
		if ( ! is_array( $held ) || ( $held['action'] ?? '' ) !== $action ) {
			return null;
		}
		delete_transient( self::key() );
		return (array) ( $held['payload'] ?? array() );
	}

	/**
	 * Throw the held request away.
	 */
	public static function drop(): void {
		delete_transient( self::key() );
	}

	/**
	 * Print the warning page for a held request. Returns false when nothing is waiting.
	 *
	 * @param Plugin $plugin Container.
	 * @param string $action The form that finishes the request.
	 * @param string $base   Screen URL to return to.
	 * @param string $what   Words for what will be made, such as "this snapshot" or "this file".
	 */
	public static function render( Plugin $plugin, string $action, string $base, string $what ): bool {
		$held = get_transient( self::key() );
		if ( ! is_array( $held ) || ( $held['action'] ?? '' ) !== $action ) {
			return false;
		}
		$ids    = array_map( 'intval', (array) ( $held['payload']['ids'] ?? array() ) );
		$titles = self::titles( $plugin, $ids );
		if ( array() === $titles ) {
			self::drop();
			return false;
		}
		echo '<div class="notice notice-warning inline estasoft-hp-confirm" role="alertdialog" aria-labelledby="estasoft-hp-confirm-title"><h2 id="estasoft-hp-confirm-title">' . esc_html__( 'Put Sensitive records in this file?', 'estasoft-handover-passport' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: words such as "this file". */
				__( 'These records are marked Sensitive. If you go on, they will be written into %s and anyone who gets the file can read them.', 'estasoft-handover-passport' ),
				$what
			)
		) . '</p><ul class="ul-disc">';
		foreach ( $titles as $title ) {
			echo '<li><strong>' . esc_html( $title ) . '</strong></li>';
		}
		echo '</ul><p>' . esc_html__( 'Their passwords and keys are never stored in Passport, so none can appear. The file will list these records by name at the end.', 'estasoft-handover-passport' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		Form::nonce( $action, false );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="return" value="' . esc_url( $base ) . '">';
		echo '<p><button type="submit" name="op" value="confirm" class="button button-primary">' . esc_html__( 'Confirm and continue', 'estasoft-handover-passport' ) . '</button> ';
		echo '<button type="submit" name="op" value="cancel" class="button">' . esc_html__( 'Cancel', 'estasoft-handover-passport' ) . '</button></p></form></div>';
		return true;
	}

	/**
	 * Names of the Sensitive records among the given IDs.
	 *
	 * @param Plugin    $plugin Container.
	 * @param list<int> $ids    Record IDs.
	 * @return array<int, string> ID to title.
	 */
	public static function titles( Plugin $plugin, array $ids ): array {
		$out = array();
		foreach ( $plugin->records()->everything() as $record ) {
			if ( ! empty( $record['sensitive'] ) && in_array( (int) $record['id'], $ids, true ) ) {
				$out[ (int) $record['id'] ] = (string) $record['title'];
			}
		}
		return $out;
	}

	/**
	 * Print the tick boxes for the Sensitive records the person may add. Prints nothing when they may not add any.
	 *
	 * @param Plugin $plugin Container.
	 */
	public static function fieldset( Plugin $plugin ): void {
		if ( ! Capabilities::canSeeSensitive() ) {
			return;
		}
		$titles = array();
		foreach ( $plugin->records()->everything() as $record ) {
			if ( ! empty( $record['sensitive'] ) ) {
				$titles[ (int) $record['id'] ] = (string) $record['title'];
			}
		}
		if ( array() === $titles ) {
			return;
		}
		asort( $titles );
		echo '<fieldset class="estasoft-hp-sensitive"><legend>' . esc_html__( 'Sensitive records', 'estasoft-handover-passport' ) . '</legend>';
		echo '<p class="description">' . esc_html__( 'Left out unless you tick them. You will be asked to confirm before they are written into a file. Packs for the client and the Emergency sheet are better without them.', 'estasoft-handover-passport' ) . '</p>';
		foreach ( $titles as $id => $title ) {
			echo '<label><input type="checkbox" name="sensitive[]" value="' . esc_attr( (string) $id ) . '"> ' . esc_html( $title ) . '</label><br>';
		}
		echo '</fieldset>';
	}

	/**
	 * Transient key for the current person.
	 */
	private static function key(): string {
		return 'estasoft_hp_held_' . get_current_user_id();
	}
}
