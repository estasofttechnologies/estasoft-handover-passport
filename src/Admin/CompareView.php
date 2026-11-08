<?php
/**
 * The Compare tab (HND-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Export\ExportOptions;
use Estasoft\HandoverPassport\Export\ExportRefused;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Lists what was added, removed and changed between two snapshots, or between a snapshot and the live Passport.
 * Sensitive fields are reported as changed and never shown.
 */
final class CompareView {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Print the tab.
	 */
	public function render(): void {
		$snapshots = $this->plugin->snapshots()->all();
		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Compare', 'estasoft-handover-passport' ) . '</h2>';
		echo '<p>' . esc_html__( 'See what changed between two snapshots, or between a snapshot and the Passport as it is now. Only the records the snapshot\'s pack includes are compared. Fields marked Sensitive show only that they changed.', 'estasoft-handover-passport' ) . '</p>';
		if ( count( $snapshots ) < 1 ) {
			echo '<p>' . esc_html__( 'Make a snapshot first.', 'estasoft-handover-passport' ) . '</p></div>';
			return;
		}
		$a = Request::int( 'a' );
		$b = Request::key( 'b' );
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( HandoverScreen::SLUG ) . '"><input type="hidden" name="tab" value="compare">';
		echo '<p><label for="estasoft-hp-cmp-a"><strong>' . esc_html__( 'Older', 'estasoft-handover-passport' ) . '</strong></label><br><select name="a" id="estasoft-hp-cmp-a">';
		foreach ( $snapshots as $row ) {
			echo '<option value="' . esc_attr( (string) $row['id'] ) . '"' . selected( $a, (int) $row['id'], false ) . '>' . esc_html( $this->label( $row ) ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a fixed attribute.
		}
		echo '</select></p><p><label for="estasoft-hp-cmp-b"><strong>' . esc_html__( 'Compared with', 'estasoft-handover-passport' ) . '</strong></label><br><select name="b" id="estasoft-hp-cmp-b"><option value="live"' . selected( $b, 'live', false ) . '>' . esc_html__( 'The Passport now', 'estasoft-handover-passport' ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a fixed attribute.
		foreach ( $snapshots as $row ) {
			echo '<option value="' . esc_attr( (string) $row['id'] ) . '"' . selected( $b, (string) $row['id'], false ) . '>' . esc_html( $this->label( $row ) ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a fixed attribute.
		}
		echo '</select></p><p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Compare', 'estasoft-handover-passport' ) . '</button></p></form></div>';

		if ( $a < 1 || '' === $b ) {
			return;
		}
		$options = new ExportOptions( Capabilities::canSeeSensitive(), array(), get_current_user_id() );
		try {
			$diff = $this->plugin->snapshots()->compare( $a, 'live' === $b ? 'live' : absint( $b ), $options );
		} catch ( ExportRefused $e ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
			return;
		}
		if ( null === $diff ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'One of those snapshots does not exist.', 'estasoft-handover-passport' ) . '</p></div>';
			return;
		}
		$this->result( $diff );
	}

	/**
	 * Print the differences.
	 *
	 * @param array<string, mixed> $diff Result of the comparison.
	 */
	private function result( array $diff ): void {
		$added   = (array) $diff['added'];
		$removed = (array) $diff['removed'];
		$changed = (array) $diff['changed'];
		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Result', 'estasoft-handover-passport' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: records added, 2: removed, 3: changed, 4: unchanged. */
				__( '%1$d added, %2$d removed, %3$d changed, %4$d unchanged.', 'estasoft-handover-passport' ),
				count( $added ),
				count( $removed ),
				count( $changed ),
				(int) $diff['unchanged']
			)
		) . '</p>';
		if ( ! empty( $diff['notes_changed'] ) ) {
			echo '<p>' . esc_html__( 'The notes to the successor changed.', 'estasoft-handover-passport' ) . '</p>';
		}
		if ( (int) ( $diff['sensitive_skipped'] ?? 0 ) > 0 ) {
			echo '<p class="description">' . esc_html__( 'Sensitive records are not part of this comparison, because you do not have permission to see them.', 'estasoft-handover-passport' ) . '</p>';
		}
		if ( array() === $added && array() === $removed && array() === $changed && empty( $diff['notes_changed'] ) ) {
			echo '<p><strong>' . esc_html__( 'Nothing changed.', 'estasoft-handover-passport' ) . '</strong></p></div>';
			return;
		}
		echo '</div>';
		$this->list( __( 'Added', 'estasoft-handover-passport' ), $added );
		$this->list( __( 'Removed', 'estasoft-handover-passport' ), $removed );
		if ( array() !== $changed ) {
			echo '<h3>' . esc_html__( 'Changed', 'estasoft-handover-passport' ) . '</h3><table class="widefat striped estasoft-hp-table"><thead><tr><th scope="col">' . esc_html__( 'Record', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Field', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Before', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'After', 'estasoft-handover-passport' ) . '</th></tr></thead><tbody>';
			foreach ( $changed as $item ) {
				foreach ( (array) $item['fields'] as $field ) {
					$hidden = __( 'Changed (value not shown)', 'estasoft-handover-passport' );
					echo '<tr><td>' . esc_html( ( '' === (string) $item['title'] ? __( 'A Sensitive record', 'estasoft-handover-passport' ) : (string) $item['title'] ) . ' · ' . (string) $item['section'] ) . '</td><td>' . esc_html( (string) $field['label'] ) . '</td>';
					echo '<td>' . esc_html( ! empty( $field['masked'] ) ? $hidden : (string) $field['before'] ) . '</td><td>' . esc_html( ! empty( $field['masked'] ) ? '' : (string) $field['after'] ) . '</td></tr>';
				}
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * A plain list of records.
	 *
	 * @param string                    $heading Heading.
	 * @param list<array<string, mixed>> $items   Records.
	 */
	private function list( string $heading, array $items ): void {
		if ( array() === $items ) {
			return;
		}
		echo '<h3>' . esc_html( $heading ) . '</h3><ul class="ul-disc">';
		foreach ( $items as $item ) {
			echo '<li>' . esc_html( ( '' === (string) $item['title'] ? __( 'A Sensitive record', 'estasoft-handover-passport' ) : (string) $item['title'] ) . ' · ' . (string) $item['section'] ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Words for a snapshot in a list.
	 *
	 * @param array<string, mixed> $row Snapshot row.
	 */
	private function label( array $row ): string {
		return (string) $row['name'] . ' (' . Dates::display( (string) $row['created_at'] ) . ')';
	}
}
