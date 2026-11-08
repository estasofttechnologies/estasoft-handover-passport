<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Security\OutboundGuard;
use Estasoft\HandoverPassport\Security\OutboundRefused;
use PHPUnit\Framework\TestCase;

/**
 * The outbound guard only lets checks reach the site's own host and never private networks (SEC-8, AS-12).
 */
final class OutboundGuardTest extends TestCase {

	private function guard( array $map, array $hosts = array( 'example.com' ), ?bool $local = false ): OutboundGuard {
		return new OutboundGuard(
			$hosts,
			static fn( string $host ): array => $map[ $host ] ?? array(),
			$local
		);
	}

	private function refusedWith( string $code, callable $fn ): void {
		try {
			$fn();
			$this->fail( 'Expected a refusal with code ' . $code );
		} catch ( OutboundRefused $e ) {
			$this->assertSame( $code, $e->codeId() );
		}
	}

	public function test_the_site_host_with_a_public_address_is_allowed(): void {
		$parts = $this->guard( array( 'example.com' => array( '93.184.216.34' ) ) )->validate( 'https://example.com/contact?x=1' );
		$this->assertSame( 'https', $parts['scheme'] );
		$this->assertSame( 443, $parts['port'] );
		$this->assertSame( '/contact?x=1', $parts['path'] );
		$this->assertSame( array( '93.184.216.34' ), $parts['ips'] );
	}

	public function test_other_hosts_are_refused(): void {
		$g = $this->guard( array( 'evil.test' => array( '93.184.216.34' ) ) );
		$this->refusedWith( 'other_host', fn() => $g->validate( 'https://evil.test/' ) );
	}

	public function test_only_http_and_https_are_allowed(): void {
		$g = $this->guard( array( 'example.com' => array( '93.184.216.34' ) ) );
		$this->refusedWith( 'bad_scheme', fn() => $g->validate( 'ftp://example.com/' ) );
		$this->refusedWith( 'bad_url', fn() => $g->validate( 'file:///etc/passwd' ) ); // No host, so it is refused as unreadable.
		$this->refusedWith( 'bad_url', fn() => $g->validate( 'not a url' ) );
	}

	public function test_addresses_with_credentials_are_refused(): void {
		$g = $this->guard( array( 'example.com' => array( '93.184.216.34' ) ) );
		$this->refusedWith( 'credentials', fn() => $g->validate( 'https://user:pass@example.com/' ) );
	}

	public function test_a_host_that_resolves_to_a_private_address_is_refused(): void {
		foreach ( array( '10.0.0.5', '192.168.1.10', '172.16.4.4', '127.0.0.1', '169.254.1.1', '::1', 'fd00::1', 'fe80::1', '0.0.0.0', '100.64.0.1' ) as $ip ) {
			$g = $this->guard( array( 'example.com' => array( $ip ) ) );
			$this->refusedWith( 'private_address', fn() => $g->validate( 'https://example.com/' ) );
		}
	}

	public function test_one_private_address_among_public_ones_refuses_the_host(): void {
		$g = $this->guard( array( 'example.com' => array( '93.184.216.34', '10.0.0.1' ) ) );
		$this->refusedWith( 'private_address', fn() => $g->validate( 'https://example.com/' ) );
	}

	public function test_cloud_metadata_addresses_are_always_refused_even_on_a_local_site(): void {
		$g = $this->guard( array( 'example.com' => array( '169.254.169.254' ) ), array( 'example.com' ), true );
		$this->refusedWith( 'metadata', fn() => $g->validate( 'https://example.com/' ) );
	}

	public function test_a_local_site_may_check_itself(): void {
		$g = $this->guard( array( 'mysite.local' => array( '127.0.0.1' ) ), array( 'mysite.local' ), null );
		$parts = $g->validate( 'http://mysite.local/' );
		$this->assertSame( 80, $parts['port'] );
	}

	public function test_an_unresolvable_host_is_refused(): void {
		$g = $this->guard( array() );
		$this->refusedWith( 'no_dns', fn() => $g->validate( 'https://example.com/' ) );
	}

	public function test_a_redirect_to_another_host_is_refused(): void {
		$g = $this->guard( array( 'example.com' => array( '93.184.216.34' ), 'other.test' => array( '93.184.216.35' ) ) );
		$this->refusedWith( 'other_host', fn() => $g->validateRedirect( 'https://example.com/a', 'https://other.test/b' ) );
	}

	public function test_a_relative_redirect_stays_on_the_site(): void {
		$g     = $this->guard( array( 'example.com' => array( '93.184.216.34' ) ) );
		$parts = $g->validateRedirect( 'https://example.com/a/b', '/login' );
		$this->assertSame( '/login', $parts['path'] );
		$parts = $g->validateRedirect( 'https://example.com/a/b', 'next' );
		$this->assertSame( '/a/next', $parts['path'] );
	}

	public function test_blocked_ip_ranges(): void {
		$this->assertTrue( OutboundGuard::isBlockedIp( '10.1.2.3' ) );
		$this->assertTrue( OutboundGuard::isBlockedIp( '::ffff:10.1.2.3' ) );
		$this->assertFalse( OutboundGuard::isBlockedIp( '93.184.216.34' ) );
		$this->assertFalse( OutboundGuard::isBlockedIp( '2606:2800:220:1:248:1893:25c8:1946' ) );
	}

	public function test_local_names(): void {
		$this->assertTrue( OutboundGuard::isLocalName( 'localhost' ) );
		$this->assertTrue( OutboundGuard::isLocalName( 'shop.test' ) );
		$this->assertTrue( OutboundGuard::isLocalName( 'site.local' ) );
		$this->assertFalse( OutboundGuard::isLocalName( 'example.com' ) );
	}
}
