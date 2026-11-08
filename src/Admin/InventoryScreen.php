<?php
/**
 * Inventory: Stack, Licences, Domains, Hosting, Services (ADM-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\Kinds;

defined( 'ABSPATH' ) || exit;

/**
 * The Inventory screen.
 */
final class InventoryScreen extends Screen {

	public const SLUG = 'estasoft-passport-inventory';

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
		return __( 'Inventory', 'estasoft-handover-passport' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tabs(): array {
		return array(
			'stack'    => __( 'Stack', 'estasoft-handover-passport' ),
			'licences' => __( 'Licences', 'estasoft-handover-passport' ),
			'domains'  => __( 'Domains', 'estasoft-handover-passport' ),
			'hosting'  => __( 'Hosting', 'estasoft-handover-passport' ),
			'services' => __( 'Services', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function tab( string $tab ): void {
		$register = new RegisterView( $this->plugin );
		$keep     = $this->keep( $tab );

		switch ( $tab ) {
			case 'stack':
				if ( in_array( Request::key( 'view' ), array( 'edit', 'delete' ), true ) && Kinds::STACKNOTE === Request::key( 'kind' ) ) {
					$register->render( Kinds::STACKNOTE, self::SLUG, $keep );
					break;
				}
				( new StackView( $this->plugin ) )->render( self::SLUG );
				break;
			case 'licences':
				$register->render( Kinds::LICENCE, self::SLUG, $keep );
				break;
			case 'domains':
				$register->render( Kinds::DOMAIN, self::SLUG, $keep );
				break;
			case 'hosting':
				$this->hosting( $register, $keep );
				break;
			default:
				$register->render( Kinds::SERVICE, self::SLUG, $keep );
		}
	}

	/**
	 * Hosting shows three registers. The one being edited takes the whole tab.
	 *
	 * @param RegisterView          $register Register view.
	 * @param array<string, string> $keep     Query arguments.
	 */
	private function hosting( RegisterView $register, array $keep ): void {
		$editing = Request::key( 'kind' );
		$kinds   = array( Kinds::HOST, Kinds::ENV, Kinds::LAYER );
		if ( in_array( Request::key( 'view' ), array( 'edit', 'delete' ), true ) && in_array( $editing, $kinds, true ) ) {
			$register->render( $editing, self::SLUG, $keep );
			return;
		}
		foreach ( $kinds as $kind ) {
			$register->render( $kind, self::SLUG, $keep );
		}
	}
}
