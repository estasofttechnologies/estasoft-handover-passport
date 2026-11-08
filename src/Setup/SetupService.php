<?php
/**
 * The setup guide: what is done, what was skipped, and the details of the Passport itself (ONB-3, ONB-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Setup;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Services\RunbookService;

defined( 'ABSPATH' ) || exit;

/**
 * Seven steps, each of which can be skipped and picked up again. A step counts as done when the Passport already holds
 * what it asks for, even if nobody pressed a button, so someone who fills in the registers directly is not asked again.
 */
final class SetupService {

	public const STEPS = array( 'scan', 'about', 'people', 'domains', 'licences', 'backups', 'review' );
	public const TYPES = array( 'brochure', 'store', 'membership', 'multilingual', 'blog' );
	public const ROLES = array( 'agency', 'owner', 'in_house', 'other' );

	public const DONE    = 'done';
	public const SKIPPED = 'skipped';
	public const TODO    = 'todo';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Words for the kinds of site.
	 *
	 * @return array<string, string>
	 */
	public static function typeLabels(): array {
		return array(
			'brochure'     => __( 'Brochure site', 'estasoft-handover-passport' ),
			'store'        => __( 'WooCommerce store', 'estasoft-handover-passport' ),
			'membership'   => __( 'Membership or learning site', 'estasoft-handover-passport' ),
			'multilingual' => __( 'Multilingual site', 'estasoft-handover-passport' ),
			'blog'         => __( 'Blog', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Words for the person's relation to the site.
	 *
	 * @return array<string, string>
	 */
	public static function roleLabels(): array {
		return array(
			'agency'   => __( 'I look after it for a client', 'estasoft-handover-passport' ),
			'owner'    => __( 'It is my own site', 'estasoft-handover-passport' ),
			'in_house' => __( 'I look after it for my employer', 'estasoft-handover-passport' ),
			'other'    => __( 'Something else', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * State of every step: done, skipped or todo, and a short account of what the Passport holds for it.
	 *
	 * @return array<string, array{state: string, auto: bool, detail: string}>
	 */
	public function steps(): array {
		$setup   = $this->stored();
		$counts  = $this->plugin->records()->items()->countsByKind();
		$people  = count( $this->plugin->people()->all() );
		$books   = count( $this->plugin->runbooks()->all() );
		$out     = array();
		$details = $this->details( $counts, $people, $books );
		foreach ( self::STEPS as $step ) {
			$auto  = $this->auto( $step, $counts, $people, $books );
			$state = self::TODO;
			if ( $auto || in_array( $step, $setup['done'], true ) ) {
				$state = self::DONE;
			} elseif ( in_array( $step, $setup['skipped'], true ) ) {
				$state = self::SKIPPED;
			}
			$out[ $step ] = array(
				'state'  => $state,
				'auto'   => $auto,
				'detail' => $details[ $step ] ?? '',
			);
		}
		return $out;
	}

	/**
	 * Steps that are not done, skipped ones included, in order.
	 *
	 * @return array<int, string>
	 */
	public function remaining(): array {
		$left = array();
		foreach ( $this->steps() as $step => $info ) {
			if ( self::DONE !== $info['state'] ) {
				$left[] = $step;
			}
		}
		return $left;
	}

	/**
	 * Whether every step is done.
	 */
	public function complete(): bool {
		return array() === $this->remaining();
	}

	/**
	 * Mark a step as done by hand.
	 *
	 * @param string $step Step key.
	 */
	public function markDone( string $step ): void {
		$this->move( $step, 'done' );
	}

	/**
	 * Skip a step for now. It stays on the list of things to do.
	 *
	 * @param string $step Step key.
	 */
	public function skip( string $step ): void {
		$this->move( $step, 'skipped' );
	}

	/**
	 * Put a step back to do.
	 *
	 * @param string $step Step key.
	 */
	public function reopen( string $step ): void {
		$this->move( $step, '' );
	}

	/**
	 * What the person chose as the kind of site and as their own role.
	 *
	 * @return array{type: string, role: string}
	 */
	public function choices(): array {
		$setup = $this->stored();
		return array(
			'type' => $setup['type'],
			'role' => $setup['role'],
		);
	}

	/**
	 * Save the details of the Passport: names, purpose, kind of site, the person's role, colour and logo.
	 *
	 * @param array<string, mixed> $input Posted values.
	 * @throws ValidationError When a value is not acceptable.
	 * @throws SecretFound     When a text looks like a password, key or token.
	 */
	public function saveAbout( array $input ): void {
		$text = static fn( string $key, int $max ): string => mb_substr( trim( sanitize_text_field( is_scalar( $input[ $key ] ?? null ) ? (string) $input[ $key ] : '' ) ), 0, $max );

		$site = $text( 'site_name', 190 );
		if ( '' === $site ) {
			$site = (string) get_option( 'blogname', '' );
		}
		$client  = $text( 'client_name', 190 );
		$purpose = mb_substr( trim( sanitize_textarea_field( is_scalar( $input['purpose'] ?? null ) ? (string) $input['purpose'] : '' ) ), 0, 1000 );
		$type    = sanitize_key( is_scalar( $input['type'] ?? null ) ? (string) $input['type'] : '' );
		$role    = sanitize_key( is_scalar( $input['role'] ?? null ) ? (string) $input['role'] : '' );
		if ( '' !== $type && ! in_array( $type, self::TYPES, true ) ) {
			throw new ValidationError( __( 'Kind of site', 'estasoft-handover-passport' ), __( 'Pick one of the listed choices.', 'estasoft-handover-passport' ) );
		}
		if ( '' !== $role && ! in_array( $role, self::ROLES, true ) ) {
			throw new ValidationError( __( 'Your role', 'estasoft-handover-passport' ), __( 'Pick one of the listed choices.', 'estasoft-handover-passport' ) );
		}

		$colour_raw = trim( is_scalar( $input['brand_color'] ?? null ) ? (string) $input['brand_color'] : '' );
		$colour     = '' === $colour_raw ? '' : (string) sanitize_hex_color( $colour_raw );
		if ( '' !== $colour_raw && '' === $colour ) {
			throw new ValidationError( __( 'Brand colour', 'estasoft-handover-passport' ), __( 'Use a colour written like #2f4858.', 'estasoft-handover-passport' ) );
		}

		$logo = absint( is_scalar( $input['logo_id'] ?? null ) ? (string) $input['logo_id'] : 0 );
		if ( $logo > 0 && ! wp_attachment_is_image( $logo ) ) {
			throw new ValidationError( __( 'Logo', 'estasoft-handover-passport' ), __( 'Choose an image from the Media Library.', 'estasoft-handover-passport' ) );
		}

		$guard = $this->plugin->secretGuard();
		$guard->assertClean( $site, __( 'Site name', 'estasoft-handover-passport' ) );
		$guard->assertClean( $client, __( 'Client or owner', 'estasoft-handover-passport' ) );
		$guard->assertClean( $purpose, __( 'What the site is for', 'estasoft-handover-passport' ) );

		$passport = $this->plugin->passport();
		$before   = array(
			'site_name'   => (string) $passport->get( 'site_name', '' ),
			'client_name' => (string) $passport->get( 'client_name', '' ),
			'purpose'     => (string) $passport->get( 'purpose', '' ),
			'brand_color' => (string) $passport->get( 'brand_color', '' ),
			'logo_id'     => (int) $passport->get( 'logo_id', 0 ),
			'type'        => $this->stored()['type'],
			'role'        => $this->stored()['role'],
		);
		$after    = array(
			'site_name'   => $site,
			'client_name' => $client,
			'purpose'     => $purpose,
			'brand_color' => '' === $colour ? (string) $passport->get( 'brand_color', '' ) : $colour,
			'logo_id'     => $logo,
			'type'        => $type,
			'role'        => $role,
		);

		$setup         = $this->stored();
		$setup['type'] = $type;
		$setup['role'] = $role;
		$passport->update(
			array(
				'site_name'   => $after['site_name'],
				'client_name' => $after['client_name'],
				'purpose'     => $after['purpose'],
				'brand_color' => $after['brand_color'],
				'logo_id'     => $after['logo_id'],
				'setup'       => $setup,
			)
		);

		if ( $before !== $after ) {
			$changed = array_keys( array_diff_assoc( $after, $before ) );
			$this->plugin->changeLog()->add(
				'passport_details_changed',
				'passport',
				0,
				$after['site_name'],
				array_intersect_key( $before, array_flip( $changed ) ),
				array_intersect_key( $after, array_flip( $changed ) )
			);
		}
	}

	/**
	 * What would be added for a kind of site: runbooks from the templates and addresses that exist on this site.
	 *
	 * @param string $type One of self::TYPES.
	 * @return array{runbooks: array<int, string>, urls: array<int, array{name: string, address: string, why: string}>}
	 */
	public function suggestions( string $type ): array {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return array( 'runbooks' => array(), 'urls' => array() );
		}
		$extra = array(
			'brochure'     => array( 'renew_ssl' ),
			'store'        => array( 'hacked_first_hour', 'maintenance_mode', 'restore_files' ),
			'membership'   => array( 'hacked_first_hour', 'regain_admin' ),
			'multilingual' => array( 'restore_files' ),
			'blog'         => array( 'renew_ssl' ),
		);
		$keys  = array_merge( array( 'restore_site', 'restore_database', 'rollback_update', 'contact_tree' ), $extra[ $type ] );

		$urls = array(
			array(
				'name'    => __( 'Home page', 'estasoft-handover-passport' ),
				'address' => home_url( '/' ),
				'why'     => __( 'If this does not load, visitors see nothing.', 'estasoft-handover-passport' ),
			),
		);
		if ( 'store' === $type && function_exists( 'wc_get_page_permalink' ) ) {
			foreach ( array( 'shop' => __( 'Shop', 'estasoft-handover-passport' ), 'cart' => __( 'Cart', 'estasoft-handover-passport' ), 'checkout' => __( 'Checkout', 'estasoft-handover-passport' ) ) as $page => $name ) {
				$address = (string) wc_get_page_permalink( $page );
				if ( '' !== $address ) {
					$urls[] = array(
						'name'    => $name,
						'address' => $address,
						'why'     => __( 'Customers cannot buy if this page fails.', 'estasoft-handover-passport' ),
					);
				}
			}
		}
		if ( 'membership' === $type ) {
			$urls[] = array(
				'name'    => __( 'Log in page', 'estasoft-handover-passport' ),
				'address' => wp_login_url(),
				'why'     => __( 'Members cannot reach their content if this page fails.', 'estasoft-handover-passport' ),
			);
		}
		if ( 'blog' === $type ) {
			$urls[] = array(
				'name'    => __( 'Feed', 'estasoft-handover-passport' ),
				'address' => (string) get_bloginfo( 'rss2_url' ),
				'why'     => __( 'Readers and other sites follow the blog through its feed.', 'estasoft-handover-passport' ),
			);
		}

		return array(
			'runbooks' => $keys,
			'urls'     => $urls,
		);
	}

	/**
	 * Add what is suggested for the chosen kind of site, leaving out what the Passport already holds.
	 *
	 * @return array{runbooks: int, urls: int}
	 * @throws ValidationError When no kind of site was chosen.
	 */
	public function applySuggestions(): array {
		$type = $this->stored()['type'];
		if ( '' === $type ) {
			throw new ValidationError( __( 'Kind of site', 'estasoft-handover-passport' ), __( 'Choose the kind of site first.', 'estasoft-handover-passport' ) );
		}
		$suggested = $this->suggestions( $type );
		$have      = array();
		foreach ( $this->plugin->runbooks()->all() as $runbook ) {
			if ( '' !== (string) ( $runbook['template_key'] ?? '' ) ) {
				$have[ (string) $runbook['template_key'] ] = true;
			}
		}
		$runbooks  = 0;
		$templates = RunbookService::templates();
		foreach ( $suggested['runbooks'] as $key ) {
			if ( isset( $have[ $key ] ) || ! isset( $templates[ $key ] ) ) {
				continue;
			}
			$this->plugin->runbooks()->createFromTemplate( $key );
			++$runbooks;
		}

		$known = array();
		foreach ( $this->plugin->records()->listKind( Kinds::URL, true ) as $record ) {
			$known[ untrailingslashit( (string) ( $record['fields']['address'] ?? '' ) ) ] = true;
		}
		$urls = 0;
		foreach ( $suggested['urls'] as $url ) {
			if ( isset( $known[ untrailingslashit( $url['address'] ) ] ) ) {
				continue;
			}
			$this->plugin->records()->create(
				Kinds::URL,
				array(
					'name'            => $url['name'],
					'address'         => $url['address'],
					'why'             => $url['why'],
					'expected_status' => 200,
				)
			);
			++$urls;
		}
		return array(
			'runbooks' => $runbooks,
			'urls'     => $urls,
		);
	}

	/**
	 * The stored setup values, always complete.
	 *
	 * @return array{done: array<int, string>, skipped: array<int, string>, role: string, type: string}
	 */
	private function stored(): array {
		$setup = (array) $this->plugin->passport()->get( 'setup', array() );
		$valid = static fn( $names ): array => array_values( array_intersect( self::STEPS, array_map( 'strval', (array) $names ) ) );
		return array(
			'done'    => $valid( $setup['done'] ?? array() ),
			'skipped' => $valid( $setup['skipped'] ?? array() ),
			'role'    => (string) ( $setup['role'] ?? '' ),
			'type'    => (string) ( $setup['type'] ?? '' ),
		);
	}

	/**
	 * Move a step between the lists.
	 *
	 * @param string $step Step key.
	 * @param string $to   "done", "skipped" or "" for neither.
	 */
	private function move( string $step, string $to ): void {
		if ( ! in_array( $step, self::STEPS, true ) ) {
			return;
		}
		$setup            = $this->stored();
		$setup['done']    = array_values( array_diff( $setup['done'], array( $step ) ) );
		$setup['skipped'] = array_values( array_diff( $setup['skipped'], array( $step ) ) );
		if ( 'done' === $to ) {
			$setup['done'][] = $step;
		} elseif ( 'skipped' === $to ) {
			$setup['skipped'][] = $step;
		}
		$this->plugin->passport()->update( array( 'setup' => $setup ) );
	}

	/**
	 * Whether the Passport already holds what a step asks for.
	 *
	 * @param string             $step   Step key.
	 * @param array<string, int> $counts Live record counts by kind.
	 * @param int                $people People in the directory.
	 * @param int                $books  Runbooks.
	 */
	private function auto( string $step, array $counts, int $people, int $books ): bool {
		switch ( $step ) {
			case 'scan':
				return null !== $this->plugin->scanService()->hoursSinceLast();
			case 'about':
				return '' !== (string) $this->plugin->passport()->get( 'client_name', '' );
			case 'people':
				return $people > 0;
			case 'domains':
				return ( $counts[ Kinds::DOMAIN ] ?? 0 ) > 0 && ( $counts[ Kinds::HOST ] ?? 0 ) > 0;
			case 'licences':
				return ( $counts[ Kinds::LICENCE ] ?? 0 ) > 0;
			case 'backups':
				return ( $counts[ Kinds::BACKUP ] ?? 0 ) > 0 && $books > 0;
			default:
				return false;
		}
	}

	/**
	 * A line for each step saying what is in the Passport now.
	 *
	 * @param array<string, int> $counts Live record counts by kind.
	 * @param int                $people People in the directory.
	 * @param int                $books  Runbooks.
	 * @return array<string, string>
	 */
	private function details( array $counts, int $people, int $books ): array {
		$n = static fn( string $kind ): int => (int) ( $counts[ $kind ] ?? 0 );
		return array(
			'people'   => sprintf(
				/* translators: %d: number of people in the directory. */
				_n( '%d person in the directory.', '%d people in the directory.', $people, 'estasoft-handover-passport' ),
				$people
			),
			'domains'  => sprintf(
				/* translators: 1: number of domains, 2: number of hosting records. */
				__( '%1$d domains and %2$d hosting records.', 'estasoft-handover-passport' ),
				$n( Kinds::DOMAIN ),
				$n( Kinds::HOST )
			),
			'licences' => sprintf(
				/* translators: %d: number of licences. */
				_n( '%d licence recorded.', '%d licences recorded.', $n( Kinds::LICENCE ), 'estasoft-handover-passport' ),
				$n( Kinds::LICENCE )
			),
			'backups'  => sprintf(
				/* translators: 1: number of backups, 2: number of runbooks. */
				__( '%1$d backups and %2$d runbooks.', 'estasoft-handover-passport' ),
				$n( Kinds::BACKUP ),
				$books
			),
		);
	}
}
