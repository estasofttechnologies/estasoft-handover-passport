<?php
/**
 * The Help tab on each Passport screen: what the screen is for and one worked example (ADM-5).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Help tabs WordPress shows under the Help button at the top of a screen.
 */
final class Help {

	/**
	 * Show the Help tabs when the screen with this hook suffix loads.
	 *
	 * @param string $hook Hook suffix returned by add_menu_page() or add_submenu_page().
	 * @param string $slug Menu slug of the screen.
	 */
	public static function attach( string $hook, string $slug ): void {
		if ( '' === $hook ) {
			return;
		}
		add_action(
			'load-' . $hook,
			static function () use ( $slug ): void {
				self::add( $slug );
			}
		);
	}

	/**
	 * Add the tabs of one screen to the current admin screen.
	 *
	 * @param string $slug Menu slug of the screen.
	 */
	public static function add( string $slug ): void {
		$screen = get_current_screen();
		if ( null === $screen ) {
			return;
		}
		foreach ( self::tabs( $slug ) as $id => $tab ) {
			$screen->add_help_tab(
				array(
					'id'      => 'estasoft-hp-' . $id,
					'title'   => $tab['title'],
					'content' => $tab['content'],
				)
			);
		}
		$screen->set_help_sidebar( self::sidebar() );
	}

	/**
	 * The tabs for a screen. Each holds plain paragraphs and, for the worked example, an ordered list.
	 *
	 * @param string $slug Menu slug.
	 * @return array<string, array{title: string, content: string}>
	 */
	public static function tabs( string $slug ): array {
		$text = self::text( $slug );
		if ( array() === $text ) {
			return array();
		}
		$steps = '';
		foreach ( $text['example'] as $step ) {
			$steps .= '<li>' . esc_html( $step ) . '</li>';
		}
		return array(
			'about'   => array(
				'title'   => __( 'About this screen', 'estasoft-handover-passport' ),
				'content' => '<p>' . esc_html( $text['about'] ) . '</p>',
			),
			'example' => array(
				'title'   => __( 'A worked example', 'estasoft-handover-passport' ),
				'content' => '<p><strong>' . esc_html( $text['goal'] ) . '</strong></p><ol>' . $steps . '</ol>',
			),
			'privacy' => array(
				'title'   => __( 'Where the data lives', 'estasoft-handover-passport' ),
				'content' => '<p>' . esc_html__( 'Everything on these screens is stored in this site\'s own database. Passport sends nothing to ESTASOFT TECHNOLOGIES or to any other service. A record marked Sensitive is shown only to people who hold the Sensitive capability, and passwords, keys and tokens are never stored: a record points to the vault where they are kept.', 'estasoft-handover-passport' ) . '</p>',
			),
		);
	}

	/**
	 * The sidebar: where to ask for help.
	 */
	private static function sidebar(): string {
		return '<p><strong>' . esc_html__( 'Need more help?', 'estasoft-handover-passport' ) . '</strong></p>'
			. '<p><a href="' . esc_url( 'https://wordpress.org/support/plugin/estasoft-handover-passport/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support forum', 'estasoft-handover-passport' ) . '</a></p>'
			. '<p><a href="' . esc_url( 'https://github.com/estasofttechnologies/estasoft-handover-passport' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Source and issues', 'estasoft-handover-passport' ) . '</a></p>';
	}

	/**
	 * The words for each screen.
	 *
	 * @param string $slug Menu slug.
	 * @return array{about: string, goal: string, example: array<int, string>}|array{}
	 */
	private static function text( string $slug ): array {
		switch ( $slug ) {
			case Admin::MENU_SLUG:
				return array(
					'about'   => __( 'The Overview is the page to open first. It shows how many findings need fixing, how ready the Passport is for a handover, the dates coming in the next 90 days, how current the records are, the safety nets, and how much has changed since the last handover someone acknowledged. Scan now reads the site again.', 'estasoft-handover-passport' ),
					'goal'    => __( 'Example: a client asks when the domain expires.', 'estasoft-handover-passport' ),
					'example' => array(
						__( 'Open the Overview and look at the card with the next 90 days.', 'estasoft-handover-passport' ),
						__( 'If the domain is not listed, open Inventory, then Domains, and add it with its expiry date.', 'estasoft-handover-passport' ),
						__( 'The date now shows on the Overview and a reminder email is sent before it, to the people listed in Settings.', 'estasoft-handover-passport' ),
					),
				);
			case SetupScreen::SLUG:
				return array(
					'about'   => __( 'Setup is seven short steps: scan the site, describe it, add the people, add the domains and hosting, add the licences, record the backups and review. Each step can be skipped and picked up later, and a step counts as done when the Passport already holds what it asks for.', 'estasoft-handover-passport' ),
					'goal'    => __( 'Example: set up a new site in about an hour.', 'estasoft-handover-passport' ),
					'example' => array(
						__( 'Press Scan now on the first step and wait for it to finish.', 'estasoft-handover-passport' ),
						__( 'On the About step, write who the site belongs to and what it is for, then save.', 'estasoft-handover-passport' ),
						__( 'Skip a step you cannot finish today. It waits on the Overview as something to do.', 'estasoft-handover-passport' ),
					),
				);
			case InventoryScreen::SLUG:
				return array(
					'about'   => __( 'Inventory is the list of what the site depends on. Stack is read from the site by the scan: WordPress, PHP, the database, plugins, themes and scheduled tasks. Licences, Domains, Hosting and Services are written by people, because no scan can know who pays for them or when they renew.', 'estasoft-handover-passport' ),
					'goal'    => __( 'Example: record a plugin licence that renews every year.', 'estasoft-handover-passport' ),
					'example' => array(
						__( 'Open the Licences tab and press Add a licence.', 'estasoft-handover-passport' ),
						__( 'Write the plugin and the vendor, choose the renewal date and the person who pays. Do not type the licence key: write where it is kept instead.', 'estasoft-handover-passport' ),
						__( 'Save. When the date comes round and the licence is renewed, press Renewed and enter the next date.', 'estasoft-handover-passport' ),
					),
				);
			case SafetyScreen::SLUG:
				return array(
					'about'   => __( 'Safety nets are what lets someone recover the site. Backups records what is backed up, where and when it was last confirmed. Restore tests records each time a backup was really restored. Runbooks are step-by-step instructions for a bad day. Critical addresses are the pages that must answer after a restore.', 'estasoft-handover-passport' ),
					'goal'    => __( 'Example: write down that a restore was tested.', 'estasoft-handover-passport' ),
					'example' => array(
						__( 'Open the Restore tests tab and add a test.', 'estasoft-handover-passport' ),
						__( 'Enter the date, who did it, what was restored, how long it took and the result.', 'estasoft-handover-passport' ),
						__( 'Save. The Overview then shows when a restore was last proved to work, not only when a backup was made.', 'estasoft-handover-passport' ),
					),
				);
			case PeopleScreen::SLUG:
				return array(
					'about'   => __( 'People holds the directory (who, which role, how to reach them), the emergency contact order, who owns each asset, and who has access to which system. Ownership lists the assets that have no owner, so a gap is visible before it matters.', 'estasoft-handover-passport' ),
					'goal'    => __( 'Example: a developer leaves the project.', 'estasoft-handover-passport' ),
					'example' => array(
						__( 'Open the People tab and press Offboard next to the person.', 'estasoft-handover-passport' ),
						__( 'Work down the checklist: the systems where they have access, their WordPress account and the assets they hold or pay for.', 'estasoft-handover-passport' ),
						__( 'Then edit the person and fill in Left on, so the Passport stops treating them as someone who works on the site.', 'estasoft-handover-passport' ),
					),
				);
			case HandoverScreen::SLUG:
				return array(
					'about'   => __( 'Handover turns the Passport into something you can give to a person. A snapshot freezes the Passport as it is today. An export puts a snapshot, or the live Passport, into a PDF, an HTML page, JSON or CSV. Compare shows what changed between two snapshots, or since one. An acknowledgement records who received the handover and when.', 'estasoft-handover-passport' ),
					'goal'    => __( 'Example: hand the site to a new maintainer.', 'estasoft-handover-passport' ),
					'example' => array(
						__( 'Open the Checklist tab and fix what it lists as not ready.', 'estasoft-handover-passport' ),
						__( 'Open Snapshots, create a snapshot and give it a name that says why.', 'estasoft-handover-passport' ),
						__( 'Export it as a PDF, give it to the receiver, and record their acknowledgement on the snapshot.', 'estasoft-handover-passport' ),
					),
				);
			case HistoryScreen::SLUG:
				return array(
					'about'   => __( 'History is the memory of the site. The change log is written by Passport itself: plugin and theme changes, new administrators, address changes and edits to records. Notes are what people did that no scan can see. Incidents are what went wrong, why, and how long the fix took.', 'estasoft-handover-passport' ),
					'goal'    => __( 'Example: record that the site was down for an hour.', 'estasoft-handover-passport' ),
					'example' => array(
						__( 'Open the Incidents tab and add an incident.', 'estasoft-handover-passport' ),
						__( 'Write what happened, the impact, the cause, the fix and the time it took to restore. Name the runbook that was followed, if any.', 'estasoft-handover-passport' ),
						__( 'Save. Write the follow-ups, one per line, so the same thing is less likely next time.', 'estasoft-handover-passport' ),
					),
				);
			case FindingsScreen::SLUG:
				return array(
					'about'   => __( 'A finding is something the Passport thinks needs attention, written as a fact and a date. Open findings need a person. Snoozed findings are hidden until a date. Accepted findings are known and left alone, with a reason. Resolved findings were fixed. Rules lists the checks and lets you read what each one looks for.', 'estasoft-handover-passport' ),
					'goal'    => __( 'Example: a finding you cannot act on this month.', 'estasoft-handover-passport' ),
					'example' => array(
						__( 'Open the finding and press Snooze or accept.', 'estasoft-handover-passport' ),
						__( 'To come back to it, choose Snooze and a date. To leave it for good, choose Accept and write the reason.', 'estasoft-handover-passport' ),
						__( 'The reason and your name are kept, so the next person can see why it was left.', 'estasoft-handover-passport' ),
					),
				);
			case SettingsScreen::SLUG:
				return array(
					'about'   => __( 'Settings holds the review periods, the scan time, the reminder recipients, who may do what, and what happens to the data when the plugin is deleted. A person can see or change only what the capabilities on the Who can do what tab allow.', 'estasoft-handover-passport' ),
					'goal'    => __( 'Example: send renewal reminders to the agency inbox.', 'estasoft-handover-passport' ),
					'example' => array(
						__( 'Open the Reminders tab.', 'estasoft-handover-passport' ),
						__( 'Add the address of the shared inbox to the list of recipients and save.', 'estasoft-handover-passport' ),
						__( 'Press the test email button to check that the message arrives. Passport also sends to the account holder and payer named on each record.', 'estasoft-handover-passport' ),
					),
				);
		}
		return array();
	}
}
