<?php
/**
 * Read the certificate this server is shown for its own host (DOM-6).
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
 * Opens a TLS connection to the site's own address on port 443. A CDN may present its own certificate, so the record
 * is labelled as seen from this server.
 */
final class SslChecker {

	/**
	 * Reader: takes host and port and returns the parsed certificate (openssl_x509_parse shape plus key info), or an error text.
	 *
	 * @var Closure(string, int): array<string, mixed>
	 */
	private Closure $read;

	/**
	 * Constructor.
	 *
	 * @param OutboundGuard $guard Allows only the site's own host.
	 * @param callable|null $read  Reader, or null for a real connection.
	 */
	public function __construct( private OutboundGuard $guard, ?callable $read = null ) {
		$this->read = null === $read ? Closure::fromCallable( array( self::class, 'connect' ) ) : Closure::fromCallable( $read );
	}

	/**
	 * Check a host.
	 *
	 * @param string $host Site host name.
	 * @return array<string, mixed> valid_to, valid_from, issuer, names, key_type, checked_at, error.
	 */
	public function check( string $host ): array {
		$base = array(
			'valid_to'   => '',
			'valid_from' => '',
			'issuer'     => '',
			'names'      => array(),
			'key_type'   => '',
			'checked_at' => Clock::sql(),
			'error'      => '',
		);
		try {
			$this->guard->validate( 'https://' . $host . '/' );
		} catch ( OutboundRefused $e ) {
			$base['error'] = $e->getMessage();
			return $base;
		}
		$raw = ( $this->read )( $host, 443 );
		if ( isset( $raw['error'] ) ) {
			$base['error'] = (string) $raw['error'];
			return $base;
		}
		return self::summarise( $raw ) + array( 'checked_at' => $base['checked_at'], 'error' => '' );
	}

	/**
	 * Turn a parsed certificate into the stored fields.
	 *
	 * @param array<string, mixed> $cert Parsed certificate: validFrom_time_t, validTo_time_t, issuer, subject, extensions, key_type, key_bits.
	 * @return array{valid_to: string, valid_from: string, issuer: string, names: list<string>, key_type: string}
	 */
	public static function summarise( array $cert ): array {
		$issuer = (array) ( $cert['issuer'] ?? array() );
		$name   = (string) ( $issuer['O'] ?? $issuer['CN'] ?? '' );
		if ( '' !== $name && isset( $issuer['CN'] ) && (string) $issuer['CN'] !== $name ) {
			$name .= ' (' . $issuer['CN'] . ')';
		}

		$names = array();
		$san   = (string) ( $cert['extensions']['subjectAltName'] ?? '' );
		foreach ( explode( ',', $san ) as $part ) {
			$part = trim( $part );
			if ( 0 === stripos( $part, 'DNS:' ) ) {
				$names[] = strtolower( substr( $part, 4 ) );
			}
		}
		if ( array() === $names && isset( $cert['subject']['CN'] ) ) {
			$names[] = strtolower( (string) $cert['subject']['CN'] );
		}

		$type = (string) ( $cert['key_type'] ?? '' );
		$bits = (int) ( $cert['key_bits'] ?? 0 );

		return array(
			'valid_to'   => isset( $cert['validTo_time_t'] ) ? gmdate( 'Y-m-d', (int) $cert['validTo_time_t'] ) : '',
			'valid_from' => isset( $cert['validFrom_time_t'] ) ? gmdate( 'Y-m-d', (int) $cert['validFrom_time_t'] ) : '',
			'issuer'     => $name,
			'names'      => array_values( array_unique( $names ) ),
			'key_type'   => '' === $type ? '' : trim( $type . ( $bits > 0 ? ' ' . $bits : '' ) ),
		);
	}

	/**
	 * Default reader: a real TLS connection that records the certificate, valid or not.
	 *
	 * @param string $host Host.
	 * @param int    $port Port.
	 * @return array<string, mixed>
	 */
	private static function connect( string $host, int $port ): array {
		if ( ! function_exists( 'stream_socket_client' ) || ! function_exists( 'openssl_x509_parse' ) ) {
			return array( 'error' => __( 'This server cannot open a TLS connection.', 'estasoft-handover-passport' ) );
		}
		$context = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'verify_peer'       => false,
					'verify_peer_name'  => false,
					'SNI_enabled'       => true,
					'peer_name'         => $host,
				),
			)
		);
		$errno  = 0;
		$errstr = '';
		$stream = @stream_socket_client( 'ssl://' . $host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is handled below.
		if ( ! is_resource( $stream ) ) {
			return array( 'error' => '' !== $errstr ? $errstr : __( 'The connection failed.', 'estasoft-handover-passport' ) );
		}
		$params = stream_context_get_params( $stream );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A network stream, not a file.
		$cert = $params['options']['ssl']['peer_certificate'] ?? null;
		if ( null === $cert ) {
			return array( 'error' => __( 'No certificate was presented.', 'estasoft-handover-passport' ) );
		}
		$parsed = openssl_x509_parse( $cert );
		if ( ! is_array( $parsed ) ) {
			return array( 'error' => __( 'The certificate could not be read.', 'estasoft-handover-passport' ) );
		}
		$key = openssl_pkey_get_public( $cert );
		if ( false !== $key ) {
			$details = openssl_pkey_get_details( $key );
			if ( is_array( $details ) ) {
				$types                = array( OPENSSL_KEYTYPE_RSA => 'RSA', OPENSSL_KEYTYPE_EC => 'EC', OPENSSL_KEYTYPE_DSA => 'DSA' );
				$parsed['key_type']   = $types[ $details['type'] ?? -1 ] ?? '';
				$parsed['key_bits']   = (int) ( $details['bits'] ?? 0 );
			}
		}
		return $parsed;
	}
}
