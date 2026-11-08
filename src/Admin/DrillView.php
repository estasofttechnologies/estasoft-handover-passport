<?php
/**
 * A drill: following a runbook step by step and keeping the record (RUN-5, URL-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Services\DrillService;
use Estasoft\HandoverPassport\Services\RestoreTestService;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The drill page, its handler and the list of drills.
 */
final class DrillView {

	public const ACTION = 'estasoft_hp_drill';

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
	 * The address of a drill.
	 *
	 * @param int $id Drill ID.
	 */
	public static function url( int $id ): string {
		return add_query_arg( array( 'page' => SafetyScreen::SLUG, 'tab' => 'runbooks', 'view' => 'drill', 'id' => $id ), admin_url( 'admin.php' ) );
	}

	/**
	 * Tick, save notes, finish, abandon, or keep as a restore test.
	 */
	public function handle(): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to run drills.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$id     = absint( Request::post( 'id' ) );
		$do     = Request::post( 'do' );
		$op     = '' !== $do ? $do : Request::post( 'op' );
		$drills = $this->plugin->drills();
		$back   = self::url( $id );
		$list   = add_query_arg( array( 'page' => SafetyScreen::SLUG, 'tab' => 'runbooks' ), admin_url( 'admin.php' ) );
		$notes  = Request::postedGrid( 'notes' );

		try {
			if ( 1 === preg_match( '/^(tick|untick):(steps|checks):(\d{1,3})$/', $op, $m ) ) {
				$drills->apply( $id, $notes, array( 'section' => $m[2], 'index' => (int) $m[3], 'done' => 'tick' === $m[1] ) );
			} elseif ( 'save' === $op ) {
				$drills->apply( $id, $notes );
				View::say( __( 'Notes saved.', 'estasoft-handover-passport' ), false );
			} elseif ( 'finish' === $op ) {
				$drills->apply( $id, $notes );
				$drill = $drills->finish( $id, sanitize_key( Request::post( 'result' ) ), Request::postedTextarea( 'problems' ) );
				View::say( sprintf( /* translators: %d: minutes. */ _n( 'Drill finished. It took %d minute.', 'Drill finished. It took %d minutes.', (int) $drill['minutes'], 'estasoft-handover-passport' ), (int) $drill['minutes'] ), false );
			} elseif ( 'abandon' === $op ) {
				$drills->abandon( $id );
				View::say( __( 'The drill was thrown away.', 'estasoft-handover-passport' ), false );
				$back = $list;
			} elseif ( 'save_test' === $op ) {
				$drills->saveAsRestoreTest( $id, array( 'place' => sanitize_key( Request::post( 'place' ) ), 'backup_date' => Request::post( 'backup_date' ) ) );
				View::say( __( 'Saved as a restore test.', 'estasoft-handover-passport' ), false );
			} else {
				wp_die( esc_html__( 'That action is not known.', 'estasoft-handover-passport' ), '', array( 'response' => 400 ) );
			}
		} catch ( ValidationError $e ) {
			View::say( $e->getMessage() );
		} catch ( SecretFound $e ) {
			View::say( $e->getMessage() );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * The page of one drill.
	 *
	 * @param int $id Drill ID.
	 */
	public function render( int $id ): void {
		$drill = $this->plugin->drills()->find( $id );
		$list  = add_query_arg( array( 'page' => SafetyScreen::SLUG, 'tab' => 'runbooks' ), admin_url( 'admin.php' ) );
		echo '<p><a href="' . esc_url( $list ) . '">&larr; ' . esc_html__( 'All runbooks', 'estasoft-handover-passport' ) . '</a></p>';
		if ( null === $drill ) {
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html__( 'That drill no longer exists.', 'estasoft-handover-passport' ) . '</p></div>';
			return;
		}
		$runbook = $this->plugin->runbooks()->get( (int) $drill['runbook_id'], true );
		$title   = null === $runbook ? __( 'A deleted runbook', 'estasoft-handover-passport' ) : (string) $runbook['title'];
		$running = DrillService::isRunning( $drill );
		$log     = $drill['step_log'];
		$all     = array_merge( $log['steps'], $log['checks'] );
		$done    = count( array_filter( $all, static fn( array $x ): bool => '' !== (string) $x['done_at'] ) );

		echo '<div class="estasoft-hp-reader"><h2>' . esc_html( sprintf( /* translators: %s: runbook title. */ __( 'Drill: %s', 'estasoft-handover-passport' ), $title ) ) . ' ';
		echo $running ? View::badge( 'running', __( 'In progress', 'estasoft-handover-passport' ) ) : View::badge( 'result-' . (string) $drill['result'], RestoreTestService::results()[ (string) $drill['result'] ] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
		echo '</h2>';

		$facts = array(
			__( 'Runbook version', 'estasoft-handover-passport' ) => 'v' . (int) $drill['runbook_version'],
			__( 'Started', 'estasoft-handover-passport' )         => Dates::display( (string) $log['started_at'], true ) . ' UTC' . ( '' !== (string) $log['started_by'] ? ' · ' . (string) $log['started_by'] : '' ),
			__( 'Progress', 'estasoft-handover-passport' )        => sprintf( /* translators: 1: number done, 2: total. */ __( '%1$d of %2$d done', 'estasoft-handover-passport' ), $done, count( $all ) ),
		);
		if ( ! $running ) {
			$facts[ __( 'Finished', 'estasoft-handover-passport' ) ]   = Dates::display( (string) $log['finished_at'], true ) . ' UTC';
			$facts[ __( 'Total time', 'estasoft-handover-passport' ) ] = sprintf( /* translators: %d: minutes. */ _n( '%d minute', '%d minutes', (int) $drill['minutes'], 'estasoft-handover-passport' ), (int) $drill['minutes'] );
			$facts[ __( 'Done by', 'estasoft-handover-passport' ) ]    = (string) $drill['who'];
			if ( '' !== (string) $drill['problems'] ) {
				$facts[ __( 'Problems found', 'estasoft-handover-passport' ) ] = (string) $drill['problems'];
			}
		}
		View::facts( $facts );

		if ( $running && Capabilities::can( Capabilities::EDIT ) ) {
			$this->runningForm( $drill );
		} else {
			$this->table( __( 'Steps', 'estasoft-handover-passport' ), $log['steps'], 'text' );
			if ( array() !== $log['checks'] ) {
				$this->table( __( 'After the restore', 'estasoft-handover-passport' ), $log['checks'], 'label' );
			}
			if ( $running ) {
				echo '<p>' . esc_html__( 'This drill is still going. Someone who may edit the Passport can continue it.', 'estasoft-handover-passport' ) . '</p>';
			} else {
				$this->afterwards( $drill );
			}
		}
		echo '</div>';
	}

	/**
	 * The list of drills, for the runbook library.
	 */
	public function listing(): void {
		$drills = $this->plugin->drills()->all( 0, 15 );
		if ( array() === $drills ) {
			return;
		}
		echo '<div class="estasoft-hp-card"><h3>' . esc_html__( 'Drills', 'estasoft-handover-passport' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'A drill is a runbook followed step by step, with the time and the person kept for each step.', 'estasoft-handover-passport' ) . '</p>';
		echo '<table class="widefat striped estasoft-hp-table"><thead><tr><th scope="col">' . esc_html__( 'Started', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Runbook', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Result', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Time', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Done by', 'estasoft-handover-passport' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'estasoft-handover-passport' ) . '</span></th></tr></thead><tbody>';
		foreach ( $drills as $drill ) {
			$runbook = $this->plugin->runbooks()->get( (int) $drill['runbook_id'], true );
			$running = DrillService::isRunning( $drill );
			echo '<tr><td>' . esc_html( Dates::display( (string) $drill['step_log']['started_at'], true ) ) . '</td><td>' . esc_html( null === $runbook ? __( 'A deleted runbook', 'estasoft-handover-passport' ) : (string) $runbook['title'] ) . '</td>';
			echo '<td>' . ( $running ? View::badge( 'running', __( 'In progress', 'estasoft-handover-passport' ) ) : View::badge( 'result-' . (string) $drill['result'], RestoreTestService::results()[ (string) $drill['result'] ] ?? '' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			echo '<td>' . esc_html( $running ? '' : sprintf( /* translators: %d: minutes. */ _n( '%d minute', '%d minutes', (int) $drill['minutes'], 'estasoft-handover-passport' ), (int) $drill['minutes'] ) ) . '</td><td>' . esc_html( (string) $drill['who'] ) . '</td>';
			echo '<td><a href="' . esc_url( self::url( (int) $drill['id'] ) ) . '">' . esc_html( $running ? __( 'Continue', 'estasoft-handover-passport' ) : __( 'Open', 'estasoft-handover-passport' ) ) . '</a></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * The form for a drill that is going: steps with their buttons, then the finish form.
	 *
	 * @param array<string, mixed> $drill The drill.
	 */
	private function runningForm( array $drill ): void {
		$id  = (int) $drill['id'];
		$log = $drill['step_log'];
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-form">';
		$this->hidden( 'save', $id );
		// Pressing Enter in a note must save the notes, never tick the first step.
		echo '<button type="submit" name="do" value="save" class="screen-reader-text" tabindex="-1">' . esc_html__( 'Save notes', 'estasoft-handover-passport' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Press Done when you have finished a step. The time and your name are kept with it. Add a note to any step.', 'estasoft-handover-passport' ) . '</p>';
		$this->rows( 'steps', $log['steps'], 'text', __( 'Steps', 'estasoft-handover-passport' ) );
		if ( array() !== $log['checks'] ) {
			$this->rows( 'checks', $log['checks'], 'label', __( 'After the restore, check this', 'estasoft-handover-passport' ) );
		}
		echo '<p><button type="submit" name="do" value="save" class="button">' . esc_html__( 'Save notes', 'estasoft-handover-passport' ) . '</button></p>';

		echo '<fieldset class="estasoft-hp-card"><legend>' . esc_html__( 'Finish the drill', 'estasoft-handover-passport' ) . '</legend>';
		Form::row( 'result', array( 'type' => 'select', 'label' => __( 'How did it go?', 'estasoft-handover-passport' ), 'options' => RestoreTestService::results(), 'help' => __( 'Pass needs every step ticked. Choose Partial if you stopped early or something did not work.', 'estasoft-handover-passport' ) ), 'pass' );
		Form::row( 'problems', array( 'type' => 'textarea', 'label' => __( 'Problems found', 'estasoft-handover-passport' ), 'help' => __( 'What was unclear, missing or wrong in the runbook. Never write a password.', 'estasoft-handover-passport' ) ), '' );
		echo '<p><button type="submit" name="do" value="finish" class="button button-primary">' . esc_html__( 'Finish and record it', 'estasoft-handover-passport' ) . '</button></p></fieldset>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
		$this->hidden( 'abandon', $id );
		echo '<button type="submit" class="button-link estasoft-hp-danger">' . esc_html__( 'Throw this drill away', 'estasoft-handover-passport' ) . '</button></form>';
	}

	/**
	 * The steps of a drill that is going, each with a button.
	 *
	 * @param string                       $section steps or checks.
	 * @param list<array<string, mixed>>   $rows    Rows.
	 * @param string                       $key     Which field holds the words.
	 * @param string                       $heading Heading.
	 */
	private function rows( string $section, array $rows, string $key, string $heading ): void {
		echo '<h3>' . esc_html( $heading ) . '</h3><ol class="estasoft-hp-readsteps estasoft-hp-drillsteps">';
		foreach ( $rows as $i => $row ) {
			$done = '' !== (string) $row['done_at'];
			echo '<li' . ( ! empty( $row['warning'] ) ? ' class="estasoft-hp-readsteps__care"' : '' ) . '>';
			echo ! empty( $row['warning'] ) ? '<strong>' . esc_html__( 'Careful:', 'estasoft-handover-passport' ) . '</strong> ' : '';
			echo nl2br( esc_html( (string) $row[ $key ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped before nl2br.
			$detail = 'steps' === $section ? implode( ' · ', array_filter( array( (string) $row['who'], (int) $row['minutes'] > 0 ? sprintf( /* translators: %d: minutes. */ _n( '%d minute', '%d minutes', (int) $row['minutes'], 'estasoft-handover-passport' ), (int) $row['minutes'] ) : '' ) ) ) : (string) $row['detail'];
			if ( '' !== $detail ) {
				echo '<br><span class="description">' . esc_html( $detail ) . '</span>';
			}
			if ( 'steps' === $section && '' !== (string) $row['link'] ) {
				echo '<br><a href="' . esc_url( (string) $row['link'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) $row['link'] ) . '</a>';
			}
			echo '<div class="estasoft-hp-drillrow">';
			if ( $done ) {
				echo '<span class="estasoft-hp-drilldone">' . View::badge( 'done', __( 'Done', 'estasoft-handover-passport' ) ) . ' ' . esc_html( Dates::display( (string) $row['done_at'], true ) . ' UTC · ' . (string) $row['by'] ) . '</span> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
				echo '<button type="submit" name="do" value="untick:' . esc_attr( $section . ':' . $i ) . '" class="button-link">' . esc_html__( 'Undo', 'estasoft-handover-passport' ) . '</button> ';
			} else {
				echo '<button type="submit" name="do" value="tick:' . esc_attr( $section . ':' . $i ) . '" class="button">' . esc_html__( 'Done', 'estasoft-handover-passport' ) . '</button> ';
			}
			echo '<label class="screen-reader-text" for="estasoft-hp-note-' . esc_attr( $section . '-' . $i ) . '">' . esc_html( sprintf( /* translators: %d: row number. */ __( 'Note for item %d', 'estasoft-handover-passport' ), $i + 1 ) ) . '</label>';
			echo '<input type="text" class="regular-text" maxlength="500" id="estasoft-hp-note-' . esc_attr( $section . '-' . $i ) . '" name="notes[' . esc_attr( $section ) . '][' . esc_attr( (string) $i ) . ']" value="' . esc_attr( (string) $row['note'] ) . '" placeholder="' . esc_attr__( 'Note', 'estasoft-handover-passport' ) . '">';
			echo '</div></li>';
		}
		echo '</ol>';
	}

	/**
	 * The record of a finished drill, read only.
	 *
	 * @param string                     $heading Heading.
	 * @param list<array<string, mixed>> $rows    Rows.
	 * @param string                     $key     Which field holds the words.
	 */
	private function table( string $heading, array $rows, string $key ): void {
		echo '<h3>' . esc_html( $heading ) . '</h3>';
		if ( array() === $rows ) {
			return;
		}
		echo '<table class="widefat striped estasoft-hp-table"><thead><tr><th scope="col">#</th><th scope="col">' . esc_html__( 'What', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Done', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'By', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Note', 'estasoft-handover-passport' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $i => $row ) {
			echo '<tr><td>' . esc_html( (string) ( $i + 1 ) ) . '</td><td>' . esc_html( (string) $row[ $key ] ) . '</td><td>' . esc_html( '' === (string) $row['done_at'] ? __( 'Not done', 'estasoft-handover-passport' ) : Dates::display( (string) $row['done_at'], true ) . ' UTC' ) . '</td><td>' . esc_html( (string) $row['by'] ) . '</td><td>' . esc_html( (string) $row['note'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * What can be done with a finished drill: keep it as a restore test.
	 *
	 * @param array<string, mixed> $drill The drill.
	 */
	private function afterwards( array $drill ): void {
		if ( '' === (string) $drill['scope'] ) {
			return;
		}
		$tests = add_query_arg( array( 'page' => SafetyScreen::SLUG, 'tab' => 'tests' ), admin_url( 'admin.php' ) );
		echo '<div class="estasoft-hp-card"><h3>' . esc_html__( 'Keep it as a restore test', 'estasoft-handover-passport' ) . '</h3>';
		if ( (int) $drill['step_log']['saved_test_id'] > 0 ) {
			echo '<p>' . esc_html__( 'This drill is saved as a restore test.', 'estasoft-handover-passport' ) . ' <a href="' . esc_url( $tests ) . '">' . esc_html__( 'See restore tests', 'estasoft-handover-passport' ) . '</a></p></div>';
			return;
		}
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			echo '<p>' . esc_html__( 'Someone who may edit the Passport can save this drill as a restore test.', 'estasoft-handover-passport' ) . '</p></div>';
			return;
		}
		echo '<p>' . esc_html__( 'This runbook restores the site, so this drill can count as a restore test. Only do this if the restore was real: onto a staging site or another host, from a real backup.', 'estasoft-handover-passport' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-form">';
		$this->hidden( 'save_test', (int) $drill['id'] );
		Form::row( 'place', array( 'type' => 'select', 'label' => __( 'Where it was restored to', 'estasoft-handover-passport' ), 'options' => RestoreTestService::places() ), 'staging' );
		Form::row( 'backup_date', array( 'type' => 'date', 'label' => __( 'Date of the backup used', 'estasoft-handover-passport' ) ), '' );
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save as a restore test', 'estasoft-handover-passport' ) . '</button></p></form></div>';
	}

	/**
	 * Hidden fields of a form.
	 *
	 * @param string $op Operation.
	 * @param int    $id Drill ID.
	 */
	private function hidden( string $op, int $id ): void {
		Form::nonce( self::ACTION, false );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
	}
}
