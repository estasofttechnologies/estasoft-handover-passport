<?php
/**
 * Quick add: shortcuts in the admin bar's New menu (ONB-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Security\Capabilities;
use WP_Admin_Bar;

defined( 'ABSPATH' ) || exit;

/**
 * From any screen of the admin area, the New menu in the admin bar lists the four things people add most often: a
 * licence, a domain, a person and a maintenance note. Each opens the form that already exists for it, with its own
 * checks, so nothing is saved without the same rules as anywhere else. Only people who may edit see it, and it is not
 * added on the front end.
 */
final class QuickAdd {

	public const GROUP = 'estasoft-hp-quick-add';

	/**
	 * Attach to the admin bar.
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add' ), 80 );
	}

	/**
	 * Add the shortcuts.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 */
	public function add( $bar ): void {
		if ( ! is_admin() || ! $bar instanceof WP_Admin_Bar || ! Capabilities::can( Capabilities::EDIT ) ) {
			return;
		}
		// The New menu exists only for people who may create posts, pages, media or users; others get a small menu of ours.
		$parent = 'new-content';
		if ( null === $bar->get_node( $parent ) ) {
			$parent = self::GROUP;
			$bar->add_node(
				array(
					'id'    => $parent,
					'title' => __( 'Passport: add', 'estasoft-handover-passport' ),
					'href'  => false,
				)
			);
		}
		foreach ( self::items() as $slug => $item ) {
			$bar->add_node(
				array(
					'parent' => $parent,
					'id'     => self::GROUP . '-' . $slug,
					'title'  => $item['title'],
					'href'   => $item['url'],
				)
			);
		}
	}

	/**
	 * The four shortcuts: title and address of the form.
	 *
	 * @return array<string, array{title: string, url: string}>
	 */
	public static function items(): array {
		$admin = admin_url( 'admin.php' );
		return array(
			'licence' => array(
				'title' => __( 'Passport: licence', 'estasoft-handover-passport' ),
				'url'   => add_query_arg( array( 'page' => InventoryScreen::SLUG, 'tab' => 'licences', 'view' => 'edit', 'kind' => Kinds::LICENCE, 'id' => 0 ), $admin ),
			),
			'domain'  => array(
				'title' => __( 'Passport: domain', 'estasoft-handover-passport' ),
				'url'   => add_query_arg( array( 'page' => InventoryScreen::SLUG, 'tab' => 'domains', 'view' => 'edit', 'kind' => Kinds::DOMAIN, 'id' => 0 ), $admin ),
			),
			'person'  => array(
				'title' => __( 'Passport: person', 'estasoft-handover-passport' ),
				'url'   => add_query_arg( array( 'page' => PeopleScreen::SLUG, 'tab' => 'people', 'view' => 'edit', 'id' => 0 ), $admin ),
			),
			'note'    => array(
				'title' => __( 'Passport: maintenance note', 'estasoft-handover-passport' ),
				'url'   => add_query_arg( array( 'page' => HistoryScreen::SLUG, 'tab' => 'notes' ), $admin ),
			),
		);
	}
}
