<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Checks\MailProviders;
use PHPUnit\Framework\TestCase;

/**
 * The provider is named from the MX host suffix and nothing more (DOM-5).
 */
final class MailProvidersTest extends TestCase {

	public function test_names_a_provider_from_the_mx_host_suffix(): void {
		$table = array( 'google.com' => 'Google Workspace', 'protection.outlook.com' => 'Microsoft 365' );
		$this->assertSame( array( 'Google Workspace' ), MailProviders::name( array( '1 aspmx.l.google.com', '5 ALT1.ASPMX.L.GOOGLE.COM.' ), $table ) );
		$this->assertSame( array( 'Microsoft 365' ), MailProviders::name( array( '0 example-com.mail.protection.outlook.com' ), $table ) );
	}

	public function test_a_lookalike_host_is_not_matched(): void {
		$this->assertSame( array(), MailProviders::name( array( '10 mail.notgoogle.com', '10 google.com.evil.test' ), array( 'google.com' => 'Google Workspace' ) ) );
	}

	public function test_an_exact_host_matches_and_unknown_hosts_name_nobody(): void {
		$this->assertSame( array( 'Zoho Mail' ), MailProviders::name( array( '10 zoho.com' ), array( 'zoho.com' => 'Zoho Mail' ) ) );
		$this->assertSame( array(), MailProviders::name( array( '10 mail.example.com' ), array( 'zoho.com' => 'Zoho Mail' ) ) );
		$this->assertSame( array(), MailProviders::name( array(), array( 'zoho.com' => 'Zoho Mail' ) ) );
	}

	public function test_several_providers_are_listed_once_each(): void {
		$names = MailProviders::name( array( '1 a.google.com', '2 b.google.com', '3 x.protection.outlook.com' ), array( 'google.com' => 'Google Workspace', 'protection.outlook.com' => 'Microsoft 365' ) );
		$this->assertSame( array( 'Google Workspace', 'Microsoft 365' ), $names );
	}

	public function test_the_shipped_table_names_common_providers(): void {
		$this->assertSame( array( 'Google Workspace' ), MailProviders::name( array( '1 aspmx.l.google.com' ) ) );
	}
}
