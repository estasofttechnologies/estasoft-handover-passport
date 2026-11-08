<?php
/**
 * The Handover screen: checklist, snapshots, exports, comparison, notes and the export log (HND-1 to HND-7, EXP-9).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Security\Capabilities;

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
			'log'       => __( 'Exports', 'estasoft-handover-passport' ),
			'notes'     => __( 'Notes', 'estasoft-handover-passport' ),
			'export'    => __( 'New export', 'estasoft-handover-passport' ),
			'compare'   => __( 'Compare', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function hiddenTabs(): array {
		return array(
			'export'  => 'log',
			'compare' => 'snapshots',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function describe( string $tab ): string {
		switch ( $tab ) {
			case 'snapshots':
				$count = count( $this->plugin->snapshots()->all() );
				return sprintf(
					/* translators: %d: number of snapshots. */
					_n( '%d snapshot. A snapshot is a sealed copy of the register that cannot be changed.', '%d snapshots. A snapshot is a sealed copy of the register that cannot be changed.', $count, 'estasoft-handover-passport' ),
					$count
				);
			case 'log':
				$count = count( $this->plugin->exports()->recent( Capabilities::can( Capabilities::CONFIGURE ) ? 0 : get_current_user_id() ) );
				return sprintf(
					/* translators: %d: number of exports. */
					_n( '%d export made from this site.', '%d exports made from this site.', $count, 'estasoft-handover-passport' ),
					$count
				);
			case 'export':
				return __( 'Choose a pack and a format. Packs never hold a password or key, because Passport does not store any.', 'estasoft-handover-passport' );
			case 'compare':
				return __( 'See what changed between a snapshot and the Passport as it is now, or between two snapshots.', 'estasoft-handover-passport' );
			case 'notes':
				return __( 'Notes for whoever takes over, and where the Passport stands in the handover.', 'estasoft-handover-passport' );
			default:
				$progress = $this->plugin->handover()->progress();
				return sprintf(
					/* translators: 1: number of checklist items done, 2: number of items counted. */
					__( '%1$d of %2$d ready. Finish the list, then take a snapshot and export the pack.', 'estasoft-handover-passport' ),
					$progress['done'],
					$progress['total']
				);
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function action( string $tab ): ?array {
		if ( ! Capabilities::can( Capabilities::EXPORT ) ) {
			return null;
		}
		if ( 'log' === $tab ) {
			return array(
				'label' => __( 'New export', 'estasoft-handover-passport' ),
				'url'   => add_query_arg( array( 'page' => self::SLUG, 'tab' => 'export' ), admin_url( 'admin.php' ) ),
			);
		}
		if ( 'checklist' === $tab || 'snapshots' === $tab ) {
			return array(
				'label' => __( 'Take snapshot', 'estasoft-handover-passport' ),
				'url'   => add_query_arg( array( 'page' => self::SLUG, 'tab' => 'snapshots', 'make' => '1' ), admin_url( 'admin.php' ) ) . '#estasoft-hp-make-snapshot',
				'icon'  => 'archive',
			);
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function extra( string $tab ): string {
		if ( 'checklist' === $tab && Capabilities::can( Capabilities::EXPORT ) ) {
			return '<a class="btn" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'export' ), admin_url( 'admin.php' ) ) ) . '">' . Icons::svg( 'download', 16 ) . esc_html__( 'Export pack', 'estasoft-handover-passport' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is fixed markup.
		}
		if ( 'snapshots' === $tab && Capabilities::can( Capabilities::VIEW ) ) {
			return '<a class="btn" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'compare' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Compare', 'estasoft-handover-passport' ) . '</a>';
		}
		return '';
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
