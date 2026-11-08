<?php
/**
 * Request one address on this site and report what came back (URL-2, URL-3, SEC-8).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Checks;

use Closure;
use Estasoft\HandoverPassport\Security\OutboundGuard;
use Estasoft\HandoverPassport\Security\OutboundRefused;
use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Own-site GET requests only: ten seconds, five redirects, no cookies. The page body is read, compared, and dropped.
 */
final class UrlChecker {

	public const MAX_REDIRECTS = 5;
	public const TIMEOUT       = 10;
	public const MAX_BYTES     = 1048576;

	/**
	 * Transport: takes a URL and returns status, location and body, or an error text.
	 *
	 * @var Closure(string): array{status?: int, location?: string, body?: string, error?: string}
	 */
	private Closure $fetch;

	/**
	 * Constructor.
	 *
	 * @param OutboundGuard $guard Decides which addresses may be requested.
	 * @param callable|null $fetch Transport, or null for the WordPress HTTP API.
	 */
	public function __construct( private OutboundGuard $guard, ?callable $fetch = null ) {
		$this->fetch = null === $fetch ? Closure::fromCallable( array( self::class, 'wordpressFetch' ) ) : Closure::fromCallable( $fetch );
	}

	/**
	 * Check an address.
	 *
	 * @param string $url             Address.
	 * @param int    $expected_status Status that counts as working.
	 * @param string $expected_text   Text that must be on the page, or empty.
	 * @return array<string, mixed> ok, status, final, at, ms, text_ok, reason; "refused" instead of "ok" when the guard said no.
	 */
	public function check( string $url, int $expected_status = 200, string $expected_text = '' ): array {
		$started = microtime( true );
		$result  = array( 'at' => Clock::sql() );
		$current = $url;
		$status  = 0;
		$body    = '';

		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			try {
				$this->guard->validate( $current );
			} catch ( OutboundRefused $e ) {
				return $result + array(
					'refused' => true,
					'reason'  => $e->getMessage(),
					'status'  => 0,
					'final'   => $current,
				);
			}
			$response = ( $this->fetch )( $current );
			if ( isset( $response['error'] ) ) {
				return $result + array(
					'ok'      => false,
					'status'  => 0,
					'final'   => $current,
					'ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
					'text_ok' => null,
					'reason'  => (string) $response['error'],
				);
			}
			$status = (int) ( $response['status'] ?? 0 );
			$body   = (string) ( $response['body'] ?? '' );
			if ( $status >= 300 && $status < 400 && '' !== (string) ( $response['location'] ?? '' ) ) {
				$next = OutboundGuard::absolute( $current, (string) $response['location'] );
				if ( $hop === self::MAX_REDIRECTS ) {
					return $result + array(
						'ok'      => false,
						'status'  => $status,
						'final'   => $current,
						'ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
						'text_ok' => null,
						'reason'  => __( 'Too many redirects.', 'estasoft-handover-passport' ),
					);
				}
				try {
					$this->guard->validate( $next );
				} catch ( OutboundRefused $e ) {
					return $result + array(
						'ok'      => false,
						'status'  => $status,
						'final'   => $current,
						'ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
						'text_ok' => null,
						'reason'  => __( 'The page redirects away from this site, so the redirect was not followed.', 'estasoft-handover-passport' ),
					);
				}
				$current = $next;
				continue;
			}
			break;
		}

		$text_ok = '' === $expected_text ? null : false !== strpos( $body, $expected_text );
		$ok      = $status === $expected_status && false !== ( $text_ok ?? true );
		$reason  = '';
		if ( $status !== $expected_status ) {
			/* translators: 1: status received, 2: status expected. */
			$reason = sprintf( __( 'Status %1$d, expected %2$d.', 'estasoft-handover-passport' ), $status, $expected_status );
		} elseif ( false === $text_ok ) {
			$reason = __( 'The expected text was not on the page.', 'estasoft-handover-passport' );
		}

		return $result + array(
			'ok'      => $ok,
			'status'  => $status,
			'final'   => $current,
			'ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'text_ok' => $text_ok,
			'reason'  => $reason,
		);
	}

	/**
	 * Transport that uses the WordPress HTTP API without following redirects or sending cookies.
	 *
	 * @param string $url Address already approved by the guard.
	 * @return array{status?: int, location?: string, body?: string, error?: string}
	 */
	private static function wordpressFetch( string $url ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'cookies'             => array(),
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'Estasoft Handover Passport/' . ( defined( 'ESTASOFT_HP_VERSION' ) ? ESTASOFT_HP_VERSION : '1' ),
				'reject_unsafe_urls'  => false,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}
		return array(
			'status'   => (int) wp_remote_retrieve_response_code( $response ),
			'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
			'body'     => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
