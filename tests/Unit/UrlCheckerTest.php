<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Checks\UrlChecker;
use Estasoft\HandoverPassport\Security\OutboundGuard;
use PHPUnit\Framework\TestCase;

/**
 * The address checker follows redirects only inside the site and reports facts, not verdicts (URL-2, SEC-8).
 */
final class UrlCheckerTest extends TestCase {

	/**
	 * Build a checker whose transport answers from a map of address => response.
	 *
	 * @param array<string, array<string, mixed>> $responses Responses by address.
	 * @param list<string>                        $seen      Addresses requested, filled by reference.
	 */
	private function checker( array $responses, array &$seen = array() ): UrlChecker {
		$guard = new OutboundGuard( array( 'example.com' ), static fn( string $host ): array => 'example.com' === $host ? array( '93.184.216.34' ) : array(), false );
		return new UrlChecker(
			$guard,
			static function ( string $url ) use ( $responses, &$seen ): array {
				$seen[] = $url;
				return $responses[ $url ] ?? array( 'status' => 404, 'body' => '' );
			}
		);
	}

	public function test_expected_status_and_text_pass(): void {
		$res = $this->checker( array( 'https://example.com/' => array( 'status' => 200, 'body' => '<h1>Welcome</h1>' ) ) )->check( 'https://example.com/', 200, 'Welcome' );
		$this->assertTrue( $res['ok'] );
		$this->assertTrue( $res['text_ok'] );
		$this->assertSame( 200, $res['status'] );
		$this->assertSame( '', $res['reason'] );
		$this->assertSame( 'https://example.com/', $res['final'] );
	}

	public function test_a_different_status_fails_and_says_which(): void {
		$res = $this->checker( array( 'https://example.com/' => array( 'status' => 503, 'body' => '' ) ) )->check( 'https://example.com/' );
		$this->assertFalse( $res['ok'] );
		$this->assertSame( 503, $res['status'] );
		$this->assertStringContainsString( '503', $res['reason'] );
		$this->assertStringContainsString( '200', $res['reason'] );
	}

	public function test_missing_text_fails_even_with_the_right_status(): void {
		$res = $this->checker( array( 'https://example.com/' => array( 'status' => 200, 'body' => 'Hello' ) ) )->check( 'https://example.com/', 200, 'Goodbye' );
		$this->assertFalse( $res['ok'] );
		$this->assertFalse( $res['text_ok'] );
		$this->assertNotSame( '', $res['reason'] );
	}

	public function test_no_expected_text_leaves_text_ok_null(): void {
		$res = $this->checker( array( 'https://example.com/' => array( 'status' => 200, 'body' => 'x' ) ) )->check( 'https://example.com/' );
		$this->assertNull( $res['text_ok'] );
		$this->assertTrue( $res['ok'] );
	}

	public function test_a_redirect_inside_the_site_is_followed(): void {
		$seen = array();
		$res  = $this->checker(
			array(
				'https://example.com/old' => array( 'status' => 301, 'location' => '/new' ),
				'https://example.com/new' => array( 'status' => 200, 'body' => 'ok' ),
			),
			$seen
		)->check( 'https://example.com/old' );
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'https://example.com/new', $res['final'] );
		$this->assertSame( array( 'https://example.com/old', 'https://example.com/new' ), $seen );
	}

	public function test_a_redirect_to_another_site_is_not_followed(): void {
		$seen = array();
		$res  = $this->checker( array( 'https://example.com/' => array( 'status' => 302, 'location' => 'https://evil.test/steal' ) ), $seen )->check( 'https://example.com/' );
		$this->assertFalse( $res['ok'] );
		$this->assertSame( array( 'https://example.com/' ), $seen, 'The other site must never be requested.' );
		$this->assertSame( 302, $res['status'] );
		$this->assertStringContainsString( 'redirects away', $res['reason'] );
	}

	public function test_a_redirect_loop_stops(): void {
		$seen = array();
		$res  = $this->checker( array( 'https://example.com/a' => array( 'status' => 302, 'location' => '/a' ) ), $seen )->check( 'https://example.com/a' );
		$this->assertFalse( $res['ok'] );
		$this->assertCount( UrlChecker::MAX_REDIRECTS + 1, $seen );
		$this->assertStringContainsString( 'Too many redirects', $res['reason'] );
	}

	public function test_an_address_on_another_host_is_refused_before_any_request(): void {
		$seen = array();
		$res  = $this->checker( array(), $seen )->check( 'https://other.test/' );
		$this->assertArrayNotHasKey( 'ok', $res, 'A refusal is not a failed check.' );
		$this->assertTrue( $res['refused'] );
		$this->assertSame( array(), $seen );
	}

	public function test_a_transport_error_is_reported_as_a_failure_with_its_reason(): void {
		$guard = new OutboundGuard( array( 'example.com' ), static fn(): array => array( '93.184.216.34' ), false );
		$res   = ( new UrlChecker( $guard, static fn(): array => array( 'error' => 'Connection timed out' ) ) )->check( 'https://example.com/' );
		$this->assertFalse( $res['ok'] );
		$this->assertSame( 0, $res['status'] );
		$this->assertSame( 'Connection timed out', $res['reason'] );
	}
}
