<?php
/**
 * Handlers for the forms on register screens: save, delete, undo and verify (CAP-3, SEC-2).
 *
 * Every handler checks the capability and a nonce before it changes anything, and sends the person
 * back to the screen they came from.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\ValidationError;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Security\SecretFound;
use Estasoft\HandoverPassport\Services\PeopleService;
use Estasoft\HandoverPassport\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * One admin-post action with an operation and a target.
 */
final class Actions {

	public const ACTION = 'estasoft_hp_record';

	/**
	 * Date field that a one-press button sets to today, by kind (BKP-3, ACC-4).
	 */
	private const STAMPS = array(
		Kinds::BACKUP => 'last_confirmed',
		Kinds::ACCESS => 'last_reviewed',
	);

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Attach the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Entry point.
	 */
	public function handle(): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to change records.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		$op     = Request::post( 'op' );
		$target = Request::post( 'target' );
		$id     = absint( Request::post( 'id' ) );
		$kind   = sanitize_key( Request::post( 'kind' ) );
		$return = $this->returnUrl();

		if ( ! in_array( $op, array( 'save', 'delete', 'undelete', 'verify', 'move', 'stamp', 'check', 'check_ssl', 'check_urls' ), true ) || ! in_array( $target, array( 'item', 'person', 'role' ), true ) ) {
			wp_safe_redirect( $return );
			exit;
		}

		try {
			if ( 'role' === $target ) {
				$notice = $this->role( $op, $id, $kind );
			} else {
				$notice = 'item' === $target ? $this->item( $op, $id, $kind ) : $this->person( $op, $id );
			}
		} catch ( ValidationError $e ) {
			$this->reject( $target, $kind, $id, $return, $this->errorKey( $target, $kind, $e->field() ), $e->getMessage() );
		} catch ( SecretFound $e ) {
			$this->reject( $target, $kind, $id, $return, $this->errorKey( $target, $kind, strtok( $e->field(), '.' ) ?: '' ), $e->getMessage() );
		}

		wp_safe_redirect( add_query_arg( 'estasoft_hp_notice', $notice, $return ) );
		exit;
	}

	/**
	 * Run an operation on an item record.
	 *
	 * @param string $op   Operation.
	 * @param int    $id   Record ID.
	 * @param string $kind Kind key.
	 * @return string Notice key.
	 * @throws ValidationError When the input is invalid.
	 * @throws SecretFound     When a field looks like a secret.
	 */
	private function item( string $op, int $id, string $kind ): string {
		$records = $this->plugin->records();
		if ( $id > 0 && ! $this->mayTouch( $records->get( $id, true ) ) ) {
			return 'denied';
		}
		switch ( $op ) {
			case 'save':
				if ( null === Kinds::get( $kind ) ) {
					return 'denied';
				}
				$input = $this->itemInput( $kind );
				if ( $id > 0 ) {
					$records->update( $id, $input );
				} else {
					$records->create( $kind, $input );
				}
				return 'saved';
			case 'delete':
				$records->delete( $id );
				return 'deleted';
			case 'undelete':
				$records->undelete( $id );
				return 'restored';
			case 'stamp':
				$record = $records->get( $id, true );
				$field  = self::STAMPS[ (string) ( $record['kind'] ?? '' ) ] ?? '';
				if ( '' === $field ) {
					return 'denied';
				}
				$records->update( $id, array( $field => Clock::today() ) );
				return Kinds::BACKUP === $record['kind'] ? 'confirmed' : 'reviewed';
			case 'check':
			case 'check_ssl':
			case 'check_urls':
				return $this->check( $op, $id, $records->get( $id, true ) );
			default:
				$records->verify( $id );
				return 'verified';
		}
	}

	/**
	 * Start a DNS, certificate or address check and turn its result into a notice key.
	 *
	 * @param string                    $op     check, check_ssl or check_urls.
	 * @param int                       $id     Record ID, or 0 for all addresses.
	 * @param array<string, mixed>|null $record The record, if any.
	 * @return string Notice key.
	 */
	private function check( string $op, int $id, ?array $record ): string {
		$checks = $this->plugin->checks();
		if ( 'check_urls' === $op ) {
			$out = $checks->allUrls();
			if ( 0 === $out['checked'] + $out['refused'] + $out['waited'] ) {
				return 'urls_none';
			}
			if ( 0 === $out['checked'] + $out['refused'] ) {
				return 'check_wait'; // Every address was checked within the last minute.
			}
			return $out['failed'] > 0 || $out['refused'] > 0 ? 'urls_failed' : 'urls_done';
		}
		if ( null === $record ) {
			return 'denied';
		}
		$kind = (string) $record['kind'];
		if ( 'check' === $op && Kinds::URL === $kind ) {
			$one = $checks->url( $id );
			if ( 'wait' === $one['status'] ) {
				return 'check_wait';
			}
			if ( 'refused' === $one['status'] ) {
				return 'check_refused';
			}
			return 'ok' === $one['status'] && ! empty( $one['result']['ok'] ) ? 'url_ok' : 'url_failed';
		}
		if ( Kinds::DOMAIN !== $kind ) {
			return 'denied';
		}
		$domain = strtolower( (string) ( $record['fields']['domain'] ?? '' ) );
		if ( 'check' === $op ) {
			$one = $checks->dns( $domain, 'screen' );
			return array(
				'ok'      => 'dns_done',
				'wait'    => 'check_wait',
				'invalid' => 'dns_invalid',
			)[ $one['status'] ] ?? 'dns_failed';
		}
		$host = '';
		foreach ( array( $domain, 'www.' . $domain ) as $candidate ) {
			if ( in_array( $candidate, $checks->siteHosts(), true ) ) {
				$host = $candidate;
				break;
			}
		}
		if ( '' === $host ) {
			return 'ssl_refused';
		}
		$one = $checks->ssl( $host );
		return array(
			'ok'      => 'ssl_done',
			'wait'    => 'check_wait',
			'refused' => 'ssl_refused',
		)[ $one['status'] ] ?? 'ssl_failed';
	}

	/**
	 * Run an operation on a person.
	 *
	 * @param string $op Operation.
	 * @param int    $id Person ID.
	 * @return string Notice key.
	 * @throws ValidationError When the input is invalid.
	 * @throws SecretFound     When a field looks like a secret.
	 */
	private function person( string $op, int $id ): string {
		$people = $this->plugin->people();
		switch ( $op ) {
			case 'save':
				$input = $this->personInput();
				if ( $id > 0 ) {
					$people->update( $id, $input );
				} else {
					$people->create( $input );
				}
				return 'saved';
			case 'delete':
				$people->delete( $id );
				return 'deleted';
			case 'undelete':
				$people->undelete( $id );
				return 'restored';
			default:
				$people->verify( $id );
				return 'verified';
		}
	}

	/**
	 * Move a person up or down inside a role, which sets the emergency call order (PPL-3).
	 *
	 * @param string $op        Operation.
	 * @param int    $person_id Person ID.
	 * @param string $role      Role key.
	 * @return string Notice key.
	 */
	private function role( string $op, int $person_id, string $role ): string {
		if ( 'move' !== $op || ! array_key_exists( $role, PeopleService::roles() ) ) {
			return 'denied';
		}
		$people = $this->plugin->people();
		$ids    = $people->inRole( $role );
		$at     = array_search( $person_id, $ids, true );
		if ( false === $at ) {
			return 'denied';
		}
		$to = 'up' === Request::post( 'dir' ) ? $at - 1 : $at + 1;
		if ( $to < 0 || $to >= count( $ids ) ) {
			return 'saved';
		}
		[ $ids[ $at ], $ids[ $to ] ] = array( $ids[ $to ], $ids[ $at ] );
		$people->setRole( $role, $ids );
		return 'saved';
	}

	/**
	 * Posted values for an item: its fields plus flags. Sensitive fields are skipped for people who may not see them.
	 *
	 * @param string $kind Kind key.
	 * @return array<string, mixed>
	 */
	private function itemInput( string $kind ): array {
		$def    = Kinds::get( $kind ) ?? array( 'fields' => array() );
		$posted = Request::postedFields();
		$input  = array();
		foreach ( array_keys( (array) $def['fields'] ) as $key ) {
			if ( array_key_exists( $key, $posted ) ) {
				$input[ $key ] = $posted[ $key ];
			} elseif ( 'people' === ( $def['fields'][ $key ]['type'] ?? '' ) ) {
				$input[ $key ] = array(); // An empty multi-select posts nothing; it means "nobody".
			}
		}
		if ( ! Capabilities::canSeeSensitive() ) {
			foreach ( \Estasoft\HandoverPassport\Domain\Sensitivity::fieldKeys( $kind ) as $key ) {
				unset( $input[ $key ] );
			}
		}
		foreach ( array( 'vis_client', 'vis_emergency', 'sensitive' ) as $flag ) {
			$input[ $flag ] = '1' === Request::post( $flag ) ? '1' : '0';
		}
		$input['review_days'] = Request::post( 'review_days' );
		return $input;
	}

	/**
	 * Posted values for a person.
	 *
	 * @return array<string, mixed>
	 */
	private function personInput(): array {
		$posted = Request::postedFields();
		$input  = array();
		foreach ( array_keys( PeopleService::fields() ) as $key ) {
			if ( array_key_exists( $key, $posted ) ) {
				$input[ $key ] = $posted[ $key ];
			}
		}
		$input['roles'] = isset( $posted['roles'] ) && is_array( $posted['roles'] ) ? $posted['roles'] : array();
		$share          = array();
		foreach ( array_keys( PeopleService::defaultShare() ) as $flag ) {
			$share[ $flag ] = '1' === Request::post( 'share_' . $flag );
		}
		$input['share']       = $share;
		$input['review_days'] = Request::post( 'review_days' );
		return $input;
	}

	/**
	 * Whether the current user may change a record: a hidden Sensitive record is off limits without the capability.
	 *
	 * @param array<string, mixed>|null $record Record or null.
	 */
	private function mayTouch( ?array $record ): bool {
		return null !== $record && ( ! $record['sensitive'] || Capabilities::canSeeSensitive() );
	}

	/**
	 * Keep the rejected input and return to the form.
	 *
	 * @param string $target  item or person.
	 * @param string $kind    Kind key.
	 * @param int    $id      Record ID.
	 * @param string $return  Return address.
	 * @param string $key     Field key with the problem, or ''.
	 * @param string $message Error text.
	 */
	private function reject( string $target, string $kind, int $id, string $return, string $key, string $message ): void {
		$input  = Request::postedFields();
		$input += array(
			'vis_client'    => Request::post( 'vis_client' ),
			'vis_emergency' => Request::post( 'vis_emergency' ),
			'sensitive'     => Request::post( 'sensitive' ),
			'review_days'   => Request::post( 'review_days' ),
		);
		$scope  = 'item' === $target ? 'item:' . $kind . ':' . $id : 'person:' . $id;
		FormState::put( $this->plugin->secretGuard(), $scope, $input, array( '' !== $key ? $key : '_form' => $message ) );
		$args = array( 'view' => 'edit', 'id' => $id );
		if ( 'item' === $target ) {
			$args['kind'] = $kind;
		}
		wp_safe_redirect( add_query_arg( $args, $return ) );
		exit;
	}

	/**
	 * Find the field key whose label matches an error.
	 *
	 * @param string $target item or person.
	 * @param string $kind   Kind key.
	 * @param string $label  Field label from the exception.
	 */
	private function errorKey( string $target, string $kind, string $label ): string {
		$fields = 'item' === $target ? (array) ( Kinds::get( $kind )['fields'] ?? array() ) : PeopleService::fields();
		foreach ( $fields as $key => $field ) {
			if ( (string) $field['label'] === $label ) {
				return (string) $key;
			}
		}
		return '';
	}

	/**
	 * The address to return to: only inside the Passport admin pages (open-redirect guard).
	 */
	private function returnUrl(): string {
		$raw      = Request::postedUrl( 'return' );
		$fallback = admin_url( 'admin.php?page=' . Admin::MENU_SLUG );
		if ( '' === $raw || 0 !== strpos( $raw, admin_url( 'admin.php' ) ) ) {
			return $fallback;
		}
		return $raw;
	}
}
