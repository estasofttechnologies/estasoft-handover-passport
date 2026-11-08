<?php
/**
 * The runbook library and editor (RUN-1, RUN-2, RUN-3, RUN-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Services\RunbookService;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Lists runbooks, edits one with a step editor, and starts one from a template.
 */
final class RunbookView {

	public const ACTION = 'estasoft_hp_runbook';

	private const BLANK_STEPS         = 3;
	private const BLANK_PREREQUISITES = 2;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Attach the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Print the tab: the library, or the editor when a runbook is open.
	 */
	public function render(): void {
		$view = Request::key( 'view' );
		if ( 'new' === $view && Capabilities::can( Capabilities::EDIT ) ) {
			$this->editor( 0 );
			return;
		}
		if ( 'edit' === $view ) {
			$id = Request::int( 'id' );
			if ( null !== $this->plugin->runbooks()->get( $id ) ) {
				$this->editor( $id );
				return;
			}
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html__( 'That runbook no longer exists.', 'estasoft-handover-passport' ) . '</p></div>';
		}
		$this->library();
	}

	/**
	 * Save, start from a template, review, rehearse, delete or restore.
	 */
	public function handle(): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to change runbooks.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$op      = Request::post( 'op' );
		$id      = absint( Request::post( 'id' ) );
		$service = $this->plugin->runbooks();
		$notice  = 'saved';
		$to      = $this->base();

		try {
			switch ( $op ) {
				case 'save':
				case 'save_more':
					$input   = $this->input();
					$runbook = $id > 0 ? $service->update( $id, $input ) : $service->create( $input );
					$notice  = 'runbook_saved';
					if ( 'save_more' === $op ) {
						$to = add_query_arg( array( 'view' => 'edit', 'id' => (int) $runbook['id'] ), $this->base() );
					}
					break;
				case 'from_template':
					$runbook = $service->createFromTemplate( sanitize_key( Request::post( 'template' ) ) );
					$notice  = 'runbook_created';
					$to      = add_query_arg( array( 'view' => 'edit', 'id' => (int) $runbook['id'] ), $this->base() );
					break;
				case 'reviewed':
					$service->markReviewed( $id );
					$notice = 'reviewed';
					$to     = add_query_arg( array( 'view' => 'edit', 'id' => $id ), $this->base() );
					break;
				case 'rehearsed':
					$service->markRehearsed( $id );
					$notice = 'rehearsed';
					$to     = add_query_arg( array( 'view' => 'edit', 'id' => $id ), $this->base() );
					break;
				case 'delete':
					$service->delete( $id );
					$notice = 'deleted';
					break;
				case 'undelete':
					$service->undelete( $id );
					$notice = 'restored';
					break;
				default:
					$notice = 'denied';
			}
		} catch ( ValidationError | SecretFound $e ) {
			$label = $e instanceof ValidationError ? $e->field() : (string) strtok( $e->field(), '.' );
			$key   = 'Title' === $label || __( 'Title', 'estasoft-handover-passport' ) === $label ? 'title' : '_form';
			FormState::put( $this->plugin->secretGuard(), 'runbook:' . $id, $this->input(), array( $key => $e->getMessage() ) );
			wp_safe_redirect( add_query_arg( 'estasoft_hp_notice', 'runbook_invalid', add_query_arg( array( 'view' => $id > 0 ? 'edit' : 'new', 'id' => $id ), $this->base() ) ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'estasoft_hp_notice', $notice, $to ) );
		exit;
	}

	/**
	 * The runbooks tab address.
	 */
	private function base(): string {
		return add_query_arg( array( 'page' => SafetyScreen::SLUG, 'tab' => 'runbooks' ), admin_url( 'admin.php' ) );
	}

	/**
	 * Values the form posted, as the service expects them.
	 *
	 * @return array<string, mixed>
	 */
	private function input(): array {
		$posted = Request::postedFields();
		$input  = array();
		foreach ( array( 'title', 'purpose', 'when_to_use', 'owner_role', 'restore_scope', 'status', 'review_days', 'verification', 'rollback_text', 'change_note' ) as $key ) {
			if ( isset( $posted[ $key ] ) && is_scalar( $posted[ $key ] ) ) {
				$input[ $key ] = (string) $posted[ $key ];
			}
		}
		$input['critical']      = isset( $posted['critical'] ) && '1' === (string) $posted['critical'] ? '1' : '0';
		$input['steps']         = Request::postedRows( 'steps' );
		$input['prerequisites'] = Request::postedRows( 'prerequisites' );
		return $input;
	}

	/**
	 * The library: what exists, how fresh it is, and how to start one.
	 */
	private function library(): void {
		$service  = $this->plugin->runbooks();
		$runbooks = $service->all();
		$can_edit = Capabilities::can( Capabilities::EDIT );
		$base     = $this->base();

		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Runbooks', 'estasoft-handover-passport' ) . '</h2>';
		echo '<p>' . esc_html__( 'A runbook is a written set of steps someone can follow when something breaks, without having to ask the person who built the site.', 'estasoft-handover-passport' ) . '</p>';
		if ( $can_edit ) {
			echo '<p><a class="button button-primary" href="' . esc_url( add_query_arg( 'view', 'new', $base ) ) . '">' . esc_html__( 'New runbook', 'estasoft-handover-passport' ) . '</a></p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline-form">';
			$this->hidden( 'from_template', 0 );
			echo '<label for="estasoft-hp-template">' . esc_html__( 'Or start from a template', 'estasoft-handover-passport' ) . '</label> ';
			echo '<select id="estasoft-hp-template" name="template">';
			foreach ( RunbookService::templates() as $key => $template ) {
				echo '<option value="' . esc_attr( (string) $key ) . '">' . esc_html( (string) $template['title'] ) . '</option>';
			}
			echo '</select> <button type="submit" class="button">' . esc_html__( 'Create a draft', 'estasoft-handover-passport' ) . '</button>';
			echo '<p class="description">' . esc_html__( 'A template is a starting point written for a typical WordPress site. Check every step against how this site is built before you rely on it.', 'estasoft-handover-passport' ) . '</p></form>';
		}
		echo '</div>';

		if ( array() === $runbooks ) {
			echo '<p>' . esc_html__( 'No runbook has been written yet.', 'estasoft-handover-passport' ) . '</p>';
			$this->deletedList( $service );
			return;
		}

		$roles = RunbookService::roles();
		echo '<table class="widefat striped estasoft-hp-table"><thead><tr>';
		foreach ( array( __( 'Runbook', 'estasoft-handover-passport' ), __( 'Status', 'estasoft-handover-passport' ), __( 'Owner', 'estasoft-handover-passport' ), __( 'Steps', 'estasoft-handover-passport' ), __( 'Time', 'estasoft-handover-passport' ), __( 'Last reviewed', 'estasoft-handover-passport' ), __( 'Last rehearsed', 'estasoft-handover-passport' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'estasoft-handover-passport' ) . '</span></th></tr></thead><tbody>';
		foreach ( $runbooks as $runbook ) {
			$edit = add_query_arg( array( 'view' => 'edit', 'id' => (int) $runbook['id'] ), $base );
			echo '<tr><td><a href="' . esc_url( $edit ) . '"><strong>' . esc_html( (string) $runbook['title'] ) . '</strong></a>';
			if ( ! empty( $runbook['critical'] ) ) {
				echo ' ' . View::badge( 'critical', __( 'Critical', 'estasoft-handover-passport' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			}
			echo '</td><td>' . View::badge( 'runbook-' . (string) $runbook['status'], 'published' === $runbook['status'] ? __( 'Published', 'estasoft-handover-passport' ) : __( 'Draft', 'estasoft-handover-passport' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			echo '<td>' . esc_html( $roles[ (string) $runbook['owner_role'] ] ?? '' ) . '</td>';
			echo '<td>' . esc_html( (string) count( (array) $runbook['steps'] ) ) . '</td>';
			echo '<td>' . esc_html( (int) $runbook['estimated_minutes'] > 0 ? sprintf( /* translators: %d: minutes. */ _n( '%d minute', '%d minutes', (int) $runbook['estimated_minutes'], 'estasoft-handover-passport' ), (int) $runbook['estimated_minutes'] ) : '' ) . '</td>';
			echo '<td>' . esc_html( $this->stampText( $runbook['last_reviewed'] ) ) . '</td>';
			echo '<td>' . esc_html( $this->stampText( $runbook['last_rehearsed'] ) ) . '</td><td class="estasoft-hp-actions">';
			echo '<a href="' . esc_url( $edit ) . '">' . ( $can_edit ? esc_html__( 'Edit', 'estasoft-handover-passport' ) : esc_html__( 'Open', 'estasoft-handover-passport' ) ) . '</a>';
			if ( $can_edit ) {
				echo ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
				$this->hidden( 'delete', (int) $runbook['id'] );
				echo '<button type="submit" class="button-link estasoft-hp-danger">' . esc_html__( 'Delete', 'estasoft-handover-passport' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$this->deletedList( $service );
	}

	/**
	 * Deleted runbooks, kept for 30 days with Undo.
	 *
	 * @param RunbookService $service Service.
	 */
	private function deletedList( RunbookService $service ): void {
		$rows = Capabilities::can( Capabilities::EDIT ) ? $service->deleted() : array();
		if ( array() === $rows ) {
			return;
		}
		echo '<details class="estasoft-hp-deleted"><summary>' . esc_html(
			sprintf(
				/* translators: %d: number of deleted runbooks. */
				_n( '%d deleted runbook, kept for 30 days', '%d deleted runbooks, kept for 30 days', count( $rows ), 'estasoft-handover-passport' ),
				count( $rows )
			)
		) . '</summary><ul>';
		foreach ( $rows as $row ) {
			echo '<li>' . esc_html( (string) $row['title'] ) . ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
			$this->hidden( 'undelete', (int) $row['id'] );
			echo '<button type="submit" class="button-link">' . esc_html__( 'Undo', 'estasoft-handover-passport' ) . '</button></form></li>';
		}
		echo '</ul></details>';
	}

	/**
	 * The editor for one runbook, or a new one.
	 *
	 * @param int $id Runbook ID, or 0 for a new one.
	 */
	private function editor( int $id ): void {
		$service  = $this->plugin->runbooks();
		$existing = $id > 0 ? ( $service->get( $id ) ?? array() ) : array();
		$state    = FormState::take( 'runbook:' . $id );
		$errors   = null === $state ? array() : $state['errors'];
		$value    = static function ( string $key, $default = '' ) use ( $state, $existing ) {
			if ( null !== $state && array_key_exists( $key, $state['input'] ) ) {
				return $state['input'][ $key ];
			}
			return $existing[ $key ] ?? $default;
		};
		$can_edit = Capabilities::can( Capabilities::EDIT );
		$steps    = null !== $state && isset( $state['input']['steps'] ) ? (array) $state['input']['steps'] : (array) ( $existing['steps'] ?? array() );
		$pre      = null !== $state && isset( $state['input']['prerequisites'] ) ? (array) $state['input']['prerequisites'] : (array) ( $existing['prerequisites'] ?? array() );
		$verify   = null !== $state && isset( $state['input']['verification'] ) ? (string) $state['input']['verification'] : implode( "\n", (array) ( $existing['verification'] ?? array() ) );
		$base     = $this->base();

		echo '<p><a href="' . esc_url( $base ) . '">&larr; ' . esc_html__( 'All runbooks', 'estasoft-handover-passport' ) . '</a></p>';
		echo '<h2>' . esc_html( $id > 0 ? (string) $existing['title'] : __( 'New runbook', 'estasoft-handover-passport' ) ) . '</h2>';
		if ( isset( $errors['_form'] ) ) {
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html( $errors['_form'] ) . '</p></div>';
		}
		if ( $id > 0 ) {
			View::facts(
				array(
					__( 'Version', 'estasoft-handover-passport' )        => 'v' . (int) $existing['current_version'],
					__( 'Last reviewed', 'estasoft-handover-passport' )  => $this->stampText( $existing['last_reviewed'] ),
					__( 'Last rehearsed', 'estasoft-handover-passport' ) => $this->stampText( $existing['last_rehearsed'] ),
					__( 'Estimated time', 'estasoft-handover-passport' ) => (int) $existing['estimated_minutes'] > 0 ? sprintf( /* translators: %d: minutes. */ _n( '%d minute', '%d minutes', (int) $existing['estimated_minutes'], 'estasoft-handover-passport' ), (int) $existing['estimated_minutes'] ) : __( 'Not estimated', 'estasoft-handover-passport' ),
				)
			);
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-form">';
		$this->hidden( 'save', $id );
		echo '<fieldset' . ( $can_edit ? '' : ' disabled' ) . '>';
		Form::row( 'f[title]', array( 'type' => 'text', 'label' => __( 'Title', 'estasoft-handover-passport' ), 'required' => true, 'max' => 255 ), $value( 'title' ), $errors['title'] ?? '' );
		Form::row( 'f[purpose]', array( 'type' => 'textarea', 'label' => __( 'Purpose', 'estasoft-handover-passport' ), 'help' => __( 'What this runbook gets back to working.', 'estasoft-handover-passport' ) ), $value( 'purpose' ) );
		Form::row( 'f[when_to_use]', array( 'type' => 'textarea', 'label' => __( 'When to use it', 'estasoft-handover-passport' ), 'help' => __( 'The signs that mean someone should open this runbook.', 'estasoft-handover-passport' ) ), $value( 'when_to_use' ) );
		Form::row( 'f[owner_role]', array( 'type' => 'select', 'label' => __( 'Who owns it', 'estasoft-handover-passport' ), 'options' => RunbookService::roles() ), $value( 'owner_role' ) );
		Form::row(
			'f[restore_scope]',
			array(
				'type'    => 'select',
				'label'   => __( 'What it restores', 'estasoft-handover-passport' ),
				'help'    => __( 'Choose "Whole site" for the runbook someone follows to bring the site back from nothing.', 'estasoft-handover-passport' ),
				'options' => array(
					'site'     => __( 'Whole site', 'estasoft-handover-passport' ),
					'database' => __( 'Database only', 'estasoft-handover-passport' ),
					'files'    => __( 'Files only', 'estasoft-handover-passport' ),
				),
			),
			$value( 'restore_scope' )
		);
		Form::row(
			'f[status]',
			array(
				'type'    => 'select',
				'label'   => __( 'Status', 'estasoft-handover-passport' ),
				'help'    => __( 'A draft is changed in place. Once published, every change to the steps makes a new version and earlier versions are kept.', 'estasoft-handover-passport' ),
				'options' => array(
					'draft'     => __( 'Draft', 'estasoft-handover-passport' ),
					'published' => __( 'Published', 'estasoft-handover-passport' ),
				),
			),
			$value( 'status', 'draft' )
		);
		Form::checkbox( 'f[critical]', __( 'This is a critical runbook', 'estasoft-handover-passport' ), ! empty( $value( 'critical', 0 ) ) && '0' !== (string) $value( 'critical', 0 ), __( 'Critical runbooks are counted in the readiness checks.', 'estasoft-handover-passport' ) );
		Form::row( 'f[review_days]', array( 'type' => 'int', 'label' => __( 'Review every (days)', 'estasoft-handover-passport' ), 'help' => __( 'Leave empty to use the default.', 'estasoft-handover-passport' ) ), (string) ( $value( 'review_days' ) ?? '' ) );

		$this->prerequisiteRows( $pre );
		$this->stepRows( $steps );

		Form::row( 'f[verification]', array( 'type' => 'textarea', 'label' => __( 'How to check it worked', 'estasoft-handover-passport' ), 'help' => __( 'One check per line, for example "The home page loads" or "An order can be placed".', 'estasoft-handover-passport' ) ), $verify );
		Form::row( 'f[rollback_text]', array( 'type' => 'textarea', 'label' => __( 'If it goes wrong', 'estasoft-handover-passport' ), 'help' => __( 'How to go back to where you started.', 'estasoft-handover-passport' ) ), (string) $value( 'rollback_text', $existing['rollback_text'] ?? '' ) );
		Form::row( 'f[change_note]', array( 'type' => 'text', 'label' => __( 'What changed', 'estasoft-handover-passport' ), 'help' => __( 'A short note kept with the version.', 'estasoft-handover-passport' ), 'max' => 500 ), (string) ( null !== $state ? ( $state['input']['change_note'] ?? '' ) : '' ) );
		echo '</fieldset>';
		if ( $can_edit ) {
			echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save runbook', 'estasoft-handover-passport' ) . '</button> ';
			echo '<button type="submit" name="op" value="save_more" class="button">' . esc_html__( 'Save and keep editing', 'estasoft-handover-passport' ) . '</button></p>';
		}
		echo '</form>';

		if ( $id > 0 ) {
			if ( $can_edit ) {
				echo '<div class="estasoft-hp-card"><h3>' . esc_html__( 'Keep it honest', 'estasoft-handover-passport' ) . '</h3>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
				$this->hidden( 'reviewed', $id );
				echo '<button type="submit" class="button">' . esc_html__( 'Mark as reviewed today', 'estasoft-handover-passport' ) . '</button></form> ';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
				$this->hidden( 'rehearsed', $id );
				echo '<button type="submit" class="button">' . esc_html__( 'Mark as rehearsed today', 'estasoft-handover-passport' ) . '</button></form>';
				echo '<p class="description">' . esc_html__( 'Reviewed means someone read it and it is still correct. Rehearsed means someone followed it, for example on a staging copy.', 'estasoft-handover-passport' ) . '</p></div>';
			}
			$this->versionList( $id );
		}
	}

	/**
	 * Rows for "Before you start".
	 *
	 * @param array<int, mixed> $rows Existing rows.
	 */
	private function prerequisiteRows( array $rows ): void {
		echo '<fieldset class="estasoft-hp-rows"><legend>' . esc_html__( 'Before you start', 'estasoft-handover-passport' ) . '</legend>';
		echo '<p class="description">' . esc_html__( 'What must be in hand first, and who has it. Write where a password is kept, never the password.', 'estasoft-handover-passport' ) . '</p>';
		$rows = array_values( array_filter( $rows, 'is_array' ) );
		for ( $i = 0; $i < count( $rows ) + self::BLANK_PREREQUISITES; $i++ ) {
			$row = $rows[ $i ] ?? array();
			$n   = $i + 1;
			echo '<div class="estasoft-hp-row">';
			echo '<input type="text" name="prerequisites[' . esc_attr( (string) $i ) . '][what]" value="' . esc_attr( (string) ( $row['what'] ?? '' ) ) . '" class="regular-text" maxlength="500" aria-label="' . esc_attr( sprintf( /* translators: %d: row number. */ __( 'Item %d: what is needed', 'estasoft-handover-passport' ), $n ) ) . '"> ';
			echo '<input type="text" name="prerequisites[' . esc_attr( (string) $i ) . '][who]" value="' . esc_attr( (string) ( $row['who'] ?? '' ) ) . '" maxlength="190" placeholder="' . esc_attr__( 'Who has it', 'estasoft-handover-passport' ) . '" aria-label="' . esc_attr( sprintf( /* translators: %d: row number. */ __( 'Item %d: who has it', 'estasoft-handover-passport' ), $n ) ) . '"> ';
			if ( array() !== $row ) {
				echo '<label><input type="checkbox" name="prerequisites[' . esc_attr( (string) $i ) . '][remove]" value="1"> ' . esc_html__( 'Remove', 'estasoft-handover-passport' ) . '</label>';
			}
			echo '</div>';
		}
		echo '</fieldset>';
	}

	/**
	 * The step editor (RUN-2). Order is set by the Position number, so it works without a script and with a keyboard.
	 *
	 * @param array<int, mixed> $steps Existing steps.
	 */
	private function stepRows( array $steps ): void {
		$steps = array_values( array_filter( $steps, 'is_array' ) );
		$roles = RunbookService::roles();
		echo '<fieldset class="estasoft-hp-rows estasoft-hp-steps"><legend>' . esc_html__( 'Steps', 'estasoft-handover-passport' ) . '</legend>';
		echo '<p class="description">' . esc_html__( 'Each step says what to do. To move a step, change its position number. Tick Remove to delete a step, or Duplicate to add a copy of it after saving. Empty rows are ignored. Never write passwords or keys in a step; write where they are kept.', 'estasoft-handover-passport' ) . '</p>';
		$total = count( $steps ) + self::BLANK_STEPS;
		for ( $i = 0; $i < $total; $i++ ) {
			$row = $steps[ $i ] ?? array();
			$n   = $i + 1;
			$p   = 'steps[' . $i . ']';
			echo '<div class="estasoft-hp-step">';
			echo '<div class="estasoft-hp-step__main"><label class="screen-reader-text" for="estasoft-hp-step-' . esc_attr( (string) $i ) . '-text">' . esc_html( sprintf( /* translators: %d: step number. */ __( 'Step %d: what to do', 'estasoft-handover-passport' ), $n ) ) . '</label>';
			echo '<textarea id="estasoft-hp-step-' . esc_attr( (string) $i ) . '-text" name="' . esc_attr( $p ) . '[text]" rows="2" class="large-text" maxlength="1000">' . esc_textarea( (string) ( $row['text'] ?? '' ) ) . '</textarea></div>';
			echo '<div class="estasoft-hp-step__meta">';
			echo '<label>' . esc_html__( 'Position', 'estasoft-handover-passport' ) . ' <input type="number" min="1" step="1" class="small-text" name="' . esc_attr( $p ) . '[position]" value="' . esc_attr( array() === $row ? '' : (string) $n ) . '"></label> ';
			echo '<label>' . esc_html__( 'Who', 'estasoft-handover-passport' ) . ' <select name="' . esc_attr( $p ) . '[who]"><option value="">' . esc_html__( '—', 'estasoft-handover-passport' ) . '</option>';
			foreach ( $roles as $key => $role ) {
				echo '<option value="' . esc_attr( (string) $key ) . '"' . selected( (string) ( $row['who'] ?? '' ), (string) $key, false ) . '>' . esc_html( $role ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a fixed attribute.
			}
			echo '</select></label> ';
			echo '<label>' . esc_html__( 'Minutes', 'estasoft-handover-passport' ) . ' <input type="number" min="0" step="1" class="small-text" name="' . esc_attr( $p ) . '[minutes]" value="' . esc_attr( ! empty( $row['minutes'] ) ? (string) (int) $row['minutes'] : '' ) . '"></label> ';
			echo '<label>' . esc_html__( 'Link', 'estasoft-handover-passport' ) . ' <input type="url" class="regular-text" name="' . esc_attr( $p ) . '[link]" value="' . esc_attr( (string) ( $row['link'] ?? '' ) ) . '" placeholder="https://"></label> ';
			echo '<label><input type="checkbox" name="' . esc_attr( $p ) . '[warning]" value="1"' . checked( ! empty( $row['warning'] ), true, false ) . '> ' . esc_html__( 'Warning', 'estasoft-handover-passport' ) . '</label> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a fixed attribute.
			if ( array() !== $row ) {
				echo '<label><input type="checkbox" name="' . esc_attr( $p ) . '[duplicate]" value="1"> ' . esc_html__( 'Duplicate', 'estasoft-handover-passport' ) . '</label> ';
				echo '<label><input type="checkbox" name="' . esc_attr( $p ) . '[remove]" value="1"> ' . esc_html__( 'Remove', 'estasoft-handover-passport' ) . '</label>';
			}
			echo '</div></div>';
		}
		echo '</fieldset>';
	}

	/**
	 * Earlier versions, read only (RUN-6).
	 *
	 * @param int $id Runbook ID.
	 */
	private function versionList( int $id ): void {
		$versions = $this->plugin->runbooks()->versions( $id );
		if ( count( $versions ) < 2 ) {
			return;
		}
		echo '<div class="estasoft-hp-card"><h3>' . esc_html__( 'Versions', 'estasoft-handover-passport' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Earlier versions are kept as they were and cannot be changed.', 'estasoft-handover-passport' ) . '</p><ul>';
		foreach ( $versions as $version ) {
			$user = (int) $version['created_by'] > 0 ? get_userdata( (int) $version['created_by'] ) : false;
			echo '<li>' . esc_html(
				sprintf(
					/* translators: 1: version number, 2: date, 3: person, 4: number of steps. */
					__( 'Version %1$d, %2$s, by %3$s, %4$d steps', 'estasoft-handover-passport' ),
					(int) $version['version'],
					Dates::displayDate( Dates::datePart( (string) $version['created_at'] ) ),
					$user ? $user->display_name : __( 'someone', 'estasoft-handover-passport' ),
					count( (array) $version['steps'] )
				)
			);
			if ( '' !== (string) $version['change_note'] ) {
				echo ' — ' . esc_html( (string) $version['change_note'] );
			}
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Hidden fields of a form.
	 *
	 * @param string $op Operation.
	 * @param int    $id Runbook ID.
	 */
	private function hidden( string $op, int $id ): void {
		Form::nonce( self::ACTION, false );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
	}

	/**
	 * A stamp as a date, or "Never".
	 *
	 * @param mixed $stamp Date and time or null.
	 */
	private function stampText( $stamp ): string {
		return is_string( $stamp ) && '' !== $stamp ? Dates::displayDate( Dates::datePart( $stamp ) ) : __( 'Never', 'estasoft-handover-passport' );
	}
}
