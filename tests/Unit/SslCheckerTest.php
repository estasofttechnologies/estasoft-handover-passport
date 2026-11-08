<?php
declare(strict_types=1);

namespace Estasoft\HandoverPassport\Tests\Unit;

use Estasoft\HandoverPassport\Checks\SslChecker;
use Estasoft\HandoverPassport\Security\OutboundGuard;
use PHPUnit\Framework\TestCase;

/**
 * The certificate reader states dates and names; it never says the certificate is "secure" (DOM-3).
 */
final class SslCheckerTest extends TestCase {

	private function guard(): OutboundGuard {
		return new OutboundGuard( array( 'example.com' ), static fn( string $host ): array => 'example.com' === $host ? array( '93.184.216.34' ) : array(), false );
	}

	public function test_summarise_reads_dates_issuer_names_and_key(): void {
		$sum = SslChecker::summarise(
			array(
				'validFrom_time_t' => gmmktime( 0, 0, 0, 1, 15, 2026 ),
				'validTo_time_t'   => gmmktime( 23, 59, 59, 4, 15, 2026 ),
				'issuer'           => array( 'O' => "Let's Encrypt", 'CN' => 'R11' ),
				'subject'          => array( 'CN' => 'example.com' ),
				'extensions'       => array( 'subjectAltName' => 'DNS:Example.com, DNS:www.example.com, IP Address:1.2.3.4' ),
				'key_type'         => 'RSA',
				'key_bits'         => 2048,
			)
		);
		$this->assertSame( '2026-04-15', $sum['valid_to'] );
		$this->assertSame( '2026-01-15', $sum['valid_from'] );
		$this->assertSame( "Let's Encrypt (R11)", $sum['issuer'] );
		$this->assertSame( array( 'example.com', 'www.example.com' ), $sum['names'] );
		$this->assertSame( 'RSA 2048', $sum['key_type'] );
	}

	public function test_summarise_falls_back_to_the_subject_name_and_tolerates_gaps(): void {
		$sum = SslChecker::summarise( array( 'subject' => array( 'CN' => 'Shop.Example.com' ), 'issuer' => array( 'CN' => 'Some CA' ) ) );
		$this->assertSame( array( 'shop.example.com' ), $sum['names'] );
		$this->assertSame( 'Some CA', $sum['issuer'] );
		$this->assertSame( '', $sum['valid_to'] );
		$this->assertSame( '', $sum['key_type'] );
	}

	public function test_check_stores_the_reading(): void {
		$checker = new SslChecker( $this->guard(), static fn( string $host, int $port ): array => array( 'validTo_time_t' => gmmktime( 0, 0, 0, 6, 1, 2026 ), 'validFrom_time_t' => gmmktime( 0, 0, 0, 3, 1, 2026 ), 'issuer' => array( 'O' => 'Acme CA' ), 'subject' => array( 'CN' => $host ) ) );
		$res     = $checker->check( 'example.com' );
		$this->assertSame( '', $res['error'] );
		$this->assertSame( '2026-06-01', $res['valid_to'] );
		$this->assertSame( array( 'example.com' ), $res['names'] );
		$this->assertNotSame( '', $res['checked_at'] );
	}

	public function test_a_read_error_is_returned_with_empty_dates(): void {
		$checker = new SslChecker( $this->guard(), static fn(): array => array( 'error' => 'Connection refused' ) );
		$res     = $checker->check( 'example.com' );
		$this->assertSame( 'Connection refused', $res['error'] );
		$this->assertSame( '', $res['valid_to'] );
	}

	public function test_another_host_is_refused_without_connecting(): void {
		$called  = false;
		$checker = new SslChecker(
			$this->guard(),
			static function () use ( &$called ): array {
				$called = true;
				return array();
			}
		);
		$res = $checker->check( 'evil.test' );
		$this->assertNotSame( '', $res['error'] );
		$this->assertFalse( $called );
	}
}
