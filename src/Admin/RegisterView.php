<?php
/**
 * A register of one kind of record: list, add and edit form, delete confirmation (PRT-3, PRT-9, ADM-8).
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

use Estasoft\HandoverPassport\Domain\Fields;
use Estasoft\HandoverPassport\Domain\Freshness;
use Estasoft\HandoverPassport\Domain\Kinds;
use Estasoft\HandoverPassport\Domain\LicenceStatus;
use Estasoft\HandoverPassport\Plugin;
use Estasoft\HandoverPassport\Security\Capabilities;
use Estasoft\HandoverPassport\Support\Clock;
use Estasoft\HandoverPassport\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Draws one register. The same code serves every kind, driven by its schema.
 */
final class RegisterView {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private Plugin $plugin ) {}

	/**
	 * Print the register for a kind.
	 *
	 * @param string                $kind  Kind key.
	 * @param string                $page  Menu slug.
	 * @param array<string, string> $query Extra query arguments that keep the tab, for example tab=licences.
	 */
	public function render( string $kind, string $page, array $query = array() ): void {
		$def = Kinds::get( $kind );
		if ( null === $def ) {
			return;
		}
		$base = add_query_arg( array( 'page' => $page ) + $query, admin_url( 'admin.php' ) );
		$view = Request::key( 'view', 'list' );
		$id   = Request::int( 'id' );

		if ( Request::key( 'kind' ) !== '' && Request::key( 'kind' ) !== $kind ) {
			$view = 'list'; // The request is for another register on this screen.
		}

		switch ( $view ) {
			case 'edit':
				$this->form( $kind, $def, $base, $id );
				break;
			case 'delete':
				$this->confirmDelete( $kind, $base, $id );
				break;
			default:
				$this->listing( $kind, $def, $base );
		}
	}

	/**
	 * The list of records.
	 *
	 * @param string               $kind Kind key.
	 * @param array<string, mixed> $def  Schema.
	 * @param string               $base Base URL of the screen.
	 */
	private function listing( string $kind, array $def, string $base ): void {
		$can_edit = Capabilities::can( Capabilities::EDIT );
		$records  = $this->plugin->records()->listKind( $kind, Capabilities::canSeeSensitive() );
		$people   = $this->peopleNames();
		$add_url  = add_query_arg( array( 'view' => 'edit', 'kind' => $kind, 'id' => 0 ), $base );

		echo '<div class="estasoft-hp-register" id="register-' . esc_attr( $kind ) . '">';
		echo '<div class="estasoft-hp-register__head"><h2>' . esc_html( (string) $def['plural'] ) . '</h2>';
		if ( $can_edit ) {
			echo '<a class="button button-primary" href="' . esc_url( $add_url ) . '">' . esc_html(
				sprintf(
					/* translators: %s: kind label, for example "Licence". */
					__( 'Add %s', 'estasoft-handover-passport' ),
					mb_strtolower( (string) $def['label'] )
				)
			) . '</a>';
		}
		echo '</div>';

		$this->suggestions( $kind, $records, $base );

		if ( array() === $records ) {
			echo '<div class="estasoft-hp-empty"><p>' . esc_html( $this->emptyText( $kind, $def ) ) . '</p>';
			if ( $can_edit ) {
				echo '<p><a class="button button-primary" href="' . esc_url( $add_url ) . '">' . esc_html(
					sprintf(
						/* translators: %s: kind label. */
						__( 'Add the first %s', 'estasoft-handover-passport' ),
						mb_strtolower( (string) $def['label'] )
					)
				) . '</a></p>';
			}
			echo '</div>';
			$this->deletedList( $kind, $base, $can_edit );
			echo '</div>';
			return;
		}

		$columns   = (array) $def['columns'];
		$facts     = $this->plugin->scanService()->latestFacts();
		$installed = array_fill_keys( array_map( 'strtolower', array_merge( array_keys( (array) ( $facts['plugins'] ?? array() ) ), array_keys( (array) ( $facts['themes'] ?? array() ) ) ) ), true );
		echo '<table class="widefat striped estasoft-hp-table"><thead><tr>';
		foreach ( $columns as $column ) {
			echo '<th scope="col">' . esc_html( $this->columnLabel( $def, (string) $column ) ) . '</th>';
		}
		$timeless = ! empty( $def['timeless'] );
		echo '<th scope="col">' . esc_html__( 'Source', 'estasoft-handover-passport' ) . '</th>';
		if ( ! $timeless ) {
			echo '<th scope="col">' . esc_html__( 'Review', 'estasoft-handover-passport' ) . '</th>';
		}
		if ( Kinds::LICENCE === $kind ) {
			echo '<th scope="col">' . esc_html__( 'Status', 'estasoft-handover-passport' ) . '</th>';
		}
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'estasoft-handover-passport' ) . '</span></th></tr></thead><tbody>';

		foreach ( $records as $record ) {
			echo '<tr>';
			if ( ! empty( $record['hidden'] ) ) {
				echo '<td colspan="' . esc_attr( (string) ( count( $columns ) + ( $timeless ? 1 : 2 ) + ( Kinds::LICENCE === $kind ? 1 : 0 ) ) ) . '"><em>' . esc_html__( 'Sensitive record, hidden', 'estasoft-handover-passport' ) . '</em></td><td></td></tr>';
				continue;
			}
			foreach ( $columns as $i => $column ) {
				$text = $this->cell( $def, (string) $column, $record, $people );
				if ( 0 === $i && $can_edit ) {
					echo '<td><strong><a href="' . esc_url( add_query_arg( array( 'view' => 'edit', 'kind' => $kind, 'id' => $record['id'] ), $base ) ) . '">' . esc_html( '' !== $text ? $text : (string) $record['title'] ) . '</a></strong></td>';
				} else {
					echo '<td>' . esc_html( $text ) . '</td>';
				}
			}
			echo '<td>' . View::badge( (string) $record['source'], $this->sourceLabel( (string) $record['source'] ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			if ( ! $timeless ) {
				echo '<td>' . View::badge( (string) $record['freshness'], Freshness::label( (string) $record['freshness'] ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
			}
			if ( Kinds::LICENCE === $kind ) {
				$status = LicenceStatus::compute( (array) $record['fields'], Clock::today(), (int) $this->plugin->settings()->get( 'thresholds.licence_lead_days', 30 ) );
				echo '<td>' . View::badge( $status, LicenceStatus::label( $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
				$slug = strtolower( (string) ( $record['fields']['slug'] ?? '' ) );
				if ( '' !== $slug && ! isset( $installed[ $slug ] ) && array() !== $installed ) {
					echo ' ' . View::badge( 'unknown', __( 'Not installed', 'estasoft-handover-passport' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes its parts.
				}
				echo '</td>';
			}
			echo '<td class="estasoft-hp-actions">';
			if ( $can_edit ) {
				if ( ! $timeless ) {
					$this->verifyButton( $record, $base );
				}
				$this->kindButtons( $kind, $record, $base );
				echo ' <a href="' . esc_url( add_query_arg( array( 'view' => 'edit', 'kind' => $kind, 'id' => $record['id'] ), $base ) ) . '">' . esc_html__( 'Edit', 'estasoft-handover-passport' ) . '</a>';
				if ( 'detected' !== $record['source'] ) {
					echo ' <a class="estasoft-hp-danger" href="' . esc_url( add_query_arg( array( 'view' => 'delete', 'kind' => $kind, 'id' => $record['id'] ), $base ) ) . '">' . esc_html__( 'Delete', 'estasoft-handover-passport' ) . '</a>';
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$this->deletedList( $kind, $base, $can_edit );
		echo '</div>';
	}

	/**
	 * Records deleted in the last 30 days, with Undo (PRT-9).
	 *
	 * @param string $kind     Kind key.
	 * @param string $base     Screen URL.
	 * @param bool   $can_edit Whether the viewer may edit.
	 */
	private function deletedList( string $kind, string $base, bool $can_edit ): void {
		if ( ! $can_edit ) {
			return;
		}
		$rows = array_values( array_filter( $this->plugin->records()->items()->deleted(), static fn( array $row ): bool => (string) $row['kind'] === $kind ) );
		if ( array() === $rows ) {
			return;
		}
		echo '<details class="estasoft-hp-deleted"><summary>' . esc_html(
			sprintf(
				/* translators: %d: number of deleted records. */
				_n( '%d deleted record, kept for 30 days', '%d deleted records, kept for 30 days', count( $rows ), 'estasoft-handover-passport' ),
				count( $rows )
			)
		) . '</summary><ul>';
		foreach ( $rows as $row ) {
			echo '<li>' . esc_html( (string) $row['title'] ) . ' ';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
			$this->hidden( 'undelete', 'item', (int) $row['id'], $base );
			echo '<button type="submit" class="button-link">' . esc_html__( 'Undo', 'estasoft-handover-passport' ) . '</button></form></li>';
		}
		echo '</ul></details>';
	}

	/**
	 * Add or edit form.
	 *
	 * @param string               $kind Kind key.
	 * @param array<string, mixed> $def  Schema.
	 * @param string               $base Screen URL.
	 * @param int                  $id   Record ID, 0 for new.
	 */
	private function form( string $kind, array $def, string $base, int $id ): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to edit records.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		$record = $id > 0 ? $this->plugin->records()->get( $id, Capabilities::canSeeSensitive() ) : null;
		if ( $id > 0 && ( null === $record || $kind !== $record['kind'] || ! empty( $record['hidden'] ) ) ) {
			echo '<p>' . esc_html__( 'That record was not found.', 'estasoft-handover-passport' ) . ' <a href="' . esc_url( $base ) . '">' . esc_html__( 'Back to the list', 'estasoft-handover-passport' ) . '</a></p>';
			return;
		}

		$state   = FormState::take( 'item:' . $kind . ':' . $id );
		$input   = null === $state ? array() : $state['input'];
		$errors  = null === $state ? array() : $state['errors'];
		$people  = $this->peopleNames();
		$see     = Capabilities::canSeeSensitive();
		$initial = array();
		if ( null === $record ) {
			if ( '' !== Request::text( 'prefill' ) ) {
				$initial[ (string) $def['title'] ] = Request::text( 'prefill' );
			}
			foreach ( array_keys( (array) $def['fields'] ) as $field_key ) {
				if ( '' !== Request::text( 'init_' . $field_key ) ) {
					$initial[ $field_key ] = Request::text( 'init_' . $field_key );
				}
			}
		}

		echo '<div class="estasoft-hp-form"><h2>' . esc_html(
			null === $record ? sprintf(
				/* translators: %s: kind label, for example "Licence". */
				__( 'Add %s', 'estasoft-handover-passport' ),
				mb_strtolower( (string) $def['label'] )
			) : (string) $record['title']
		) . '</h2>';

		if ( array() !== $errors ) {
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html__( 'The record was not saved. Fix the fields below and try again.', 'estasoft-handover-passport' ) . '</p></div>';
		}

		$this->renewPanel( $def, $record, $base, $kind, $id );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$this->hidden( 'save', 'item', $id, $base, $kind );

		$ownership_keys = array_keys( Kinds::ownershipFields() );
		$main           = array();
		$owner          = array();
		foreach ( (array) $def['fields'] as $key => $field ) {
			if ( in_array( (string) $key, $ownership_keys, true ) ) {
				$owner[ $key ] = $field;
			} else {
				$main[ $key ] = $field;
			}
		}

		$this->fieldset( __( 'Details', 'estasoft-handover-passport' ), $main, $kind, $record, $input, $errors, $people, $see, $initial );
		if ( array() !== $owner ) {
			$this->fieldset( __( 'Ownership', 'estasoft-handover-passport' ), $owner, $kind, $record, $input, $errors, $people, $see, array() );
		}

		echo '<fieldset class="estasoft-hp-fieldset"><legend>' . esc_html__( 'Where this record appears', 'estasoft-handover-passport' ) . '</legend>';
		Form::checkbox( 'vis_client', __( 'Include in the Client pack', 'estasoft-handover-passport' ), $this->flag( $input, $record, 'vis_client', (int) ( $def['vis'][0] ?? 0 ) ) );
		Form::checkbox( 'vis_emergency', __( 'Include in the Emergency sheet', 'estasoft-handover-passport' ), $this->flag( $input, $record, 'vis_emergency', (int) ( $def['vis'][1] ?? 0 ) ) );
		Form::checkbox( 'sensitive', __( 'Sensitive record', 'estasoft-handover-passport' ), $this->flag( $input, $record, 'sensitive', 0 ), __( 'People without the Sensitive capability see "Sensitive record, hidden". Sensitive records stay out of packs.', 'estasoft-handover-passport' ) );
		echo '</fieldset>';

		if ( ! empty( $def['due'] ) ) {
			echo '<fieldset class="estasoft-hp-fieldset"><legend>' . esc_html__( 'Reminders', 'estasoft-handover-passport' ) . '</legend>';
			Form::row(
				'reminder_days',
				array(
					'type'  => 'text',
					'label' => __( 'Remind me', 'estasoft-handover-passport' ),
					'max'   => 60,
					'help'  => sprintf(
						/* translators: %s: list of days from Settings, for example "60, 30, 14, 7, 1, 0". */
						__( 'Days before the date, separated by commas. Leave empty to use the list from Settings (%s). Write off to send no reminders for this record.', 'estasoft-handover-passport' ),
						implode( ', ', $this->plugin->reminders()->leadTimes() )
					),
				),
				$input['reminder_days'] ?? ( null !== $record ? (string) ( $record['fields']['reminder_days'] ?? '' ) : '' ),
				$errors['reminder_days'] ?? ''
			);
			echo '</fieldset>';
		}

		if ( empty( $def['timeless'] ) ) {
			echo '<fieldset class="estasoft-hp-fieldset"><legend>' . esc_html__( 'Review', 'estasoft-handover-passport' ) . '</legend>';
			Form::row(
				'review_days',
				array( 'type' => 'int', 'label' => __( 'Review every (days)', 'estasoft-handover-passport' ), 'help' => __( 'Leave empty to use the interval from Settings.', 'estasoft-handover-passport' ) ),
				$input['review_days'] ?? ( null !== $record && $record['review_days'] !== $this->plugin->settings()->reviewDays( (string) $def['section'] ) ? $record['review_days'] : '' )
			);
			echo '</fieldset>';
		}

		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'estasoft-handover-passport' ) . '</button> <a class="button" href="' . esc_url( $base ) . '">' . esc_html__( 'Cancel', 'estasoft-handover-passport' ) . '</a></p>';
		echo '</form></div>';
	}

	/**
	 * The Renewed panel: enter the next date and the reminders start again for it (LIC-7).
	 *
	 * @param array<string, mixed>      $def    Schema.
	 * @param array<string, mixed>|null $record Record, or null on a new one.
	 * @param string                    $base   Screen URL.
	 * @param string                    $kind   Kind key.
	 * @param int                       $id     Record ID.
	 */
	private function renewPanel( array $def, ?array $record, string $base, string $kind, int $id ): void {
		if ( null === $record || empty( $def['due'] ) ) {
			return;
		}
		$current = (string) ( $record['fields'][ (string) $def['due'] ] ?? '' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-renew">';
		$this->hidden( 'renew', 'item', $id, $base, $kind );
		echo '<h3>' . esc_html__( 'Renewed?', 'estasoft-handover-passport' ) . '</h3>';
		echo '<p class="description">' . esc_html(
			'' === $current
				? __( 'Enter the next date. Reminders start again for it.', 'estasoft-handover-passport' )
				: sprintf(
					/* translators: %s: the current date. */
					__( 'The current date is %s. Enter the next one. Reminders start again for it and History records the renewal.', 'estasoft-handover-passport' ),
					Dates::displayDate( $current )
				)
		) . '</p>';
		Form::row( 'renew_date', array( 'type' => 'date', 'label' => __( 'Next date', 'estasoft-handover-passport' ) ), '' );
		echo '<p><button type="submit" class="button">' . esc_html__( 'Renewed', 'estasoft-handover-passport' ) . '</button></p></form>';
	}

	/**
	 * Print a group of fields.
	 *
	 * @param string                    $legend Group title.
	 * @param array<string, mixed>      $fields Field definitions.
	 * @param string                    $kind   Kind.
	 * @param array<string, mixed>|null $record Record or null.
	 * @param array<string, mixed>      $input  Rejected input, if any.
	 * @param array<string, string>     $errors Errors by key.
	 * @param array<int, string>        $people Person names by ID.
	 * @param bool                      $see    Whether the viewer may see Sensitive values.
	 * @param array<string, mixed>      $initial Starting values for a new record.
	 */
	private function fieldset( string $legend, array $fields, string $kind, ?array $record, array $input, array $errors, array $people, bool $see, array $initial ): void {
		echo '<fieldset class="estasoft-hp-fieldset"><legend>' . esc_html( $legend ) . '</legend>';
		foreach ( $fields as $key => $field ) {
			if ( ! empty( $field['sensitive'] ) && ! $see ) {
				continue; // Not posted, so the stored value stays as it is.
			}
			if ( $this->isLocked( $record, $kind, (string) $key ) ) {
				echo '<div class="estasoft-hp-field"><span class="estasoft-hp-label">' . esc_html( (string) $field['label'] ) . '</span> <strong>' . esc_html( (string) ( $record['fields'][ $key ] ?? '' ) ) . '</strong> <span class="description">' . esc_html__( 'Detected, so it cannot be changed here.', 'estasoft-handover-passport' ) . '</span></div>';
				continue;
			}
			if ( array_key_exists( $key, $input ) ) {
				$value = $input[ $key ];
			} elseif ( array_key_exists( $key, $initial ) ) {
				$value = $initial[ $key ];
			} elseif ( 'people' === $field['type'] ) {
				$value = $record['owners'][ $key ] ?? array();
			} else {
				$value = $record['fields'][ $key ] ?? ( $field['default'] ?? '' );
			}
			Form::row( 'f[' . $key . ']', $field, $value, $errors[ $key ] ?? '', $people );
		}
		echo '</fieldset>';
	}

	/**
	 * Confirmation page before a delete (PRT-9).
	 *
	 * @param string $kind Kind key.
	 * @param string $base Screen URL.
	 * @param int    $id   Record ID.
	 */
	private function confirmDelete( string $kind, string $base, int $id ): void {
		if ( ! Capabilities::can( Capabilities::EDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to delete records.', 'estasoft-handover-passport' ), '', array( 'response' => 403 ) );
		}
		$record = $this->plugin->records()->get( $id, Capabilities::canSeeSensitive() );
		if ( null === $record || $kind !== $record['kind'] || ! empty( $record['hidden'] ) ) {
			echo '<p>' . esc_html__( 'That record was not found.', 'estasoft-handover-passport' ) . '</p>';
			return;
		}
		echo '<div class="estasoft-hp-form"><h2>' . esc_html__( 'Delete this record?', 'estasoft-handover-passport' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: record title. */
				__( '"%s" is hidden for 30 days. You can undo this until then, and the deletion is written to the change log.', 'estasoft-handover-passport' ),
				(string) $record['title']
			)
		) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$this->hidden( 'delete', 'item', $id, $base, $kind );
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Delete', 'estasoft-handover-passport' ) . '</button> <a class="button" href="' . esc_url( $base ) . '">' . esc_html__( 'Keep it', 'estasoft-handover-passport' ) . '</a></form></div>';
	}

	/**
	 * Hidden fields every record form carries: action, nonce and return address.
	 *
	 * @param string $op     save, delete, undelete or verify.
	 * @param string $target item or person.
	 * @param int    $id     Record ID.
	 * @param string $return_to Address to return to.
	 * @param string $kind   Kind key for items.
	 */
	public function hidden( string $op, string $target, int $id, string $return_to, string $kind = '' ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( Actions::ACTION ) . '">';
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		echo '<input type="hidden" name="target" value="' . esc_attr( $target ) . '">';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		echo '<input type="hidden" name="kind" value="' . esc_attr( $kind ) . '">';
		echo '<input type="hidden" name="return" value="' . esc_url( $return_to ) . '">';
		Form::nonce( Actions::ACTION, false );
	}

	/**
	 * The Verify button for a row (PRT-3).
	 *
	 * @param array<string, mixed> $record Record.
	 * @param string               $base   Screen URL.
	 */
	private function verifyButton( array $record, string $base ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
		$this->hidden( 'verify', 'item', (int) $record['id'], $base );
		echo '<button type="submit" class="button-link">' . esc_html__( 'Verify', 'estasoft-handover-passport' ) . '</button></form>';
	}

	/**
	 * Suggested records from detected plugins that are not yet recorded (SCN-5, BKP-2).
	 *
	 * @param string                           $kind    Kind key.
	 * @param array<int, array<string, mixed>> $records Existing records.
	 * @param string                           $base    Screen URL.
	 */
	private function suggestions( string $kind, array $records, string $base ): void {
		if ( ! in_array( $kind, array( Kinds::SERVICE, Kinds::BACKUP ), true ) || ! Capabilities::can( Capabilities::EDIT ) ) {
			return;
		}
		$facts = $this->plugin->scanService()->latestFacts();
		$known = array();
		foreach ( $records as $record ) {
			$known[ strtolower( (string) $record['title'] ) ] = true;
		}
		$list = array();
		foreach ( ( new \Estasoft\HandoverPassport\Scan\ServiceMap() )->suggest( (array) ( $facts['plugins'] ?? array() ) ) as $hit ) {
			$is_backup = 'backup' === $hit['kind'];
			if ( ( Kinds::BACKUP === $kind ) !== $is_backup || isset( $known[ strtolower( $hit['plugin'] ) ] ) ) {
				continue;
			}
			$list[] = $hit;
		}
		if ( array() === $list ) {
			return;
		}
		echo '<div class="notice notice-info inline estasoft-hp-suggest"><p><strong>' . esc_html__( 'Found on this site', 'estasoft-handover-passport' ) . '</strong> ' . esc_html__( 'These plugins usually need a record. Add one so nothing is forgotten.', 'estasoft-handover-passport' ) . '</p><ul>';
		foreach ( $list as $hit ) {
			echo '<li>' . esc_html( $hit['plugin'] ) . ' — ' . esc_html( $hit['service'] ) . ' ';
			echo '<a href="' . esc_url( add_query_arg( array( 'view' => 'edit', 'kind' => $kind, 'id' => 0, 'prefill' => $hit['plugin'] ), $base ) ) . '">' . esc_html__( 'Add', 'estasoft-handover-passport' ) . '</a></li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Text for an empty register (ADM-8).
	 *
	 * @param string               $kind Kind key.
	 * @param array<string, mixed> $def  Schema.
	 */
	private function emptyText( string $kind, array $def ): string {
		$hints = array(
			Kinds::LICENCE => __( 'Premium plugins, themes and paid services belong here, with the date each one renews.', 'estasoft-handover-passport' ),
			Kinds::DOMAIN  => __( 'List every domain the site uses, who registered it and when it expires.', 'estasoft-handover-passport' ),
			Kinds::HOST    => __( 'Record who hosts the site, the plan and how to reach support.', 'estasoft-handover-passport' ),
			Kinds::ENV     => __( 'List production, staging and development, and how code moves between them.', 'estasoft-handover-passport' ),
			Kinds::LAYER   => __( 'Record the CDN, DNS provider, email sending and caching that sit around the site.', 'estasoft-handover-passport' ),
			Kinds::SERVICE => __( 'Payment, email, analytics and other outside services the site depends on.', 'estasoft-handover-passport' ),
			Kinds::BACKUP  => __( 'Say how the site is backed up, where the copies are and who can restore them.', 'estasoft-handover-passport' ),
			Kinds::ACCESS  => __( 'Say who can sign in to which system, and where those credentials are kept. Never the credentials themselves.', 'estasoft-handover-passport' ),
			Kinds::ASSET   => __( 'Certificates, repositories and other assets that someone must keep an eye on.', 'estasoft-handover-passport' ),
		);
		return $hints[ $kind ] ?? sprintf(
			/* translators: %s: plural kind label. */
			__( 'No %s recorded yet.', 'estasoft-handover-passport' ),
			mb_strtolower( (string) $def['plural'] )
		);
	}

	/**
	 * Column heading for a field or a computed column.
	 *
	 * @param array<string, mixed> $def    Schema.
	 * @param string               $column Column key.
	 */
	private function columnLabel( array $def, string $column ): string {
		return (string) ( $def['fields'][ $column ]['label'] ?? ucfirst( str_replace( '_', ' ', $column ) ) );
	}

	/**
	 * Text for one cell.
	 *
	 * @param array<string, mixed> $def    Schema.
	 * @param string               $column Column key.
	 * @param array<string, mixed> $record Record.
	 * @param array<int, string>   $people Person names by ID.
	 */
	private function cell( array $def, string $column, array $record, array $people ): string {
		if ( 'last_check' === $column ) {
			return self::checkText( (array) ( $record['fields']['last_check'] ?? array() ) );
		}
		$field = $def['fields'][ $column ] ?? array( 'type' => 'text' );
		$value = $record['fields'][ $column ] ?? '';
		switch ( (string) $field['type'] ) {
			case 'select':
				return (string) ( $field['options'][ (string) $value ] ?? $value );
			case 'money':
				$text = '' === $value ? '' : Fields::moneyDisplay( (int) $value );
				return '' !== $text && ! empty( $record['fields']['currency'] ) ? $text . ' ' . strtoupper( (string) $record['fields']['currency'] ) : $text;
			case 'date':
				return Dates::displayDate( '' === $value ? null : (string) $value );
			case 'lines':
			case 'links':
				return implode( ', ', array_map( 'strval', (array) $value ) );
			case 'people':
				return implode( ', ', array_map( static fn( $pid ): string => $people[ (int) $pid ] ?? '', (array) ( $record['owners'][ $column ] ?? array() ) ) );
			default:
				return (string) $value;
		}
	}

	/**
	 * The last result of an address check in words.
	 *
	 * @param array<string, mixed> $check Stored result.
	 */
	public static function checkText( array $check ): string {
		if ( array() === $check ) {
			return __( 'Not checked yet', 'estasoft-handover-passport' );
		}
		$when = Dates::displayDate( substr( (string) ( $check['at'] ?? '' ), 0, 10 ) );
		if ( ! empty( $check['refused'] ) ) {
			/* translators: %s: date. */
			return sprintf( __( 'Not checked on %s: refused', 'estasoft-handover-passport' ), $when );
		}
		if ( ! empty( $check['ok'] ) ) {
			/* translators: 1: HTTP status, 2: date. */
			return sprintf( __( 'Answered %1$d on %2$s', 'estasoft-handover-passport' ), (int) ( $check['status'] ?? 0 ), $when );
		}
		/* translators: 1: reason, 2: date. */
		return sprintf( __( 'Failed on %2$s: %1$s', 'estasoft-handover-passport' ), (string) ( $check['reason'] ?? '' ), $when );
	}

	/**
	 * One-press buttons that belong to a kind: Confirm, Reviewed, Check now.
	 *
	 * @param string               $kind   Kind key.
	 * @param array<string, mixed> $record Record.
	 * @param string               $base   Screen URL.
	 */
	private function kindButtons( string $kind, array $record, string $base ): void {
		$buttons = array(
			Kinds::BACKUP => array( 'stamp', __( 'Confirm it ran', 'estasoft-handover-passport' ) ),
			Kinds::ACCESS => array( 'stamp', __( 'Reviewed', 'estasoft-handover-passport' ) ),
			Kinds::URL    => array( 'check', __( 'Check now', 'estasoft-handover-passport' ) ),
		);
		if ( isset( $buttons[ $kind ] ) ) {
			echo ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="estasoft-hp-inline">';
			$this->hidden( $buttons[ $kind ][0], 'item', (int) $record['id'], $base, $kind );
			echo '<button type="submit" class="button-link">' . esc_html( $buttons[ $kind ][1] ) . '</button></form>';
		}
	}

	/**
	 * Words for a source.
	 *
	 * @param string $source detected, entered or imported.
	 */
	private function sourceLabel( string $source ): string {
		$map = array(
			'detected' => __( 'Detected', 'estasoft-handover-passport' ),
			'entered'  => __( 'Entered', 'estasoft-handover-passport' ),
			'imported' => __( 'Imported', 'estasoft-handover-passport' ),
		);
		return $map[ $source ] ?? $source;
	}

	/**
	 * Person names by ID, for people fields.
	 *
	 * @return array<int, string>
	 */
	private function peopleNames(): array {
		$out = array();
		foreach ( $this->plugin->people()->all() as $person ) {
			$out[ (int) $person['id'] ] = (string) $person['name'];
		}
		return $out;
	}

	/**
	 * A checkbox flag from rejected input, the stored record or a default.
	 *
	 * @param array<string, mixed>      $input   Rejected input.
	 * @param array<string, mixed>|null $record  Stored record.
	 * @param string                    $key     Flag key.
	 * @param int                       $fallback Default.
	 */
	private function flag( array $input, ?array $record, string $key, int $fallback ): bool {
		if ( array() !== $input ) {
			return ! empty( $input[ $key ] );
		}
		return null !== $record ? ! empty( $record[ $key ] ) : (bool) $fallback;
	}

	/**
	 * Whether a field of a Detected record is fixed.
	 *
	 * @param array<string, mixed>|null $record Record.
	 * @param string                    $kind   Kind.
	 * @param string                    $key    Field key.
	 */
	private function isLocked( ?array $record, string $kind, string $key ): bool {
		return null !== $record && 'detected' === $record['source'] && (string) ( Kinds::get( $kind )['subject'] ?? '' ) === $key;
	}
}
