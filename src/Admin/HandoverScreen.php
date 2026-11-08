<?php
/**
 * The Handover screen: checklist, snapshots, exports, comparison, notes and the export log (HND-1 to HND-7, EXP-9).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Everything about handing the site over.
 */
final class HandoverScreen extends Screen {

	public const SLUG = 'estasoft-passport-handover';

	/**
	 * Attach the form handlers of the tabs.
	 */
	public function register(): void {
		( new ChecklistView( $this->plugin ) )->register();
		( new SnapshotView( $this->plugin ) )->register();
		( new ExportView( $this->plugin ) )->register();
		( new NotesView( $this->plugin ) )->register();
	}

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
		return __( 'Handover', 'estasoft-handover-passport' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tabs(): array {
		return array(
			'checklist' => __( 'Checklist', 'estasoft-handover-passport' ),
			'snapshots' => __( 'Snapshots', 'estasoft-handover-passport' ),
			'export'    => __( 'Export', 'estasoft-handover-passport' ),
			'compare'   => __( 'Compare', 'estasoft-handover-passport' ),
			'notes'     => __( 'Notes and status', 'estasoft-handover-passport' ),
			'log'       => __( 'Export log', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function tab( string $tab ): void {
		$base = add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) );
		switch ( $tab ) {
			case 'snapshots':
				( new SnapshotView( $this->plugin ) )->render( $base );
				break;
			case 'export':
				( new ExportView( $this->plugin ) )->render( $base );
				break;
			case 'compare':
				( new CompareView( $this->plugin ) )->render();
				break;
			case 'notes':
				( new NotesView( $this->plugin ) )->render( $base );
				break;
			case 'log':
				( new ExportView( $this->plugin ) )->renderLog();
				break;
			default:
				( new ChecklistView( $this->plugin ) )->render( $base );
		}
	}
}
