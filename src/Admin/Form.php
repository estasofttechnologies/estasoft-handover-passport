<?php
/**
 * Form controls built from a field definition. Every value is escaped where it is printed (SEC-4).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Renders one labelled control per field. Errors are tied to their control for screen readers (ADM-4).
 */
final class Form {

	/**
	 * Print a labelled control.
	 *
	 * @param string               $name   Input name.
	 * @param array<string, mixed> $def    Field definition.
	 * @param mixed                $value  Current value.
	 * @param string               $error  Error message for this field, or ''.
	 * @param array<int, string>   $people Person ID => name, for people fields.
	 */
	public static function row( string $name, array $def, $value, string $error = '', array $people = array() ): void {
		$id       = 'estasoft-hp-f-' . $name;
		$help_id  = $id . '-help';
		$error_id = $id . '-error';
		$type     = (string) $def['type'];
		$described = array();
		if ( ! empty( $def['help'] ) ) {
			$described[] = $help_id;
		}
		if ( '' !== $error ) {
			$described[] = $error_id;
		}
		$aria = array() === $described ? '' : ' aria-describedby="' . esc_attr( implode( ' ', $described ) ) . '"';
		$aria .= '' !== $error ? ' aria-invalid="true"' : '';
		$req   = ! empty( $def['required'] ) ? ' required aria-required="true"' : '';

		echo '<div class="estasoft-hp-field' . ( '' !== $error ? ' has-error' : '' ) . '">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( (string) $def['label'] ) . ( ! empty( $def['required'] ) ? ' <span class="estasoft-hp-req" aria-hidden="true">*</span>' : '' ) . ( ! empty( $def['sensitive'] ) ? ' <span class="estasoft-hp-tag">' . esc_html__( 'Sensitive', 'estasoft-handover-passport' ) . '</span>' : '' ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Parts are escaped or fixed markup.

		switch ( $type ) {
			case 'textarea':
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="4" class="large-text"' . $aria . $req . '>' . esc_textarea( (string) $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $aria and $req are built from escaped parts above.
				break;
			case 'lines':
			case 'links':
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="3" class="large-text"' . $aria . '>' . esc_textarea( implode( "\n", array_map( 'strval', (array) $value ) ) ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- See above.
				break;
			case 'select':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $aria . $req . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- See above.
				echo '<option value="">' . esc_html__( '—', 'estasoft-handover-passport' ) . '</option>';
				foreach ( (array) ( $def['options'] ?? array() ) as $key => $label ) {
					echo '<option value="' . esc_attr( (string) $key ) . '"' . selected( (string) $value, (string) $key, false ) . '>' . esc_html( (string) $label ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a fixed attribute.
				}
				echo '</select>';
				break;
			case 'people':
				$chosen = array_map( 'intval', (array) $value );
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '[]" multiple size="4"' . $aria . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- See above.
				foreach ( $people as $person_id => $person_name ) {
					echo '<option value="' . esc_attr( (string) $person_id ) . '"' . selected( in_array( (int) $person_id, $chosen, true ), true, false ) . '>' . esc_html( $person_name ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a fixed attribute.
				}
				echo '</select>';
				break;
			case 'money':
				$shown = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) && '' !== $value ) ? Fields::moneyDisplay( (int) $value ) : (string) $value;
				echo '<input type="text" inputmode="decimal" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $shown ) . '" class="regular-text"' . $aria . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- See above.
				break;
			case 'date':
				echo '<input type="date" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . $aria . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- See above.
				break;
			case 'int':
				echo '<input type="number" min="0" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="small-text"' . $aria . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- See above.
				break;
			case 'email':
			case 'url':
				echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="regular-text"' . $aria . $req . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- See above.
				break;
			default:
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="regular-text"' . ( ! empty( $def['max'] ) ? ' maxlength="' . esc_attr( (string) (int) $def['max'] ) . '"' : '' ) . $aria . $req . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- See above.
		}

		if ( ! empty( $def['help'] ) ) {
			echo '<p class="description" id="' . esc_attr( $help_id ) . '">' . esc_html( (string) $def['help'] ) . '</p>';
		}
		if ( '' !== $error ) {
			echo '<p class="estasoft-hp-error" id="' . esc_attr( $error_id ) . '" role="alert">' . esc_html( $error ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Print a single checkbox.
	 *
	 * @param string $name    Input name.
	 * @param string $label   Visible label.
	 * @param bool   $checked Whether it is ticked.
	 * @param string $help    Optional help text.
	 */
	public static function checkbox( string $name, string $label, bool $checked, string $help = '' ): void {
		$id = 'estasoft-hp-f-' . $name;
		echo '<div class="estasoft-hp-field estasoft-hp-field--check"><label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( $checked, true, false ) . '> ' . esc_html( $label ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a fixed attribute.
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Print the security token for a form without an element id, so a page with
	 * many forms stays valid HTML.
	 *
	 * @param string $action Nonce action.
	 * @param bool   $referer Whether to add the referer field.
	 */
	public static function nonce( string $action, bool $referer = true ): void {
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
		if ( $referer ) {
			wp_referer_field();
		}
	}
}
