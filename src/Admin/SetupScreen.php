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
	 * The open page, worked out once per request.
	 *
	 * @var string|null
	 */
	private ?string $where = null;

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
	 * Address of the screen, which opens at the welcome page or at the first step that is not done.
	 */
	public static function url(): string {
		return add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) );
	}

	/**
	 * Address of one step, of the welcome page ("welcome") or of the closing page ("finish").
	 *
	 * @param string $step Step key.
	 */
	public static function stepUrl( string $step ): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'step' => $step,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The step before (-1) or after (1) another one, or '' at either end.
	 *
	 * @param string $step   Step key.
	 * @param int    $offset -1 or 1.
	 */
	private static function neighbour( string $step, int $offset ): string {
		$at = array_search( $step, SetupService::STEPS, true );
		if ( false === $at ) {
			return '';
		}
		return SetupService::STEPS[ $at + $offset ] ?? '';
	}

	/**
	 * Which page of the guide is open: "welcome", one step key or "finish".
	 */
	private function where(): string {
		if ( null !== $this->where ) {
			return $this->where;
		}
		$setup   = $this->plugin->setup();
		$steps   = $setup->steps();
		$asked   = Request::key( 'step' );
		$touched = false;
		foreach ( $steps as $info ) {
			if ( SetupService::SKIPPED === $info['state'] || ( SetupService::DONE === $info['state'] && ! $info['auto'] ) ) {
				$touched = true;
			}
		}
		if ( 'welcome' === $asked || 'finish' === $asked || isset( $steps[ $asked ] ) ) {
			$this->where = $asked;
		} elseif ( ! $touched ) {
			$this->where = 'welcome';
		} else {
			$left        = $setup->remaining();
			$this->where = array() === $left ? 'finish' : (string) reset( $left );
		}
		return $this->where;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function describe( string $tab ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The guide has one tab.
		$where = $this->where();
		if ( 'welcome' === $where ) {
			return __( 'Seven short steps. Skip any of them and come back later.', 'estasoft-handover-passport' );
		}
		if ( 'finish' === $where ) {
			$left  = count( $this->plugin->setup()->remaining() );
			$total = count( SetupService::STEPS );
			return 0 === $left
				? __( 'Every step is done.', 'estasoft-handover-passport' )
				: sprintf(
					/* translators: 1: steps done, 2: number of steps. */
					__( '%1$d of %2$d steps are done.', 'estasoft-handover-passport' ),
					$total - $left,
					$total
				);
		}
		return sprintf(
			/* translators: 1: step number, 2: number of steps. */
			__( 'Step %1$d of %2$d', 'estasoft-handover-passport' ),
			(int) array_search( $where, SetupService::STEPS, true ) + 1,
			count( SetupService::STEPS )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tab( string $tab ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The guide has one tab.
		$steps = $this->plugin->setup()->steps();
		$where = $this->where();
		$this->strip( $steps, $where );
		if ( 'welcome' === $where ) {
			$this->welcome();
		} elseif ( 'finish' === $where ) {
			$this->finish( $steps );
		} else {
			$this->step( $where, $steps[ $where ], Capabilities::can( Capabilities::EDIT ) );
		}
	}

	/**
	 * The row of steps at the top: a tick for a done step, a dash for a skipped one, a number for the rest. Each is a link.
	 *
	 * @param array<string, array{state: string, auto: bool, detail: string}> $steps State of every step.
	 * @param string                                                          $where Page that is open.
	 */
	private function strip( array $steps, string $where ): void {
		$copy  = self::copy();
		$words = array(
			SetupService::DONE    => __( 'done', 'estasoft-handover-passport' ),
			SetupService::SKIPPED => __( 'skipped', 'estasoft-handover-passport' ),
			SetupService::TODO    => __( 'to do', 'estasoft-handover-passport' ),
		);
		echo '<nav class="wiz" aria-label="' . esc_attr__( 'Setup steps', 'estasoft-handover-passport' ) . '">';
		$number = 0;
		foreach ( $steps as $key => $info ) {
			++$number;
			$class = $key === $where ? 's on' : 's ' . $info['state'];
			$mark  = SetupService::DONE === $info['state'] ? Icons::svg( 'ok', 14 ) : ( SetupService::SKIPPED === $info['state'] ? Icons::svg( 'minus', 14 ) : '<b>' . $number . '</b>' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::stepUrl( $key ) ) . '"' . ( $key === $where ? ' aria-current="step"' : '' ) . '>' . $mark . esc_html( $copy[ $key ]['short'] ) . '<span class="sr"> (' . esc_html( $words[ $info['state'] ] ) . ')</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is fixed markup.
		}
		echo '</nav>';
	}

	/**
	 * The first page: what the guide does and what it never does.
	 */
	private function welcome(): void {
		View::cardStart( __( 'Welcome to Passport', 'estasoft-handover-passport' ) );
		echo '<p class="lede">' . esc_html__( 'A passport is one page that says how this site is built, who looks after it and what to do when something breaks. Setup takes about ten minutes.', 'estasoft-handover-passport' ) . '</p>';
		echo '<div class="row maker"><img src="' . esc_url( ESTASOFT_HP_URL . 'assets/images/passport-icon.png' ) . '" alt="" width="48" height="48"><div><b>' . esc_html__( 'Estasoft Handover Passport', 'estasoft-handover-passport' ) . '</b><div class="t2 cap">' . esc_html__( 'By ESTASOFT TECHNOLOGIES', 'estasoft-handover-passport' ) . '</div></div></div>';
		echo '<div class="g g3">';
		$points = array(
			__( 'It reads', 'estasoft-handover-passport' ) => __( 'Your theme, plugins, PHP, database and server details. Nothing is sent anywhere.', 'estasoft-handover-passport' ),
			__( 'You add', 'estasoft-handover-passport' )  => __( 'What no scan can know: licences, domains, who owns what and how to restore.', 'estasoft-handover-passport' ),
			__( 'It never keeps', 'estasoft-handover-passport' ) => __( 'Passwords, API keys or licence keys. It records where a secret lives, not the secret.', 'estasoft-handover-passport' ),
		);
		foreach ( $points as $title => $text ) {
			echo '<section class="card"><div class="hd"><h2>' . esc_html( $title ) . '</h2></div><div class="bdy"><p class="t2">' . esc_html( $text ) . '</p></div></section>';
		}
		echo '</div>';
		$footer = '<a class="btn pri" href="' . esc_url( self::stepUrl( SetupService::STEPS[0] ) ) . '">' . esc_html__( 'Start', 'estasoft-handover-passport' ) . Icons::svg( 'arrow', 16 ) . '</a>'
			. '<a class="btn lnk" href="' . esc_url( admin_url( 'admin.php?page=' . Admin::MENU_SLUG ) ) . '">' . esc_html__( 'Skip setup for now', 'estasoft-handover-passport' ) . '</a>';
		View::cardEnd( $footer );
	}

	/**
	 * The last page: every step with its state, and the way out.
	 *
	 * @param array<string, array{state: string, auto: bool, detail: string}> $steps State of every step.
	 */
	private function finish( array $steps ): void {
		$left = count( $this->plugin->setup()->remaining() );
		$copy = self::copy();
		View::cardStart( 0 === $left ? __( 'Passport is ready', 'estasoft-handover-passport' ) : __( 'Setup is not finished', 'estasoft-handover-passport' ), '', true );
		echo '<div class="intro"><p class="lede">' . esc_html(
			0 === $left
				? __( 'Every step is done. You can still change any of it from the screens named below.', 'estasoft-handover-passport' )
				: __( 'Steps that are skipped or still to do stay on the Overview as things to do. Open one to carry on.', 'estasoft-handover-passport' )
		) . '</p></div>';
		echo '<ol class="stp">';
		$number = 0;
		foreach ( $steps as $key => $info ) {
			++$number;
			$label = array(
				SetupService::DONE    => __( 'Done', 'estasoft-handover-passport' ),
				SetupService::SKIPPED => __( 'Skipped', 'estasoft-handover-passport' ),
				SetupService::TODO    => __( 'To do', 'estasoft-handover-passport' ),
			)[ $info['state'] ];
			echo '<li><span class="no' . ( SetupService::DONE === $info['state'] ? ' done' : '' ) . '">' . ( SetupService::DONE === $info['state'] ? Icons::svg( 'ok', 16 ) : esc_html( (string) $number ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is fixed markup.
			echo '<div class="go"><b>' . esc_html( $copy[ $key ]['title'] ) . '</b> ' . View::badge( 'setup-' . $info['state'], $label ) . '<div class="t2">' . esc_html( '' !== $info['detail'] ? $info['detail'] : $copy[ $key ]['text'] ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			echo '<a class="btn sm" href="' . esc_url( self::stepUrl( $key ) ) . '">' . esc_html( SetupService::DONE === $info['state'] ? __( 'Review', 'estasoft-handover-passport' ) : __( 'Open', 'estasoft-handover-passport' ) ) . '<span class="sr"> ' . esc_html( $copy[ $key ]['title'] ) . '</span></a></li>';
		}
		echo '</ol>';
		$footer = '<a class="btn" href="' . esc_url( self::stepUrl( SetupService::STEPS[ count( SetupService::STEPS ) - 1 ] ) ) . '">' . Icons::svg( 'back', 16 ) . esc_html__( 'Back', 'estasoft-handover-passport' ) . '</a>'
			. '<a class="btn pri" href="' . esc_url( admin_url( 'admin.php?page=' . Admin::MENU_SLUG ) ) . '">' . esc_html__( 'Go to the Overview', 'estasoft-handover-passport' ) . Icons::svg( 'arrow', 16 ) . '</a>';
		View::cardEnd( $footer );
	}

	/**
	 * Print one step.
	 *
	 * @param string                                           $key      Step key.
	 * @param array{state: string, auto: bool, detail: string} $info     State of the step.
	 * @param bool                                             $can_edit Whether the person may change the Passport.
	 */
	private function step( string $key, array $info, bool $can_edit ): void {
		$copy  = self::copy()[ $key ];
		$state = $info['state'];
		$label = array(
			SetupService::DONE    => __( 'Done', 'estasoft-handover-passport' ),
			SetupService::SKIPPED => __( 'Skipped', 'estasoft-handover-passport' ),
			SetupService::TODO    => __( 'To do', 'estasoft-handover-passport' ),
		)[ $state ];

		echo '<section class="card estasoft-hp-step estasoft-hp-step--' . esc_attr( $state ) . '" id="estasoft-hp-step-' . esc_attr( $key ) . '" aria-labelledby="estasoft-hp-step-title"><div class="hd"><h2 id="estasoft-hp-step-title">' . esc_html( $copy['title'] ) . '</h2>' . View::badge( 'setup-' . $state, $label ) . '</div><div class="bdy">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
		echo '<p class="lede">' . esc_html( $copy['text'] ) . '</p>';
		if ( '' !== $info['detail'] ) {
			echo '<p class="t2">' . esc_html( $info['detail'] ) . '</p>';
		}
		if ( 'scan' === $key ) {
			$this->found();
		}
		if ( 'about' === $key && $can_edit ) {
			$this->aboutForm();
		}
		if ( 'backups' === $key && $can_edit ) {
			$this->suggestionsBox();
		}
		View::cardEnd( $this->footer( $key, $info, $can_edit ) );
	}

	/**
	 * The buttons under a step: Back, the one filled button, and the smaller choices. Which one is filled depends on
	 * what is left to do: the step's own work while it is open, Next once it is done.
	 *
	 * @param string                                           $key      Step key.
	 * @param array{state: string, auto: bool, detail: string} $info     State of the step.
	 * @param bool                                             $can_edit Whether the person may change the Passport.
	 */
	private function footer( string $key, array $info, bool $can_edit ): string {
		$copy  = self::copy()[ $key ];
		$state = $info['state'];
		$prev  = self::neighbour( $key, -1 );
		$next  = self::neighbour( $key, 1 );
		$back  = self::link( self::stepUrl( '' === $prev ? 'welcome' : $prev ), __( 'Back', 'estasoft-handover-passport' ), '', 'back', true );
		$go    = static fn( bool $filled ): string => self::link( self::stepUrl( '' === $next ? 'finish' : $next ), '' === $next ? __( 'Finish', 'estasoft-handover-passport' ) : __( 'Next', 'estasoft-handover-passport' ), $filled ? 'pri' : '', 'arrow', false );
		$open  = static fn( bool $filled ): string => '' === $copy['url'] ? '' : self::link( $copy['url'], $copy['link'], $filled ? 'pri' : '' );

		if ( ! $can_edit ) {
			return $back . $go( true ) . $open( false );
		}

		$out  = $back;
		$save = 'about' === $key ? '<button type="submit" form="estasoft-hp-about" class="btn pri">' . esc_html__( 'Save and continue', 'estasoft-handover-passport' ) . Icons::svg( 'arrow', 16 ) . '</button>' : '';
		if ( SetupService::DONE === $state ) {
			$out .= '' !== $save ? $save . $go( false ) : $go( true );
			if ( 'scan' === $key ) {
				$out .= $this->button( 'scan', $key, __( 'Scan again', 'estasoft-handover-passport' ), false );
			}
			$out .= $open( false );
			if ( ! $info['auto'] ) {
				$out .= $this->button( 'reopen', $key, __( 'Put back on the list', 'estasoft-handover-passport' ), false );
			}
			return $out;
		}

		if ( 'scan' === $key ) {
			$out .= $this->button( 'scan', $key, __( 'Scan now', 'estasoft-handover-passport' ), true ) . $open( false );
		} elseif ( 'about' === $key ) {
			$out .= $save;
		} else {
			$out .= $open( true );
		}
		$out .= $this->button( 'done', $key, __( 'Mark as done', 'estasoft-handover-passport' ), false );
		if ( SetupService::TODO === $state ) {
			return $out . $this->button( 'skip', $key, __( 'Skip for now', 'estasoft-handover-passport' ), false );
		}
		return $out . $go( false ) . $this->button( 'reopen', $key, __( 'Put back on the list', 'estasoft-handover-passport' ), false );
	}

	/**
	 * A link drawn as a button.
	 *
	 * @param string $url   Address.
	 * @param string $label Words, plain text.
	 * @param string $extra Extra class such as "pri".
	 * @param string $icon  Icon name or ''.
	 * @param bool   $first True when the icon comes before the words.
	 */
	private static function link( string $url, string $label, string $extra = '', string $icon = '', bool $first = false ): string {
		$svg = '' === $icon ? '' : Icons::svg( $icon, 16 );
		return '<a class="btn' . ( '' !== $extra ? ' ' . esc_attr( $extra ) : '' ) . '" href="' . esc_url( $url ) . '">' . ( $first ? $svg : '' ) . esc_html( $label ) . ( $first ? '' : $svg ) . '</a>';
	}

	/**
	 * What the last scan found, a few lines of it, so the person can see the scan worked and nothing is missing.
	 */
	private function found(): void {
		$facts = $this->plugin->scanService()->latestFacts();
		if ( array() === $facts ) {
			return;
		}
		$themes  = (array) ( $facts['themes'] ?? array() );
		$parents = array();
		foreach ( $themes as $theme ) {
			if ( '' !== (string) ( $theme['parent'] ?? '' ) ) {
				$parents[ (string) $theme['parent'] ] = true;
			}
		}
		$items = array();
		foreach ( $themes as $slug => $theme ) {
			$slug = (string) $slug;
			if ( empty( $theme['active'] ) && ! isset( $parents[ $slug ] ) ) {
				continue;
			}
			$kind    = '' !== (string) ( $theme['parent'] ?? '' ) ? __( 'Theme (child)', 'estasoft-handover-passport' ) : ( isset( $parents[ $slug ] ) ? __( 'Theme (parent)', 'estasoft-handover-passport' ) : __( 'Theme', 'estasoft-handover-passport' ) );
			$items[] = array( (string) ( $theme['name'] ?? $slug ), $slug, $kind, (string) ( $theme['version'] ?? '' ) );
		}
		$plugins = (array) ( $facts['plugins'] ?? array() );
		uasort(
			$plugins,
			static fn( $a, $b ): int => array( empty( $a['active'] ) ? 1 : 0, (string) ( $a['name'] ?? '' ) ) <=> array( empty( $b['active'] ) ? 1 : 0, (string) ( $b['name'] ?? '' ) )
		);
		foreach ( $plugins as $slug => $plugin ) {
			$items[] = array( (string) ( $plugin['name'] ?? $slug ), (string) $slug, empty( $plugin['active'] ) ? __( 'Plugin (inactive)', 'estasoft-handover-passport' ) : __( 'Plugin', 'estasoft-handover-passport' ), (string) ( $plugin['version'] ?? '' ) );
		}
		$total = count( $items );
		echo '<p class="b found-head">' . esc_html(
			sprintf(
				/* translators: %d: number of items the scan found. */
				_n( '%d item found', '%d items found', $total, 'estasoft-handover-passport' ),
				$total
			)
		) . '</p>';
		echo '<div class="scr"><table class="tbl cards"><caption class="sr">' . esc_html__( 'Detected items', 'estasoft-handover-passport' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Name', 'estasoft-handover-passport' ), __( 'Kind', 'estasoft-handover-passport' ), __( 'Version', 'estasoft-handover-passport' ), __( 'Source', 'estasoft-handover-passport' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $items, 0, 8 ) as $row ) {
			echo '<tr><td class="t1" data-label="' . esc_attr__( 'Name', 'estasoft-handover-passport' ) . '"><b>' . esc_html( $row[0] ) . '</b><span class="sub">' . esc_html( $row[1] ) . '</span></td>';
			echo '<td data-label="' . esc_attr__( 'Kind', 'estasoft-handover-passport' ) . '">' . esc_html( $row[2] ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Version', 'estasoft-handover-passport' ) . '">' . esc_html( $row[3] ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Source', 'estasoft-handover-passport' ) . '"><span class="tag">' . Icons::svg( 'search', 12 ) . esc_html__( 'Detected', 'estasoft-handover-passport' ) . '</span></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() is fixed markup.
		}
		echo '</tbody></table></div>';
		if ( $total > 8 ) {
			echo '<p class="cap t2 found-note">' . esc_html(
				sprintf(
					/* translators: 1: rows shown, 2: number of items found. */
					__( 'Showing %1$d of %2$d items. The rest are on the Stack screen.', 'estasoft-handover-passport' ),
					8,
					$total
				)
			) . '</p>';
		}
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
			. '<button type="submit" class="btn' . ( $primary ? ' pri' : '' ) . '">' . esc_html( $label ) . '</button></form>';
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

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-form" id="estasoft-hp-about">';
		Form::nonce( self::ACTION, false );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="op" value="about"><input type="hidden" name="step" value="about">';
		Form::row( 'site_name', array( 'type' => 'text', 'label' => __( 'Site name', 'estasoft-handover-passport' ), 'max' => 190 ), (string) ( $in['site_name'] ?? '' ), (string) ( $errors['site_name'] ?? '' ) );
		Form::row( 'client_name', array( 'type' => 'text', 'label' => __( 'Client or owner', 'estasoft-handover-passport' ), 'max' => 190, 'help' => __( 'Who the site belongs to. It appears on the packs you hand over.', 'estasoft-handover-passport' ) ), (string) ( $in['client_name'] ?? '' ), (string) ( $errors['client_name'] ?? '' ) );
		Form::row( 'purpose', array( 'type' => 'textarea', 'label' => __( 'What the site is for', 'estasoft-handover-passport' ), 'help' => __( 'One or two sentences a new person can read first. Never write passwords or keys.', 'estasoft-handover-passport' ) ), (string) ( $in['purpose'] ?? '' ), (string) ( $errors['purpose'] ?? '' ) );
		Form::row( 'role', array( 'type' => 'select', 'label' => __( 'Your role', 'estasoft-handover-passport' ), 'options' => SetupService::roleLabels() ), (string) ( $in['role'] ?? '' ), (string) ( $errors['role'] ?? '' ) );
		Form::row( 'type', array( 'type' => 'select', 'label' => __( 'Kind of site', 'estasoft-handover-passport' ), 'options' => SetupService::typeLabels(), 'help' => __( 'Used in step 6 to suggest runbooks and addresses to watch.', 'estasoft-handover-passport' ) ), (string) ( $in['type'] ?? '' ), (string) ( $errors['type'] ?? '' ) );
		Form::row( 'brand_color', array( 'type' => 'text', 'label' => __( 'Brand colour', 'estasoft-handover-passport' ), 'max' => 7, 'help' => __( 'Used for headings in the PDF and HTML packs, for example #2f4858.', 'estasoft-handover-passport' ) ), (string) ( $in['brand_color'] ?? '' ), (string) ( $errors['brand_color'] ?? '' ) );
		$this->logoField( (int) ( $in['logo_id'] ?? 0 ), (string) ( $errors['logo_id'] ?? '' ) );
		echo '</form>';
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
	 * @return array<string, array{short: string, title: string, text: string, link: string, url: string}>
	 */
	public static function copy(): array {
		return array(
			'scan'     => array(
				'short' => __( 'Scan', 'estasoft-handover-passport' ),
				'title' => __( 'Scan the site', 'estasoft-handover-passport' ),
				'text'  => __( 'Passport reads WordPress, PHP, the database, plugins, themes and administrators from this site. The first scan is the baseline; later scans list what changed.', 'estasoft-handover-passport' ),
				'link'  => __( 'See the stack', 'estasoft-handover-passport' ),
				'url'   => add_query_arg( array( 'page' => InventoryScreen::SLUG, 'tab' => 'stack' ), admin_url( 'admin.php' ) ),
			),
			'about'    => array(
				'short' => __( 'About', 'estasoft-handover-passport' ),
				'title' => __( 'About the site', 'estasoft-handover-passport' ),
				'text'  => __( 'Say what the site is, who it belongs to and what it is for. This is what a new person reads first.', 'estasoft-handover-passport' ),
				'link'  => '',
				'url'   => '',
			),
			'people'   => array(
				'short' => __( 'People', 'estasoft-handover-passport' ),
				'title' => __( 'People', 'estasoft-handover-passport' ),
				'text'  => __( 'Add the owner, whoever pays, whoever looks after the site and who to call first when it breaks. Work email addresses only.', 'estasoft-handover-passport' ),
				'link'  => __( 'Open People', 'estasoft-handover-passport' ),
				'url'   => add_query_arg( array( 'page' => PeopleScreen::SLUG, 'tab' => 'people' ), admin_url( 'admin.php' ) ),
			),
			'domains'  => array(
				'short' => __( 'Domains', 'estasoft-handover-passport' ),
				'title' => __( 'Domains, DNS and hosting', 'estasoft-handover-passport' ),
				'text'  => __( 'Record each domain, where its DNS is kept, when it renews, and who hosts the site. These dates are the ones that cost most when missed.', 'estasoft-handover-passport' ),
				'link'  => __( 'Open Domains', 'estasoft-handover-passport' ),
				'url'   => add_query_arg( array( 'page' => InventoryScreen::SLUG, 'tab' => 'domains' ), admin_url( 'admin.php' ) ),
			),
			'licences' => array(
				'short' => __( 'Licences', 'estasoft-handover-passport' ),
				'title' => __( 'Licences', 'estasoft-handover-passport' ),
				'text'  => __( 'For each plugin or theme that does not come from WordPress.org, record the vendor, the renewal date and who pays. If everything on the site is free, mark this step as done.', 'estasoft-handover-passport' ),
				'link'  => __( 'Open Licences', 'estasoft-handover-passport' ),
				'url'   => add_query_arg( array( 'page' => InventoryScreen::SLUG, 'tab' => 'licences' ), admin_url( 'admin.php' ) ),
			),
			'backups'  => array(
				'short' => __( 'Backups', 'estasoft-handover-passport' ),
				'title' => __( 'Backups and a restore runbook', 'estasoft-handover-passport' ),
				'text'  => __( 'Record where the backups are kept and how often they run, and write down how to restore from them before the day you need to.', 'estasoft-handover-passport' ),
				'link'  => __( 'Open Backups', 'estasoft-handover-passport' ),
				'url'   => add_query_arg( array( 'page' => SafetyScreen::SLUG, 'tab' => 'backups' ), admin_url( 'admin.php' ) ),
			),
			'review'   => array(
				'short' => __( 'Review', 'estasoft-handover-passport' ),
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
		$valid  = in_array( $step, SetupService::STEPS, true );
		$to     = $step;

		try {
			switch ( $op ) {
				case 'skip':
					$setup->skip( $step );
					$notice = 'setup_skipped';
					$to     = self::neighbour( $step, 1 );
					break;
				case 'done':
					$setup->markDone( $step );
					$notice = 'setup_done';
					$to     = self::neighbour( $step, 1 );
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
					$to     = self::neighbour( 'about', 1 );
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
		if ( $valid ) {
			$args['step'] = '' === $to ? 'finish' : $to;
		}
		if ( '' !== $notice ) {
			$args['estasoft_hp_notice'] = $notice;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
