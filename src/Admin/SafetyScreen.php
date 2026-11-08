<?php
/**
 * Safety nets: Backups now; Runbooks and Critical URLs arrive with the safety-net milestone (ADM-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\Kinds;

defined( 'ABSPATH' ) || exit;

/**
 * The Safety nets screen.
 */
final class SafetyScreen extends Screen {

	public const SLUG = 'estasoft-passport-safety';

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Safety nets', 'estasoft-handover-passport' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tabs(): array {
		return array(
			'backups' => __( 'Backups', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function tab( string $tab ): void {
		( new RegisterView( $this->plugin ) )->render( Kinds::BACKUP, self::SLUG, $this->keep( $tab ) );
	}
}
