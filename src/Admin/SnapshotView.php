<?php
/**
 * The Snapshots tab: make, verify, acknowledge, download and delete snapshots (HND-2 to HND-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Export\ExportOptions;
use Estasoft\HandoverPassport\Export\ExportRefused;
use Estasoft\HandoverPassport\Export\ExportService;
use Estasoft\HandoverPassport\Export\Render\PdfRenderer;
use Estasoft\HandoverPassport\Export\Render\RenderFailed;
use Estasoft\HandoverPassport\Findings\SnapshotIntegrity;
use Estasoft\HandoverPassport\Handover\SnapshotService;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * A snapshot is the content of a pack at a moment, with a fingerprint. This tab lists them and does what can be done
 * with one. Nothing here can change what a snapshot holds.
 */
final class SnapshotView {

	public const ACTION = 'estasoft_hp_snapshot';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Attach the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Print the tab.
	 *
	 * @param string $base Screen URL with the tab.
	 */
	public function render( string $base ): void {
		$can_export = Capabilities::can( Capabilities::EXPORT );
		if ( $can_export && '1' === Request::key( 'estasoft_hp_confirm' ) && SensitiveConfirm::render( $this->plugin, self::ACTION, $base, __( 'this snapshot', 'estasoft-handover-passport' ) ) ) {
			return;
		}
		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Snapshots', 'estasoft-handover-passport' ) . '</h2>';
		echo '<p>' . esc_html__( 'A snapshot keeps what a pack said on the day you made it, so you can show later what you handed over. It cannot be edited. Each one has a fingerprint (a SHA-256 hash) of its content.', 'estasoft-handover-passport' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'What the fingerprint tells you: whether the stored content is still the same as when the fingerprint was made. What it does not tell you: who made it, or that nobody with access to the database changed both. This version does not sign snapshots.', 'estasoft-handover-passport' ) . '</p></div>';

		if ( $can_export ) {
			$this->createForm( $base );
		}
		$rows = $this->plugin->snapshots()->all();
		if ( array() === $rows ) {
			echo '<div class="estasoft-hp-empty"><p>' . esc_html__( 'No snapshot has been made yet. Make one when you hand the site over.', 'estasoft-handover-passport' ) . '</p></div>';
			return;
		}
		$integrity = (array) get_option( SnapshotIntegrity::OPTION, array() );
		foreach ( $rows as $row ) {
			$this->card( $row, (array) ( $integrity[ (int) $row['id'] ] ?? array() ), $base, $can_export );
		}
	}

	/**
	 * One snapshot.
	 *
	 * @param array<string, mixed> $row       Snapshot row without content.
	 * @param array<string, mixed> $integrity Last verification result.
	 * @param string               $base      Screen URL.
	 * @param bool                 $can_export Whether the person may act on it.
	 */
	private function card( array $row, array $integrity, string $base, bool $can_export ): void {
		$id      = (int) $row['id'];
		$profile = $this->plugin->exports()->profiles()->get( (string) $row['profile_key'] );
		$user    = get_userdata( (int) $row['created_by'] );
		echo '<div class="estasoft-hp-card estasoft-hp-snapshot" id="snapshot-' . esc_attr( (string) $id ) . '"><h3>' . esc_html( (string) $row['name'] ) . '</h3>';
		View::facts(
			array(
				__( 'Pack', 'estasoft-handover-passport' ) => null === $profile ? (string) $row['profile_key'] : $profile->label,
				__( 'Made', 'estasoft-handover-passport' ) => Dates::display( (string) $row['created_at'], true ) . ' UTC' . ( false === $user ? '' : ' · ' . $user->display_name ),
				__( 'Fingerprint', 'estasoft-handover-passport' ) => (string) $row['hash'],
				__( 'Plugin version', 'estasoft-handover-passport' ) => (string) $row['plugin_version'],
				__( 'Note', 'estasoft-handover-passport' ) => (string) $row['note'],
			)
		);
		if ( array() === $integrity ) {
			echo '<p>' . View::badge( 'unknown', __( 'Not verified yet', 'estasoft-handover-passport' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
		} else {
			echo '<p>' . View::badge( ! empty( $integrity['ok'] ) ? 'ok' : 'bad', ! empty( $integrity['ok'] ) ? __( 'Fingerprint matches', 'estasoft-handover-passport' ) : __( 'Fingerprint does not match', 'estasoft-handover-passport' ) ) . ' ' . esc_html( sprintf( /* translators: %s: date and time. */ __( 'checked %s UTC', 'estasoft-handover-passport' ), Dates::display( (string) ( $integrity['at'] ?? '' ), true ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
		}
		$acks = is_array( $row['acknowledgements'] ) ? $row['acknowledgements'] : array();
		if ( array() !== $acks ) {
			echo '<h4>' . esc_html__( 'Received', 'estasoft-handover-passport' ) . '</h4><ul class="ul-disc">';
			foreach ( $acks as $ack ) {
				$who = trim( (string) ( $ack['name'] ?? '' ) . ( '' !== (string) ( $ack['organisation'] ?? '' ) ? ', ' . (string) $ack['organisation'] : '' ) . ( '' !== (string) ( $ack['role'] ?? '' ) ? ' (' . (string) $ack['role'] . ')' : '' ) );
				$how = SnapshotService::methods()[ (string) ( $ack['method'] ?? '' ) ] ?? '';
				echo '<li>' . esc_html( $who . ' · ' . Dates::displayDate( (string) ( $ack['date'] ?? '' ) ) . ( '' !== $how ? ' · ' . $how : '' ) . ( '' !== (string) ( $ack['note'] ?? '' ) ? ' · ' . (string) $ack['note'] : '' ) . ' · ' . sprintf( /* translators: %s: person who recorded it. */ __( 'recorded by %s', 'estasoft-handover-passport' ), (string) ( $ack['recorded_by'] ?? '' ) ) ) . '</li>';
			}
			echo '</ul>';
		}
		if ( Capabilities::can( Capabilities::VIEW ) ) {
			echo '<p><a href="' . esc_url( add_query_arg( array( 'page' => HistoryScreen::SLUG, 'tab' => 'changes', 'since_snapshot' => $id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'See what changed since this snapshot', 'estasoft-handover-passport' ) . '</a></p>';
		}
		if ( $can_export ) {
			$this->buttons( $id, $base );
		}
		echo '</div>';
	}

	/**
	 * The things that can be done with a snapshot.
	 *
	 * @param int    $id   Snapshot ID.
	 * @param string $base Screen URL.
	 */
	private function buttons( int $id, string $base ): void {
		echo '<div class="estasoft-hp-snapshot__actions">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
		$this->hidden( 'verify', $id, $base );
		echo '<button type="submit" class="button">' . esc_html__( 'Verify', 'estasoft-handover-passport' ) . '</button></form> ';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
		$this->hidden( 'download', $id, $base );
		echo '<label class="screen-reader-text" for="estasoft-hp-fmt-' . esc_attr( (string) $id ) . '">' . esc_html__( 'Format', 'estasoft-handover-passport' ) . '</label><select name="format" id="estasoft-hp-fmt-' . esc_attr( (string) $id ) . '">';
		foreach ( ExportService::formats() as $key => $format ) {
			echo '<option value="' . esc_attr( $key ) . '"' . ( 'pdf' === $key && '' !== PdfRenderer::unavailable() ? ' disabled' : '' ) . '>' . esc_html( $format['label'] ) . '</option>';
		}
		echo '<option value="print">' . esc_html__( 'Print view (in the browser)', 'estasoft-handover-passport' ) . '</option>';
		echo '</select> <button type="submit" class="button">' . esc_html__( 'Download', 'estasoft-handover-passport' ) . '</button></form>';

		$state  = FormState::take( 'snapshot_ack_' . $id );
		$input  = null === $state ? array() : $state['input'];
		$errors = null === $state ? array() : $state['errors'];
		echo '<details' . ( array() !== $errors ? ' open' : '' ) . '><summary>' . esc_html__( 'Record that it was received', 'estasoft-handover-passport' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$this->hidden( 'acknowledge', $id, $base );
		Form::row( 'f[name]', array( 'type' => 'text', 'label' => __( 'Name of the person who received it', 'estasoft-handover-passport' ), 'required' => true, 'max' => 190 ), (string) ( $input['name'] ?? '' ), (string) ( $errors['name'] ?? '' ) );
		Form::row( 'f[organisation]', array( 'type' => 'text', 'label' => __( 'Organisation', 'estasoft-handover-passport' ), 'max' => 190 ), (string) ( $input['organisation'] ?? '' ), '' );
		Form::row( 'f[role]', array( 'type' => 'text', 'label' => __( 'Role', 'estasoft-handover-passport' ), 'max' => 190 ), (string) ( $input['role'] ?? '' ), '' );
		Form::row( 'f[date]', array( 'type' => 'date', 'label' => __( 'Date received', 'estasoft-handover-passport' ) ), (string) ( $input['date'] ?? Clock::today() ), (string) ( $errors['date'] ?? '' ) );
		Form::row( 'f[method]', array( 'type' => 'select', 'label' => __( 'How it was received', 'estasoft-handover-passport' ), 'required' => true, 'options' => SnapshotService::methods() ), (string) ( $input['method'] ?? '' ), (string) ( $errors['method'] ?? '' ) );
		Form::row( 'f[note]', array( 'type' => 'textarea', 'label' => __( 'Note', 'estasoft-handover-passport' ) ), (string) ( $input['note'] ?? '' ), (string) ( $errors['note'] ?? '' ) );
		echo '<p class="description">' . esc_html__( 'You are recording this for the person who received it. Marking it received sets the Passport to Handed over.', 'estasoft-handover-passport' ) . '</p>';
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Record', 'estasoft-handover-passport' ) . '</button></p></form></details>';

		if ( Capabilities::can( Capabilities::CONFIGURE ) ) {
			echo '<details><summary class="estasoft-hp-danger">' . esc_html__( 'Delete this snapshot', 'estasoft-handover-passport' ) . '</summary>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$this->hidden( 'delete', $id, $base );
			echo '<p>' . esc_html__( 'This cannot be undone. The name, fingerprint and date stay in the history.', 'estasoft-handover-passport' ) . '</p>';
			echo '<p><label><input type="checkbox" name="confirm" value="1"> ' . esc_html__( 'Yes, delete this snapshot for good', 'estasoft-handover-passport' ) . '</label></p>';
			echo '<p><button type="submit" class="button">' . esc_html__( 'Delete snapshot', 'estasoft-handover-passport' ) . '</button></p></form></details>';
		}
		echo '</div>';
	}

	/**
	 * The form that makes a snapshot.
	 *
	 * @param string $base Screen URL.
	 */
	private function createForm( string $base ): void {
		$state  = FormState::take( 'snapshot_create' );
		$input  = null === $state ? array() : $state['input'];
		$errors = null === $state ? array() : $state['errors'];
		$packs  = array();
		foreach ( $this->plugin->exports()->profiles()->all() as $profile ) {
			if ( $profile->needsSensitiveCapability() && ! Capabilities::canSeeSensitive() ) {
				continue;
			}
			$packs[ $profile->key ] = $profile->label;
		}
		echo '<details class="estasoft-hp-card"' . ( array() !== $errors ? ' open' : '' ) . '><summary><strong>' . esc_html__( 'Make a snapshot', 'estasoft-handover-passport' ) . '</strong></summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$this->hidden( 'create', 0, $base );
		Form::row( 'name', array( 'type' => 'text', 'label' => __( 'Name', 'estasoft-handover-passport' ), 'required' => true, 'max' => 190, 'help' => __( 'For example "Handover to Acme, May 2027".', 'estasoft-handover-passport' ) ), (string) ( $input['name'] ?? '' ), (string) ( $errors['name'] ?? '' ) );
		Form::row( 'pack', array( 'type' => 'select', 'label' => __( 'Pack', 'estasoft-handover-passport' ), 'required' => true, 'options' => $packs ), (string) ( $input['pack'] ?? 'client' ), (string) ( $errors['pack'] ?? '' ) );
		Form::row( 'note', array( 'type' => 'textarea', 'label' => __( 'Note', 'estasoft-handover-passport' ), 'help' => __( 'Optional. Never write passwords or keys here.', 'estasoft-handover-passport' ) ), (string) ( $input['note'] ?? '' ), (string) ( $errors['note'] ?? '' ) );
		SensitiveConfirm::fieldset( $this->plugin );
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Make snapshot', 'estasoft-handover-passport' ) . '</button></p></form></details>';
	}

	/**
	 * Do what a form asked.
	 */
	public function handle(): void {
		$op    = Request::post( 'op' );
		$needs = in_array( $op, array( 'delete' ), true ) ? Capabilities::CONFIGURE : Capabilities::EXPORT;
		if ( ! Capabilities::can( $needs ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$raw    = Request::postedUrl( 'return' );
		$return = ( '' !== $raw && 0 === strpos( $raw, admin_url( 'admin.php' ) ) ) ? $raw : add_query_arg( array( 'page' => HandoverScreen::SLUG, 'tab' => 'snapshots' ), admin_url( 'admin.php' ) );
		$id     = absint( Request::post( 'id' ) );

		switch ( $op ) {
			case 'create':
				$fields = array( 'name' => Request::post( 'name' ), 'pack' => Request::post( 'pack' ), 'note' => Request::postedTextarea( 'note' ) );
				$ids    = SensitiveConfirm::postedIds();
				if ( array() !== $ids ) {
					SensitiveConfirm::hold( self::ACTION, array( 'fields' => $fields, 'ids' => $ids ) );
					$this->back( $return, '', array( 'estasoft_hp_confirm' => '1' ) );
				}
				$this->create( $fields, array(), $return );
				break;
			case 'confirm':
				$held = SensitiveConfirm::claim( self::ACTION );
				if ( null === $held ) {
					$this->back( $return, 'confirm_expired' );
				}
				$this->create( (array) ( $held['fields'] ?? array() ), array_map( 'intval', (array) ( $held['ids'] ?? array() ) ), $return );
				break;
			case 'cancel':
				SensitiveConfirm::drop();
				$this->back( $return, 'confirm_cancelled' );
				break;
			case 'verify':
				$result = $this->plugin->snapshots()->verify( $id );
				$this->back( $return, null === $result ? 'snapshot_gone' : ( $result['ok'] ? 'snapshot_match' : 'snapshot_mismatch' ) );
				break;
			case 'acknowledge':
				$this->acknowledge( $id, $return );
				break;
			case 'delete':
				if ( '1' !== Request::post( 'confirm' ) ) {
					$this->back( $return, 'snapshot_confirm' );
				}
				try {
					$this->plugin->snapshots()->delete( $id );
				} catch ( ValidationError $e ) {
					$this->back( $return, 'snapshot_gone' );
				}
				$this->back( $return, 'snapshot_deleted' );
				break;
			case 'download':
				$this->download( $id, Request::post( 'format' ), $return );
				break;
		}
		$this->back( $return, '' );
	}

	/**
	 * Make the snapshot.
	 *
	 * @param array<string, string> $fields Name, pack and note.
	 * @param array<int, int>       $ids    Sensitive records the person confirmed.
	 * @param string                $return_to Where to go afterwards.
	 */
	private function create( array $fields, array $ids, string $return_to ): void {
		$options = new ExportOptions( Capabilities::canSeeSensitive(), $ids, get_current_user_id() );
		try {
			$this->plugin->snapshots()->create( (string) ( $fields['pack'] ?? '' ), (string) ( $fields['name'] ?? '' ), (string) ( $fields['note'] ?? '' ), $options );
		} catch ( ValidationError | SecretFound $e ) {
			$label = $e instanceof ValidationError ? $e->field() : (string) strtok( $e->field(), '.' );
			$keys  = array(
				__( 'Name', 'estasoft-handover-passport' ) => 'name',
				__( 'Note', 'estasoft-handover-passport' ) => 'note',
				__( 'Pack', 'estasoft-handover-passport' ) => 'pack',
			);
			FormState::put( $this->plugin->secretGuard(), 'snapshot_create', $fields, array( ( $keys[ $label ] ?? 'name' ) => $e->getMessage() ) );
			$this->back( $return_to, 'snapshot_invalid' );
		} catch ( ExportRefused $e ) {
			View::say( $e->getMessage() );
			$this->back( $return_to, '' );
		}
		$this->back( $return_to, 'snapshot_made' );
	}

	/**
	 * Record an acknowledgement.
	 *
	 * @param int    $id     Snapshot ID.
	 * @param string $return_to Where to go afterwards.
	 */
	private function acknowledge( int $id, string $return_to ): void {
		$input = Request::postedFields();
		try {
			$this->plugin->snapshots()->acknowledge( $id, $input );
		} catch ( ValidationError | SecretFound $e ) {
			$label = $e instanceof ValidationError ? $e->field() : (string) strtok( $e->field(), '.' );
			$keys  = array(
				__( 'Name', 'estasoft-handover-passport' ) => 'name',
				__( 'Date', 'estasoft-handover-passport' ) => 'date',
				__( 'How it was received', 'estasoft-handover-passport' ) => 'method',
				__( 'Acknowledgement', 'estasoft-handover-passport' ) => 'note',
			);
			if ( __( 'Snapshot', 'estasoft-handover-passport' ) === $label ) {
				$this->back( $return_to, 'snapshot_gone' );
			}
			FormState::put( $this->plugin->secretGuard(), 'snapshot_ack_' . $id, $input, array( ( $keys[ $label ] ?? 'note' ) => $e->getMessage() ) );
			$this->back( $return_to, 'ack_invalid' );
		}
		$this->back( $return_to, 'snapshot_acked' );
	}

	/**
	 * Send a snapshot as a file.
	 *
	 * @param int    $id     Snapshot ID.
	 * @param string $format Format key or "print".
	 * @param string $return_to Where to go when it cannot be made.
	 */
	private function download( int $id, string $format, string $return_to ): void {
		$options = new ExportOptions( Capabilities::canSeeSensitive(), array(), get_current_user_id() );
		try {
			$result = $this->plugin->exports()->makeFromSnapshot( $id, sanitize_key( $format ), $options );
		} catch ( ValidationError | ExportRefused | RenderFailed $e ) {
			View::say( $e->getMessage() );
			$this->back( $return_to, '' );
		} catch ( SecretFound $e ) {
			View::say( __( 'A value in this snapshot looks like a password or key, so no file was made.', 'estasoft-handover-passport' ) );
			$this->back( $return_to, '' );
		}
		Download::send( $result, ExportService::PRINT === $result->format );
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string                $return_to URL.
	 * @param string                $notice Notice key, or an empty string for none.
	 * @param array<string, string> $args   More query arguments.
	 */
	private function back( string $return_to, string $notice, array $args = array() ): never {
		$url = remove_query_arg( array( 'estasoft_hp_notice', 'estasoft_hp_confirm' ), $return_to );
		if ( '' !== $notice ) {
			$args['estasoft_hp_notice'] = $notice;
		}
		wp_safe_redirect( array() === $args ? $url : add_query_arg( $args, $url ) );
		exit;
	}

	/**
	 * Hidden fields of a form.
	 *
	 * @param string $op   Operation.
	 * @param int    $id   Snapshot ID.
	 * @param string $base Return URL.
	 */
	private function hidden( string $op, int $id, string $base ): void {
		Form::nonce( self::ACTION, false );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="return" value="' . esc_url( $base ) . '">';
	}
}
