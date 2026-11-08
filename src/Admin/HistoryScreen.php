<?php
/**
 * History: the change log and maintenance notes (ADM-1, CHG-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Export\ExportResult;
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
	public const CSV_ACTION  = 'estasoft_hp_changes_csv';
	private const PER_PAGE   = 25;

	/**
	 * Attach the note handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::NOTE_ACTION, array( $this, 'handleNote' ) );
		add_action( 'admin_post_' . self::CSV_ACTION, array( $this, 'downloadCsv' ) );
	}

	/**
	 * The filters the change log is shown with, from the address: a kind of change and, optionally, a snapshot.
	 *
	 * @return array{filters: array<string, mixed>, kind: string, since: int}
	 */
	private function requestedFilters(): array {
		$kind    = Request::key( 'kind' );
		$filters = array();
		$since   = 0;
		if ( '' !== $kind && array_key_exists( $kind, ChangeText::filterKinds() ) ) {
			$filters['kind'] = $kind;
		} else {
			$kind = '';
		}
		$since_id = Request::int( 'since_snapshot' );
		foreach ( $this->plugin->snapshots()->all() as $snapshot ) {
			if ( (int) $snapshot['id'] === $since_id ) {
				$filters = $filters + $this->sinceFilter( $snapshot );
				$since   = $since_id;
				break;
			}
		}
		return array( 'filters' => $filters, 'kind' => $kind, 'since' => $since );
	}

	/**
	 * Send the change log, with the filters on screen, as a CSV file (CHG-6).
	 */
	public function downloadCsv(): void {
		if ( ! Capabilities::can( Capabilities::EXPORT ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::CSV_ACTION );
		$asked = $this->requestedFilters();
		$repo  = $this->plugin->changeLog()->repo();
		$back  = add_query_arg( array_filter( array( 'page' => self::SLUG, 'tab' => 'changes', 'kind' => $asked['kind'], 'since_snapshot' => $asked['since'] ? (string) $asked['since'] : '' ) ), admin_url( 'admin.php' ) );
		if ( $repo->countFor( $asked['filters'] ) > ChangeCsv::LIMIT ) {
			wp_safe_redirect( add_query_arg( 'estasoft_hp_notice', 'changes_csv_toolarge', $back ) );
			exit;
		}
		$rows  = $this->plugin->changeLog()->forViewer( $repo->search( $asked['filters'], ChangeCsv::LIMIT ), Capabilities::canSeeSensitive() );
		$bytes = ChangeCsv::build( $rows );
		$this->plugin->exports()->logFile( 'change_log', 'csv', $bytes, get_current_user_id() );
		Download::send( new ExportResult( $bytes, 'change-log-' . Clock::today() . '.csv', 'text/csv; charset=utf-8', 'csv', 'change_log' ) );
	}

	/**
	 * Address of the download for the filters that are on screen.
	 *
	 * @param string $kind     Kind filter, or an empty string.
	 * @param int    $snapshot Snapshot filter, or 0.
	 */
	private function csvUrl( string $kind, int $snapshot ): string {
		$args = array( 'action' => self::CSV_ACTION );
		if ( '' !== $kind ) {
			$args['kind'] = $kind;
		}
		if ( $snapshot > 0 ) {
			$args['since_snapshot'] = (string) $snapshot;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::CSV_ACTION );
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
			'changes'   => __( 'Change log', 'estasoft-handover-passport' ),
			'notes'     => __( 'Notes', 'estasoft-handover-passport' ),
			'incidents' => __( 'Incidents', 'estasoft-handover-passport' ),
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
		if ( 'incidents' === $tab ) {
			echo '<p class="description">' . esc_html__( 'Write down what went wrong while it is fresh: what happened, the effect, the cause, the fix, how long the site took to come back and what will change. The next maintainer learns more from this than from the change log.', 'estasoft-handover-passport' ) . '</p>';
			( new RegisterView( $this->plugin ) )->render( Kinds::INCIDENT, self::SLUG, $this->keep( 'incidents' ) );
			return;
		}
		$this->changes();
	}

	/**
	 * The change log with filters.
	 */
	private function changes(): void {
		$page_no   = max( 1, Request::int( 'paged' ) );
		$asked     = $this->requestedFilters();
		$filters   = $asked['filters'];
		$kind      = $asked['kind'];
		$since_id  = $asked['since'];
		$snapshots = $this->plugin->snapshots()->all();
		$since     = null;
		foreach ( $snapshots as $snapshot ) {
			if ( (int) $snapshot['id'] === $since_id ) {
				$since = $snapshot;
				break;
			}
		}
		$repo  = $this->plugin->changeLog()->repo();
		$total = $repo->countFor( $filters );
		$rows  = $this->plugin->changeLog()->forViewer( $repo->search( $filters, self::PER_PAGE, ( $page_no - 1 ) * self::PER_PAGE ), Capabilities::canSeeSensitive() );
		$base  = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'changes' ), admin_url( 'admin.php' ) );
		if ( null !== $since ) {
			$base = add_query_arg( 'since_snapshot', (string) $since_id, $base );
		}

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="estasoft-hp-filterbar">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="changes">';
		echo '<label for="estasoft-hp-kind">' . esc_html__( 'Show', 'estasoft-handover-passport' ) . '</label> <select id="estasoft-hp-kind" name="kind"><option value="">' . esc_html__( 'Everything', 'estasoft-handover-passport' ) . '</option>';
		foreach ( ChangeText::filterKinds() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $kind, $key, false ) . '>' . esc_html( $label ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a fixed attribute.
		}
		echo '</select>';
		if ( array() !== $snapshots ) {
			echo ' <label for="estasoft-hp-since">' . esc_html__( 'Since snapshot', 'estasoft-handover-passport' ) . '</label> <select id="estasoft-hp-since" name="since_snapshot"><option value="">' . esc_html__( 'Any time', 'estasoft-handover-passport' ) . '</option>';
			foreach ( $snapshots as $snapshot ) {
				echo '<option value="' . esc_attr( (string) $snapshot['id'] ) . '"' . selected( $since_id, (int) $snapshot['id'], false ) . '>' . esc_html( (string) $snapshot['name'] . ' · ' . Dates::display( (string) $snapshot['created_at'], true ) . ' UTC' ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a fixed attribute.
			}
			echo '</select>';
		}
		echo ' <button type="submit" class="button">' . esc_html__( 'Filter', 'estasoft-handover-passport' ) . '</button></form>';
		if ( Capabilities::can( Capabilities::EXPORT ) ) {
			echo '<p><a class="button" href="' . esc_url( $this->csvUrl( $kind, $since_id ) ) . '">' . esc_html__( 'Download as CSV', 'estasoft-handover-passport' ) . '</a> <span class="description">' . esc_html__( 'The entries that match the filter above, opened in a spreadsheet.', 'estasoft-handover-passport' ) . '</span></p>';
		}

		if ( null !== $since ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: 1: snapshot name, 2: date and time. */
					__( 'Showing what changed after the snapshot “%1$s”, made %2$s UTC.', 'estasoft-handover-passport' ),
					(string) $since['name'],
					Dates::display( (string) $since['created_at'], true )
				)
			) . '</p>';
		}
		if ( array() === $rows ) {
			$empty = null !== $since
				? __( 'Nothing has changed since this snapshot.', 'estasoft-handover-passport' )
				: __( 'No changes are logged yet. Updates, new administrators and edits to records appear here with who made them.', 'estasoft-handover-passport' );
			echo '<div class="estasoft-hp-empty"><p>' . esc_html( $empty ) . '</p></div>';
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
	 * The filter that leaves only what was logged after a snapshot was made. The log entry written when the snapshot was
	 * made is used when it still exists, so that entry itself is never counted; otherwise the time of the snapshot is.
	 * An entry counts only when it was written within a minute of the snapshot, so a leftover entry of an earlier
	 * snapshot that had the same number cannot be mistaken for it.
	 *
	 * @param array<string, mixed> $snapshot Snapshot row.
	 * @return array<string, mixed>
	 */
	private function sinceFilter( array $snapshot ): array {
		$made_at = strtotime( (string) $snapshot['created_at'] . ' UTC' );
		$entries = $this->plugin->changeLog()->repo()->search( array( 'kind' => 'snapshot_created', 'subject_type' => 'snapshot', 'subject_id' => (int) $snapshot['id'] ), 10 );
		foreach ( $entries as $entry ) {
			$logged_at = strtotime( (string) $entry['changed_at'] . ' UTC' );
			if ( false !== $made_at && false !== $logged_at && abs( $logged_at - $made_at ) <= 60 ) {
				return array( 'after_id' => (int) $entry['id'] );
			}
		}
		return array( 'since' => (string) $snapshot['created_at'] );
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
			Form::row(
				'note_type',
				array(
					'type'     => 'select',
					'label'    => __( 'Type', 'estasoft-handover-passport' ),
					'options'  => $types,
					'required' => true,
					'help'     => __( 'For something that broke, a short note is fine. To record the cause, the fix and how long it took, use the Incidents tab.', 'estasoft-handover-passport' ),
				),
				$input['note_type'] ?? ''
			);
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
