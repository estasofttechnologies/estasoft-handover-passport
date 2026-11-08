<?php
/**
 * Compares an intended DNS zone with a snapshot (DOM-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Checks;

use Estasoft\HandoverPassport\Support\Str;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions: no WordPress calls, so they are easy to test. Simple record types only (A, AAAA, CNAME, MX, NS, TXT, CAA)
 * at the apex, for www and for _dmarc. Other names are reported as skipped, never as matching.
 */
final class ZoneCompare {

	public const MATCHES = 'matches';
	public const DIFFERS = 'differs';
	public const MISSING = 'missing';
	public const SKIPPED = 'skipped';

	private const TYPES = array( 'A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT', 'CAA' );

	/**
	 * Read rows from pasted text: "TYPE, name, value, ttl, purpose" lines or BIND-style lines.
	 *
	 * @param string $text Zone text.
	 * @return array<int, array{type: string, name: string, value: string, ttl: string, purpose: string}>
	 */
	public static function parse( string $text ): array {
		$rows = array();
		foreach ( Str::split( '/\R/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || ';' === $line[0] || '#' === $line[0] ) {
				continue;
			}
			$row = preg_match( '/[,\t|]/', $line ) ? self::fromDelimited( $line ) : self::fromBind( $line );
			if ( null !== $row ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Compare rows with one snapshot.
	 *
	 * @param string                                                                                     $domain   Domain the zone belongs to.
	 * @param array<int, array{type: string, name: string, value: string, ttl: string, purpose: string}> $rows     Parsed rows.
	 * @param array<string, mixed>                                                                       $snapshot Snapshot data.
	 * @return array<int, array{type: string, scope: string, status: string, expected: array<int, string>, seen: array<int, string>}>
	 */
	public static function compare( string $domain, array $rows, array $snapshot ): array {
		$groups = array();
		foreach ( $rows as $row ) {
			$scope = self::scope( $domain, $row['name'] );
			$key   = $scope . '|' . $row['type'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'type' => $row['type'], 'scope' => $scope, 'expected' => array() );
			}
			$groups[ $key ]['expected'][] = self::normalise( $row['type'], $row['value'] );
		}

		$out = array();
		foreach ( $groups as $group ) {
			$seen = self::seen( $group['scope'], $group['type'], $snapshot );
			if ( null === $seen ) {
				$out[] = array( 'type' => $group['type'], 'scope' => $group['scope'], 'status' => self::SKIPPED, 'expected' => $group['expected'], 'seen' => array() );
				continue;
			}
			$seen     = array_values( array_unique( array_map( static fn( string $v ): string => self::normalise( $group['type'], $v ), $seen ) ) );
			$expected = array_values( array_unique( $group['expected'] ) );
			sort( $seen );
			sort( $expected );
			if ( array() === $seen ) {
				$status = self::MISSING;
			} elseif ( 'TXT' === $group['type'] ) {
				$status = array() === array_diff( $expected, $seen ) ? self::MATCHES : self::DIFFERS;
			} else {
				$status = $expected === $seen ? self::MATCHES : self::DIFFERS;
			}
			$out[] = array( 'type' => $group['type'], 'scope' => $group['scope'], 'status' => $status, 'expected' => $expected, 'seen' => $seen );
		}
		return $out;
	}

	/**
	 * Counts by status.
	 *
	 * @param array<int, array{status: string}> $results Output of compare().
	 * @return array{matches: int, differs: int, missing: int, skipped: int}
	 */
	public static function summary( array $results ): array {
		$sum = array( self::MATCHES => 0, self::DIFFERS => 0, self::MISSING => 0, self::SKIPPED => 0 );
		foreach ( $results as $result ) {
			++$sum[ $result['status'] ];
		}
		return $sum;
	}

	/**
	 * A row written as separated values.
	 *
	 * @param string $line Line.
	 * @return array{type: string, name: string, value: string, ttl: string, purpose: string}|null
	 */
	private static function fromDelimited( string $line ): ?array {
		$parts = array_map( 'trim', Str::split( '/[,\t|]/', $line ) );
		$type  = strtoupper( (string) ( $parts[0] ?? '' ) );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return null;
		}
		return array(
			'type'    => $type,
			'name'    => (string) ( $parts[1] ?? '@' ),
			'value'   => (string) ( $parts[2] ?? '' ),
			'ttl'     => (string) ( $parts[3] ?? '' ),
			'purpose' => (string) ( $parts[4] ?? '' ),
		);
	}

	/**
	 * A row written like a zone file: name [ttl] [IN] TYPE value.
	 *
	 * @param string $line Line.
	 * @return array{type: string, name: string, value: string, ttl: string, purpose: string}|null
	 */
	private static function fromBind( string $line ): ?array {
		$tokens = Str::split( '/\s+/', $line );
		foreach ( $tokens as $index => $token ) {
			if ( ! in_array( strtoupper( $token ), self::TYPES, true ) ) {
				continue;
			}
			$before = array_slice( $tokens, 0, $index );
			$ttl    = '';
			$name   = '@';
			foreach ( $before as $word ) {
				if ( ctype_digit( $word ) ) {
					$ttl = $word;
				} elseif ( 'IN' !== strtoupper( $word ) ) {
					$name = $word;
				}
			}
			return array(
				'type'    => strtoupper( $token ),
				'name'    => $name,
				'value'   => trim( implode( ' ', array_slice( $tokens, $index + 1 ) ) ),
				'ttl'     => $ttl,
				'purpose' => '',
			);
		}
		return null;
	}

	/**
	 * Where a name belongs: apex, www, dmarc or other.
	 *
	 * @param string $domain Domain.
	 * @param string $name   Name as written.
	 */
	private static function scope( string $domain, string $name ): string {
		$name   = strtolower( rtrim( trim( $name ), '.' ) );
		$domain = strtolower( rtrim( $domain, '.' ) );
		if ( '' === $name || '@' === $name || $name === $domain ) {
			return 'apex';
		}
		if ( 'www' === $name || 'www.' . $domain === $name ) {
			return 'www';
		}
		if ( '_dmarc' === $name || '_dmarc.' . $domain === $name ) {
			return 'dmarc';
		}
		return 'other';
	}

	/**
	 * What the snapshot saw for a scope and type, or null when it does not read that.
	 *
	 * @param string               $scope    Scope.
	 * @param string               $type     Record type.
	 * @param array<string, mixed> $snapshot Snapshot data.
	 * @return array<int, string>|null
	 */
	private static function seen( string $scope, string $type, array $snapshot ): ?array {
		$list = static fn( string $key ): array => array_values( array_filter( array_map( 'strval', (array) ( $snapshot[ $key ] ?? array() ) ), static fn( string $v ): bool => '' !== $v ) );
		if ( 'apex' === $scope ) {
			$map = array( 'A' => 'a', 'AAAA' => 'aaaa', 'NS' => 'ns', 'MX' => 'mx', 'TXT' => 'txt', 'CAA' => 'caa' );
			return isset( $map[ $type ] ) ? $list( $map[ $type ] ) : null;
		}
		if ( 'www' === $scope && 'CNAME' === $type ) {
			$value = (string) ( $snapshot['cname_www'] ?? '' );
			return '' === $value ? array() : array( $value );
		}
		if ( 'dmarc' === $scope && 'TXT' === $type ) {
			$value = (string) ( $snapshot['dmarc'] ?? '' );
			return '' === $value ? array() : array( $value );
		}
		return null;
	}

	/**
	 * A value in the form used for comparing.
	 *
	 * @param string $type  Record type.
	 * @param string $value Value.
	 */
	private static function normalise( string $type, string $value ): string {
		$value = trim( $value );
		if ( 'TXT' === $type ) {
			$value = trim( str_replace( array( '" "', '"' ), array( '', '' ), $value ) );
		}
		$value = strtolower( preg_replace( '/\s+/', ' ', $value ) ?? $value );
		if ( in_array( $type, array( 'NS', 'CNAME', 'MX' ), true ) ) {
			$value = rtrim( $value, '.' );
		}
		return $value;
	}
}
