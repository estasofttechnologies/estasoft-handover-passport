<?php
/**
 * Outbound request guard (SEC-8): own host only, no private or metadata addresses.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Pure checks used before the plugin contacts the site's own address.
 */
final class OutboundGuard {

	/**
	 * Host names that point at cloud metadata services.
	 */
	private const METADATA_HOSTS = array( 'metadata.google.internal', 'metadata', 'instance-data' );

	/**
	 * Addresses of cloud metadata services.
	 */
	private const METADATA_IPS = array( '169.254.169.254', '169.254.170.2', '100.100.100.200', 'fd00:ec2::254' );

	/**
	 * Hosts that belong to this site, lower case.
	 *
	 * @var array<int, string>
	 */
	private array $site_hosts;

	/**
	 * Whether the site itself runs on a local address (a development site).
	 *
	 * @var bool
	 */
	private bool $site_is_local;

	/**
	 * Resolver returning the addresses of a host name.
	 *
	 * @var callable(string): array<int, string>
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $site_hosts    Host names of the site.
	 * @param callable           $resolver      Takes a host name and returns a list of its IP addresses.
	 * @param bool|null          $site_is_local Override the local-site detection.
	 */
	public function __construct( array $site_hosts, callable $resolver, ?bool $site_is_local = null ) {
		$this->site_hosts = array_values( array_unique( array_map( 'strtolower', array_filter( $site_hosts ) ) ) );
		$this->resolver   = $resolver;

		if ( null === $site_is_local ) {
			$site_is_local = false;
			foreach ( $this->site_hosts as $host ) {
				if ( self::isLocalName( $host ) || $this->allAddressesBlocked( $host ) ) {
					$site_is_local = true;
				}
			}
		}
		$this->site_is_local = $site_is_local;
	}

	/**
	 * Validate an address and return its parts plus the checked IP addresses.
	 *
	 * @param string $url Address to check.
	 * @return array{scheme: string, host: string, port: int, path: string, ips: array<int, string>}
	 * @throws OutboundRefused When the address is not allowed.
	 */
	public function validate( string $url ): array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			throw new OutboundRefused( 'bad_url', __( 'That address could not be read.', 'estasoft-handover-passport' ) );
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			throw new OutboundRefused( 'bad_scheme', __( 'Only http and https addresses can be checked.', 'estasoft-handover-passport' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			throw new OutboundRefused( 'credentials', __( 'Addresses with a user name or password are not checked.', 'estasoft-handover-passport' ) );
		}
		$host = strtolower( trim( (string) $parts['host'], '[]' ) );
		if ( ! in_array( $host, $this->site_hosts, true ) ) {
			throw new OutboundRefused( 'other_host', __( 'Only addresses on this site can be checked.', 'estasoft-handover-passport' ) );
		}
		if ( in_array( $host, self::METADATA_HOSTS, true ) ) {
			throw new OutboundRefused( 'metadata', __( 'That address is not allowed.', 'estasoft-handover-passport' ) );
		}

		$ips = $this->addresses( $host );
		if ( array() === $ips ) {
			throw new OutboundRefused( 'no_dns', __( 'The host name did not resolve to an address.', 'estasoft-handover-passport' ) );
		}
		foreach ( $ips as $ip ) {
			if ( in_array( $ip, self::METADATA_IPS, true ) ) {
				throw new OutboundRefused( 'metadata', __( 'That address is not allowed.', 'estasoft-handover-passport' ) );
			}
			if ( ! $this->site_is_local && self::isBlockedIp( $ip ) ) {
				throw new OutboundRefused( 'private_address', __( 'That address points to a private network, so it was not checked.', 'estasoft-handover-passport' ) );
			}
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		return array(
			'scheme' => $scheme,
			'host'   => $host,
			'port'   => $port,
			'path'   => ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ),
			'ips'    => $ips,
		);
	}

	/**
	 * Resolve a redirect target against the current address and validate it again.
	 *
	 * @param string $current  Address that answered with a redirect.
	 * @param string $location Value of the Location header.
	 * @return array{scheme: string, host: string, port: int, path: string, ips: array<int, string>}
	 * @throws OutboundRefused When the target leaves the site.
	 */
	public function validateRedirect( string $current, string $location ): array {
		return $this->validate( self::absolute( $current, $location ) );
	}

	/**
	 * Turn a Location header into an absolute address.
	 *
	 * @param string $base     Address of the response.
	 * @param string $location Location header.
	 */
	public static function absolute( string $base, string $location ): string {
		if ( 1 === preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $location ) ) {
			return $location;
		}
		$parts = wp_parse_url( $base );
		$root  = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( str_starts_with( $location, '//' ) ) {
			return ( $parts['scheme'] ?? 'https' ) . ':' . $location;
		}
		if ( str_starts_with( $location, '/' ) ) {
			return $root . $location;
		}
		$dir = isset( $parts['path'] ) ? preg_replace( '#/[^/]*$#', '/', (string) $parts['path'] ) : '/';
		return $root . $dir . $location;
	}

	/**
	 * Whether an IP address is private, loopback, link-local, reserved or shared.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 */
	public static function isBlockedIp( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		if ( str_contains( $ip, ':' ) ) {
			$packed = inet_pton( $ip );
			if ( false === $packed ) {
				return true;
			}
			// IPv4-mapped (::ffff:a.b.c.d): judge the embedded IPv4 address.
			if ( str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
				return self::isBlockedIp( (string) inet_ntop( substr( $packed, 12 ) ) );
			}
			$first = ord( $packed[0] );
			$sec   = ord( $packed[1] );
			return str_repeat( "\0", 16 ) === $packed
				|| str_repeat( "\0", 15 ) . "\1" === $packed
				|| ( $first & 0xfe ) === 0xfc                  // fc00::/7 unique local.
				|| ( 0xfe === $first && ( $sec & 0xc0 ) === 0x80 ); // fe80::/10 link local.
		}
		$long = ip2long( $ip );
		if ( false === $long ) {
			return true;
		}
		$ranges = array(
			array( '0.0.0.0', 8 ),
			array( '10.0.0.0', 8 ),
			array( '100.64.0.0', 10 ),
			array( '127.0.0.0', 8 ),
			array( '169.254.0.0', 16 ),
			array( '172.16.0.0', 12 ),
			array( '192.0.0.0', 24 ),
			array( '192.168.0.0', 16 ),
			array( '198.18.0.0', 15 ),
			array( '224.0.0.0', 4 ),
			array( '240.0.0.0', 4 ),
		);
		foreach ( $ranges as $range ) {
			$mask = -1 << ( 32 - $range[1] );
			if ( ( $long & $mask ) === ( ip2long( $range[0] ) & $mask ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a host name is plainly local.
	 *
	 * @param string $host Host name.
	 */
	public static function isLocalName( string $host ): bool {
		$host = strtolower( $host );
		if ( 'localhost' === $host ) {
			return true;
		}
		foreach ( array( '.localhost', '.local', '.test', '.example', '.invalid', '.internal' ) as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve a host to addresses, accepting a literal IP address as is.
	 *
	 * @param string $host Host name.
	 * @return array<int, string>
	 */
	private function addresses( string $host ): array {
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}
		$ips = ( $this->resolver )( $host );
		return array_values( array_unique( array_filter( $ips, 'is_string' ) ) );
	}

	/**
	 * Whether every address of a host is blocked (so the site itself is local).
	 *
	 * @param string $host Host name.
	 */
	private function allAddressesBlocked( string $host ): bool {
		$ips = $this->addresses( $host );
		if ( array() === $ips ) {
			return false;
		}
		foreach ( $ips as $ip ) {
			if ( ! self::isBlockedIp( $ip ) ) {
				return false;
			}
		}
		return true;
	}
}
