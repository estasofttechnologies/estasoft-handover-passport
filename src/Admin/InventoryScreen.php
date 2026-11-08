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
	protected function describe( string $tab ): string {
		if ( ! $this->onList() ) {
			return '';
		}
		switch ( $tab ) {
			case 'licences':
				return $this->countLine( Kinds::LICENCE ) . ' ' . __( 'Passport records where each key lives and never the key itself.', 'estasoft-handover-passport' );
			case 'services':
				return $this->countLine( Kinds::SERVICE ) . ' ' . __( 'The outside services the site depends on, and who looks after each.', 'estasoft-handover-passport' );
			case 'domains':
				return __( 'Every domain the site uses, who holds it and when it expires.', 'estasoft-handover-passport' );
			case 'hosting':
				return __( 'Who hosts the site, the environments it runs in and the layers around it.', 'estasoft-handover-passport' );
			default:
				return __( 'What the site is built from, read from the site itself.', 'estasoft-handover-passport' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function action( string $tab ): ?array {
		switch ( $tab ) {
			case 'licences':
				return $this->addAction( Kinds::LICENCE, $tab );
			case 'services':
				return $this->addAction( Kinds::SERVICE, $tab );
			case 'domains':
				return $this->addAction( Kinds::DOMAIN, $tab );
			case 'hosting':
				return $this->addAction( Kinds::HOST, $tab );
			default:
				return null;
		}
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
				$register->render( Kinds::LICENCE, self::SLUG, $keep, true );
				break;
			case 'domains':
				( new DomainView( $this->plugin ) )->render( self::SLUG, $keep );
				break;
			case 'hosting':
				$this->hosting( $register, $keep );
				break;
			default:
				$register->render( Kinds::SERVICE, self::SLUG, $keep, true );
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
			$register->render( $kind, self::SLUG, $keep, Kinds::HOST === $kind, true );
		}
	}
}
