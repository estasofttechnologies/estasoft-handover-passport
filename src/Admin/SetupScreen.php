<?php
/**
 * The setup guide screen (ONB-2, ONB-3, ONB-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Setup\SetupService;

defined( 'ABSPATH' ) || exit;

/**
 * Seven steps in order. Every step can be skipped and picked up again, and a skipped step stays on the Overview as a
 * thing to do.
 */
final class SetupScreen extends Screen {

	public const SLUG   = 'estasoft-passport-setup';
	public const ACTION = 'estasoft_hp_setup';

	/**
	 * Attach the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
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
		return __( 'Setup', 'estasoft-handover-passport' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tabs(): array {
		return array( 'steps' => __( 'Steps', 'estasoft-handover-passport' ) );
	}

	/**
	 * Address of the screen.
	 *
	 * @param string $anchor Optional element ID to jump to.
	 */
	public static function url( string $anchor = '' ): string {
		return add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) ) . ( '' !== $anchor ? '#' . $anchor : '' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tab( string $tab ): void {
		$setup = $this->plugin->setup();
		$steps = $setup->steps();
		$left  = count( $setup->remaining() );
		$total = count( $steps );

		echo '<p class="estasoft-hp-lede">' . esc_html(
			0 === $left
				? __( 'Every step is done. You can still change any of it from the screens named below.', 'estasoft-handover-passport' )
				: sprintf(
					/* translators: 1: steps done, 2: number of steps. */
					__( '%1$d of %2$d steps are done. Skip any step and come back to it later; skipped steps stay on the Overview as things to do.', 'estasoft-handover-passport' ),
					$total - $left,
					$total
				)
		) . '</p>';

		$can_edit = Capabilities::can( Capabilities::EDIT );
		echo '<ol class="estasoft-hp-steps">';
		$number = 0;
		foreach ( $steps as $key => $info ) {
			++$number;
			$this->step( $key, $info, $number, $total, $can_edit );
		}
		echo '</ol>';
	}

	/**
	 * Print one step.
	 *
	 * @param string                                           $key      Step key.
	 * @param array{state: string, auto: bool, detail: string} $info     State of the step.
	 * @param int                                              $number   Position.
	 * @param int                                              $total    Number of steps.
	 * @param bool                                             $can_edit Whether the person may change the Passport.
	 */
	private function step( string $key, array $info, int $number, int $total, bool $can_edit ): void {
		$copy  = self::copy()[ $key ];
		$state = $info['state'];
		$label = array(
			SetupService::DONE    => __( 'Done', 'estasoft-handover-passport' ),
			SetupService::SKIPPED => __( 'Skipped', 'estasoft-handover-passport' ),
			SetupService::TODO    => __( 'To do', 'estasoft-handover-passport' ),
		)[ $state ];
		$id    = 'estasoft-hp-step-' . $key;

		echo '<li class="estasoft-hp-step estasoft-hp-step--' . esc_attr( $state ) . '" id="' . esc_attr( $id ) . '"><section aria-labelledby="' . esc_attr( $id ) . '-title">';
		echo '<h2 id="' . esc_attr( $id ) . '-title">' . esc_html(
			sprintf(
				/* translators: 1: step number, 2: number of steps, 3: step title. */
				__( 'Step %1$d of %2$d: %3$s', 'estasoft-handover-passport' ),
				$number,
				$total,
				$copy['title']
			)
		) . ' ' . View::badge( 'setup-' . $state, $label ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
		echo '<p>' . esc_html( $copy['text'] ) . '</p>';
		if ( '' !== $info['detail'] ) {
			echo '<p class="description">' . esc_html( $info['detail'] ) . '</p>';
		}

		if ( 'about' === $key && $can_edit ) {
			$this->aboutForm();
		}
		if ( 'backups' === $key && $can_edit ) {
			$this->suggestionsBox();
		}

		echo '<p class="estasoft-hp-step-actions">';
		if ( 'scan' === $key && $can_edit ) {
			echo $this->button( 'scan', $key, __( 'Scan now', 'estasoft-handover-passport' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- button() escapes its parts.
		}
		if ( '' !== $copy['url'] ) {
			echo '<a class="button' . ( SetupService::DONE === $state ? '' : ' button-primary' ) . '" href="' . esc_url( $copy['url'] ) . '">' . esc_html( $copy['link'] ) . '</a> ';
		}
		if ( $can_edit ) {
			if ( SetupService::DONE !== $state ) {
				echo $this->button( 'done', $key, __( 'Mark as done', 'estasoft-handover-passport' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- button() escapes its parts.
			}
			if ( SetupService::TODO === $state ) {
				echo $this->button( 'skip', $key, __( 'Skip for now', 'estasoft-handover-passport' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- button() escapes its parts.
			}
			if ( ( SetupService::DONE === $state && ! $info['auto'] ) || SetupService::SKIPPED === $state ) {
				echo $this->button( 'reopen', $key, __( 'Put back on the list', 'estasoft-handover-passport' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- button() escapes its parts.
			}
		}
		echo '</p></section></li>';
	}

	/**
	 * A one-button form.
	 *
	 * @param string $op      Operation.
	 * @param string $step    Step key.
	 * @param string $label   Button text.
	 * @param bool   $primary Whether it is the main button.
	 */
	private function button( string $op, string $step, string $label, bool $primary ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline-form">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="step" value="' . esc_attr( $step ) . '">'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::ACTION ) ) . '">'
			. '<button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form> ';
	}

	/**
	 * The form for step 2.
	 */
	private function aboutForm(): void {
		$passport = $this->plugin->passport();
		$choices  = $this->plugin->setup()->choices();
		$state    = FormState::take( 'setup_about' );
		$in       = null === $state ? array(
			'site_name'   => (string) $passport->get( 'site_name', '' ),
			'client_name' => (string) $passport->get( 'client_name', '' ),
			'purpose'     => (string) $passport->get( 'purpose', '' ),
			'type'        => $choices['type'],
			'role'        => $choices['role'],
			'brand_color' => (string) $passport->get( 'brand_color', '' ),
			'logo_id'     => (int) $passport->get( 'logo_id', 0 ),
		) : $state['input'];
		$errors   = null === $state ? array() : $state['errors'];

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-form">';
		Form::nonce( self::ACTION, false );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="op" value="about"><input type="hidden" name="step" value="about">';
		Form::row( 'site_name', array( 'type' => 'text', 'label' => __( 'Site name', 'estasoft-handover-passport' ), 'max' => 190 ), (string) ( $in['site_name'] ?? '' ), (string) ( $errors['site_name'] ?? '' ) );
		Form::row( 'client_name', array( 'type' => 'text', 'label' => __( 'Client or owner', 'estasoft-handover-passport' ), 'max' => 190, 'help' => __( 'Who the site belongs to. It appears on the packs you hand over.', 'estasoft-handover-passport' ) ), (string) ( $in['client_name'] ?? '' ), (string) ( $errors['client_name'] ?? '' ) );
		Form::row( 'purpose', array( 'type' => 'textarea', 'label' => __( 'What the site is for', 'estasoft-handover-passport' ), 'help' => __( 'One or two sentences a new person can read first. Never write passwords or keys.', 'estasoft-handover-passport' ) ), (string) ( $in['purpose'] ?? '' ), (string) ( $errors['purpose'] ?? '' ) );
		Form::row( 'role', array( 'type' => 'select', 'label' => __( 'Your role', 'estasoft-handover-passport' ), 'options' => SetupService::roleLabels() ), (string) ( $in['role'] ?? '' ), (string) ( $errors['role'] ?? '' ) );
		Form::row( 'type', array( 'type' => 'select', 'label' => __( 'Kind of site', 'estasoft-handover-passport' ), 'options' => SetupService::typeLabels(), 'help' => __( 'Used in step 6 to suggest runbooks and addresses to watch.', 'estasoft-handover-passport' ) ), (string) ( $in['type'] ?? '' ), (string) ( $errors['type'] ?? '' ) );
		Form::row( 'brand_color', array( 'type' => 'text', 'label' => __( 'Brand colour', 'estasoft-handover-passport' ), 'max' => 7, 'help' => __( 'Used for headings in the PDF and HTML packs, for example #2f4858.', 'estasoft-handover-passport' ) ), (string) ( $in['brand_color'] ?? '' ), (string) ( $errors['brand_color'] ?? '' ) );
		$this->logoField( (int) ( $in['logo_id'] ?? 0 ), (string) ( $errors['logo_id'] ?? '' ) );
		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save these details', 'estasoft-handover-passport' ) . '</button></p></form>';
	}

	/**
	 * The logo field: a button that opens the Media Library, a preview and a way to remove it.
	 *
	 * @param int    $logo_id Attachment ID or 0.
	 * @param string $error   Error message or ''.
	 */
	private function logoField( int $logo_id, string $error ): void {
		$url = $logo_id > 0 ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		echo '<div class="estasoft-hp-field' . ( '' !== $error ? ' has-error' : '' ) . ' estasoft-hp-logo" data-estasoft-logo>';
		echo '<span class="estasoft-hp-label" id="estasoft-hp-logo-label">' . esc_html__( 'Logo', 'estasoft-handover-passport' ) . '</span>';
		echo '<input type="hidden" name="logo_id" value="' . esc_attr( (string) $logo_id ) . '" data-estasoft-logo-id>';
		echo '<div class="estasoft-hp-logo-preview" data-estasoft-logo-preview>' . ( '' !== $url ? '<img src="' . esc_url( $url ) . '" alt="' . esc_attr__( 'The chosen logo', 'estasoft-handover-passport' ) . '">' : '' ) . '</div>';
		echo '<button type="button" class="button" data-estasoft-logo-choose data-title="' . esc_attr__( 'Choose a logo', 'estasoft-handover-passport' ) . '" data-button="' . esc_attr__( 'Use this image', 'estasoft-handover-passport' ) . '" aria-describedby="estasoft-hp-logo-label">' . esc_html__( 'Choose from the Media Library', 'estasoft-handover-passport' ) . '</button> ';
		echo '<button type="button" class="button-link" data-estasoft-logo-remove' . ( '' === $url ? ' hidden' : '' ) . '>' . esc_html__( 'Remove the logo', 'estasoft-handover-passport' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Printed on the cover of the PDF and HTML packs. A PNG or JPEG works best.', 'estasoft-handover-passport' ) . '</p>';
		if ( '' !== $error ) {
			echo '<p class="estasoft-hp-error" role="alert">' . esc_html( $error ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * What step 6 can add for the chosen kind of site.
	 */
	private function suggestionsBox(): void {
		$type = $this->plugin->setup()->choices()['type'];
		if ( '' === $type ) {
			echo '<p class="description">' . esc_html__( 'Choose the kind of site in step 2 and this step can suggest runbooks and addresses to watch.', 'estasoft-handover-passport' ) . '</p>';
			return;
		}
		$suggested = $this->plugin->setup()->suggestions( $type );
		$templates = \Estasoft\HandoverPassport\Services\RunbookService::templates();
		$names     = array();
		foreach ( $suggested['runbooks'] as $key ) {
			if ( isset( $templates[ $key ] ) ) {
				$names[] = (string) $templates[ $key ]['title'];
			}
		}
		echo '<div class="estasoft-hp-card"><p><strong>' . esc_html(
			sprintf(
				/* translators: %s: kind of site, for example "Blog". */
				__( 'Suggested for a site of this kind: %s', 'estasoft-handover-passport' ),
				SetupService::typeLabels()[ $type ] ?? $type
			)
		) . '</strong></p>';
		echo '<p>' . esc_html__( 'Runbooks, made as drafts you must check against how this site is built:', 'estasoft-handover-passport' ) . ' ' . esc_html( implode( ', ', $names ) ) . '.</p>';
		$addresses = array();
		foreach ( $suggested['urls'] as $url ) {
			$addresses[] = $url['name'];
		}
		echo '<p>' . esc_html__( 'Addresses to watch, taken from this site:', 'estasoft-handover-passport' ) . ' ' . esc_html( implode( ', ', $addresses ) ) . '.</p>';
		echo $this->button( 'suggest', 'backups', __( 'Add what is missing', 'estasoft-handover-passport' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- button() escapes its parts.
		echo '</div>';
	}

	/**
	 * Fixed words for each step.
	 *
	 * @return array<string, array{title: string, text: string, link: string, url: string}>
	 */
	public static function copy(): array {
		return array(
			'scan'     => array(
				'title' => __( 'Scan the site', 'estasoft-handover-passport' ),
				'text'  => __( 'Passport reads WordPress, PHP, the database, plugins, themes and administrators from this site. The first scan is the baseline; later scans list what changed.', 'estasoft-handover-passport' ),
				'link'  => __( 'See the stack', 'estasoft-handover-passport' ),
				'url'   => add_query_arg( array( 'page' => InventoryScreen::SLUG, 'tab' => 'stack' ), admin_url( 'admin.php' ) ),
			),
			'about'    => array(
				'title' => __( 'About the site', 'estasoft-handover-passport' ),
				'text'  => __( 'Say what the site is, who it belongs to and what it is for. This is what a new person reads first.', 'estasoft-handover-passport' ),
				'link'  => '',
				'url'   => '',
			),
			'people'   => array(
				'title' => __( 'People', 'estasoft-handover-passport' ),
				'text'  => __( 'Add the owner, whoever pays, whoever looks after the site and who to call first when it breaks. Work email addresses only.', 'estasoft-handover-passport' ),
				'link'  => __( 'Open People', 'estasoft-handover-passport' ),
				'url'   => add_query_arg( array( 'page' => PeopleScreen::SLUG, 'tab' => 'people' ), admin_url( 'admin.php' ) ),
			),
			'domains'  => array(
				'title' => __( 'Domains, DNS and hosting', 'estasoft-handover-passport' ),
				'text'  => __( 'Record each domain, where its DNS is kept, when it renews, and who hosts the site. These dates are the ones that cost most when missed.', 'estasoft-handover-passport' ),
				'link'  => __( 'Open Domains', 'estasoft-handover-passport' ),
				'url'   => add_query_arg( array( 'page' => InventoryScreen::SLUG, 'tab' => 'domains' ), admin_url( 'admin.php' ) ),
			),
			'licences' => array(
				'title' => __( 'Licences', 'estasoft-handover-passport' ),
				'text'  => __( 'For each plugin or theme that does not come from WordPress.org, record the vendor, the renewal date and who pays. If everything on the site is free, mark this step as done.', 'estasoft-handover-passport' ),
				'link'  => __( 'Open Licences', 'estasoft-handover-passport' ),
				'url'   => add_query_arg( array( 'page' => InventoryScreen::SLUG, 'tab' => 'licences' ), admin_url( 'admin.php' ) ),
			),
			'backups'  => array(
				'title' => __( 'Backups and a restore runbook', 'estasoft-handover-passport' ),
				'text'  => __( 'Record where the backups are kept and how often they run, and write down how to restore from them before the day you need to.', 'estasoft-handover-passport' ),
				'link'  => __( 'Open Backups', 'estasoft-handover-passport' ),
				'url'   => add_query_arg( array( 'page' => SafetyScreen::SLUG, 'tab' => 'backups' ), admin_url( 'admin.php' ) ),
			),
			'review'   => array(
				'title' => __( 'Review what is missing', 'estasoft-handover-passport' ),
				'text'  => __( 'Open the findings. Each one states a fact and a date, and says what to do about it. Fix what you can, snooze or accept the rest with a reason.', 'estasoft-handover-passport' ),
				'link'  => __( 'Open Findings', 'estasoft-handover-passport' ),
				'url'   => add_query_arg( array( 'page' => FindingsScreen::SLUG ), admin_url( 'admin.php' ) ),
			),
		);
	}

	/**
	 * Carry out a button or the details form.
	 */
	public function handle(): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to change the Passport.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$op     = Request::post( 'op' );
		$step   = sanitize_key( Request::post( 'step' ) );
		$setup  = $this->plugin->setup();
		$notice = 'saved';
		$anchor = in_array( $step, SetupService::STEPS, true ) ? 'estasoft-hp-step-' . $step : '';

		try {
			switch ( $op ) {
				case 'skip':
					$setup->skip( $step );
					$notice = 'setup_skipped';
					break;
				case 'done':
					$setup->markDone( $step );
					$notice = 'setup_done';
					break;
				case 'reopen':
					$setup->reopen( $step );
					$notice = 'setup_reopened';
					break;
				case 'scan':
					$result = $this->plugin->scanService()->runOnDemand( 'manual' );
					$notice = array( 'done' => 'scan_done', 'busy' => 'scan_busy', 'wait' => 'scan_wait' )[ $result['status'] ] ?? 'scan_failed';
					break;
				case 'about':
					$input = array(
						'site_name'   => Request::post( 'site_name' ),
						'client_name' => Request::post( 'client_name' ),
						'purpose'     => Request::postedTextarea( 'purpose' ),
						'type'        => Request::post( 'type' ),
						'role'        => Request::post( 'role' ),
						'brand_color' => Request::post( 'brand_color' ),
						'logo_id'     => Request::post( 'logo_id' ),
					);
					try {
						$setup->saveAbout( $input );
					} catch ( ValidationError | SecretFound $e ) {
						$field = $e instanceof ValidationError ? $e->field() : (string) strtok( $e->field(), '.' );
						$keys  = array(
							__( 'Site name', 'estasoft-handover-passport' )            => 'site_name',
							__( 'Client or owner', 'estasoft-handover-passport' )      => 'client_name',
							__( 'What the site is for', 'estasoft-handover-passport' ) => 'purpose',
							__( 'Your role', 'estasoft-handover-passport' )            => 'role',
							__( 'Kind of site', 'estasoft-handover-passport' )         => 'type',
							__( 'Brand colour', 'estasoft-handover-passport' )         => 'brand_color',
							__( 'Logo', 'estasoft-handover-passport' )                 => 'logo_id',
						);
						FormState::put( $this->plugin->secretGuard(), 'setup_about', $input, array( ( $keys[ $field ] ?? 'purpose' ) => $e->getMessage() ) );
						$notice = 'setup_invalid';
						break;
					}
					$notice = 'setup_saved';
					break;
				case 'suggest':
					$added = $setup->applySuggestions();
					View::say(
						sprintf(
							/* translators: 1: number of runbooks added, 2: number of addresses added. */
							__( 'Added %1$d runbooks and %2$d addresses. The runbooks are drafts: check every step against how this site is built.', 'estasoft-handover-passport' ),
							$added['runbooks'],
							$added['urls']
						),
						false
					);
					$notice = '';
					break;
			}
		} catch ( ValidationError $e ) {
			View::say( $e->getMessage() );
			$notice = '';
		}

		$args = array( 'page' => self::SLUG );
		if ( '' !== $notice ) {
			$args['estasoft_hp_notice'] = $notice;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . ( '' !== $anchor ? '#' . $anchor : '' ) );
		exit;
	}
}
