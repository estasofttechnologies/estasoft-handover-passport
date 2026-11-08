<?php
/**
 * Settings: review intervals, scan, thresholds, retention, who can do what, and removal (ADM-1, CAP-2, PRV-1).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Checks\CheckLimiter;
use Estasoft\HandoverPassport\Domain\LeadTimes;
use Estasoft\HandoverPassport\Export\EmailedCopy;
use Estasoft\HandoverPassport\Install\Schedule;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Support\Dates;
use Estasoft\HandoverPassport\Support\Str;

defined( 'ABSPATH' ) || exit;

/**
 * The Settings screen. Everything here needs the configure capability.
 */
final class SettingsScreen extends Screen {

	public const SLUG        = 'estasoft-passport-settings';
	public const ACTION      = 'estasoft_hp_save_settings';
	public const MAIL_ACTION = 'estasoft_hp_test_mail';
	public const COPY_ACTION = 'estasoft_hp_send_copy';
	public const FIX_ACTION  = 'estasoft_hp_repair';

	/**
	 * Attach the save handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handleSave' ) );
		add_action( 'admin_post_' . self::MAIL_ACTION, array( $this, 'handleTestMail' ) );
		add_action( 'admin_post_' . self::COPY_ACTION, array( $this, 'handleSendCopy' ) );
		add_action( 'admin_post_' . self::FIX_ACTION, array( $this, 'handleRepair' ) );
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
		return __( 'Settings', 'estasoft-handover-passport' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function capability(): string {
		return Capabilities::CONFIGURE;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tabs(): array {
		return array(
			'general'   => __( 'General', 'estasoft-handover-passport' ),
			'reminders' => __( 'Reminders', 'estasoft-handover-passport' ),
			'access'    => __( 'Roles', 'estasoft-handover-passport' ),
			'findings'  => __( 'Rules', 'estasoft-handover-passport' ),
			'privacy'   => __( 'Privacy', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function describe( string $tab ): string {
		switch ( $tab ) {
			case 'reminders':
				return __( 'Who is reminded, and how long before a date.', 'estasoft-handover-passport' );
			case 'access':
				return __( 'Who may see, change, export and configure the Passport. No new role is created.', 'estasoft-handover-passport' );
			case 'findings':
				return __( 'The numbers that decide when a finding opens, and the recovery targets.', 'estasoft-handover-passport' );
			case 'privacy':
				return __( 'What is held, how long it is kept and what happens when the plugin is deleted.', 'estasoft-handover-passport' );
			default:
				return __( 'Defaults for new records, the daily scan and where notices appear.', 'estasoft-handover-passport' );
		}
	}

	/**
	 * Threshold fields: setting key => label, smallest, largest, default.
	 *
	 * @return array<string, array{0: string, 1: int, 2: int, 3: int}>
	 */
	public static function thresholdFields(): array {
		return array(
			'eol_warn_days'       => array( __( 'Warn about a version that stops being supported within (days)', 'estasoft-handover-passport' ), 1, 730, 90 ),
			'update_age_days'     => array( __( 'An update is overdue after it has waited (days)', 'estasoft-handover-passport' ), 1, 365, 30 ),
			'licence_warn_days'   => array( __( 'A licence is flagged when it ends within (days)', 'estasoft-handover-passport' ), 1, 365, 60 ),
			'licence_lead_days'   => array( __( 'A licence "renews soon" this many days before its date', 'estasoft-handover-passport' ), 0, 365, 30 ),
			'domain_warn_days'    => array( __( 'A domain is flagged when it expires within (days)', 'estasoft-handover-passport' ), 1, 365, 30 ),
			'ssl_warn_days'       => array( __( 'A certificate is flagged when it expires within (days)', 'estasoft-handover-passport' ), 1, 180, 21 ),
			'hosting_warn_days'   => array( __( 'A hosting plan is flagged when it renews within (days)', 'estasoft-handover-passport' ), 1, 365, 30 ),
			'restore_test_days'   => array( __( 'A restore test is overdue after (days)', 'estasoft-handover-passport' ), 1, 1095, 180 ),
			'backup_confirm_days' => array( __( 'A backup is unconfirmed after (days)', 'estasoft-handover-passport' ), 1, 365, 45 ),
			'scan_stale_hours'    => array( __( 'The scan is late after (hours)', 'estasoft-handover-passport' ), 1, 720, 48 ),
			'access_review_days'  => array( __( 'An access entry is unreviewed after (days)', 'estasoft-handover-passport' ), 1, 3650, 90 ),
		);
	}

	/**
	 * Review sections and their labels.
	 *
	 * @return array<string, string>
	 */
	public static function sections(): array {
		return array(
			'stack'    => __( 'Stack', 'estasoft-handover-passport' ),
			'licences' => __( 'Licences', 'estasoft-handover-passport' ),
			'domains'  => __( 'Domains', 'estasoft-handover-passport' ),
			'hosting'  => __( 'Hosting', 'estasoft-handover-passport' ),
			'services' => __( 'Services', 'estasoft-handover-passport' ),
			'backups'  => __( 'Backups', 'estasoft-handover-passport' ),
			'people'   => __( 'People', 'estasoft-handover-passport' ),
			'access'   => __( 'Access', 'estasoft-handover-passport' ),
			'runbooks' => __( 'Runbooks', 'estasoft-handover-passport' ),
			'other'    => __( 'Other records', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tab Tab key.
	 */
	protected function tab( string $tab ): void {
		$settings = $this->plugin->settings();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		Form::nonce( self::ACTION );

		if ( 'findings' === $tab ) {
			$this->findingsTab( $settings );
		} elseif ( 'reminders' === $tab ) {
			$this->remindersTab( $settings );
		} elseif ( 'access' === $tab ) {
			$this->accessTab( $settings->get( 'capability_grants', array() ) );
		} elseif ( 'privacy' === $tab ) {
			$this->privacyTab( (bool) $settings->get( 'delete_on_uninstall', false ), (array) $settings->get( 'retention', array() ) );
		} else {
			$this->generalTab( $settings );
		}
		echo '<div class="savebar"><button type="submit" class="btn pri">' . esc_html__( 'Save settings', 'estasoft-handover-passport' ) . '</button><a class="btn" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Cancel', 'estasoft-handover-passport' ) . '</a></div></form>';
		if ( 'reminders' === $tab ) {
			$this->deliveryPanel();
		}
		if ( 'privacy' === $tab ) {
			$this->copyPanel();
			$this->dataPanel();
		}
	}

	/**
	 * Check data: what is left behind when a link points at nothing or a record has a kind this version does not know (DAT-8).
	 */
	private function dataPanel(): void {
		View::cardStart( __( 'Check data', 'estasoft-handover-passport' ) );
		echo '<p>' . esc_html__( 'Looks for links that point at a person or a record that no longer exists, and for records of a kind this version of Passport does not know. Nothing is changed until you tick the box and press the button.', 'estasoft-handover-passport' ) . '</p>';
		$base = add_query_arg( array( 'page' => self::SLUG, 'tab' => 'privacy' ), admin_url( 'admin.php' ) );
		if ( '1' !== Request::key( 'check' ) ) {
			echo '<p><a class="btn" href="' . esc_url( add_query_arg( 'check', '1', $base ) ) . '">' . esc_html__( 'Check data', 'estasoft-handover-passport' ) . '</a></p>';
			View::cardEnd();
			return;
		}

		$found = $this->plugin->dataCheck()->scan();
		if ( array() === $found['links'] && array() === $found['records'] ) {
			echo '<p><strong>' . esc_html(
				sprintf(
					/* translators: %d: number of links looked at. */
					_n( 'No problem found. %d link was looked at.', 'No problem found. %d links were looked at.', $found['checked_links'], 'estasoft-handover-passport' ),
					$found['checked_links']
				)
			) . '</strong></p>';
			View::cardEnd();
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::FIX_ACTION ) . '">';
		Form::nonce( self::FIX_ACTION );
		if ( array() !== $found['links'] ) {
			echo '<h3>' . esc_html__( 'Links that point at nothing', 'estasoft-handover-passport' ) . '</h3><div class="scr"><table class="tbl estasoft-hp-table"><thead><tr><th scope="col">' . esc_html__( 'Remove', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Link', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Why', 'estasoft-handover-passport' ) . '</th></tr></thead><tbody>';
			foreach ( $found['links'] as $link ) {
				echo '<tr><td><label><span class="screen-reader-text">' . esc_html( sprintf( /* translators: %d: link number. */ __( 'Remove link %d', 'estasoft-handover-passport' ), $link['id'] ) ) . '</span><input type="checkbox" name="link_ids[]" value="' . esc_attr( (string) $link['id'] ) . '" checked></label></td><td>' . esc_html( sprintf( /* translators: 1: link number, 2: what the link says. */ __( '#%1$d, %2$s', 'estasoft-handover-passport' ), $link['id'], $link['relation'] ) ) . '</td><td>' . esc_html( $link['reason'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( array() !== $found['records'] ) {
			echo '<h3>' . esc_html__( 'Records of a kind this version does not know', 'estasoft-handover-passport' ) . '</h3><p class="description">' . esc_html__( 'A record like this may belong to a newer version of Passport, for example after a downgrade. Removing it deletes it for good.', 'estasoft-handover-passport' ) . '</p><div class="scr"><table class="tbl estasoft-hp-table"><thead><tr><th scope="col">' . esc_html__( 'Remove', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Record', 'estasoft-handover-passport' ) . '</th><th scope="col">' . esc_html__( 'Kind', 'estasoft-handover-passport' ) . '</th></tr></thead><tbody>';
			foreach ( $found['records'] as $record ) {
				$title = $record['sensitive'] && ! Capabilities::canSeeSensitive() ? __( 'A Sensitive record', 'estasoft-handover-passport' ) : $record['title'];
				echo '<tr><td><label><span class="screen-reader-text">' . esc_html( sprintf( /* translators: %d: record number. */ __( 'Remove record %d', 'estasoft-handover-passport' ), $record['id'] ) ) . '</span><input type="checkbox" name="record_ids[]" value="' . esc_attr( (string) $record['id'] ) . '"></label></td><td>' . esc_html( sprintf( /* translators: 1: record number, 2: record title. */ __( '#%1$d: %2$s', 'estasoft-handover-passport' ), $record['id'], $title ) ) . '</td><td><code>' . esc_html( $record['kind'] ) . '</code></td></tr>';
			}
			echo '</tbody></table>';
		}
		Form::checkbox( 'confirm_repair', __( 'I understand that the ticked rows are removed for good', 'estasoft-handover-passport' ), false );
		echo '<p><button type="submit" class="btn pri">' . esc_html__( 'Remove the ticked rows', 'estasoft-handover-passport' ) . '</button></p></form>';
		View::cardEnd();
	}

	/**
	 * Remove what was ticked in the data check.
	 */
	public function handleRepair(): void {
		if ( ! Capabilities::can( Capabilities::CONFIGURE ) ) {
			wp_die( esc_html__( 'You do not have permission to change settings.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::FIX_ACTION );
		$args = array( 'page' => self::SLUG, 'tab' => 'privacy' );
		if ( '1' !== Request::post( 'confirm_repair' ) ) {
			View::say( __( 'Nothing was removed. Tick the box that says you understand the rows are removed for good, then try again.', 'estasoft-handover-passport' ) );
			$args['check'] = '1';
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}
		$links   = isset( $_POST['link_ids'] ) && is_array( $_POST['link_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['link_ids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked above; each value is cast to an integer here and looked at again by the check.
		$records = isset( $_POST['record_ids'] ) && is_array( $_POST['record_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['record_ids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked above; each value is cast to an integer here and looked at again by the check.
		$removed = $this->plugin->dataCheck()->remove( $links, $records );
		View::say(
			sprintf(
				/* translators: 1: number of links, 2: number of records. */
				__( 'Removed %1$d links and %2$d records.', 'estasoft-handover-passport' ),
				$removed['links'],
				$removed['records']
			),
			false
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * The Send a copy now button, a form of its own after the settings form (EXP-10).
	 */
	private function copyPanel(): void {
		$copy = $this->plugin->emailedCopy();
		View::cardStart( __( 'Send a copy now', 'estasoft-handover-passport' ) );
		$state = $copy->state();
		echo '<p>' . esc_html(
			'' === $state['sent_at']
				? __( 'No copy has been emailed yet.', 'estasoft-handover-passport' )
				/* translators: %s: date and time of the last copy. */
				: sprintf( __( 'The last copy was emailed on %s.', 'estasoft-handover-passport' ), Dates::display( $state['sent_at'], true ) )
		) . '</p>';
		$warning = $copy->warning();
		if ( '' !== $warning ) {
			echo '<p>' . esc_html( $warning ) . '</p>';
		}
		if ( $copy->active() && Capabilities::can( Capabilities::EXPORT ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::COPY_ACTION ) . '">';
			Form::nonce( self::COPY_ACTION );
			echo '<p><button type="submit" class="btn">' . esc_html__( 'Send a copy now', 'estasoft-handover-passport' ) . '</button></p></form>';
		} else {
			echo '<p class="description">' . esc_html__( 'Save the settings above with the emailed copy switched on and an address listed, and the button appears here.', 'estasoft-handover-passport' ) . '</p>';
		}
		View::cardEnd();
	}

	/**
	 * Email the Emergency sheet now.
	 */
	public function handleSendCopy(): void {
		if ( ! Capabilities::can( Capabilities::CONFIGURE ) || ! Capabilities::can( Capabilities::EXPORT ) ) {
			wp_die( esc_html__( 'You do not have permission to send a copy.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::COPY_ACTION );
		$result = $this->plugin->emailedCopy()->send( 'manual' );
		$notice = array(
			'sent'     => 'copy_sent',
			'wait'     => 'copy_wait',
			'nobody'   => 'copy_nobody',
			'disabled' => 'copy_nobody',
			'paused'   => 'copy_paused',
		)[ $result['state'] ] ?? 'copy_failed';
		$args   = array( 'page' => self::SLUG, 'tab' => 'privacy', 'estasoft_hp_notice' => $notice );
		if ( 'copy_wait' === $notice ) {
			$args['estasoft_hp_wait'] = CheckLimiter::secondsLeft( 'copy', 'sheet' );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Delivery check: what the last runs did and a Send test email button (RMD-5). It is a form of its own, after the settings form.
	 */
	private function deliveryPanel(): void {
		$health = $this->plugin->reminders()->health();
		View::cardStart( __( 'Delivery check', 'estasoft-handover-passport' ) );
		echo '<p>' . esc_html__( 'Reminders go out through the email of this WordPress site. If the site cannot send email, no reminder arrives. Send a test email to see whether it can.', 'estasoft-handover-passport' ) . '</p>';
		echo '<dl class="estasoft-hp-defs">';
		$rows = array(
			__( 'Last daily run', 'estasoft-handover-passport' )   => '' === $health['last_run'] ? __( 'Not yet', 'estasoft-handover-passport' ) : Dates::display( $health['last_run'], true ),
			__( 'Last reminder sent', 'estasoft-handover-passport' ) => '' === $health['last_sent'] ? __( 'None yet', 'estasoft-handover-passport' ) : Dates::display( $health['last_sent'], true ),
		);
		if ( '' !== $health['test_at'] ) {
			$rows[ __( 'Last test email', 'estasoft-handover-passport' ) ] = Dates::display( $health['test_at'], true ) . ' (' . ( $health['test_ok'] ? __( 'sent', 'estasoft-handover-passport' ) : __( 'not sent', 'estasoft-handover-passport' ) ) . ')';
		}
		if ( $health['failed_days'] > 0 ) {
			$rows[ __( 'Days in a row with failed reminders', 'estasoft-handover-passport' ) ] = (string) $health['failed_days'];
		}
		if ( '' !== $health['last_error'] ) {
			$rows[ __( 'Last error from the mailer', 'estasoft-handover-passport' ) ] = $health['last_error'];
		}
		foreach ( $rows as $term => $text ) {
			echo '<dt>' . esc_html( (string) $term ) . '</dt><dd>' . esc_html( (string) $text ) . '</dd>';
		}
		echo '</dl>';
		if ( Capabilities::can( Capabilities::CONFIGURE ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::MAIL_ACTION ) . '">';
			Form::nonce( self::MAIL_ACTION );
			Form::row( 'test_mail_to', array( 'type' => 'email', 'label' => __( 'Send a test email to', 'estasoft-handover-passport' ) ), (string) wp_get_current_user()->user_email );
			echo '<p><button type="submit" class="btn">' . esc_html__( 'Send test email', 'estasoft-handover-passport' ) . '</button></p></form>';
		}
		View::cardEnd();
	}

	/**
	 * Send the test email.
	 */
	public function handleTestMail(): void {
		if ( ! Capabilities::can( Capabilities::CONFIGURE ) ) {
			wp_die( esc_html__( 'You do not have permission to change settings.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::MAIL_ACTION );
		$result = $this->plugin->reminders()->sendTest( Request::post( 'test_mail_to' ) );
		$notice = array( 'sent' => 'mail_sent', 'invalid' => 'mail_invalid', 'wait' => 'mail_wait' )[ $result['reason'] ] ?? 'mail_failed';
		$args   = array( 'page' => self::SLUG, 'tab' => 'reminders', 'estasoft_hp_notice' => $notice );
		if ( 'mail_wait' === $notice ) {
			$args['estasoft_hp_wait'] = (int) ( $result['wait'] ?? 60 );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Review intervals, scan and thresholds.
	 *
	 * @param \Estasoft\HandoverPassport\Settings\Settings $settings Settings.
	 */
	private function generalTab( $settings ): void {
		View::cardStart( __( 'How often records are reviewed', 'estasoft-handover-passport' ) );
		echo '<p class="description">' . esc_html__( 'After this many days a record is Due, and after the due window it is Stale. A record can override this on its own form.', 'estasoft-handover-passport' ) . '</p>';
		foreach ( self::sections() as $key => $label ) {
			Form::row( 'review_days_' . $key, array( 'type' => 'int', 'label' => $label ), $settings->get( 'review_days.' . $key, 90 ) );
		}
		Form::row( 'due_days', array( 'type' => 'int', 'label' => __( 'Days a record stays Due before it is Stale', 'estasoft-handover-passport' ) ), $settings->get( 'due_days', 14 ) );
		View::cardEnd();

		View::cardStart( __( 'Scan', 'estasoft-handover-passport' ) );
		Form::row( 'scan_time', array( 'type' => 'text', 'label' => __( 'Daily scan time (site time, HH:MM)', 'estasoft-handover-passport' ), 'max' => 5 ), $settings->get( 'scan_time', '03:00' ) );
		Form::row( 'scan_limit', array( 'type' => 'int', 'label' => __( 'Longest a scan may run (seconds)', 'estasoft-handover-passport' ), 'help' => __( 'Between 5 and 120.', 'estasoft-handover-passport' ) ), $settings->get( 'scan_limit', 20 ) );
		View::cardEnd();

		View::cardStart( __( 'Lead times', 'estasoft-handover-passport' ) );
		Form::row( 'backup_confirm', array( 'type' => 'int', 'label' => __( 'A backup should be confirmed every (days)', 'estasoft-handover-passport' ) ), $settings->get( 'backup_confirm', 30 ) );
		Form::row( 'access_review_days', array( 'type' => 'int', 'label' => __( 'Access entries should be reviewed every (days)', 'estasoft-handover-passport' ) ), $settings->get( 'access_review_days', 90 ) );
		View::cardEnd();

		View::cardStart( __( 'Weekly address check', 'estasoft-handover-passport' ) );
		Form::checkbox( 'url_check_weekly', __( 'Check the critical addresses every Sunday and send one email when one fails', 'estasoft-handover-passport' ), (bool) $settings->get( 'url_check_weekly', false ), __( 'This server asks each address in the Critical addresses list once a week. It is not an uptime monitor: it cannot tell you when the site goes down. Off by default. The email goes to the reminder recipients.', 'estasoft-handover-passport' ) );
		View::cardEnd();

		View::cardStart( __( 'Where notices appear', 'estasoft-handover-passport' ) );
		echo '<p class="description">' . esc_html__( 'Findings and dates show on the Passport screens. Passport never puts a banner on every admin page.', 'estasoft-handover-passport' ) . '</p>';
		Form::checkbox( 'dashboard_widget', __( 'Also show a short summary on the WordPress Dashboard', 'estasoft-handover-passport' ), (bool) $settings->get( 'dashboard_widget', false ), __( 'The next five dates and the number of findings that need fixing, for people who may view the Passport. Off by default.', 'estasoft-handover-passport' ) );
		View::cardEnd();
	}

	/**
	 * Thresholds the rules use and the recovery targets (FND-4, BKP-5).
	 *
	 * @param \Estasoft\HandoverPassport\Settings\Settings $settings Settings.
	 */
	private function findingsTab( $settings ): void {
		echo '<p class="description">' . esc_html__( 'These numbers decide when a finding opens. Changing them re-checks the rules straight away. Individual rules can be switched off from the Findings screen.', 'estasoft-handover-passport' ) . '</p>';
		View::cardStart( __( 'When a finding opens', 'estasoft-handover-passport' ) );
		foreach ( self::thresholdFields() as $key => $def ) {
			Form::row( 'th_' . $key, array( 'type' => 'int', 'label' => $def[0] ), $settings->get( 'thresholds.' . $key, $def[3] ) );
		}
		View::cardEnd();

		View::cardStart( __( 'Recovery targets', 'estasoft-handover-passport' ) );
		echo '<p class="description">' . esc_html__( 'Leave blank if nobody has agreed a target. Restore tests are compared with these numbers when they are set.', 'estasoft-handover-passport' ) . '</p>';
		Form::row( 'recovery_data_loss_hours', array( 'type' => 'int', 'label' => __( 'Most data that may be lost (hours)', 'estasoft-handover-passport' ) ), $settings->get( 'recovery.data_loss_hours' ) ?? '' );
		Form::row( 'recovery_downtime_hours', array( 'type' => 'int', 'label' => __( 'Longest the site may be down (hours)', 'estasoft-handover-passport' ) ), $settings->get( 'recovery.downtime_hours' ) ?? '' );
		View::cardEnd();
	}

	/**
	 * Reminder e-mail settings (RMD-1..5).
	 *
	 * @param \Estasoft\HandoverPassport\Settings\Settings $settings Settings.
	 */
	private function remindersTab( $settings ): void {
		View::cardStart( __( 'Reminders', 'estasoft-handover-passport' ) );
		Form::checkbox( 'reminders_enabled', __( 'Send reminder e-mails', 'estasoft-handover-passport' ), (bool) $settings->get( 'reminders_enabled', true ), __( 'One e-mail per date at each lead time below. Nothing is sent once the date is renewed or the record is removed.', 'estasoft-handover-passport' ) );
		Form::row( 'reminder_days', array( 'type' => 'text', 'label' => __( 'Lead times (days before the date, comma separated)', 'estasoft-handover-passport' ), 'max' => 60 ), implode( ', ', $this->plugin->reminders()->leadTimes() ) );
		Form::row( 'reminder_emails', array( 'type' => 'lines', 'label' => __( 'Who receives them (one address per line)', 'estasoft-handover-passport' ), 'help' => __( 'The account holder and payer on each record are added automatically when they have an address. With nobody listed and no holder or payer address, reminders go to the site administration email.', 'estasoft-handover-passport' ) ), (array) $settings->get( 'reminder_emails', array() ) );
		Form::checkbox( 'digest_enabled', __( 'Also send a weekly summary on Mondays at 08:00', 'estasoft-handover-passport' ), (bool) $settings->get( 'digest_enabled', false ), __( 'A short list of what needs attention. Off by default.', 'estasoft-handover-passport' ) );
		View::cardEnd();
	}

	/**
	 * Capability grid (CAP-2).
	 *
	 * @param mixed $grants Current grants by role.
	 */
	private function accessTab( $grants ): void {
		$grants = is_array( $grants ) ? $grants : array();
		View::bareCardStart();
		echo '<div class="scr"><table class="tbl estasoft-hp-table"><caption class="sr">' . esc_html__( 'Who may do what', 'estasoft-handover-passport' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Role', 'estasoft-handover-passport' ) . '</th>';
		$caps = Capabilities::all();
		foreach ( array_keys( $caps ) as $cap ) {
			echo '<th scope="col">' . esc_html( $this->capName( $cap ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			if ( 'administrator' === $slug ) {
				continue;
			}
			echo '<tr><th scope="row">' . esc_html( translate_user_role( $name ) ) . '</th>';
			foreach ( array_keys( $caps ) as $cap ) {
				$on = isset( $grants[ $slug ] ) && in_array( $cap, (array) $grants[ $slug ], true );
				echo '<td><label><span class="screen-reader-text">' . esc_html(
					sprintf(
					/* translators: 1: role name, 2: capability name. */
						__( '%1$s: %2$s', 'estasoft-handover-passport' ),
						translate_user_role( $name ),
						$this->capName( $cap )
					)
				) . '</span><input type="checkbox" name="grants[' . esc_attr( (string) $slug ) . '][]" value="' . esc_attr( $cap ) . '"' . checked( $on, true, false ) . '></label></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a fixed attribute.
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		View::cardEnd( '<span class="t2 cap">' . esc_html__( 'Administrators always have all five. Tick what other roles may do.', 'estasoft-handover-passport' ) . '</span>' );
		View::cardStart( __( 'What each one allows', 'estasoft-handover-passport' ) );
		echo '<dl class="estasoft-hp-defs">';
		foreach ( $caps as $cap => $text ) {
			echo '<dt>' . esc_html( $this->capName( $cap ) ) . '</dt><dd>' . esc_html( $text ) . '</dd>';
		}
		echo '</dl>';
		View::cardEnd();
	}

	/**
	 * Short name of a capability.
	 *
	 * @param string $cap Capability.
	 */
	private function capName( string $cap ): string {
		$names = array(
			Capabilities::VIEW           => __( 'View', 'estasoft-handover-passport' ),
			Capabilities::VIEW_SENSITIVE => __( 'View Sensitive', 'estasoft-handover-passport' ),
			Capabilities::EDIT           => __( 'Edit', 'estasoft-handover-passport' ),
			Capabilities::EXPORT         => __( 'Export', 'estasoft-handover-passport' ),
			Capabilities::CONFIGURE      => __( 'Configure', 'estasoft-handover-passport' ),
		);
		return $names[ $cap ] ?? $cap;
	}

	/**
	 * What is stored, retention and removal (PRV-1, PRV-5).
	 *
	 * @param bool                 $delete    Whether data is removed on uninstall.
	 * @param array<string, mixed> $retention Retention settings.
	 */
	private function privacyTab( bool $delete, array $retention ): void {
		View::cardStart( __( 'Personal data held', 'estasoft-handover-passport' ) );
		echo '<ul class="estasoft-hp-list">';
		foreach ( PrivacyPolicy::stored() as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul><p>' . esc_html__( 'Nothing is sent to ESTASOFT TECHNOLOGIES or to any other outside party. Scans read local data only.', 'estasoft-handover-passport' ) . '</p>';
		echo '<p>' . esc_html__( 'Every record stays in this site\'s own database. Data leaves it only in a file someone downloads, or in an email you have switched on: reminders, the weekly summary, the weekly address check or the emailed copy of the Emergency sheet.', 'estasoft-handover-passport' ) . '</p>';
		echo '<p>' . wp_kses(
			sprintf(
				/* translators: 1: link to Export Personal Data, 2: link to Erase Personal Data. */
				__( 'To answer a request from a person, use %1$s or %2$s. Passport takes part in both: the export lists what is held about an email address, and the erase replaces the person with "Former contact" and keeps their place in the roles.', 'estasoft-handover-passport' ),
				'<a href="' . esc_url( admin_url( 'export-personal-data.php' ) ) . '">' . esc_html__( 'Export Personal Data', 'estasoft-handover-passport' ) . '</a>',
				'<a href="' . esc_url( admin_url( 'erase-personal-data.php' ) ) . '">' . esc_html__( 'Erase Personal Data', 'estasoft-handover-passport' ) . '</a>'
			),
			array( 'a' => array( 'href' => array() ) )
		) . '</p>';
		View::cardEnd();

		$copy = $this->plugin->emailedCopy()->config();
		View::cardStart( __( 'Emailed copy of the Emergency sheet', 'estasoft-handover-passport' ) );
		echo '<p class="description">' . esc_html__( 'Off by default. When on, the one-page Emergency sheet is emailed as a PDF to the addresses below, so a copy exists outside this site. Email is not encrypted. The sheet holds no record marked Sensitive and never a password, key or token. If an email cannot be sent, the Overview says so and Passport tries again once a day, up to three more times.', 'estasoft-handover-passport' ) . '</p>';
		Form::checkbox( 'copy_enabled', __( 'Email the Emergency sheet', 'estasoft-handover-passport' ), $copy['enabled'] );
		Form::row( 'copy_addresses', array( 'type' => 'lines', 'label' => __( 'Who receives it (one address per line, up to five)', 'estasoft-handover-passport' ), 'help' => __( 'Use an inbox that someone other than the site\'s own users can reach, such as the agency\'s shared mailbox.', 'estasoft-handover-passport' ) ), $copy['addresses'] );
		Form::checkbox( 'copy_on_snapshot', __( 'Send one whenever a snapshot is made', 'estasoft-handover-passport' ), $copy['on_snapshot'] );
		Form::checkbox( 'copy_monthly', __( 'Send one every month', 'estasoft-handover-passport' ), $copy['monthly'] );
		View::cardEnd();

		View::cardStart( __( 'How long things are kept', 'estasoft-handover-passport' ) );
		Form::row( 'ret_changes_months', array( 'type' => 'int', 'label' => __( 'Change log (months)', 'estasoft-handover-passport' ) ), $retention['changes_months'] ?? 24 );
		Form::row( 'ret_deleted_days', array( 'type' => 'int', 'label' => __( 'Deleted records are kept for (days)', 'estasoft-handover-passport' ), 'help' => __( 'After this they are removed for good.', 'estasoft-handover-passport' ) ), $retention['deleted_days'] ?? 30 );
		View::cardEnd();

		View::cardStart( __( 'When the plugin is deleted', 'estasoft-handover-passport' ) );
		Form::checkbox( 'delete_on_uninstall', __( 'Delete everything when the plugin is deleted', 'estasoft-handover-passport' ), $delete, __( 'Off by default. When off, deleting the plugin keeps the records, so a client record is never lost by accident.', 'estasoft-handover-passport' ) );
		View::cardEnd();
	}

	/**
	 * Save settings.
	 */
	public function handleSave(): void {
		if ( ! Capabilities::can( Capabilities::CONFIGURE ) ) {
			wp_die( esc_html__( 'You do not have permission to change settings.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		$tab      = Request::post( 'tab' );
		$settings = $this->plugin->settings();
		$int      = static fn( string $name, int $min, int $max, int $fallback ): int => '' !== Request::post( $name ) ? max( $min, min( $max, absint( Request::post( $name ) ) ) ) : $fallback;
		$changed  = array();

		if ( 'findings' === $tab ) {
			$thresholds = array();
			foreach ( self::thresholdFields() as $key => $def ) {
				$thresholds[ $key ] = $int( 'th_' . $key, $def[1], $def[2], (int) $settings->get( 'thresholds.' . $key, $def[3] ) );
			}
			$recovery = array();
			foreach ( array( 'data_loss_hours' => 'recovery_data_loss_hours', 'downtime_hours' => 'recovery_downtime_hours' ) as $key => $field ) {
				$recovery[ $key ] = '' === Request::post( $field ) ? null : max( 0, min( 8760, absint( Request::post( $field ) ) ) );
			}
			$settings->update( array( 'thresholds' => $thresholds, 'recovery' => $recovery ) );
			$changed[] = __( 'finding thresholds', 'estasoft-handover-passport' );
		} elseif ( 'reminders' === $tab ) {
			$days   = LeadTimes::parse( Request::post( 'reminder_days' ) );
			$emails = array();
			foreach ( Str::split( '/\R/', Request::postedTextarea( 'reminder_emails' ) ) as $line ) {
				$address = sanitize_email( $line );
				if ( '' !== $address && is_email( $address ) ) {
					$emails[ strtolower( $address ) ] = $address;
				}
			}
			$settings->update(
				array(
					'reminders_enabled' => '1' === Request::post( 'reminders_enabled' ),
					'reminder_days'     => array() === $days ? LeadTimes::DEFAULT : $days,
					'reminder_emails'   => array_values( array_slice( $emails, 0, 20 ) ),
					'digest_enabled'    => '1' === Request::post( 'digest_enabled' ),
				)
			);
			$changed[] = __( 'reminders', 'estasoft-handover-passport' );
		} elseif ( 'access' === $tab ) {
			$raw    = isset( $_POST['grants'] ) && is_array( $_POST['grants'] ) ? wp_unslash( $_POST['grants'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked above; each value is matched against fixed lists below.
			$grants = array();
			$roles  = array_keys( wp_roles()->get_names() );
			foreach ( $raw as $role => $caps ) {
				$role = sanitize_key( (string) $role );
				if ( 'administrator' === $role || ! in_array( $role, $roles, true ) ) {
					continue;
				}
				$grants[ $role ] = array_values( array_intersect( Capabilities::keys(), array_map( 'sanitize_key', array_map( 'strval', array_filter( (array) $caps, 'is_scalar' ) ) ) ) );
			}
			$settings->set( 'capability_grants', $grants );
			Capabilities::applyGrants( $grants );
			$changed[] = __( 'who can do what', 'estasoft-handover-passport' );
		} elseif ( 'privacy' === $tab ) {
			$copy_to = array();
			foreach ( Str::split( '/\R/', Request::postedTextarea( 'copy_addresses' ) ) as $line ) {
				$address = sanitize_email( $line );
				if ( '' !== $address && is_email( $address ) ) {
					$copy_to[ strtolower( $address ) ] = $address;
				}
			}
			$copy_was_on = $this->plugin->emailedCopy()->config()['enabled'];
			$settings->update(
				array(
					'emailed_copy' => array(
						'enabled'     => '1' === Request::post( 'copy_enabled' ),
						'addresses'   => array_values( array_slice( $copy_to, 0, EmailedCopy::MAX_ADDRESSES ) ),
						'on_snapshot' => '1' === Request::post( 'copy_on_snapshot' ),
						'monthly'     => '1' === Request::post( 'copy_monthly' ),
					),
				)
			);
			if ( ! $copy_was_on && '1' === Request::post( 'copy_enabled' ) ) {
				$this->plugin->emailedCopy()->startMonth();
			}
			$settings->set( 'delete_on_uninstall', '1' === Request::post( 'delete_on_uninstall' ) );
			$settings->update(
				array(
					'retention' => array(
						'changes_months' => $int( 'ret_changes_months', 1, 120, 24 ),
						'deleted_days'   => $int( 'ret_deleted_days', 1, 365, 30 ),
					),
				)
			);
			$changed[] = __( 'privacy and data', 'estasoft-handover-passport' );
		} else {
			$review = array();
			foreach ( array_keys( self::sections() ) as $key ) {
				$review[ $key ] = $int( 'review_days_' . $key, 1, 3650, (int) $settings->get( 'review_days.' . $key, 90 ) );
			}
			$time = Request::post( 'scan_time' );
			$time = 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : (string) $settings->get( 'scan_time', '03:00' );
			if ( $time !== (string) $settings->get( 'scan_time', '03:00' ) ) {
				Schedule::rescheduleScan( $time );
			}
			$settings->update(
				array(
					'review_days'        => $review,
					'due_days'           => $int( 'due_days', 0, 365, 14 ),
					'scan_time'          => $time,
					'scan_limit'         => $int( 'scan_limit', 5, 120, 20 ),
					'backup_confirm'     => $int( 'backup_confirm', 1, 365, 30 ),
					'access_review_days' => $int( 'access_review_days', 1, 3650, 90 ),
					'dashboard_widget'   => '1' === Request::post( 'dashboard_widget' ),
					'url_check_weekly'   => '1' === Request::post( 'url_check_weekly' ),
				)
			);
			$changed[] = __( 'review intervals and scan', 'estasoft-handover-passport' );
		}

		if ( in_array( $tab, array( 'findings', 'general' ), true ) || '' === $tab ) {
			$this->plugin->findings()->queue();
		}
		$this->plugin->changeLog()->add( 'settings_changed', 'settings', 0, __( 'Settings', 'estasoft-handover-passport' ), array(), array(), implode( ', ', $changed ), 'wordpress' );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => '' !== $tab ? $tab : 'general', 'estasoft_hp_notice' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
