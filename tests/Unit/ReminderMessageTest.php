<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Reminders\ReminderMessage;
use PHPUnit\Framework\TestCase;

/**
 * What a reminder email says, and what it never says.
 */
final class ReminderMessageTest extends TestCase {

	/**
	 * Values for one email.
	 *
	 * @param array<string, mixed> $over Values to change.
	 * @return array<string, mixed>
	 */
	private function values( array $over = array() ): array {
		return $over + array(
			'site'      => 'Acme Bakery',
			'site_url'  => 'https://acme.example',
			'kind'      => 'Licence',
			'title'     => 'Elementor Pro',
			'sensitive' => false,
			'date'      => '15 May 2026',
			'days_left' => 14,
			'link'      => 'https://acme.example/wp-admin/admin.php?page=estasoft-passport-inventory&view=edit&id=7',
		);
	}

	public function test_the_subject_says_what_and_when(): void {
		$message = ReminderMessage::due( $this->values() );
		$this->assertSame( '[Acme Bakery] Licence: Elementor Pro, in 14 days', $message['subject'] );
	}

	public function test_the_body_has_the_facts_and_the_link(): void {
		$body = ReminderMessage::due( $this->values() )['body'];
		$this->assertStringContainsString( 'Name: Elementor Pro', $body );
		$this->assertStringContainsString( 'Date: 15 May 2026 (in 14 days)', $body );
		$this->assertStringContainsString( 'https://acme.example/wp-admin/admin.php?page=estasoft-passport-inventory&view=edit&id=7', $body );
		$this->assertStringContainsString( 'reminder list', $body );
	}

	public function test_a_sensitive_record_is_not_named(): void {
		$message = ReminderMessage::due( $this->values( array( 'sensitive' => true, 'title' => 'Bank portal renewal' ) ) );
		$this->assertStringNotContainsString( 'Bank portal', $message['subject'] );
		$this->assertStringNotContainsString( 'Bank portal', $message['body'] );
		$this->assertStringContainsString( 'A record marked Sensitive', $message['subject'] );
	}

	public function test_a_title_cannot_add_a_mail_header(): void {
		$message = ReminderMessage::due( $this->values( array( 'title' => "Evil\r\nBcc: someone@example.org" ) ) );
		$this->assertSame( 1, count( explode( "\n", $message['subject'] ) ) );
		$this->assertStringNotContainsString( "\r", $message['subject'] );
	}

	public function test_when_reads_naturally(): void {
		$this->assertSame( 'today', ReminderMessage::when( 0 ) );
		$this->assertSame( 'tomorrow', ReminderMessage::when( 1 ) );
		$this->assertSame( 'in 30 days', ReminderMessage::when( 30 ) );
		$this->assertSame( '1 day ago', ReminderMessage::when( -1 ) );
		$this->assertSame( '3 days ago', ReminderMessage::when( -3 ) );
	}

	public function test_the_summary_lists_dates_findings_and_changes(): void {
		$message = ReminderMessage::digest(
			array(
				'site'         => 'Acme Bakery',
				'site_url'     => 'https://acme.example',
				'dates'        => array( array( 'date' => '15 May 2026', 'kind' => 'Licence', 'title' => 'Elementor Pro', 'days_left' => 14 ) ),
				'needs_fixing' => 2,
				'changes'      => 5,
				'since'        => '4 May 2026',
				'overview'     => 'https://acme.example/wp-admin/admin.php?page=estasoft-passport',
				'findings'     => '',
				'history'      => '',
			)
		);
		$this->assertStringContainsString( '15 May 2026  Licence: Elementor Pro (in 14 days)', $message['body'] );
		$this->assertStringContainsString( '2 findings need fixing.', $message['body'] );
		$this->assertStringContainsString( '5 changes were recorded since 4 May 2026.', $message['body'] );
		$this->assertStringContainsString( 'Weekly summary', $message['subject'] );
	}

	public function test_an_empty_summary_says_so(): void {
		$message = ReminderMessage::digest(
			array( 'site' => 'A', 'site_url' => 'https://a.example', 'dates' => array(), 'needs_fixing' => 1, 'changes' => 0, 'since' => '', 'overview' => '', 'findings' => '', 'history' => '' )
		);
		$this->assertStringContainsString( 'None entered.', $message['body'] );
		$this->assertStringContainsString( '1 finding needs fixing.', $message['body'] );
		$this->assertStringContainsString( '0 changes are in the change log.', $message['body'] );
	}

	public function test_the_test_email_says_where_it_came_from(): void {
		$message = ReminderMessage::test( array( 'site' => 'Acme Bakery', 'site_url' => 'https://acme.example' ) );
		$this->assertStringContainsString( 'Test email', $message['subject'] );
		$this->assertStringContainsString( 'https://acme.example', $message['body'] );
	}

	public function test_the_copy_email_says_it_is_not_encrypted_and_why_it_came(): void {
		$base = array( 'site' => 'Acme Bakery', 'site_url' => 'https://acme.example', 'date' => '2026-11-08', 'page' => 'https://acme.example/wp-admin/admin.php?page=x' );
		$snap = ReminderMessage::copy( $base + array( 'why' => 'snapshot' ) );
		$this->assertStringContainsString( 'Emergency sheet, 2026-11-08', $snap['subject'] );
		$this->assertStringContainsString( 'Acme Bakery', $snap['subject'] );
		$this->assertStringContainsString( 'Email is not encrypted', $snap['body'] );
		$this->assertStringContainsString( 'holds no record marked Sensitive', $snap['body'] );
		$this->assertStringContainsString( 'a new snapshot of the Passport was made', $snap['body'] );
		$this->assertStringContainsString( 'the monthly copy is on', ReminderMessage::copy( $base + array( 'why' => 'monthly' ) )['body'] );
		$this->assertStringContainsString( 'someone pressed Send a copy now', ReminderMessage::copy( $base + array( 'why' => 'manual' ) )['body'] );
		$this->assertStringContainsString( 'someone pressed Send a copy now', ReminderMessage::copy( $base + array( 'why' => 'anything else' ) )['body'], 'an unknown reason falls back to the neutral one' );
	}

	public function test_a_site_name_cannot_add_a_header_to_the_copy_email(): void {
		$message = ReminderMessage::copy( array( 'site' => "Evil\r\nBcc: x@example.test", 'site_url' => 'https://a.example', 'date' => '2026-11-08', 'why' => 'manual', 'page' => 'https://a.example/' ) );
		$this->assertSame( 0, preg_match( '/[\r\n]/', $message['subject'] ) );
	}
}
