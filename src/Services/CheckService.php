<?php
/**
 * Runs the DNS, certificate and address checks and keeps their results (DOM-2, DOM-3, DOM-6, DOM-8, URL-2).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Services;

use Estasoft\HandoverPassport\Checks\CheckLimiter;
use Estasoft\HandoverPassport\Checks\DnsChecker;
use Estasoft\HandoverPassport\Checks\SslChecker;
use Estasoft\HandoverPassport\Checks\UrlChecker;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\OutboundGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Every check is started by a person or the command line, never by a page load, and runs at most once a minute
 * per subject.
 */
final class CheckService {

	/**
	 * Constructor.
	 *
	 * @param Plugin          $plugin Plugin.
	 * @param DnsChecker|null $dns    DNS reader, or null for the server's resolver.
	 * @param SslChecker|null $ssl    Certificate reader, or null for a real connection.
	 * @param UrlChecker|null $url    Address checker, or null for the WordPress HTTP API.
	 */
	public function __construct( private Plugin $plugin, private ?DnsChecker $dns = null, private ?SslChecker $ssl = null, private ?UrlChecker $url = null ) {}

	/**
	 * Hosts that belong to this site.
	 *
	 * @return array<int, string>
	 */
	public function siteHosts(): array {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = strtolower( $host );
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Guard that lets a check reach only this site.
	 */
	public function guard(): OutboundGuard {
		return new OutboundGuard(
			$this->siteHosts(),
			static function ( string $host ): array {
				$ips = (array) gethostbynamel( $host );
				if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
					foreach ( (array) @dns_get_record( $host, DNS_AAAA ) as $row ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup returns an empty list.
						if ( isset( $row['ipv6'] ) ) {
							$ips[] = (string) $row['ipv6'];
						}
					}
				}
				return array_values( array_filter( array_map( 'strval', $ips ) ) );
			}
		);
	}

	/**
	 * Check the DNS of a domain and store a dated snapshot.
	 *
	 * @param string $domain  Domain name.
	 * @param string $channel Where the request came from, for the change log.
	 * @return array{status: string, snapshot?: array<string, mixed>, changes?: array<string, mixed>, wait?: int}
	 */
	public function dns( string $domain, string $channel = 'wordpress' ): array {
		$domain = strtolower( rtrim( trim( $domain ), '.' ) );
		if ( ! DnsChecker::isDomain( $domain ) ) {
			return array( 'status' => 'invalid' );
		}
		if ( ! CheckLimiter::take( 'dns', $domain ) ) {
			return array( 'status' => 'wait', 'wait' => CheckLimiter::secondsLeft( 'dns', $domain ) );
		}
		$snapshot = ( $this->dns ?? new DnsChecker() )->snapshot( $domain );
		if ( DnsChecker::isEmptyFailure( $snapshot ) ) {
			return array( 'status' => 'failed', 'snapshot' => $snapshot );
		}

		$records  = $this->plugin->records();
		$previous = $records->items()->bySubject( Kinds::DNS, $domain );
		$changes  = array();
		if ( null !== $previous && is_array( $previous['data'] ) ) {
			$changes = DnsChecker::changes( $previous['data'], $snapshot );
		}
		$snapshot['changed'] = array_keys( $changes );
		$snapshot['changes'] = $changes;
		$snapshot['source']  = 'server';
		$records->saveSystem( Kinds::DNS, $domain, $domain, $snapshot, array(), true );

		if ( array() !== $changes ) {
			$this->plugin->changeLog()->add( 'dns_changed', 'domain', 0, $domain, array( 'seen_before' => array_map( static fn( array $c ): array => $c['removed'], $changes ) ), array( 'seen_now' => array_map( static fn( array $c ): array => $c['added'], $changes ) ), '', $channel );
		}
		$this->plugin->findings()->queue();
		return array( 'status' => 'ok', 'snapshot' => $snapshot, 'changes' => $changes );
	}

	/**
	 * Check the certificate of this site's own host.
	 *
	 * @param string $host Site host name.
	 * @return array{status: string, certificate?: array<string, mixed>, wait?: int}
	 */
	public function ssl( string $host ): array {
		$host = strtolower( trim( $host ) );
		if ( '' === $host || ! in_array( $host, $this->siteHosts(), true ) ) {
			return array( 'status' => 'refused' );
		}
		if ( ! CheckLimiter::take( 'ssl', $host ) ) {
			return array( 'status' => 'wait', 'wait' => CheckLimiter::secondsLeft( 'ssl', $host ) );
		}
		$result = ( $this->ssl ?? new SslChecker( $this->guard() ) )->check( $host );
		if ( '' !== (string) $result['error'] ) {
			// Keep the last good reading, if there is one, and say the new one failed.
			$existing = $this->plugin->records()->items()->bySubject( Kinds::SSL, $host );
			if ( null !== $existing && is_array( $existing['data'] ) && '' !== (string) ( $existing['data']['valid_to'] ?? '' ) ) {
				$existing['data']['error']     = (string) $result['error'];
				$existing['data']['failed_at'] = (string) $result['checked_at'];
				$this->plugin->records()->saveSystem( Kinds::SSL, $host, $host, $existing['data'], array(), true );
			}
			return array( 'status' => 'failed', 'certificate' => $result );
		}
		$result['source'] = 'server';
		$this->plugin->records()->saveSystem( Kinds::SSL, $host, $host, $result, array(), true );
		$this->plugin->findings()->queue();
		return array( 'status' => 'ok', 'certificate' => $result );
	}

	/**
	 * Check one critical address.
	 *
	 * @param int $id Record ID of a Critical URL.
	 * @return array{status: string, result?: array<string, mixed>, wait?: int}
	 */
	public function url( int $id ): array {
		$row = $this->plugin->records()->items()->find( $id );
		if ( null === $row || Kinds::URL !== (string) $row['kind'] || ! empty( $row['deleted_at'] ) ) {
			return array( 'status' => 'missing' );
		}
		if ( ! CheckLimiter::take( 'url', (string) $id ) ) {
			return array( 'status' => 'wait', 'wait' => CheckLimiter::secondsLeft( 'url', (string) $id ) );
		}
		$data               = is_array( $row['data'] ) ? $row['data'] : array();
		$expect             = (int) ( $data['expected_status'] ?? 200 );
		$result             = ( $this->url ?? new UrlChecker( $this->guard() ) )->check( (string) ( $data['address'] ?? '' ), $expect > 0 ? $expect : 200, (string) ( $data['expected_text'] ?? '' ) );
		$data['last_check'] = $result;
		$this->plugin->records()->items()->update( $id, array( 'data' => $data, 'updated_at' => \Estasoft\HandoverPassport\Support\Clock::sql() ) );
		$this->plugin->findings()->queue();
		return array( 'status' => isset( $result['refused'] ) ? 'refused' : 'ok', 'result' => $result );
	}

	/**
	 * Check every critical address.
	 *
	 * @return array{checked: int, failed: int, refused: int, waited: int, wait: int}
	 */
	public function allUrls(): array {
		$out = array( 'checked' => 0, 'failed' => 0, 'refused' => 0, 'waited' => 0, 'wait' => 0 );
		foreach ( $this->plugin->records()->items()->query( array( 'kind' => Kinds::URL, 'deleted_at' => null ), 'id', 'ASC' ) as $row ) {
			$one = $this->url( (int) $row['id'] );
			if ( 'wait' === $one['status'] ) {
				++$out['waited'];
				$out['wait'] = max( $out['wait'], (int) ( $one['wait'] ?? 0 ) );
			} elseif ( 'refused' === $one['status'] ) {
				++$out['refused'];
			} elseif ( 'ok' === $one['status'] ) {
				++$out['checked'];
				if ( empty( $one['result']['ok'] ) ) {
					++$out['failed'];
				}
			}
		}
		return $out;
	}
}
