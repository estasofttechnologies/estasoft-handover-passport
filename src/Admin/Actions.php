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

defined( 'ABSPATH' ) || exit;

/**
 * One admin-post action with an operation and a target.
 */
final class Actions {

	public const ACTION = 'estasoft_hp_record';

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

		if ( ! in_array( $op, array( 'save', 'delete', 'undelete', 'verify', 'move' ), true ) || ! in_array( $target, array( 'item', 'person', 'role' ), true ) ) {
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
			default:
				$records->verify( $id );
				return 'verified';
		}
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
