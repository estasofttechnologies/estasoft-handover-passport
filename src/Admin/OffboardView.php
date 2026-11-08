<?php
/**
 * The leaver checklist: what to close, hand over or change when a person stops working on the site (ACC-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Data\LinkRepository;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Services\PeopleService;
use Estasoft\HandoverPassport\Support\Dates;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * A page to read and print, built from what the Passport already holds. It changes nothing: the ticks are for the
 * person doing the work and are not saved. Records marked Sensitive appear only as "Sensitive record, hidden" unless the
 * viewer holds the Sensitive capability.
 */
final class OffboardView {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Address of the checklist for one person.
	 *
	 * @param int $person_id Person ID.
	 */
	public static function url( int $person_id ): string {
		return add_query_arg( array( 'page' => PeopleScreen::SLUG, 'tab' => 'people', 'view' => 'offboard', 'id' => $person_id ), admin_url( 'admin.php' ) );
	}

	/**
	 * Print the checklist.
	 *
	 * @param string $base Address of the People tab.
	 * @param int    $id   Person ID.
	 */
	public function render( string $base, int $id ): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to edit people.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		$person = $this->plugin->people()->get( $id );
		if ( null === $person ) {
			echo '<p>' . esc_html__( 'That person was not found.', 'estasoft-handover-passport' ) . ' <a href="' . esc_url( $base ) . '">' . esc_html__( 'Back to the list', 'estasoft-handover-passport' ) . '</a></p>';
			return;
		}
		$name = (string) $person['name'];

		echo '<div class="estasoft-hp-offboard"><h2>' . esc_html(
			sprintf(
				/* translators: %s: name of the person. */
				__( 'Leaver checklist: %s', 'estasoft-handover-passport' ),
				$name
			)
		) . '</h2>';
		echo '<p>' . esc_html__( 'Use this list when the person stops working on the site. Passport changes nothing by itself, and the ticks on this page are not saved.', 'estasoft-handover-passport' ) . '</p>';
		echo '<p class="estasoft-hp-noprint"><button type="button" class="button" data-estasoft-print>' . esc_html__( 'Print this list', 'estasoft-handover-passport' ) . '</button> <a class="button" href="' . esc_url( $base ) . '">' . esc_html__( 'Back to the list', 'estasoft-handover-passport' ) . '</a></p>';

		$this->systems( $id );
		$this->account( $person );
		$this->assets( $id );
		$this->roles( $person );
		$this->passport( $person, $base );
		$this->others();
		echo '</div>';
	}

	/**
	 * Open a section.
	 *
	 * @param string $title Heading.
	 */
	private function open( string $title ): void {
		echo '<section class="estasoft-hp-card"><h3>' . esc_html( $title ) . '</h3>';
	}

	/**
	 * One line with a box to tick.
	 *
	 * @param string $text Line text, already safe to print as text.
	 * @param string $link Optional address to open.
	 * @param string $word Link text.
	 */
	private function line( string $text, string $link = '', string $word = '' ): void {
		echo '<li><label><input type="checkbox"> ' . esc_html( $text ) . '</label>';
		if ( '' !== $link ) {
			echo ' <a class="estasoft-hp-noprint" href="' . esc_url( $link ) . '">' . esc_html( $word ) . '</a>';
		}
		echo '</li>';
	}

	/**
	 * Systems listed in the access register where the person has access.
	 *
	 * @param int $id Person ID.
	 */
	private function systems( int $id ): void {
		$this->open( __( '1. Systems where they have access', 'estasoft-handover-passport' ) );
		$can   = Capabilities::canSeeSensitive();
		$links = new FindingLinks( $this->plugin );
		$found = false;
		echo '<ul class="estasoft-hp-checklist">';
		foreach ( $this->owned( $id, array( LinkRepository::ACCESS ) ) as $item ) {
			if ( Kinds::ACCESS !== $item['kind'] ) {
				continue;
			}
			$found = true;
			$url   = add_query_arg( $links->tabFor( Kinds::ACCESS ) + array( 'view' => 'edit', 'kind' => Kinds::ACCESS, 'id' => $item['id'] ), admin_url( 'admin.php' ) );
			if ( ! empty( $item['hidden'] ) ) {
				$this->line( __( 'A system marked Sensitive. Ask someone who may see it to take this person off.', 'estasoft-handover-passport' ) );
				continue;
			}
			$fields = (array) $item['fields'];
			$parts  = array( (string) ( Kinds::accessSystems()[ (string) ( $fields['system'] ?? '' ) ] ?? ( $fields['system'] ?? '' ) ) );
			foreach ( array( 'address', 'level' ) as $key ) {
				if ( '' !== (string) ( $fields[ $key ] ?? '' ) ) {
					$parts[] = (string) $fields[ $key ];
				}
			}
			$text = sprintf(
				/* translators: %s: the system, its address and the access level. */
				__( 'Remove their access: %s', 'estasoft-handover-passport' ),
				implode( ', ', array_filter( $parts ) )
			);
			if ( $can && '' !== (string) ( $fields['credentials_in'] ?? '' ) ) {
				$text .= ' ' . sprintf(
					/* translators: %s: where the credentials are kept. */
					__( '(credentials are kept in: %s)', 'estasoft-handover-passport' ),
					(string) $fields['credentials_in']
				);
			}
			$this->line( $text, $url, __( 'Open the entry', 'estasoft-handover-passport' ) );
		}
		echo '</ul>';
		if ( ! $found ) {
			echo '<p class="description">' . esc_html__( 'The access register does not name this person. If they have access that is not listed there, add it first, then remove it.', 'estasoft-handover-passport' ) . '</p>';
		}
		echo '</section>';
	}

	/**
	 * The WordPress account that has the same email address.
	 *
	 * @param array<string, mixed> $person Person.
	 */
	private function account( array $person ): void {
		$this->open( __( '2. Their WordPress account', 'estasoft-handover-passport' ) );
		if ( ! current_user_can( 'list_users' ) ) {
			echo '<p>' . esc_html__( 'Ask a site administrator to check whether this person has a WordPress account and to remove or demote it.', 'estasoft-handover-passport' ) . '</p></section>';
			return;
		}
		$email = (string) $person['email'];
		$user  = '' !== $email ? get_user_by( 'email', $email ) : false;
		if ( ! $user instanceof WP_User ) {
			echo '<p>' . esc_html__( 'No WordPress account has the email address in this person\'s entry. They may use another address: check the Users screen.', 'estasoft-handover-passport' ) . ' <a class="estasoft-hp-noprint" href="' . esc_url( admin_url( 'users.php' ) ) . '">' . esc_html__( 'Open Users', 'estasoft-handover-passport' ) . '</a></p></section>';
			return;
		}
		$roles = array();
		foreach ( (array) $user->roles as $role ) {
			$roles[] = translate_user_role( (string) ( wp_roles()->get_names()[ $role ] ?? $role ) );
		}
		$posts = (int) count_user_posts( $user->ID, 'post', true );
		echo '<ul class="estasoft-hp-checklist">';
		$this->line(
			sprintf(
				/* translators: 1: user name, 2: roles, 3: number of posts. */
				__( 'Remove or demote the account "%1$s" (role: %2$s). It has %3$d published posts to hand to someone else.', 'estasoft-handover-passport' ),
				(string) $user->user_login,
				array() === $roles ? __( 'none', 'estasoft-handover-passport' ) : implode( ', ', $roles ),
				$posts
			),
			current_user_can( 'edit_user', $user->ID ) ? get_edit_user_link( $user->ID ) : '',
			__( 'Open the account', 'estasoft-handover-passport' )
		);
		$this->line( __( 'End their active sessions and revoke any application passwords.', 'estasoft-handover-passport' ) );
		echo '</ul></section>';
	}

	/**
	 * Records the person holds, pays for or administers.
	 *
	 * @param int $id Person ID.
	 */
	private function assets( int $id ): void {
		$this->open( __( '3. Assets they hold, pay for or administer', 'estasoft-handover-passport' ) );
		$labels = array(
			LinkRepository::HOLDER => __( 'account holder', 'estasoft-handover-passport' ),
			LinkRepository::PAYER  => __( 'payer', 'estasoft-handover-passport' ),
			LinkRepository::ADMIN  => __( 'administrator', 'estasoft-handover-passport' ),
		);
		$links  = new FindingLinks( $this->plugin );
		$found  = false;
		echo '<ul class="estasoft-hp-checklist">';
		foreach ( $this->owned( $id, array_keys( $labels ) ) as $item ) {
			$found = true;
			if ( ! empty( $item['hidden'] ) ) {
				$this->line( __( 'A record marked Sensitive. Ask someone who may see it to move it to another person.', 'estasoft-handover-passport' ) );
				continue;
			}
			$def = Kinds::get( (string) $item['kind'] );
			$url = in_array( (string) $item['kind'], Kinds::registerKinds(), true ) ? add_query_arg( $links->tabFor( (string) $item['kind'] ) + array( 'view' => 'edit', 'kind' => (string) $item['kind'], 'id' => $item['id'] ), admin_url( 'admin.php' ) ) : '';
			$this->line(
				sprintf(
					/* translators: 1: record title, 2: kind of record, 3: how the person is tied to it. */
					__( 'Name someone else for %1$s (%2$s), where this person is the %3$s', 'estasoft-handover-passport' ),
					(string) $item['title'],
					null === $def ? (string) $item['kind'] : (string) $def['label'],
					$labels[ (string) $item['relation'] ] ?? (string) $item['relation']
				),
				$url,
				__( 'Open the record', 'estasoft-handover-passport' )
			);
		}
		echo '</ul>';
		if ( ! $found ) {
			echo '<p class="description">' . esc_html__( 'No record names this person as holder, payer or administrator.', 'estasoft-handover-passport' ) . '</p>';
		}
		echo '</section>';
	}

	/**
	 * Roles in the directory.
	 *
	 * @param array<string, mixed> $person Person.
	 */
	private function roles( array $person ): void {
		$this->open( __( '4. Roles they hold', 'estasoft-handover-passport' ) );
		$names = PeopleService::roles();
		$mine  = (array) $person['roles'];
		if ( array() === $mine ) {
			echo '<p class="description">' . esc_html__( 'The person holds no role in the directory.', 'estasoft-handover-passport' ) . '</p></section>';
			return;
		}
		echo '<ul class="estasoft-hp-checklist">';
		foreach ( $mine as $role ) {
			$this->line(
				sprintf(
					/* translators: %s: name of a role, for example "Emergency contact". */
					__( 'Name someone else as: %s', 'estasoft-handover-passport' ),
					$names[ (string) $role ] ?? (string) $role
				),
				add_query_arg( array( 'page' => PeopleScreen::SLUG, 'tab' => 'people' ), admin_url( 'admin.php' ) ) . '#estasoft-hp-roles',
				__( 'Open the roles', 'estasoft-handover-passport' )
			);
		}
		echo '</ul></section>';
	}

	/**
	 * The person's own entry.
	 *
	 * @param array<string, mixed> $person Person.
	 * @param string               $base   Address of the People tab.
	 */
	private function passport( array $person, string $base ): void {
		$this->open( __( '5. In the Passport', 'estasoft-handover-passport' ) );
		$left = (string) ( $person['left_on'] ?? '' );
		echo '<ul class="estasoft-hp-checklist">';
		$this->line(
			'' === $left
				? __( 'Fill in "Left on" in their entry, so the Passport lists them as Former.', 'estasoft-handover-passport' )
				: sprintf(
					/* translators: %s: date. */
					__( '"Left on" is already set to %s.', 'estasoft-handover-passport' ),
					Dates::displayDate( $left )
				),
			add_query_arg( array( 'view' => 'edit', 'id' => $person['id'] ), $base ),
			__( 'Open their entry', 'estasoft-handover-passport' )
		);
		$this->line( __( 'When the entry is no longer needed for the record, erase their personal details from WordPress Tools, Erase Personal Data. The roles and the records they were named on stay.', 'estasoft-handover-passport' ), admin_url( 'erase-personal-data.php' ), __( 'Open the eraser', 'estasoft-handover-passport' ) );
		echo '</ul></section>';
	}

	/**
	 * Things the Passport cannot know.
	 */
	private function others(): void {
		$this->open( __( '6. Things this list cannot know', 'estasoft-handover-passport' ) );
		echo '<ul class="estasoft-hp-checklist">';
		foreach ( array(
			__( 'Shared logins they knew: change the password and update where it is kept.', 'estasoft-handover-passport' ),
			__( 'Email accounts, forwarding rules and mailing lists that use a site address.', 'estasoft-handover-passport' ),
			__( 'Keys and tokens they created: SSH keys, deploy keys, API keys and webhooks.', 'estasoft-handover-passport' ),
			__( 'Two-step sign-in devices or recovery addresses that belong to them.', 'estasoft-handover-passport' ),
			__( 'Tools in their own name: analytics, search console, tag manager, payment and advertising accounts.', 'estasoft-handover-passport' ),
		) as $text ) {
			$this->line( $text );
		}
		echo '</ul></section>';
	}

	/**
	 * Live records a person is tied to by one of the relations, as the viewer may see them.
	 *
	 * @param int               $person_id Person ID.
	 * @param array<int,string> $relations Relation names.
	 * @return array<int, array<string, mixed>>
	 */
	private function owned( int $person_id, array $relations ): array {
		$can = Capabilities::canSeeSensitive();
		$out = array();
		foreach ( $this->plugin->people()->assetsFor( $person_id ) as $link ) {
			if ( ! in_array( $link['relation'], $relations, true ) ) {
				continue;
			}
			$record = $this->plugin->records()->get( $link['item_id'], $can );
			if ( null === $record ) {
				continue;
			}
			$record['relation'] = $link['relation'];
			$out[]              = $record;
		}
		return $out;
	}
}
