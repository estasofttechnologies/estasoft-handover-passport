<?php
/**
 * The Export and Export log tabs (EXP-2 to EXP-7, EXP-9).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Export\ExportOptions;
use Estasoft\HandoverPassport\Export\ExportRefused;
use Estasoft\HandoverPassport\Export\ExportService;
use Estasoft\HandoverPassport\Export\PackCopies;
use Estasoft\HandoverPassport\Export\Profile;
use Estasoft\HandoverPassport\Export\Profiles;
use Estasoft\HandoverPassport\Export\Render\PdfRenderer;
use Estasoft\HandoverPassport\Export\Render\RenderFailed;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Makes a pack of the Passport as it is now, in any format, and lists what was made.
 */
final class ExportView {

	public const ACTION      = 'estasoft_hp_export';
	public const COPY_ACTION = 'estasoft_hp_pack_copy';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Attach the handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::COPY_ACTION, array( $this, 'handleCopy' ) );
	}

	/**
	 * Print the Export tab.
	 *
	 * @param string $base Screen URL with the tab.
	 */
	public function render( string $base ): void {
		if ( ! Capabilities::can( Capabilities::EXPORT ) ) {
			View::cardStart( __( 'Export a pack', 'estasoft-handover-passport' ) );
			echo '<p>' . esc_html__( 'You need permission to export to use this tab. Ask someone who can change Passport settings.', 'estasoft-handover-passport' ) . '</p>';
			View::cardEnd();
			return;
		}
		if ( '1' === Request::key( 'estasoft_hp_confirm' ) && SensitiveConfirm::render( $this->plugin, self::ACTION, $base, __( 'this file', 'estasoft-handover-passport' ) ) ) {
			return;
		}
		$packs = array();
		foreach ( $this->plugin->exports()->profiles()->all() as $profile ) {
			if ( $profile->needsSensitiveCapability() && ! Capabilities::canSeeSensitive() ) {
				continue;
			}
			$packs[ $profile->key ] = $profile;
		}
		$why = PdfRenderer::unavailable();

		if ( '' !== $why ) {
			echo '<div class="msg wn">' . Icons::svg( 'warning', 16 ) . '<div>' . esc_html( sprintf( /* translators: %s: reason. */ __( 'PDF is not available on this server. %s Use the print view and Save as PDF in your browser, or one of the other formats.', 'estasoft-handover-passport' ), $why ) ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is fixed markup.
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-exportform">';
		Form::nonce( self::ACTION, false );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="return" value="' . esc_url( $base ) . '">';
		View::cardStart( __( 'Export a pack', 'estasoft-handover-passport' ) );
		echo '<p class="t2">' . esc_html__( 'A pack is the Passport as it is now, in the words and chapters of one audience. To keep a copy of what you handed over, make a snapshot instead. Packs never hold a password or key, because Passport does not store any.', 'estasoft-handover-passport' ) . '</p>';
		echo '<fieldset class="estasoft-hp-choices"><legend class="lbl">' . esc_html__( 'Pack', 'estasoft-handover-passport' ) . '</legend>';
		$first = true;
		foreach ( $packs as $profile ) {
			echo '<label class="card estasoft-hp-choice"><input type="radio" name="pack" value="' . esc_attr( $profile->key ) . '"' . ( $first ? ' checked' : '' ) . '><span><b>' . esc_html( $profile->label ) . '</b><br><span class="t2">' . esc_html( $profile->description ) . '</span></span></label>';
			$first = false;
		}
		echo '</fieldset>';
		View::cardEnd();

		View::cardStart( __( 'Options', 'estasoft-handover-passport' ) );
		echo '<div class="estasoft-hp-field"><label for="estasoft-hp-format">' . esc_html__( 'Format', 'estasoft-handover-passport' ) . '</label><select name="format" id="estasoft-hp-format">';
		foreach ( ExportService::formats() as $key => $format ) {
			echo '<option value="' . esc_attr( $key ) . '"' . ( 'pdf' === $key && '' !== $why ? ' disabled' : '' ) . '>' . esc_html( $format['label'] ) . '</option>';
		}
		echo '</select></div>';
		SensitiveConfirm::fieldset( $this->plugin );
		View::cardEnd();

		echo '<div class="savebar"><button type="submit" name="op" value="download" class="btn pri">' . Icons::svg( 'download', 16 ) . esc_html__( 'Download', 'estasoft-handover-passport' ) . '</button> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is fixed markup.
		echo '<button type="submit" name="op" value="print" class="btn">' . esc_html__( 'Open print view', 'estasoft-handover-passport' ) . '</button> ';
		echo '<a class="btn" href="' . esc_url( add_query_arg( array( 'page' => HandoverScreen::SLUG, 'tab' => 'log' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Cancel', 'estasoft-handover-passport' ) . '</a></div>';
		echo '<p class="t2 cap">' . esc_html__( 'The print view opens in this browser. Use Print and choose Save as PDF; it works on every server.', 'estasoft-handover-passport' ) . '</p></form>';

		if ( Capabilities::can( Capabilities::CONFIGURE ) ) {
			$this->copies( $base );
		}
	}

	/**
	 * Your own packs.
	 *
	 * @param string $base Screen URL.
	 */
	private function copies( string $base ): void {
		$service = $this->plugin->packCopies();
		$copies  = $service->all();
		View::cardStart( __( 'Your own packs', 'estasoft-handover-passport' ) );
		echo '<p class="t2">' . esc_html__( 'Copy a pack and choose its chapters. A copy follows the rules of the pack it came from: a copy of the Client pack still shows only what is marked visible to the client and never holds Sensitive values.', 'estasoft-handover-passport' ) . '</p>';
		if ( array() !== $copies ) {
			echo '<div class="scr"><table class="tbl cards estasoft-hp-table"><caption class="sr">' . esc_html__( 'Your own packs', 'estasoft-handover-passport' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Name', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Copy of', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Chapters', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Actions', 'estasoft-handover-passport' ) . '</th></tr></thead><tbody>';
			$builtin  = Profiles::builtin();
			$chapters = Profiles::chapters();
			foreach ( $copies as $key => $copy ) {
				$names = array_map( static fn( string $c ): string => $chapters[ $c ] ?? $c, array_map( 'strval', (array) ( $copy['sections'] ?? array() ) ) );
				echo '<tr><td class="t1" data-label="' . esc_attr__( 'Name', 'estasoft-handover-passport' ) . '"><b>' . esc_html( (string) ( $copy['label'] ?? $key ) ) . '</b></td><td data-label="' . esc_attr__( 'Copy of', 'estasoft-handover-passport' ) . '">' . esc_html( isset( $builtin[ (string) ( $copy['base'] ?? '' ) ] ) ? $builtin[ (string) $copy['base'] ]->label : '' ) . '</td><td data-label="' . esc_attr__( 'Chapters', 'estasoft-handover-passport' ) . '">' . esc_html( implode( ', ', $names ) ) . '</td><td class="act" data-label="' . esc_attr__( 'Actions', 'estasoft-handover-passport' ) . '">';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
				Form::nonce( self::COPY_ACTION, false );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::COPY_ACTION ) . '"><input type="hidden" name="op" value="delete"><input type="hidden" name="key" value="' . esc_attr( (string) $key ) . '"><input type="hidden" name="return" value="' . esc_url( $base ) . '">';
				echo '<button type="submit" class="btn sm estasoft-hp-danger">' . esc_html__( 'Delete', 'estasoft-handover-passport' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table></div>';
		}
		$state  = FormState::take( 'pack_copy' );
		$input  = null === $state ? array() : $state['input'];
		$errors = null === $state ? array() : $state['errors'];
		echo '<details class="estasoft-hp-tools"' . ( array() !== $errors ? ' open' : '' ) . '><summary class="btn sm">' . esc_html__( 'Make a copy', 'estasoft-handover-passport' ) . '</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		Form::nonce( self::COPY_ACTION, false );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::COPY_ACTION ) . '"><input type="hidden" name="op" value="create"><input type="hidden" name="return" value="' . esc_url( $base ) . '">';
		$bases = array();
		foreach ( Profiles::builtin() as $profile ) {
			$bases[ $profile->key ] = $profile->label;
		}
		Form::row( 'name', array( 'type' => 'text', 'label' => __( 'Name', 'estasoft-handover-passport' ), 'required' => true, 'max' => 60 ), (string) ( $input['name'] ?? '' ), (string) ( $errors['name'] ?? '' ) );
		Form::row( 'base', array( 'type' => 'select', 'label' => __( 'Copy of', 'estasoft-handover-passport' ), 'required' => true, 'options' => $bases ), (string) ( $input['base'] ?? 'client' ), (string) ( $errors['base'] ?? '' ) );
		$chosen = array_map( 'strval', (array) ( $input['sections'] ?? array() ) );
		foreach ( array( 'normal' => __( 'Chapters for a copy of the Client pack, Technical pack or Full internal', 'estasoft-handover-passport' ), 'emergency' => __( 'Chapters for a copy of the Emergency sheet', 'estasoft-handover-passport' ) ) as $kind => $legend ) {
			echo '<fieldset><legend>' . esc_html( $legend ) . '</legend>';
			foreach ( PackCopies::chaptersFor( 'emergency' === $kind ? Profile::EMERGENCY : Profile::CLIENT ) as $key => $label ) {
				echo '<label><input type="checkbox" name="sections[]" value="' . esc_attr( $key ) . '"' . ( in_array( $key, $chosen, true ) ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label><br>';
			}
			echo '</fieldset>';
		}
		if ( isset( $errors['sections'] ) ) {
			echo '<p class="estasoft-hp-error" role="alert">' . esc_html( (string) $errors['sections'] ) . '</p>';
		}
		echo '<p class="submit"><button type="submit" class="btn pri">' . esc_html__( 'Save the copy', 'estasoft-handover-passport' ) . '</button></p></form></details>';
		View::cardEnd();
	}

	/**
	 * Print the Export log tab (EXP-9).
	 */
	public function renderLog(): void {
		if ( ! Capabilities::can( Capabilities::EXPORT ) ) {
			View::cardStart( __( 'Exports', 'estasoft-handover-passport' ) );
			echo '<p>' . esc_html__( 'You need permission to export to see this log.', 'estasoft-handover-passport' ) . '</p>';
			View::cardEnd();
			return;
		}
		$all  = Capabilities::can( Capabilities::CONFIGURE );
		$rows = $this->plugin->exports()->recent( $all ? 0 : get_current_user_id() );
		if ( array() === $rows ) {
			View::cardStart( __( 'Exports', 'estasoft-handover-passport' ) );
			echo '<p>' . esc_html__( 'Nothing has been exported yet.', 'estasoft-handover-passport' ) . '</p>';
			View::cardEnd();
			return;
		}
		$profiles = $this->plugin->exports()->profiles();
		$formats  = ExportService::formats();
		View::bareCardStart();
		echo '<div class="scr"><table class="tbl cards estasoft-hp-table"><caption class="sr">' . esc_html__( 'Export log', 'estasoft-handover-passport' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Pack', 'estasoft-handover-passport' ), __( 'Format', 'estasoft-handover-passport' ), __( 'Made', 'estasoft-handover-passport' ), __( 'By', 'estasoft-handover-passport' ), __( 'Who may see it', 'estasoft-handover-passport' ), __( 'Snapshot', 'estasoft-handover-passport' ), __( 'Size', 'estasoft-handover-passport' ), __( 'Fingerprint', 'estasoft-handover-passport' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$user     = get_userdata( (int) $row['user_id'] );
			$profile  = $profiles->get( (string) $row['profile'] );
			$format   = ExportService::PRINT === (string) $row['format'] ? __( 'Print view', 'estasoft-handover-passport' ) : ( $formats[ (string) $row['format'] ]['label'] ?? (string) $row['format'] );
			$snapshot = (int) $row['snapshot_id'] > 0 ? $this->plugin->snapshots()->get( (int) $row['snapshot_id'] ) : null;
			echo '<tr><td class="t1" data-label="' . esc_attr__( 'Pack', 'estasoft-handover-passport' ) . '"><b>' . esc_html( 'change_log' === (string) $row['profile'] ? __( 'Change log', 'estasoft-handover-passport' ) : ( null === $profile ? (string) $row['profile'] : $profile->label ) ) . '</b></td>';
			echo '<td data-label="' . esc_attr__( 'Format', 'estasoft-handover-passport' ) . '">' . esc_html( ! empty( $row['emailed'] ) ? sprintf( /* translators: %s: file format, for example PDF. */ __( '%s, sent by email', 'estasoft-handover-passport' ), $format ) : $format ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Made', 'estasoft-handover-passport' ) . '">' . esc_html( Dates::display( (string) $row['exported_at'], true ) . ' UTC' ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'By', 'estasoft-handover-passport' ) . '">' . esc_html( 0 === (int) $row['user_id'] ? __( 'Passport (automatic)', 'estasoft-handover-passport' ) : ( false === $user ? '' : (string) $user->display_name ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Who may see it', 'estasoft-handover-passport' ) . '">' . $this->audienceTag( $profile ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
			echo '<td data-label="' . esc_attr__( 'Snapshot', 'estasoft-handover-passport' ) . '">' . esc_html( (int) $row['snapshot_id'] > 0 ? ( null === $snapshot ? __( 'Deleted snapshot', 'estasoft-handover-passport' ) : (string) $snapshot['name'] ) : '' ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Size', 'estasoft-handover-passport' ) . '">' . esc_html( View::size( (int) $row['size'] ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Fingerprint', 'estasoft-handover-passport' ) . '"><code>' . esc_html( substr( (string) $row['hash'], 0, 12 ) ) . '…</code></td></tr>';
		}
		echo '</tbody></table></div>';
		View::cardEnd();
		echo '<p class="t2 cap">' . esc_html( $all ? __( 'Every file made in the retention period. The log holds no file, only who made it, when, and a fingerprint of it.', 'estasoft-handover-passport' ) : __( 'The files you made in the retention period. The log holds no file, only when you made it and a fingerprint of it.', 'estasoft-handover-passport' ) ) . '</p>';
	}

	/**
	 * A tag that says who may see a pack: the client, the emergency contact or only the team.
	 *
	 * @param Profile|null $profile Profile of the pack, or null for the change log.
	 */
	private function audienceTag( ?Profile $profile ): string {
		if ( null === $profile ) {
			return '<span class="tag">' . Icons::svg( 'lock', 12 ) . esc_html__( 'Internal', 'estasoft-handover-passport' ) . '</span>';
		}
		if ( Profile::CLIENT === $profile->audience ) {
			return '<span class="tag">' . Icons::svg( 'users', 12 ) . esc_html__( 'Client pack', 'estasoft-handover-passport' ) . '</span>';
		}
		if ( Profile::EMERGENCY === $profile->audience ) {
			return '<span class="tag">' . Icons::svg( 'warning', 12 ) . esc_html__( 'Emergency sheet', 'estasoft-handover-passport' ) . '</span>';
		}
		return '<span class="tag">' . Icons::svg( 'lock', 12 ) . esc_html__( 'Internal', 'estasoft-handover-passport' ) . '</span>';
	}

	/**
	 * Make a pack and send it.
	 */
	public function handle(): void {
		if ( ! Capabilities::can( Capabilities::EXPORT ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$raw    = Request::postedUrl( 'return' );
		$return = ( '' !== $raw && 0 === strpos( $raw, admin_url( 'admin.php' ) ) ) ? $raw : add_query_arg( array( 'page' => HandoverScreen::SLUG, 'tab' => 'export' ), admin_url( 'admin.php' ) );
		$return = remove_query_arg( array( 'estasoft_hp_notice', 'estasoft_hp_confirm' ), $return );
		$op     = Request::post( 'op' );

		if ( 'cancel' === $op ) {
			SensitiveConfirm::drop();
			$this->back( $return, 'confirm_cancelled' );
		}
		if ( 'confirm' === $op ) {
			$held = SensitiveConfirm::claim( self::ACTION );
			if ( null === $held ) {
				$this->back( $return, 'confirm_expired' );
			}
			$this->deliver( (string) ( $held['pack'] ?? '' ), (string) ( $held['format'] ?? '' ), array_map( 'intval', (array) ( $held['ids'] ?? array() ) ), $return );
		}
		$pack   = Request::post( 'pack' );
		$format = 'print' === $op ? ExportService::PRINT : Request::post( 'format' );
		$ids    = SensitiveConfirm::postedIds();
		if ( array() !== $ids ) {
			SensitiveConfirm::hold( self::ACTION, array( 'pack' => $pack, 'format' => $format, 'ids' => $ids ) );
			$this->back( $return, '', array( 'estasoft_hp_confirm' => '1' ) );
		}
		$this->deliver( $pack, $format, array(), $return );
	}

	/**
	 * Make the file and send it, or go back with the reason.
	 *
	 * @param string          $pack   Pack key.
	 * @param string          $format Format key or "print".
	 * @param array<int, int> $ids    Sensitive records the person confirmed.
	 * @param string          $return_to Where to go when it cannot be made.
	 */
	private function deliver( string $pack, string $format, array $ids, string $return_to ): never {
		$options = new ExportOptions( Capabilities::canSeeSensitive(), $ids, get_current_user_id() );
		try {
			$result = $this->plugin->exports()->make( sanitize_key( $pack ), sanitize_key( $format ), $options );
		} catch ( ValidationError | ExportRefused | RenderFailed $e ) {
			View::say( $e->getMessage() );
			$this->back( $return_to, '' );
		} catch ( SecretFound $e ) {
			View::say( __( 'A value in the Passport looks like a password or key, so no file was made. Open the record named in the history or the findings and remove it.', 'estasoft-handover-passport' ) );
			$this->back( $return_to, '' );
		}
		Download::send( $result, ExportService::PRINT === $result->format );
	}

	/**
	 * Make, change or delete a copy of a pack.
	 */
	public function handleCopy(): void {
		if ( ! Capabilities::can( Capabilities::CONFIGURE ) ) {
			wp_die( esc_html__( 'You do not have permission to change packs.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::COPY_ACTION );
		$raw    = Request::postedUrl( 'return' );
		$return = ( '' !== $raw && 0 === strpos( $raw, admin_url( 'admin.php' ) ) ) ? $raw : add_query_arg( array( 'page' => HandoverScreen::SLUG, 'tab' => 'export' ), admin_url( 'admin.php' ) );
		$return = remove_query_arg( array( 'estasoft_hp_notice', 'estasoft_hp_confirm' ), $return );
		try {
			if ( 'delete' === Request::post( 'op' ) ) {
				$this->plugin->packCopies()->delete( sanitize_key( Request::post( 'key' ) ) );
				$this->back( $return, 'pack_copy_deleted' );
			}
			$sections = isset( $_POST['sections'] ) && is_array( $_POST['sections'] ) ? array_map( 'sanitize_key', array_map( 'strval', array_filter( wp_unslash( $_POST['sections'] ), 'is_scalar' ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is checked above; every value is reduced to a key and compared with the list of chapters.
			$this->plugin->packCopies()->create( sanitize_key( Request::post( 'base' ) ), Request::post( 'name' ), $sections );
		} catch ( ValidationError | SecretFound $e ) {
			$label = $e instanceof ValidationError ? $e->field() : (string) strtok( $e->field(), '.' );
			$keys  = array(
				__( 'Name', 'estasoft-handover-passport' ) => 'name',
				__( 'Copy of', 'estasoft-handover-passport' ) => 'base',
				__( 'Chapters', 'estasoft-handover-passport' ) => 'sections',
			);
			FormState::put( $this->plugin->secretGuard(), 'pack_copy', array( 'name' => Request::post( 'name' ), 'base' => Request::post( 'base' ), 'sections' => $sections ?? array() ), array( ( $keys[ $label ] ?? 'name' ) => $e->getMessage() ) );
			$this->back( $return, 'pack_copy_invalid' );
		}
		$this->back( $return, 'pack_copy_made' );
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string                $return_to URL.
	 * @param string                $notice Notice key, or an empty string for none.
	 * @param array<string, string> $args   More query arguments.
	 */
	private function back( string $return_to, string $notice, array $args = array() ): never {
		if ( '' !== $notice ) {
			$args['estasoft_hp_notice'] = $notice;
		}
		wp_safe_redirect( array() === $args ? $return_to : add_query_arg( $args, $return_to ) );
		exit;
	}
}
