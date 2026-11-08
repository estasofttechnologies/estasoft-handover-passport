<?php
/**
 * History: the change log and maintenance notes (ADM-1, CHG-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The History screen.
 */
final class HistoryScreen extends Screen {

	public const SLUG        = 'estasoft-passport-history';
	public const NOTE_ACTION = 'estasoft_hp_add_note';
	private const PER_PAGE   = 25;

	/**
	 * Attach the note handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::NOTE_ACTION, array( $this, 'handleNote' ) );
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
		return __( 'History', 'estasoft-handover-passport' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tabs(): array {
		return array(
			'changes' => __( 'Change log', 'estasoft-handover-passport' ),
			'notes'   => __( 'Notes', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function tab( string $tab ): void {
		if ( 'notes' === $tab ) {
			$this->notes();
			return;
		}
		$this->changes();
	}

	/**
	 * The change log with filters.
	 */
	private function changes(): void {
		$kind    = Request::key( 'kind' );
		$page_no = max( 1, Request::int( 'paged' ) );
		$filters = array();
		if ( '' !== $kind && array_key_exists( $kind, ChangeText::filterKinds() ) ) {
			$filters['kind'] = $kind;
		}
		$repo  = $this->plugin->changeLog()->repo();
		$total = $repo->countFor( $filters );
		$rows  = $this->plugin->changeLog()->forViewer( $repo->search( $filters, self::PER_PAGE, ( $page_no - 1 ) * self::PER_PAGE ), Capabilities::canSeeSensitive() );
		$base  = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'changes' ), admin_url( 'admin.php' ) );

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="estasoft-hp-filterbar">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="changes">';
		echo '<label for="estasoft-hp-kind">' . esc_html__( 'Show', 'estasoft-handover-passport' ) . '</label> <select id="estasoft-hp-kind" name="kind"><option value="">' . esc_html__( 'Everything', 'estasoft-handover-passport' ) . '</option>';
		foreach ( ChangeText::filterKinds() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $kind, $key, false ) . '>' . esc_html( $label ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a fixed attribute.
		}
		echo '</select> <button type="submit" class="button">' . esc_html__( 'Filter', 'estasoft-handover-passport' ) . '</button></form>';

		if ( array() === $rows ) {
			echo '<div class="estasoft-hp-empty"><p>' . esc_html__( 'No changes are logged yet. Updates, new administrators and edits to records appear here with who made them.', 'estasoft-handover-passport' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped estasoft-hp-table"><thead><tr><th scope="col">' . esc_html__( 'When', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'What', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Who', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Source', 'estasoft-handover-passport' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( Dates::display( (string) $row['changed_at'], true ) ) . '</td><td>' . esc_html( ChangeText::describe( $row ) ) . '</td><td>' . esc_html( (string) $row['actor_label'] ) . '</td><td>' . esc_html( (string) $row['source'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', '' === $kind ? $base : add_query_arg( 'kind', $kind, $base ) ),
						'format'    => '',
						'current'   => $page_no,
						'total'     => $pages,
						'prev_text' => __( 'Previous', 'estasoft-handover-passport' ),
						'next_text' => __( 'Next', 'estasoft-handover-passport' ),
					)
				)
			) . '</div></div>';
		}
	}

	/**
	 * Maintenance notes (CHG-3).
	 */
	private function notes(): void {
		$types = self::noteTypes();
		$can   = Capabilities::can( Capabilities::EDIT );

		if ( $can ) {
			$state  = FormState::take( 'note' );
			$input  = null === $state ? array() : $state['input'];
			$errors = null === $state ? array() : $state['errors'];
			echo '<div class="estasoft-hp-form"><h2>' . esc_html__( 'Add a note', 'estasoft-handover-passport' ) . '</h2>';
			if ( array() !== $errors ) {
				echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html( (string) reset( $errors ) ) . '</p></div>';
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::NOTE_ACTION ) . '">';
			Form::nonce( self::NOTE_ACTION );
			Form::row( 'note_date', array( 'type' => 'date', 'label' => __( 'Date', 'estasoft-handover-passport' ) ), $input['note_date'] ?? Clock::today() );
			Form::row( 'note_type', array( 'type' => 'select', 'label' => __( 'Type', 'estasoft-handover-passport' ), 'options' => $types, 'required' => true ), $input['note_type'] ?? '' );
			Form::row( 'note_asset', array( 'type' => 'text', 'label' => __( 'About (optional)', 'estasoft-handover-passport' ), 'help' => __( 'For example a plugin, a domain or a server.', 'estasoft-handover-passport' ), 'max' => 120 ), $input['note_asset'] ?? '' );
			Form::row( 'note_text', array( 'type' => 'textarea', 'label' => __( 'What was done', 'estasoft-handover-passport' ), 'required' => true, 'help' => __( 'Plain text. Never paste a password or key here.', 'estasoft-handover-passport' ) ), $input['note_text'] ?? '', $errors['note_text'] ?? '' );
			echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Add note', 'estasoft-handover-passport' ) . '</button></p></form></div>';
		}

		$rows = $this->plugin->changeLog()->repo()->search( array( 'kind' => 'maintenance_note' ), 50 );
		echo '<div class="estasoft-hp-register"><h2>' . esc_html__( 'Maintenance log', 'estasoft-handover-passport' ) . '</h2>';
		if ( array() === $rows ) {
			echo '<div class="estasoft-hp-empty"><p>' . esc_html__( 'Notes about work done on the site, such as updates, fixes and migrations, appear here. Agencies can use this as a maintenance log.', 'estasoft-handover-passport' ) . '</p></div></div>';
			return;
		}
		echo '<table class="widefat striped estasoft-hp-table"><thead><tr><th scope="col">' . esc_html__( 'Date', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Type', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'About', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Note', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'By', 'estasoft-handover-passport' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$data = (array) $row['after_data'];
			echo '<tr><td>' . esc_html( Dates::displayDate( (string) ( $data['date'] ?? Dates::datePart( (string) $row['changed_at'] ) ) ) ) . '</td><td>' . esc_html( $types[ (string) ( $data['type'] ?? '' ) ] ?? (string) $row['subject_label'] ) . '</td><td>' . esc_html( (string) ( $data['asset'] ?? '' ) ) . '</td><td>' . esc_html( (string) $row['note'] ) . '</td><td>' . esc_html( (string) $row['actor_label'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Note types (CHG-3).
	 *
	 * @return array<string, string>
	 */
	public static function noteTypes(): array {
		return array(
			'update'        => __( 'Update', 'estasoft-handover-passport' ),
			'fix'           => __( 'Fix', 'estasoft-handover-passport' ),
			'configuration' => __( 'Configuration', 'estasoft-handover-passport' ),
			'incident'      => __( 'Incident', 'estasoft-handover-passport' ),
			'migration'     => __( 'Migration', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Save a note.
	 */
	public function handleNote(): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to add notes.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NOTE_ACTION );

		$back  = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'notes' ), admin_url( 'admin.php' ) );
		$type  = sanitize_key( Request::post( 'note_type' ) );
		$date  = Request::post( 'note_date' );
		$asset = Request::post( 'note_asset' );
		$text  = Request::postedTextarea( 'note_text' );
		$types = self::noteTypes();
		$error = '';

		if ( ! isset( $types[ $type ] ) || '' === trim( $text ) ) {
			$error = __( 'Pick a type and say what was done.', 'estasoft-handover-passport' );
		} elseif ( '' !== $date && ! Dates::isDate( $date ) ) {
			$error = __( 'Use a real date in the form YYYY-MM-DD.', 'estasoft-handover-passport' );
		} else {
			try {
				$this->plugin->secretGuard()->assertClean( $text, __( 'Note', 'estasoft-handover-passport' ) );
				$this->plugin->secretGuard()->assertClean( $asset, __( 'Asset', 'estasoft-handover-passport' ) );
			} catch ( SecretFound $e ) {
				$error = $e->getMessage();
			}
		}
		if ( '' !== $error ) {
			FormState::put( $this->plugin->secretGuard(), 'note', array( 'note_date' => $date, 'note_type' => $type, 'note_asset' => $asset, 'note_text' => $text ), array( 'note_text' => $error ) );
			wp_safe_redirect( $back );
			exit;
		}

		$this->plugin->changeLog()->add(
			'maintenance_note',
			'note',
			0,
			$types[ $type ],
			array(),
			array( 'date' => '' === $date ? Clock::today() : $date, 'type' => $type, 'asset' => $asset ),
			$text,
			'wordpress'
		);
		wp_safe_redirect( add_query_arg( 'estasoft_hp_notice', 'note_added', $back ) );
		exit;
	}
}
