<?php
/**
 * One plain sentence for a change-log row.
 *
 * @package Estasoft\HandoverPassport
 */

declare(strict_types=1);

namespace Estasoft\HandoverPassport\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a row into words. Values that could be Sensitive were already masked when the row was written.
 */
final class ChangeText {

	/**
	 * Describe a row.
	 *
	 * @param array<string, mixed> $row Change-log row.
	 */
	public static function describe( array $row ): string {
		$label  = (string) $row['subject_label'];
		$before = (array) $row['before_data'];
		$after  = (array) $row['after_data'];
		switch ( (string) $row['kind'] ) {
			case 'plugin_updated':
			case 'theme_updated':
				/* translators: 1: name, 2: old version, 3: new version. */
				return sprintf( __( '%1$s updated from %2$s to %3$s', 'estasoft-handover-passport' ), $label, (string) ( $before['version'] ?? '' ), (string) ( $after['version'] ?? '' ) );
			case 'plugin_added':
			case 'theme_added':
				/* translators: %s: name. */
				return sprintf( __( '%s added', 'estasoft-handover-passport' ), $label );
			case 'plugin_removed':
			case 'theme_removed':
				/* translators: %s: name. */
				return sprintf( __( '%s removed', 'estasoft-handover-passport' ), $label );
			case 'plugin_activated':
				/* translators: %s: name. */
				return sprintf( __( '%s switched on', 'estasoft-handover-passport' ), $label );
			case 'plugin_deactivated':
				/* translators: %s: name. */
				return sprintf( __( '%s switched off', 'estasoft-handover-passport' ), $label );
			case 'theme_switched':
				/* translators: %s: theme name. */
				return sprintf( __( 'Theme changed to %s', 'estasoft-handover-passport' ), $label );
			case 'administrator_added':
				/* translators: %s: person's name. */
				return sprintf( __( '%s became an administrator', 'estasoft-handover-passport' ), $label );
			case 'wordpress_version_changed':
			case 'php_version_changed':
			case 'site_address_changed':
				/* translators: 1: what changed, 2: old value, 3: new value. */
				return sprintf( __( '%1$s changed from %2$s to %3$s', 'estasoft-handover-passport' ), $label, (string) ( $before['value'] ?? '' ), (string) ( $after['value'] ?? '' ) );
			case 'record_added':
				/* translators: %s: record title. */
				return sprintf( __( 'Added: %s', 'estasoft-handover-passport' ), $label );
			case 'record_edited':
				/* translators: %s: record title. */
				return sprintf( __( 'Edited: %s', 'estasoft-handover-passport' ), $label );
			case 'record_deleted':
				/* translators: %s: record title. */
				return sprintf( __( 'Deleted: %s', 'estasoft-handover-passport' ), $label );
			case 'record_restored':
				/* translators: %s: record title. */
				return sprintf( __( 'Restored: %s', 'estasoft-handover-passport' ), $label );
			case 'record_renewed':
				/* translators: 1: record title, 2: the new date. */
				return sprintf( __( 'Renewed: %1$s, next date %2$s', 'estasoft-handover-passport' ), $label, (string) ( $after['value'] ?? '' ) );
			case 'record_verified':
				/* translators: %s: record title. */
				return sprintf( __( 'Verified: %s', 'estasoft-handover-passport' ), $label );
			case 'copy_answered':
				return 'moved' === (string) ( $after['answer'] ?? '' )
					? __( 'Site address confirmed as the new address of this site', 'estasoft-handover-passport' )
					: __( 'Site marked as a copy; reminders stay off', 'estasoft-handover-passport' );
			case 'person_added':
				/* translators: %s: person's name. */
				return sprintf( __( 'Person added: %s', 'estasoft-handover-passport' ), $label );
			case 'person_edited':
				/* translators: %s: person's name. */
				return sprintf( __( 'Person edited: %s', 'estasoft-handover-passport' ), $label );
			case 'person_left':
				/* translators: %s: person's name. */
				return sprintf( __( '%s left the project', 'estasoft-handover-passport' ), $label );
			case 'person_deleted':
				/* translators: %s: person's name. */
				return sprintf( __( 'Person deleted: %s', 'estasoft-handover-passport' ), $label );
			case 'role_changed':
				/* translators: %s: role name. */
				return sprintf( __( 'People in the role "%s" changed', 'estasoft-handover-passport' ), $label );
			case 'finding_accepted':
				/* translators: %s: finding message. */
				return sprintf( __( 'Finding accepted: %s', 'estasoft-handover-passport' ), $label ) . ( '' !== (string) $row['note'] ? ' (' . (string) $row['note'] . ')' : '' );
			case 'rule_disabled':
				/* translators: %s: rule ID. */
				return sprintf( __( 'Rule %s switched off', 'estasoft-handover-passport' ), $label ) . ( '' !== (string) $row['note'] ? ': ' . (string) $row['note'] : '' );
			case 'rule_enabled':
				/* translators: %s: rule ID. */
				return sprintf( __( 'Rule %s switched on', 'estasoft-handover-passport' ), $label );
			case 'dns_changed':
				return sprintf( __( 'DNS records of %s changed between checks', 'estasoft-handover-passport' ), $label );
			case 'restore_test_logged':
				return sprintf( __( 'Restore test logged: %s', 'estasoft-handover-passport' ), $label );
			case 'restore_test_deleted':
				return sprintf( __( 'Restore test removed: %s', 'estasoft-handover-passport' ), $label );
			case 'runbook_added':
				return sprintf( __( 'Runbook added: %s', 'estasoft-handover-passport' ), $label );
			case 'runbook_edited':
				return sprintf( __( 'Runbook edited: %s', 'estasoft-handover-passport' ), $label );
			case 'runbook_reviewed':
				return sprintf( __( 'Runbook reviewed: %s', 'estasoft-handover-passport' ), $label );
			case 'runbook_rehearsed':
				return sprintf( __( 'Runbook rehearsed: %s', 'estasoft-handover-passport' ), $label );
			case 'runbook_deleted':
				return sprintf( __( 'Runbook deleted: %s', 'estasoft-handover-passport' ), $label );
			case 'runbook_restored':
				return sprintf( __( 'Runbook restored: %s', 'estasoft-handover-passport' ), $label );
			case 'snapshot_created':
				/* translators: %s: snapshot name. */
				return sprintf( __( 'Snapshot made: %s', 'estasoft-handover-passport' ), $label );
			case 'snapshot_deleted':
				/* translators: %s: snapshot name. */
				return sprintf( __( 'Snapshot deleted: %s', 'estasoft-handover-passport' ), $label );
			case 'snapshot_acknowledged':
				/* translators: 1: snapshot name, 2: name of the person who received it. */
				return sprintf( __( 'Snapshot "%1$s" acknowledged by %2$s', 'estasoft-handover-passport' ), $label, (string) ( $after['received_by'] ?? '' ) );
			case 'status_changed':
				/* translators: 1: old status, 2: new status. */
				return sprintf( __( 'Passport status changed from %1$s to %2$s', 'estasoft-handover-passport' ), (string) ( $before['status'] ?? '' ), (string) ( $after['status'] ?? $label ) ) . ( '' !== (string) $row['note'] ? ' (' . (string) $row['note'] . ')' : '' );
			case 'checklist_marked':
				/* translators: %s: checklist item. */
				return sprintf( __( 'Handover checklist updated: %s', 'estasoft-handover-passport' ), $label ) . ( '' !== (string) $row['note'] ? ': ' . (string) $row['note'] : '' );
			case 'settings_changed':
				return __( 'Settings changed', 'estasoft-handover-passport' ) . ( '' !== (string) $row['note'] ? ': ' . (string) $row['note'] : '' );
			case 'maintenance_note':
				return sprintf(
					/* translators: 1: note type, 2: note text. */
					__( 'Note (%1$s): %2$s', 'estasoft-handover-passport' ),
					$label,
					(string) $row['note']
				);
			default:
				return '' !== $label ? $label : (string) $row['kind'];
		}
	}

	/**
	 * Kinds offered in the filter, with plain names.
	 *
	 * @return array<string, string>
	 */
	public static function filterKinds(): array {
		return array(
			'plugin_updated'            => __( 'Plugin updated', 'estasoft-handover-passport' ),
			'plugin_added'              => __( 'Plugin added', 'estasoft-handover-passport' ),
			'plugin_activated'          => __( 'Plugin switched on', 'estasoft-handover-passport' ),
			'plugin_deactivated'        => __( 'Plugin switched off', 'estasoft-handover-passport' ),
			'theme_switched'            => __( 'Theme changed', 'estasoft-handover-passport' ),
			'administrator_added'       => __( 'Administrator added', 'estasoft-handover-passport' ),
			'wordpress_version_changed' => __( 'WordPress version', 'estasoft-handover-passport' ),
			'php_version_changed'       => __( 'PHP version', 'estasoft-handover-passport' ),
			'site_address_changed'      => __( 'Site address', 'estasoft-handover-passport' ),
			'record_added'              => __( 'Record added', 'estasoft-handover-passport' ),
			'record_edited'             => __( 'Record edited', 'estasoft-handover-passport' ),
			'record_deleted'            => __( 'Record deleted', 'estasoft-handover-passport' ),
			'record_renewed'            => __( 'Record renewed', 'estasoft-handover-passport' ),
			'maintenance_note'          => __( 'Maintenance note', 'estasoft-handover-passport' ),
			'finding_accepted'          => __( 'Finding accepted', 'estasoft-handover-passport' ),
			'rule_disabled'             => __( 'Rule switched off', 'estasoft-handover-passport' ),
			'snapshot_created'          => __( 'Snapshot made', 'estasoft-handover-passport' ),
			'snapshot_acknowledged'     => __( 'Snapshot acknowledged', 'estasoft-handover-passport' ),
			'snapshot_deleted'          => __( 'Snapshot deleted', 'estasoft-handover-passport' ),
			'status_changed'            => __( 'Status changed', 'estasoft-handover-passport' ),
		);
	}
}
