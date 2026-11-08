<?php
/**
 * The Domains tab: the register plus what this server sees for each domain (DOM-2 to DOM-6).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Checks\DnsChecker;
use Estasoft\HandoverPassport\Checks\MailProviders;
use Estasoft\HandoverPassport\Checks\ZoneCompare;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Every statement here is labelled as seen from this server, because a CDN or a resolver in another country can answer differently.
 */
final class DomainView {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Print the tab.
	 *
	 * @param string                $slug Screen slug.
	 * @param array<string, string> $keep Query arguments that keep the tab.
	 */
	public function render( string $slug, array $keep ): void {
		$register = new RegisterView( $this->plugin );
		$editing  = Request::key( 'kind' );
		if ( in_array( Request::key( 'view' ), array( 'edit', 'delete' ), true ) && in_array( $editing, array( Kinds::DOMAIN, Kinds::ZONE ), true ) ) {
			$register->render( $editing, $slug, $keep );
			return;
		}

		$register->render( Kinds::DOMAIN, $slug, $keep, true, true );
		$base    = add_query_arg( array_merge( array( 'page' => $slug ), $keep ), admin_url( 'admin.php' ) );
		$domains = array();
		foreach ( $this->plugin->records()->listKind( Kinds::DOMAIN, Capabilities::canSeeSensitive() ) as $record ) {
			if ( empty( $record['hidden'] ) && '' !== (string) ( $record['fields']['domain'] ?? '' ) ) {
				$domains[] = $record;
			}
		}
		if ( array() !== $domains ) {
			echo '<div class="estasoft-hp-register__head"><h2>' . esc_html__( 'What this server sees', 'estasoft-handover-passport' ) . '</h2></div>';
			echo '<p class="t2">' . esc_html__( 'Press a button to read the records or the certificate from this server. Nothing is checked when a page loads, and each check runs at most once a minute.', 'estasoft-handover-passport' ) . '</p>';
			foreach ( $domains as $record ) {
				$this->panel( $record, $base, $register );
			}
		}

		$register->render( Kinds::ZONE, $slug, $keep );
		$this->comparisons();
	}

	/**
	 * One domain: buttons, DNS facts, mail facts and the certificate.
	 *
	 * @param array<string, mixed> $record   Domain record.
	 * @param string               $base     Tab address.
	 * @param RegisterView         $register Register view, for its hidden fields.
	 */
	private function panel( array $record, string $base, RegisterView $register ): void {
		$domain   = strtolower( (string) $record['fields']['domain'] );
		$dns      = $this->plugin->records()->items()->bySubject( Kinds::DNS, $domain );
		$host     = $this->siteHost( $domain );
		$can_edit = Capabilities::can( Capabilities::EDIT );

		echo '<section class="card estasoft-hp-domain"><div class="hd"><h3>' . esc_html( $domain ) . '</h3>';
		if ( $can_edit ) {
			$this->button( $register, 'check', (int) $record['id'], $base, __( 'Check DNS', 'estasoft-handover-passport' ) );
			if ( '' !== $host ) {
				$this->button( $register, 'check_ssl', (int) $record['id'], $base, __( 'Check certificate', 'estasoft-handover-passport' ) );
			}
		}
		echo '</div><div class="bdy">';

		echo '<h4>' . esc_html__( 'DNS records', 'estasoft-handover-passport' ) . '</h4>';
		if ( null === $dns || ! is_array( $dns['data'] ) ) {
			echo '<p>' . esc_html__( 'DNS has not been read yet.', 'estasoft-handover-passport' ) . '</p>';
		} else {
			$this->dnsFacts( (array) $dns['data'], (array) $record['fields'] );
		}

		echo '<h4>' . esc_html__( 'Certificate', 'estasoft-handover-passport' ) . '</h4>';
		if ( '' === $host ) {
			echo '<p>' . esc_html__( 'A certificate can only be read for this site\'s own address, so it is not read for this domain.', 'estasoft-handover-passport' ) . '</p>';
		} else {
			$cert = $this->plugin->records()->items()->bySubject( Kinds::SSL, $host );
			if ( null === $cert || ! is_array( $cert['data'] ) ) {
				echo '<p>' . esc_html__( 'The certificate has not been read yet.', 'estasoft-handover-passport' ) . '</p>';
			} else {
				$this->certificateFacts( (array) $cert['data'], $host );
			}
		}
		echo '</div></section>';
	}

	/**
	 * The host that belongs to this site for a domain, or an empty string.
	 *
	 * @param string $domain Domain.
	 */
	private function siteHost( string $domain ): string {
		$hosts = $this->plugin->checks()->siteHosts();
		foreach ( array( $domain, 'www.' . $domain ) as $candidate ) {
			if ( in_array( $candidate, $hosts, true ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * A small form with one button that starts a check on a domain record.
	 *
	 * @param RegisterView $register Register view.
	 * @param string       $op       check or check_ssl.
	 * @param int          $id       Record ID.
	 * @param string       $base     Return address.
	 * @param string       $label    Button text.
	 */
	private function button( RegisterView $register, string $op, int $id, string $base, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
		$register->hidden( $op, 'item', $id, $base, Kinds::DOMAIN );
		echo '<button type="submit" class="btn sm">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Facts from a DNS snapshot, the email facts, and what changed since the one before.
	 *
	 * @param array<string, mixed> $snap   Snapshot data.
	 * @param array<string, mixed> $fields Domain record fields.
	 */
	private function dnsFacts( array $snap, array $fields ): void {
		$list  = static fn( $value ): string => implode( ', ', array_map( 'strval', (array) $value ) );
		$yes   = static fn( string $found ): string => '' !== $found ? __( 'Found', 'estasoft-handover-passport' ) : __( 'Not found by this server', 'estasoft-handover-passport' );
		$mx    = (array) ( $snap['mx'] ?? array() );
		$names = MailProviders::name( array_map( 'strval', $mx ) );

		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: date and time. */
				__( 'As seen from this server on %s.', 'estasoft-handover-passport' ),
				Dates::display( (string) ( $snap['checked_at'] ?? '' ), true )
			)
		) . '</p>';
		View::facts(
			array(
				__( 'Name servers', 'estasoft-handover-passport' ) => $list( $snap['ns'] ?? array() ),
				__( 'A (IPv4)', 'estasoft-handover-passport' ) => $list( $snap['a'] ?? array() ),
				__( 'AAAA (IPv6)', 'estasoft-handover-passport' ) => $list( $snap['aaaa'] ?? array() ),
				__( 'www points to', 'estasoft-handover-passport' ) => (string) ( $snap['cname_www'] ?? '' ),
				__( 'Mail servers (MX)', 'estasoft-handover-passport' ) => $list( $mx ),
				__( 'CAA', 'estasoft-handover-passport' ) => $list( $snap['caa'] ?? array() ),
			)
		);

		echo '<h5>' . esc_html__( 'Email DNS facts', 'estasoft-handover-passport' ) . '</h5>';
		View::facts(
			array(
				__( 'Mail provider, named from the MX hosts', 'estasoft-handover-passport' ) => array() === $names ? ( array() === $mx ? '' : __( 'Not recognised', 'estasoft-handover-passport' ) ) : implode( ', ', $names ),
				__( 'SPF record', 'estasoft-handover-passport' )   => $yes( (string) ( $snap['spf'] ?? '' ) ),
				__( 'DMARC record', 'estasoft-handover-passport' ) => $yes( (string) ( $snap['dmarc'] ?? '' ) ),
				__( 'DKIM selector, as entered', 'estasoft-handover-passport' ) => '' === (string) ( $fields['dkim_selector'] ?? '' ) ? __( 'Not entered', 'estasoft-handover-passport' ) : (string) $fields['dkim_selector'],
			)
		);
		echo '<p class="description">' . esc_html__( 'These are facts about the records. They are not a score of how well mail is set up.', 'estasoft-handover-passport' ) . '</p>';

		if ( array() !== (array) ( $snap['errors'] ?? array() ) ) {
			echo '<p class="estasoft-hp-muted">' . esc_html(
				sprintf(
					/* translators: %s: list of lookups. */
					__( 'These lookups did not answer: %s.', 'estasoft-handover-passport' ),
					implode( ', ', array_map( 'strval', (array) $snap['errors'] ) )
				)
			) . '</p>';
		}
		$changes = (array) ( $snap['changes'] ?? array() );
		if ( array() !== $changes ) {
			echo '<h5>' . esc_html__( 'Changed since the check before', 'estasoft-handover-passport' ) . '</h5><ul>';
			foreach ( $changes as $type => $change ) {
				$parts = array();
				if ( ! empty( $change['added'] ) ) {
					/* translators: %s: values. */
					$parts[] = sprintf( __( 'now %s', 'estasoft-handover-passport' ), implode( ', ', array_map( 'strval', (array) $change['added'] ) ) );
				}
				if ( ! empty( $change['removed'] ) ) {
					/* translators: %s: values. */
					$parts[] = sprintf( __( 'was %s', 'estasoft-handover-passport' ), implode( ', ', array_map( 'strval', (array) $change['removed'] ) ) );
				}
				echo '<li><strong>' . esc_html( strtoupper( str_replace( '_', ' ', (string) $type ) ) ) . '</strong>: ' . esc_html( implode( '; ', $parts ) ) . '</li>';
			}
			echo '</ul>';
		}
	}

	/**
	 * Facts from a certificate reading.
	 *
	 * @param array<string, mixed> $cert Certificate data.
	 * @param string               $host Host.
	 */
	private function certificateFacts( array $cert, string $host ): void {
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: host name, 2: date and time. */
				__( 'As seen from this server for %1$s on %2$s. A CDN or proxy may present its own certificate, so this may differ from what a visitor sees.', 'estasoft-handover-passport' ),
				$host,
				Dates::display( (string) ( $cert['checked_at'] ?? '' ), true )
			)
		) . '</p>';
		View::facts(
			array(
				__( 'Valid from', 'estasoft-handover-passport' ) => Dates::displayDate( (string) ( $cert['valid_from'] ?? '' ) ),
				__( 'Valid to', 'estasoft-handover-passport' ) => Dates::displayDate( (string) ( $cert['valid_to'] ?? '' ) ),
				__( 'Issued by', 'estasoft-handover-passport' ) => (string) ( $cert['issuer'] ?? '' ),
				__( 'Names', 'estasoft-handover-passport' ) => implode( ', ', array_map( 'strval', (array) ( $cert['names'] ?? array() ) ) ),
				__( 'Key', 'estasoft-handover-passport' ) => (string) ( $cert['key_type'] ?? '' ),
			)
		);
		if ( '' !== (string) ( $cert['error'] ?? '' ) ) {
			echo '<p class="estasoft-hp-error">' . esc_html(
				sprintf(
					/* translators: %s: reason. */
					__( 'The latest attempt to read the certificate failed: %s. The values above are from the last time it worked.', 'estasoft-handover-passport' ),
					(string) $cert['error']
				)
			) . '</p>';
		}
	}

	/**
	 * Compare each intended zone with the latest DNS check of its domain (DOM-4).
	 */
	private function comparisons(): void {
		$zones = $this->plugin->records()->listKind( Kinds::ZONE, true );
		foreach ( $zones as $zone ) {
			$domain = strtolower( (string) ( $zone['fields']['domain'] ?? '' ) );
			$rows   = ZoneCompare::parse( (string) ( $zone['fields']['rows'] ?? '' ) );
			if ( '' === $domain || array() === $rows ) {
				continue;
			}
			echo '<div class="estasoft-hp-card"><h3>' . esc_html(
				sprintf(
					/* translators: %s: domain. */
					__( 'Intended zone compared with the last check: %s', 'estasoft-handover-passport' ),
					$domain
				)
			) . '</h3>';
			$snapshot = $this->plugin->records()->items()->bySubject( Kinds::DNS, $domain );
			if ( null === $snapshot || ! is_array( $snapshot['data'] ) ) {
				echo '<p>' . esc_html__( 'Read the DNS records of this domain first, then the comparison appears here.', 'estasoft-handover-passport' ) . '</p></div>';
				continue;
			}
			$results = ZoneCompare::compare( $domain, $rows, (array) $snapshot['data'] );
			$sum     = ZoneCompare::summary( $results );
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: matching, 2: differing, 3: missing, 4: skipped. */
					__( '%1$d match, %2$d differ, %3$d missing, %4$d not compared.', 'estasoft-handover-passport' ),
					$sum[ ZoneCompare::MATCHES ],
					$sum[ ZoneCompare::DIFFERS ],
					$sum[ ZoneCompare::MISSING ],
					$sum[ ZoneCompare::SKIPPED ]
				)
			) . '</p>';
			$labels = array(
				ZoneCompare::MATCHES => __( 'Matches', 'estasoft-handover-passport' ),
				ZoneCompare::DIFFERS => __( 'Differs', 'estasoft-handover-passport' ),
				ZoneCompare::MISSING => __( 'Missing', 'estasoft-handover-passport' ),
				ZoneCompare::SKIPPED => __( 'Not compared', 'estasoft-handover-passport' ),
			);
			echo '<table class="widefat striped estasoft-hp-table"><thead><tr>';
			foreach ( array( __( 'Type', 'estasoft-handover-passport' ), __( 'Name', 'estasoft-handover-passport' ), __( 'Intended', 'estasoft-handover-passport' ), __( 'Seen from this server', 'estasoft-handover-passport' ), __( 'Result', 'estasoft-handover-passport' ) ) as $heading ) {
				echo '<th scope="col">' . esc_html( $heading ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $results as $result ) {
				echo '<tr><td>' . esc_html( (string) $result['type'] ) . '</td><td>' . esc_html( (string) $result['scope'] ) . '</td><td>' . esc_html( implode( ', ', (array) $result['expected'] ) ) . '</td><td>' . esc_html( implode( ', ', (array) $result['seen'] ) ) . '</td><td>' . View::badge( 'zone-' . (string) $result['status'], $labels[ (string) $result['status'] ] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			}
			echo '</tbody></table><p class="description">' . esc_html__( 'Only simple record types at the root, www and _dmarc are compared. Other rows are listed as not compared, never as matching.', 'estasoft-handover-passport' ) . '</p></div>';
		}
	}
}
