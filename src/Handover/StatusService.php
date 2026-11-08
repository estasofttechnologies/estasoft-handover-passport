<?php
/**
 * The status of the Passport: Draft, Active or Handed over (PRT-8).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Handover;

use Estasoft\HandoverPassport\Changes\ChangeLog;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Passport\Passport;

defined( 'ABSPATH' ) || exit;

/**
 * Changes the status and writes the change to the log. The status moves to Handed over when a snapshot is
 * acknowledged, and a person can move it back to Active.
 */
final class StatusService {

	/**
	 * Constructor.
	 *
	 * @param Passport  $passport  Passport record.
	 * @param ChangeLog $changeLog Change log.
	 */
	public function __construct( private Passport $passport, private ChangeLog $changeLog ) {}

	/**
	 * Set the status.
	 *
	 * @param string $status Draft, active or handed over.
	 * @param string $note   Optional words for the log.
	 * @throws ValidationError When the status is not one of the three.
	 */
	public function set( string $status, string $note = '' ): void {
		$labels = Passport::statusLabels();
		if ( ! isset( $labels[ $status ] ) ) {
			throw new ValidationError( __( 'Status', 'estasoft-handover-passport' ), __( 'Pick one of the listed choices.', 'estasoft-handover-passport' ) );
		}
		$before = (string) $this->passport->get( 'status', Passport::STATUS_DRAFT );
		if ( $before === $status ) {
			return;
		}
		$this->passport->update( array( 'status' => $status ) );
		$this->changeLog->add( 'status_changed', 'passport', 0, $labels[ $status ], array( 'status' => $labels[ $before ] ?? $before ), array( 'status' => $labels[ $status ] ), $note );
	}

	/**
	 * Current status key.
	 */
	public function current(): string {
		return (string) $this->passport->get( 'status', Passport::STATUS_DRAFT );
	}
}
