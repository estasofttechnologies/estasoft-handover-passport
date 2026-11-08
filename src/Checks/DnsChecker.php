<?php
/**
 * Read a domain's DNS records with this server's own resolver (DOM-2, DOM-3).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Checks;

use Closure;
use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * The result is labelled as seen from this server. A different resolver elsewhere may see something else.
 */
final class DnsChecker {

	/**
	 * Record types compared between checks.
	 */
	public const COMPARED = array( 'a', 'aaaa', 'ns', 'mx', 'txt', 'caa', 'cname_www' );

	/**
	 * Resolver: takes a host and a type name (A, AAAA, NS, MX, TXT, CNAME, CAA) and returns the PHP record arrays, or false on failure.
	 *
	 * @var Closure(string, string): (list<array<string, mixed>>|false)
	 */
	private Closure $resolve;

	/**
	 * Constructor.
	 *
	 * @param callable|null $resolve Resolver, or null for dns_get_record.
	 */
	public function __construct( ?callable $resolve = null ) {
		$this->resolve = null === $resolve ? Closure::fromCallable( array( self::class, 'systemResolve' ) ) : Closure::fromCallable( $resolve );
	}

	/**
	 * Whether a string is a plain domain name we are willing to look up.
	 *
	 * @param string $domain Domain.
	 */
	public static function isDomain( string $domain ): bool {
		$domain = strtolower( rtrim( $domain, '.' ) );
		return strlen( $domain ) <= 253 && 1 === preg_match( '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$|^xn--[a-z0-9.-]+$/', $domain );
	}

	/**
	 * Read the records of a domain.
	 *
	 * @param string $domain Domain name.
	 * @return array<string, mixed> a, aaaa, ns, mx, txt, caa, cname_www, spf, dmarc, errors, checked_at.
	 */
	public function snapshot( string $domain ): array {
		$domain = strtolower( rtrim( trim( $domain ), '.' ) );
		$errors = array();
		$read   = function ( string $host, string $type ) use ( &$errors ): array {
			$rows = ( $this->resolve )( $host, $type );
			if ( false === $rows ) {
				$errors[] = $type . ' ' . $host;
				return array();
			}
			return $rows;
		};

		$a    = $this->values( $read( $domain, 'A' ), 'ip' );
		$aaaa = $this->values( $read( $domain, 'AAAA' ), 'ipv6' );
		$ns   = $this->values( $read( $domain, 'NS' ), 'target' );
		$mx_rows = array();
		foreach ( $read( $domain, 'MX' ) as $row ) {
			$mx_rows[] = array( (int) ( $row['pri'] ?? 0 ), strtolower( rtrim( (string) ( $row['target'] ?? '' ), '.' ) ) );
		}
		usort( $mx_rows, static fn( array $a, array $b ): int => $a[0] <=> $b[0] ?: strcmp( $a[1], $b[1] ) );
		$mx = array_values( array_unique( array_map( static fn( array $row ): string => $row[0] . ' ' . $row[1], $mx_rows ) ) );
		$txt = $this->txt( $read( $domain, 'TXT' ) );
		$caa = array();
		foreach ( $read( $domain, 'CAA' ) as $row ) {
			$caa[] = trim( (int) ( $row['flags'] ?? 0 ) . ' ' . (string) ( $row['tag'] ?? '' ) . ' ' . (string) ( $row['value'] ?? '' ) );
		}
		$www    = $this->values( $read( 'www.' . $domain, 'CNAME' ), 'target' );
		$dmarcs = $this->txt( $read( '_dmarc.' . $domain, 'TXT' ) );

		$spf = '';
		foreach ( $txt as $record ) {
			if ( 0 === stripos( $record, 'v=spf1' ) ) {
				$spf = $record;
				break;
			}
		}
		$dmarc = '';
		foreach ( $dmarcs as $record ) {
			if ( 0 === stripos( $record, 'v=DMARC1' ) ) {
				$dmarc = $record;
				break;
			}
		}

		return array(
			'a'          => $a,
			'aaaa'       => $aaaa,
			'ns'         => $ns,
			'mx'         => $mx,
			'txt'        => $txt,
			'caa'        => $caa,
			'cname_www'  => $www[0] ?? '',
			'spf'        => $spf,
			'dmarc'      => $dmarc,
			'errors'     => array_values( array_unique( $errors ) ),
			'checked_at' => Clock::sql(),
		);
	}

	/**
	 * What changed between two snapshots: the compared types with values added or removed.
	 *
	 * @param array<string, mixed> $before Earlier snapshot fields.
	 * @param array<string, mixed> $after  Later snapshot fields.
	 * @return array<string, array{added: list<string>, removed: list<string>}>
	 */
	public static function changes( array $before, array $after ): array {
		$out    = array();
		$failed = array();
		foreach ( array_merge( (array) ( $before['errors'] ?? array() ), (array) ( $after['errors'] ?? array() ) ) as $error ) {
			$failed[] = strtolower( (string) $error );
		}
		foreach ( self::COMPARED as $type ) {
			// A lookup that failed on either side says nothing about a change.
			$prefix = 'cname_www' === $type ? 'cname www.' : $type . ' ';
			foreach ( $failed as $error ) {
				if ( 0 === strpos( $error, $prefix ) && ! ( 'txt' === $type && 0 === strpos( $error, 'txt _dmarc.' ) ) ) {
					continue 2;
				}
			}
			$old     = self::asList( $before[ $type ] ?? array() );
			$new     = self::asList( $after[ $type ] ?? array() );
			$added   = array_values( array_diff( $new, $old ) );
			$removed = array_values( array_diff( $old, $new ) );
			if ( array() !== $added || array() !== $removed ) {
				$out[ $type ] = array( 'added' => $added, 'removed' => $removed );
			}
		}
		return $out;
	}

	/**
	 * Whether a snapshot says nothing: no record of any kind came back. That is what a server with no working
	 * resolver returns, and it must not replace an earlier snapshot or be reported as "everything was removed".
	 *
	 * @param array<string, mixed> $snapshot Snapshot fields.
	 */
	public static function isEmptyFailure( array $snapshot ): bool {
		foreach ( array( 'a', 'aaaa', 'ns', 'mx', 'txt', 'caa' ) as $type ) {
			if ( array() !== (array) ( $snapshot[ $type ] ?? array() ) ) {
				return false;
			}
		}
		return '' === (string) ( $snapshot['cname_www'] ?? '' );
	}

	/**
	 * Normalise a value to a list of strings.
	 *
	 * @param mixed $value Value.
	 * @return list<string>
	 */
	private static function asList( $value ): array {
		if ( is_string( $value ) ) {
			return '' === $value ? array() : array( $value );
		}
		return array_values( array_map( 'strval', array_filter( (array) $value, 'is_scalar' ) ) );
	}

	/**
	 * Sorted unique values of one key.
	 *
	 * @param list<array<string, mixed>> $rows Record rows.
	 * @param string                     $key  Key.
	 * @return list<string>
	 */
	private function values( array $rows, string $key ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$value = strtolower( rtrim( trim( (string) ( $row[ $key ] ?? '' ) ), '.' ) );
			if ( '' !== $value ) {
				$out[ $value ] = $value;
			}
		}
		$out = array_values( $out );
		sort( $out );
		return $out;
	}

	/**
	 * Text records, with split strings joined.
	 *
	 * @param list<array<string, mixed>> $rows Record rows.
	 * @return list<string>
	 */
	private function txt( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$text = isset( $row['entries'] ) && is_array( $row['entries'] ) ? implode( '', array_map( 'strval', $row['entries'] ) ) : (string) ( $row['txt'] ?? '' );
			$text = trim( $text );
			if ( '' !== $text ) {
				$out[ $text ] = $text;
			}
		}
		$out = array_values( $out );
		sort( $out );
		return $out;
	}

	/**
	 * Default resolver: PHP's dns_get_record.
	 *
	 * @param string $host Host.
	 * @param string $type Record type name.
	 * @return list<array<string, mixed>>|false
	 */
	private static function systemResolve( string $host, string $type ) {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return false;
		}
		$map = array(
			'A'     => DNS_A,
			'AAAA'  => DNS_AAAA,
			'NS'    => DNS_NS,
			'MX'    => DNS_MX,
			'TXT'   => DNS_TXT,
			'CNAME' => DNS_CNAME,
		);
		if ( defined( 'DNS_CAA' ) ) {
			$map['CAA'] = DNS_CAA;
		}
		if ( ! isset( $map[ $type ] ) ) {
			return array();
		}
		$rows = @dns_get_record( $host, $map[ $type ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup is reported through the return value; PHP also raises a warning.
		return is_array( $rows ) ? $rows : false;
	}
}
