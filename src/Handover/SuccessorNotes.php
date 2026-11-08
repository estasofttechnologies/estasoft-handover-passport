<?php
/**
 * The notes the sender leaves for whoever takes over (HND-7).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Handover;

use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\SecretFound;

defined( 'ABSPATH' ) || exit;

/**
 * One free-text field in the Passport. It goes into the Client pack and the Technical pack.
 */
final class SuccessorNotes {

	public const MAX = 10000;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * The saved text.
	 */
	public function get(): string {
		return (string) $this->plugin->passport()->get( 'successor_notes', '' );
	}

	/**
	 * Save the text.
	 *
	 * @param string $text New text, a small Markdown subset.
	 * @throws ValidationError When it is too long.
	 * @throws SecretFound     When it looks like a password, key or token (SEC-6).
	 */
	public function save( string $text ): void {
		$text = trim( str_replace( array( "\r\n", "\r" ), "\n", $text ) );
		if ( mb_strlen( $text ) > self::MAX ) {
			throw new ValidationError( __( 'Notes to the successor', 'estasoft-handover-passport' ), __( 'Keep the notes under 10000 characters.', 'estasoft-handover-passport' ) );
		}
		$this->plugin->secretGuard()->assertClean( $text, __( 'Notes to the successor', 'estasoft-handover-passport' ) );
		$before = $this->get();
		if ( $before === $text ) {
			return;
		}
		$this->plugin->passport()->update( array( 'successor_notes' => $text ) );
		$this->plugin->changeLog()->add(
			'successor_notes_changed',
			'passport',
			0,
			__( 'Notes to the successor', 'estasoft-handover-passport' ),
			array( 'length' => mb_strlen( $before ) ),
			array( 'length' => mb_strlen( $text ) )
		);
	}
}
