<?php
/**
 * Privacy information: what is stored, suggested policy text and the personal-data tools (PRV-1, PRV-2, PRV-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the suggested text for the WordPress privacy policy guide.
 */
final class PrivacyPolicy {

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'suggest' ) );
	}

	/**
	 * What is stored, in plain words (PRV-1).
	 *
	 * @return list<string>
	 */
	public static function stored(): array {
		return array(
			__( 'Names, organisations, titles, email addresses, phone numbers, languages, time zones and notes of the people you add to the directory.', 'estasoft-handover-passport' ),
			__( 'Names and email addresses of the administrators found by the scan. The email addresses are Sensitive and shown only to people who may see Sensitive records.', 'estasoft-handover-passport' ),
			__( 'Names of WordPress users in the change log, as the person who made each change.', 'estasoft-handover-passport' ),
			__( 'Names in snapshot acknowledgements and in the export log, when those features are used.', 'estasoft-handover-passport' ),
		);
	}

	/**
	 * Add suggested text to Settings > Privacy > Policy Guide (PRV-2).
	 */
	public function suggest(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content  = '<h2>' . esc_html__( 'Estasoft Handover Passport', 'estasoft-handover-passport' ) . '</h2>';
		$content .= '<p class="privacy-policy-tutorial">' . esc_html__( 'Use this text if your site records the people who work on it with Passport. Edit it to match what you actually do.', 'estasoft-handover-passport' ) . '</p>';
		$content .= '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'estasoft-handover-passport' ) . '</strong> ';
		$content .= esc_html__( 'We keep a record of this website for the people who run it. It lists the names, work email addresses, phone numbers and roles of the people who look after the site, and the names of the people who change it. This record is stored on this website only and is not sent to any other company. To ask what we hold about you, or to have it removed, contact us.', 'estasoft-handover-passport' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Estasoft Handover Passport', 'estasoft-handover-passport' ), wp_kses_post( $content ) );
	}
}
