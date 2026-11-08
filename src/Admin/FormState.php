<?php
/**
 * Keeps a rejected form for one redirect, so a person does not retype it.
 *
 * Text that the secret guard flagged is never kept, so a refused secret is not written to the database.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Security\SecretGuard;

defined( 'ABSPATH' ) || exit;

/**
 * A short-lived, per-user copy of the last rejected input.
 */
final class FormState {

	private const TTL = 300;

	/**
	 * Store the input and errors.
	 *
	 * @param SecretGuard          $guard  Secret guard.
	 * @param string               $scope  What the form was for, for example "item:licence:0".
	 * @param array<string, mixed> $input  Raw input.
	 * @param array<string, string> $errors Error message by field key.
	 */
	public static function put( SecretGuard $guard, string $scope, array $input, array $errors ): void {
		$safe = self::strip( $guard, $input );
		set_transient( self::key(), array( 'scope' => $scope, 'input' => $safe, 'errors' => $errors ), self::TTL );
	}

	/**
	 * Take the stored state once, if it is for this form.
	 *
	 * @param string $scope Form scope.
	 * @return array{input: array<string, mixed>, errors: array<string, string>}|null
	 */
	public static function take( string $scope ): ?array {
		$state = get_transient( self::key() );
		if ( ! is_array( $state ) || ( $state['scope'] ?? '' ) !== $scope ) {
			return null;
		}
		delete_transient( self::key() );
		return array(
			'input'  => (array) ( $state['input'] ?? array() ),
			'errors' => (array) ( $state['errors'] ?? array() ),
		);
	}

	/**
	 * Transient key for the current user.
	 */
	private static function key(): string {
		return 'estasoft_hp_form_' . get_current_user_id();
	}

	/**
	 * Blank every string the guard flags.
	 *
	 * @param SecretGuard $guard Guard.
	 * @param mixed       $value Value.
	 * @return mixed
	 */
	private static function strip( SecretGuard $guard, $value ) {
		if ( is_array( $value ) ) {
			return array_map( static fn( $item ) => self::strip( $guard, $item ), $value );
		}
		if ( is_string( $value ) && null !== $guard->inspect( $value ) ) {
			return '';
		}
		return $value;
	}
}
