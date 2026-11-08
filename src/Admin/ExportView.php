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
			echo '<div class="estasoft-hp-empty"><p>' . esc_html__( 'You need permission to export to use this tab. Ask someone who can change Passport settings.', 'estasoft-handover-passport' ) . '</p></div>';
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

		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Export a pack', 'estasoft-handover-passport' ) . '</h2>';
		echo '<p>' . esc_html__( 'A pack is the Passport as it is now, in the words and chapters of one audience. To keep a copy of what you handed over, make a snapshot instead. Packs never hold a password or key, because Passport does not store any.', 'estasoft-handover-passport' ) . '</p>';
		if ( '' !== $why ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( /* translators: %s: reason. */ __( 'PDF is not available on this server. %s Use the print view and Save as PDF in your browser, or one of the other formats.', 'estasoft-handover-passport' ), $why ) ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		Form::nonce( self::ACTION, false );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="return" value="' . esc_url( $base ) . '">';
		echo '<fieldset><legend>' . esc_html__( 'Pack', 'estasoft-handover-passport' ) . '</legend>';
		$first = true;
		foreach ( $packs as $profile ) {
			echo '<p><label><input type="radio" name="pack" value="' . esc_attr( $profile->key ) . '"' . ( $first ? ' checked' : '' ) . '> <strong>' . esc_html( $profile->label ) . '</strong></label><br><span class="description">' . esc_html( $profile->description ) . '</span></p>';
			$first = false;
		}
		echo '</fieldset>';
		echo '<p><label for="estasoft-hp-format"><strong>' . esc_html__( 'Format', 'estasoft-handover-passport' ) . '</strong></label><br><select name="format" id="estasoft-hp-format">';
		foreach ( ExportService::formats() as $key => $format ) {
			echo '<option value="' . esc_attr( $key ) . '"' . ( 'pdf' === $key && '' !== $why ? ' disabled' : '' ) . '>' . esc_html( $format['label'] ) . '</option>';
		}
		echo '</select></p>';
		SensitiveConfirm::fieldset( $this->plugin );
		echo '<p class="submit"><button type="submit" name="op" value="download" class="button button-primary">' . esc_html__( 'Download', 'estasoft-handover-passport' ) . '</button> ';
		echo '<button type="submit" name="op" value="print" class="button">' . esc_html__( 'Open print view', 'estasoft-handover-passport' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'The print view opens in this browser. Use Print and choose Save as PDF; it works on every server.', 'estasoft-handover-passport' ) . '</p></form></div>';

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
		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Your own packs', 'estasoft-handover-passport' ) . '</h2>';
		echo '<p>' . esc_html__( 'Copy a pack and choose its chapters. A copy follows the rules of the pack it came from: a copy of the Client pack still shows only what is marked visible to the client and never holds Sensitive values.', 'estasoft-handover-passport' ) . '</p>';
		if ( array() !== $copies ) {
			echo '<table class="widefat striped estasoft-hp-table"><thead><tr><th scope="col">' . esc_html__( 'Name', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Copy of', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Chapters', 'estasoft-handover-passport' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'estasoft-handover-passport' ) . '</span></th></tr></thead><tbody>';
			$builtin  = Profiles::builtin();
			$chapters = Profiles::chapters();
			foreach ( $copies as $key => $copy ) {
				$names = array_map( static fn( string $c ): string => $chapters[ $c ] ?? $c, array_map( 'strval', (array) ( $copy['sections'] ?? array() ) ) );
				echo '<tr><td>' . esc_html( (string) ( $copy['label'] ?? $key ) ) . '</td><td>' . esc_html( isset( $builtin[ (string) ( $copy['base'] ?? '' ) ] ) ? $builtin[ (string) $copy['base'] ]->label : '' ) . '</td><td>' . esc_html( implode( ', ', $names ) ) . '</td><td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
				Form::nonce( self::COPY_ACTION, false );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::COPY_ACTION ) . '"><input type="hidden" name="op" value="delete"><input type="hidden" name="key" value="' . esc_attr( (string) $key ) . '"><input type="hidden" name="return" value="' . esc_url( $base ) . '">';
				echo '<button type="submit" class="button-link estasoft-hp-danger">' . esc_html__( 'Delete', 'estasoft-handover-passport' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		}
		$state  = FormState::take( 'pack_copy' );
		$input  = null === $state ? array() : $state['input'];
		$errors = null === $state ? array() : $state['errors'];
		echo '<details' . ( array() !== $errors ? ' open' : '' ) . '><summary><strong>' . esc_html__( 'Make a copy', 'estasoft-handover-passport' ) . '</strong></summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
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
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save the copy', 'estasoft-handover-passport' ) . '</button></p></form></details></div>';
	}

	/**
	 * Print the Export log tab (EXP-9).
	 */
	public function renderLog(): void {
		if ( ! Capabilities::can( Capabilities::EXPORT ) ) {
			echo '<div class="estasoft-hp-empty"><p>' . esc_html__( 'You need permission to export to see this log.', 'estasoft-handover-passport' ) . '</p></div>';
			return;
		}
		$all  = Capabilities::can( Capabilities::CONFIGURE );
		$rows = $this->plugin->exports()->recent( $all ? 0 : get_current_user_id() );
		echo '<div class="estasoft-hp-card"><h2>' . esc_html__( 'Export log', 'estasoft-handover-passport' ) . '</h2><p>' . esc_html( $all ? __( 'Every file made in the last 12 months. The log holds no file, only who made it, when, and a fingerprint of it.', 'estasoft-handover-passport' ) : __( 'The files you made in the last 12 months. The log holds no file, only when you made it and a fingerprint of it.', 'estasoft-handover-passport' ) ) . '</p></div>';
		if ( array() === $rows ) {
			echo '<div class="estasoft-hp-empty"><p>' . esc_html__( 'Nothing has been exported yet.', 'estasoft-handover-passport' ) . '</p></div>';
			return;
		}
		$profiles = $this->plugin->exports()->profiles();
		$formats  = ExportService::formats();
		echo '<table class="widefat striped estasoft-hp-table"><thead><tr>';
		foreach ( array( __( 'When', 'estasoft-handover-passport' ), __( 'Who', 'estasoft-handover-passport' ), __( 'Pack', 'estasoft-handover-passport' ), __( 'Format', 'estasoft-handover-passport' ), __( 'Snapshot', 'estasoft-handover-passport' ), __( 'Size', 'estasoft-handover-passport' ), __( 'Fingerprint', 'estasoft-handover-passport' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$user    = get_userdata( (int) $row['user_id'] );
			$profile = $profiles->get( (string) $row['profile'] );
			$format  = ExportService::PRINT === (string) $row['format'] ? __( 'Print view', 'estasoft-handover-passport' ) : ( $formats[ (string) $row['format'] ]['label'] ?? (string) $row['format'] );
			echo '<tr><td>' . esc_html( Dates::display( (string) $row['exported_at'], true ) . ' UTC' ) . '</td>';
			echo '<td>' . esc_html( false === $user ? '' : (string) $user->display_name ) . '</td>';
			echo '<td>' . esc_html( null === $profile ? (string) $row['profile'] : $profile->label ) . '</td>';
			echo '<td>' . esc_html( $format ) . '</td>';
			$snapshot = (int) $row['snapshot_id'] > 0 ? $this->plugin->snapshots()->get( (int) $row['snapshot_id'] ) : null;
			echo '<td>' . esc_html( (int) $row['snapshot_id'] > 0 ? ( null === $snapshot ? __( 'Deleted snapshot', 'estasoft-handover-passport' ) : (string) $snapshot['name'] ) : '' ) . '</td>';
			echo '<td>' . esc_html( View::size( (int) $row['size'] ) ) . '</td>';
			echo '<td><code>' . esc_html( substr( (string) $row['hash'], 0, 16 ) ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
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
	 * @param string    $pack   Pack key.
	 * @param string    $format Format key or "print".
	 * @param list<int> $ids    Sensitive records the person confirmed.
	 * @param string    $return Where to go when it cannot be made.
	 */
	private function deliver( string $pack, string $format, array $ids, string $return ): never {
		$options = new ExportOptions( Capabilities::canSeeSensitive(), $ids, get_current_user_id() );
		try {
			$result = $this->plugin->exports()->make( sanitize_key( $pack ), sanitize_key( $format ), $options );
		} catch ( ValidationError | ExportRefused | RenderFailed $e ) {
			View::say( $e->getMessage() );
			$this->back( $return, '' );
		} catch ( SecretFound $e ) {
			View::say( __( 'A value in the Passport looks like a password or key, so no file was made. Open the record named in the history or the findings and remove it.', 'estasoft-handover-passport' ) );
			$this->back( $return, '' );
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
				__( 'Name', 'estasoft-handover-passport' )     => 'name',
				__( 'Copy of', 'estasoft-handover-passport' )  => 'base',
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
	 * @param string                $return URL.
	 * @param string                $notice Notice key, or an empty string for none.
	 * @param array<string, string> $args   More query arguments.
	 */
	private function back( string $return, string $notice, array $args = array() ): never {
		if ( '' !== $notice ) {
			$args['estasoft_hp_notice'] = $notice;
		}
		wp_safe_redirect( array() === $args ? $return : add_query_arg( $args, $return ) );
		exit;
	}
}
