<?php
/**
 * The checklist to run after a restore (URL-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Services;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The critical addresses, each to open and look at, followed by the checks only a person can make: log in, place a test
 * order, see that a form sends mail. The addresses come from the register; the other checks are a short list that
 * the agency can change.
 */
final class RestoreChecklist {

	public const SETTING    = 'restore_checks';
	public const MAX_LINES  = 20;
	public const MAX_LENGTH = 300;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * The checks a person makes by hand when nothing else has been set.
	 *
	 * @return list<string>
	 */
	public static function defaults(): array {
		return array(
			__( 'Log in to the admin area with an administrator account.', 'estasoft-handover-passport' ),
			__( 'Open an existing page or post in the editor and save it without changing anything.', 'estasoft-handover-passport' ),
			__( 'Send each contact or enquiry form once and confirm the email arrives.', 'estasoft-handover-passport' ),
			__( 'If the site sells anything, place a test order and see it in the order list.', 'estasoft-handover-passport' ),
			__( 'Check that images and styles load on the home page and on one inner page.', 'estasoft-handover-passport' ),
			__( 'Check that the address starts with https and the browser shows no certificate warning.', 'estasoft-handover-passport' ),
			__( 'Ask the site owner to look at the site and say whether anything seems wrong.', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * The checks made by hand: the saved list, or the shipped one.
	 *
	 * @return list<string>
	 */
	public function manual(): array {
		$saved = $this->plugin->settings()->get( self::SETTING, array() );
		$saved = is_array( $saved ) ? array_values( array_filter( array_map( static fn( $line ): string => is_scalar( $line ) ? trim( (string) $line ) : '', $saved ), static fn( string $line ): bool => '' !== $line ) ) : array();
		return array() === $saved ? self::defaults() : $saved;
	}

	/**
	 * Whether the agency has changed the list.
	 */
	public function isCustom(): bool {
		$saved = $this->plugin->settings()->get( self::SETTING, array() );
		return is_array( $saved ) && array() !== $saved;
	}

	/**
	 * Save the checks made by hand, one per line. An empty text goes back to the shipped list.
	 *
	 * @param string $text Lines of text.
	 * @throws ValidationError When there are too many lines or a line is too long.
	 * @throws \Estasoft\HandoverPassport\Security\SecretFound When a line looks like a secret.
	 */
	public function saveManual( string $text ): void {
		$lines = array();
		foreach ( (array) preg_split( '/\R/', sanitize_textarea_field( $text ) ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			if ( mb_strlen( $line ) > self::MAX_LENGTH ) {
				throw new ValidationError( __( 'Checks', 'estasoft-handover-passport' ), sprintf( /* translators: %d: number of characters. */ __( 'Keep each check under %d characters.', 'estasoft-handover-passport' ), self::MAX_LENGTH ) );
			}
			$lines[] = $line;
		}
		if ( count( $lines ) > self::MAX_LINES ) {
			throw new ValidationError( __( 'Checks', 'estasoft-handover-passport' ), sprintf( /* translators: %d: number of lines. */ __( 'Keep the list to %d checks or fewer.', 'estasoft-handover-passport' ), self::MAX_LINES ) );
		}
		$this->plugin->secretGuard()->assertClean( $lines, __( 'Checks', 'estasoft-handover-passport' ) );
		$this->plugin->settings()->set( self::SETTING, $lines );
		$this->plugin->changeLog()->add( 'settings_changed', 'settings', 0, '', array(), array(), __( 'After-restore checks changed', 'estasoft-handover-passport' ) );
	}

	/**
	 * Everything to check, addresses first. A Sensitive record never appears.
	 *
	 * @return list<array{kind: string, label: string, detail: string}>
	 */
	public function items(): array {
		$items = array();
		foreach ( $this->plugin->records()->listKind( Kinds::URL, false ) as $record ) {
			if ( ! empty( $record['hidden'] ) ) {
				continue;
			}
			$fields = (array) $record['fields'];
			$status = (int) ( $fields['expected_status'] ?? 200 );
			$status = $status > 0 ? $status : 200;
			$text   = trim( (string) ( $fields['expected_text'] ?? '' ) );
			$detail = (string) ( $fields['address'] ?? '' ) . '  ·  ' . sprintf( /* translators: %d: HTTP status such as 200. */ __( 'Expect status %d', 'estasoft-handover-passport' ), $status )
				. ( '' !== $text ? ' ' . sprintf( /* translators: %s: words that must appear on the page. */ __( 'and the words "%s"', 'estasoft-handover-passport' ), $text ) : '' );
			$why = trim( (string) ( $fields['why'] ?? '' ) );
			$items[] = array(
				'kind'   => 'url',
				/* translators: %s: name of a critical address such as Home page. */
				'label'  => sprintf( __( 'Open: %s', 'estasoft-handover-passport' ), (string) $record['title'] ),
				'detail' => $detail . ( '' !== $why ? '  ·  ' . $why : '' ),
			);
		}
		foreach ( $this->manual() as $line ) {
			$items[] = array( 'kind' => 'manual', 'label' => $line, 'detail' => '' );
		}
		return $items;
	}
}
