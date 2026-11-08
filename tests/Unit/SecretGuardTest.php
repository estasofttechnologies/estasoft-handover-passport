<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Security\SecretGuard;
use PHPUnit\Framework\TestCase;

/**
 * The secret guard keeps passwords, keys and tokens out of the Passport (SEC-6, AS-3).
 * The sample values below are made-up shapes, not real credentials.
 */
final class SecretGuardTest extends TestCase {

	private SecretGuard $guard;

	protected function setUp(): void {
		$this->guard = new SecretGuard();
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function secrets(): array {
		return array(
			'private key'       => array( "-----BEGIN RSA PRIVATE KEY-----\nMIIE", 'private_key' ),
			'aws key'           => array( 'key is AKIAABCDEFGHIJKLMNOP', 'aws_access_key' ),
			'github token'      => array( 'ghp_' . str_repeat( 'a1B2', 9 ), 'github_token' ),
			'slack token'       => array( 'xoxb-1234567890-abcdefghij', 'slack_token' ),
			'stripe key'        => array( 'sk_live_' . str_repeat( 'a1', 12 ), 'stripe_key' ),
			'google key'        => array( 'AIza' . str_repeat( 'Ab1_', 8 ) . 'abc', 'google_api_key' ),
			'jwt'               => array( 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.abcdefghij1234', 'jwt' ),
			'url credential'    => array( 'https://admin:hunter22@example.com/db', 'url_credential' ),
			'bearer'            => array( 'Authorization: Bearer abcdefghijklmnopqrstuvwxyz0123', 'bearer_token' ),
			'labelled password' => array( 'login password: Tr0ub4dor&3', 'labelled_value' ),
			'labelled api key'  => array( 'api_key = 9f8e7d6c', 'labelled_value' ),
		);
	}

	public function test_every_built_in_secret_shape_is_refused(): void {
		foreach ( self::secrets() as $name => $case ) {
			$this->assertSame( $case[1], $this->guard->inspect( $case[0] ), $name );
		}
	}

	public function test_long_random_looking_tokens_are_refused(): void {
		$this->assertSame( 'random_token', $this->guard->inspect( 'token q8Zx3LmP0vWb7RtYc2NdKfGh5JsAeUi1' ) );
	}

	public function test_ordinary_notes_pass(): void {
		$ok = array(
			'Renews every March. Billing contact is Dana.',
			'Login lives in the shared password manager under Clients.',
			'https://example.com/wp-admin/',
			'Restore from the nightly backup, then flush permalinks.',
			'a94a8fe5ccb19ba61c4c0873d391e987982fbbd3',
			'/var/www/html/wp-content/uploads/2026/01/some-long-file-name-here.jpg',
			'',
		);
		foreach ( $ok as $text ) {
			$this->assertNull( $this->guard->inspect( $text ), $text );
		}
	}

	public function test_the_word_password_alone_is_not_a_secret(): void {
		$this->assertNull( $this->guard->inspect( 'Ask Sam for the password' ) );
		$this->assertNull( $this->guard->inspect( 'Password policy: rotate yearly' ) );
	}

	public function test_nested_values_are_checked_and_the_field_is_named(): void {
		try {
			$this->guard->assertClean( array( 'note' => 'fine', 'extra' => array( 'login' => 'secret: abcd1234' ) ), 'Host' );
			$this->fail( 'Expected SecretFound' );
		} catch ( SecretFound $e ) {
			$this->assertSame( 'Host.extra.login', $e->field() );
			$this->assertSame( 'labelled_value', $e->patternId() );
		}
	}

	public function test_the_message_never_contains_the_matched_text(): void {
		try {
			$this->guard->assertClean( 'password: Tr0ub4dor&3', 'Note' );
			$this->fail( 'Expected SecretFound' );
		} catch ( SecretFound $e ) {
			$this->assertStringNotContainsString( 'Tr0ub4dor', $e->getMessage() );
			$this->assertStringContainsString( 'Note', $e->getMessage() );
		}
	}
}
