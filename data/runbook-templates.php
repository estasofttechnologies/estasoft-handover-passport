<?php
/**
 * Runbook templates (RUN-3).
 *
 * The wording is general guidance for a WordPress site. It is a starting point to edit, not advice for one
 * particular host, and it should be reviewed by a WordPress operations professional before a site relies on it.
 * Text in braces, such as {site_url}, is filled from the Passport when a runbook is shown or exported.
 *
 * @package Estasoft\HandoverPassport
 */

defined( 'ABSPATH' ) || exit;

return array(

	'restore_site'     => array(
		'title'         => __( 'Restore the whole site', 'estasoft-handover-passport' ),
		'purpose'       => __( 'Bring {site_url} back from a backup after data loss, a failed change or a hosting problem.', 'estasoft-handover-passport' ),
		'when_to_use'   => __( 'The site is down, broken or missing content, and fixing it in place would take longer than restoring.', 'estasoft-handover-passport' ),
		'owner_role'    => 'technical_maintainer',
		'restore_scope' => 'site',
		'critical'      => 1,
		'prerequisites' => array(
			array( 'what' => __( 'Location of the most recent backup: {backup_location}', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
			array( 'what' => __( 'Access to the hosting control panel or file transfer', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
			array( 'what' => __( 'Access to the database tool or the host\'s restore feature', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
		),
		'steps'         => array(
			array( 'text' => __( 'Tell the site owner ({owner_name}) that a restore is starting and when the site may be unavailable.', 'estasoft-handover-passport' ), 'who' => 'day_to_day_admin', 'minutes' => 5, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Put the site in maintenance mode if the site still loads.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 5, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Take one more copy of the current files and database, even if they are broken, so nothing is lost if the restore goes wrong.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 15, 'warning' => true, 'link' => '' ),
			array( 'text' => __( 'Choose the backup to restore. Note its date and what it contains (files, database or both).', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 5, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Restore the files, then the database, using the host\'s restore feature or by hand.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 30, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Check that the site address in Settings, General matches {site_url}, and clear any caches.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 10, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Switch maintenance mode off.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 2, 'warning' => false, 'link' => '' ),
		),
		'verification'  => array(
			__( 'The home page and the pages on the Critical URLs list answer as expected.', 'estasoft-handover-passport' ),
			__( 'You can sign in to the admin area.', 'estasoft-handover-passport' ),
			__( 'A form sends mail and a test order or other key action completes, if the site has them.', 'estasoft-handover-passport' ),
		),
		'rollback'      => __( 'If the restore makes things worse, put back the extra copy taken in step 3 and ask the host for help.', 'estasoft-handover-passport' ),
	),

	'restore_database' => array(
		'title'         => __( 'Restore only the database', 'estasoft-handover-passport' ),
		'purpose'       => __( 'Bring back posts, settings or orders from a database backup while keeping current files.', 'estasoft-handover-passport' ),
		'when_to_use'   => __( 'Content or settings were lost or damaged, and the files and uploads are fine.', 'estasoft-handover-passport' ),
		'owner_role'    => 'technical_maintainer',
		'restore_scope' => 'database',
		'critical'      => 1,
		'prerequisites' => array(
			array( 'what' => __( 'A database backup and the date it was taken: {backup_location}', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
			array( 'what' => __( 'Access to the database tool or the host\'s restore feature', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
		),
		'steps'         => array(
			array( 'text' => __( 'Put the site in maintenance mode so no new data arrives during the restore.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 5, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Export the current database as it is now. Keep it until the restore is confirmed.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 10, 'warning' => true, 'link' => '' ),
			array( 'text' => __( 'Import the backup. If the backup is from a different site address, replace the old address with {site_url} using a tool that handles serialized data.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 20, 'warning' => true, 'link' => '' ),
			array( 'text' => __( 'Clear caches and save Settings, Permalinks once.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 5, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Switch maintenance mode off.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 2, 'warning' => false, 'link' => '' ),
		),
		'verification'  => array(
			__( 'The content that was missing is back.', 'estasoft-handover-passport' ),
			__( 'Pages on the Critical URLs list answer as expected.', 'estasoft-handover-passport' ),
		),
		'rollback'      => __( 'Import the export taken in step 2 to return to the state before the restore.', 'estasoft-handover-passport' ),
	),

	'restore_files'    => array(
		'title'         => __( 'Restore only files', 'estasoft-handover-passport' ),
		'purpose'       => __( 'Bring back plugin, theme or upload files from a backup while keeping the current database.', 'estasoft-handover-passport' ),
		'when_to_use'   => __( 'Files were deleted, changed or damaged, and the database is fine.', 'estasoft-handover-passport' ),
		'owner_role'    => 'technical_maintainer',
		'restore_scope' => 'files',
		'critical'      => 0,
		'prerequisites' => array(
			array( 'what' => __( 'A file backup and its date: {backup_location}', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
			array( 'what' => __( 'File transfer or control panel access', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
		),
		'steps'         => array(
			array( 'text' => __( 'Decide which folder needs restoring: wp-content/uploads, a plugin, a theme, or all of wp-content.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 5, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Copy the current folder aside before replacing anything.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 10, 'warning' => true, 'link' => '' ),
			array( 'text' => __( 'Restore the folder from the backup.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 20, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Check file ownership and permissions match the rest of the site.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 5, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Clear caches.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 2, 'warning' => false, 'link' => '' ),
		),
		'verification'  => array(
			__( 'Images and downloads load, and the affected plugin or theme works.', 'estasoft-handover-passport' ),
			__( 'Pages on the Critical URLs list answer as expected.', 'estasoft-handover-passport' ),
		),
		'rollback'      => __( 'Put back the folder copied aside in step 2.', 'estasoft-handover-passport' ),
	),

	'rollback_update'  => array(
		'title'         => __( 'Roll back a failed update', 'estasoft-handover-passport' ),
		'purpose'       => __( 'Return to the previous version of a plugin, theme or WordPress after an update broke the site.', 'estasoft-handover-passport' ),
		'when_to_use'   => __( 'A site problem started straight after an update.', 'estasoft-handover-passport' ),
		'owner_role'    => 'technical_maintainer',
		'restore_scope' => '',
		'critical'      => 1,
		'prerequisites' => array(
			array( 'what' => __( 'The name and previous version of what was updated (see the History screen)', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
			array( 'what' => __( 'A copy of the previous version, or a backup taken before the update', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
			array( 'what' => __( 'File transfer or control panel access, in case the admin area is down', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
		),
		'steps'         => array(
			array( 'text' => __( 'Open the History screen and note exactly what changed and when.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 5, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'If the admin area still works, switch off the updated plugin or switch to a default theme.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 5, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'If the admin area is down, rename the plugin or theme folder over file transfer to switch it off.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 10, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Put back the previous version from your copy or backup.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 15, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'If the update changed the database and the old version cannot read it, restore the database backup from before the update instead.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 30, 'warning' => true, 'link' => '' ),
			array( 'text' => __( 'Turn off automatic updates for this item until the cause is known.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 5, 'warning' => false, 'link' => '' ),
		),
		'verification'  => array(
			__( 'The problem that started after the update is gone.', 'estasoft-handover-passport' ),
			__( 'Pages on the Critical URLs list answer as expected.', 'estasoft-handover-passport' ),
		),
		'rollback'      => __( 'If the old version does not help, restore the whole site using the "Restore the whole site" runbook.', 'estasoft-handover-passport' ),
	),

	'move_host'        => array(
		'title'         => __( 'Move to a new host (cut-over)', 'estasoft-handover-passport' ),
		'purpose'       => __( 'Move {site_url} from the current host to a new one with as little downtime as possible.', 'estasoft-handover-passport' ),
		'when_to_use'   => __( 'The site is leaving its current host, by choice or because the host is closing the account.', 'estasoft-handover-passport' ),
		'owner_role'    => 'technical_maintainer',
		'restore_scope' => '',
		'critical'      => 0,
		'prerequisites' => array(
			array( 'what' => __( 'Access to the current host and to the new host', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
			array( 'what' => __( 'Access to the DNS provider for {site_url}, and the current record values', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
			array( 'what' => __( 'Agreement from {owner_name} on the date and time of the move', 'estasoft-handover-passport' ), 'who' => 'site_owner' ),
		),
		'steps'         => array(
			array( 'text' => __( 'A few days before the move, lower the TTL of the main DNS records so the change spreads quickly.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 10, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Copy the files and database to the new host and make the site work there using a temporary address or a hosts-file entry.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 120, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Test the copy: pages, forms, sign-in, and any payments in test mode.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 30, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'On the day, put the old site in maintenance mode, copy the database again, and import it to the new host so no content is lost.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 30, 'warning' => true, 'link' => '' ),
			array( 'text' => __( 'Change the DNS records to point to the new host.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 10, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Keep the old host running until DNS has spread and the new site is confirmed.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 0, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Issue or move the SSL certificate on the new host.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 15, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Update the Passport: hosting record, backups, cron, and who holds the new account.', 'estasoft-handover-passport' ), 'who' => 'day_to_day_admin', 'minutes' => 15, 'warning' => false, 'link' => '' ),
		),
		'verification'  => array(
			__( 'The Check DNS result shows the new records.', 'estasoft-handover-passport' ),
			__( 'Pages on the Critical URLs list answer as expected from the new host.', 'estasoft-handover-passport' ),
			__( 'Scheduled tasks run on the new host.', 'estasoft-handover-passport' ),
		),
		'rollback'      => __( 'Point DNS back to the old host, which was kept running.', 'estasoft-handover-passport' ),
	),

	'renew_ssl'        => array(
		'title'         => __( 'Replace or renew an SSL certificate', 'estasoft-handover-passport' ),
		'purpose'       => __( 'Renew the certificate for {site_url} before it expires, or replace it after a problem.', 'estasoft-handover-passport' ),
		'when_to_use'   => __( 'The certificate is close to expiry, has expired, or browsers show a certificate warning.', 'estasoft-handover-passport' ),
		'owner_role'    => 'technical_maintainer',
		'restore_scope' => '',
		'critical'      => 1,
		'prerequisites' => array(
			array( 'what' => __( 'Who issues the certificate (host, CDN or certificate authority) and who holds that account', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
			array( 'what' => __( 'Access to DNS, if the issuer proves ownership with a DNS record', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
		),
		'steps'         => array(
			array( 'text' => __( 'Run Check SSL in the Passport and note the issuer and the expiry date it reports.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 2, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'If the host or CDN issues it, look at that service for a failed renewal and fix what it names (often a DNS or redirect change).', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 15, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'If you bought the certificate, generate a new request, have it issued, and install the certificate and its chain.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 30, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Restart or reload the web server if the host needs it, and clear any CDN cache.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 5, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Note the new expiry date and who renews it next time in the Passport.', 'estasoft-handover-passport' ), 'who' => 'day_to_day_admin', 'minutes' => 5, 'warning' => false, 'link' => '' ),
		),
		'verification'  => array(
			__( 'Run Check SSL again: the new expiry date shows.', 'estasoft-handover-passport' ),
			__( 'The site opens over HTTPS without a browser warning.', 'estasoft-handover-passport' ),
		),
		'rollback'      => __( 'Reinstall the previous certificate if it is still valid.', 'estasoft-handover-passport' ),
	),

	'hacked_first_hour' => array(
		'title'         => __( 'Recover a hacked site (first hour)', 'estasoft-handover-passport' ),
		'purpose'       => __( 'Limit the damage in the first hour after finding that {site_url} has been compromised.', 'estasoft-handover-passport' ),
		'when_to_use'   => __( 'The site shows content you did not add, redirects visitors, sends spam, or a security tool reports malware.', 'estasoft-handover-passport' ),
		'owner_role'    => 'technical_maintainer',
		'restore_scope' => '',
		'critical'      => 1,
		'prerequisites' => array(
			array( 'what' => __( 'Contact for the host\'s support, who may help isolate the account', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
			array( 'what' => __( 'A clean backup from before the problem began: {backup_location}', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
		),
		'steps'         => array(
			array( 'text' => __( 'Do not delete anything yet. Take a full copy of the files and database as evidence.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 15, 'warning' => true, 'link' => '' ),
			array( 'text' => __( 'Put the site in maintenance mode or ask the host to suspend public access, so visitors are not harmed.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 5, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Tell the site owner ({owner_name}) and the host\'s support what you have found.', 'estasoft-handover-passport' ), 'who' => 'day_to_day_admin', 'minutes' => 10, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Change the passwords of all administrators, the hosting account, the database and file transfer, from a device you trust. Do not store them in the Passport.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 20, 'warning' => true, 'link' => '' ),
			array( 'text' => __( 'Check the list of administrators and remove any you do not recognise (see Access in the Passport).', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 5, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Decide: restore from a clean backup, or clean the site by hand. Restoring is usually faster and safer.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 5, 'warning' => false, 'link' => '' ),
		),
		'verification'  => array(
			__( 'No unknown administrators remain.', 'estasoft-handover-passport' ),
			__( 'The cause is known or the host has been asked to find it, before the site goes back online.', 'estasoft-handover-passport' ),
		),
		'rollback'      => __( 'Keep the evidence copy until the host and the site owner agree the case is closed.', 'estasoft-handover-passport' ),
	),

	'regain_admin'     => array(
		'title'         => __( 'Regain access to a lost admin account', 'estasoft-handover-passport' ),
		'purpose'       => __( 'Get back into the WordPress admin area when the only working sign-in is lost.', 'estasoft-handover-passport' ),
		'when_to_use'   => __( 'The administrator cannot sign in and the password reset e-mail does not arrive or is not available.', 'estasoft-handover-passport' ),
		'owner_role'    => 'technical_maintainer',
		'restore_scope' => '',
		'critical'      => 0,
		'prerequisites' => array(
			array( 'what' => __( 'Access to the database tool, file transfer, or the command line on the server', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
			array( 'what' => __( 'Confirmation from {owner_name} that the request is genuine', 'estasoft-handover-passport' ), 'who' => 'site_owner' ),
		),
		'steps'         => array(
			array( 'text' => __( 'Confirm the request is genuine by speaking to the site owner using a number already on record.', 'estasoft-handover-passport' ), 'who' => 'day_to_day_admin', 'minutes' => 10, 'warning' => true, 'link' => '' ),
			array( 'text' => __( 'Try the password reset page first and check the spam folder of the address on the account.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 5, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'If that fails, use WP-CLI on the server to set a new password, or create a new administrator, or set the password from the database tool.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 10, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Sign in, set a strong password and a second sign-in step, and check the list of administrators.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 10, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Fix the reason the reset e-mail failed, such as outgoing mail settings.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 15, 'warning' => false, 'link' => '' ),
		),
		'verification'  => array(
			__( 'The right person can sign in and the password reset e-mail arrives.', 'estasoft-handover-passport' ),
		),
		'rollback'      => __( 'Remove any temporary administrator you created.', 'estasoft-handover-passport' ),
	),

	'deploy_staging'   => array(
		'title'         => __( 'Deploy from staging to production', 'estasoft-handover-passport' ),
		'purpose'       => __( 'Move tested changes from the staging site to {site_url} safely.', 'estasoft-handover-passport' ),
		'when_to_use'   => __( 'A change has been built and tested on staging and is ready to go live.', 'estasoft-handover-passport' ),
		'owner_role'    => 'technical_maintainer',
		'restore_scope' => '',
		'critical'      => 0,
		'prerequisites' => array(
			array( 'what' => __( 'A recent backup of production: {backup_location}', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
			array( 'what' => __( 'The list of what changed and whether the database changed too', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
		),
		'steps'         => array(
			array( 'text' => __( 'Take a fresh backup of production and note its time.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 10, 'warning' => true, 'link' => '' ),
			array( 'text' => __( 'Choose a quiet time and tell the site owner ({owner_name}).', 'estasoft-handover-passport' ), 'who' => 'day_to_day_admin', 'minutes' => 5, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Copy the changed files to production. Do not copy the whole database over live orders or comments unless that is planned.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 20, 'warning' => true, 'link' => '' ),
			array( 'text' => __( 'Apply any database changes in the order tested on staging.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 15, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Clear caches, then test the pages on the Critical URLs list.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 10, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Check that staging is not indexed by search engines and is not sending real e-mail.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 5, 'warning' => false, 'link' => '' ),
		),
		'verification'  => array(
			__( 'The change works on production and the Critical URLs answer as expected.', 'estasoft-handover-passport' ),
		),
		'rollback'      => __( 'Restore the backup taken in step 1 using the "Restore the whole site" runbook.', 'estasoft-handover-passport' ),
	),

	'maintenance_mode' => array(
		'title'         => __( 'Put the site in maintenance mode', 'estasoft-handover-passport' ),
		'purpose'       => __( 'Show visitors a short notice while work is done on {site_url}.', 'estasoft-handover-passport' ),
		'when_to_use'   => __( 'Before a restore, a move or any change that could leave the site half working.', 'estasoft-handover-passport' ),
		'owner_role'    => 'technical_maintainer',
		'restore_scope' => '',
		'critical'      => 0,
		'prerequisites' => array(
			array( 'what' => __( 'The way this site does it: a plugin, the host\'s tool, or a maintenance file', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer' ),
		),
		'steps'         => array(
			array( 'text' => __( 'Switch maintenance mode on using the method noted above.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 3, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Open the site in a private window to confirm the notice shows and that it returns a "temporarily unavailable" status, not a normal page.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 2, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Do the work.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 0, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Switch maintenance mode off and clear caches.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 3, 'warning' => false, 'link' => '' ),
		),
		'verification'  => array(
			__( 'The site opens normally in a private window.', 'estasoft-handover-passport' ),
		),
		'rollback'      => __( 'If the site stays stuck in maintenance mode, remove the maintenance file or switch off the plugin over file transfer.', 'estasoft-handover-passport' ),
	),

	'hand_to_maintainer' => array(
		'title'         => __( 'Hand the site to a new maintainer', 'estasoft-handover-passport' ),
		'purpose'       => __( 'Give a new person or company what they need to look after {site_url}, and take the old access away.', 'estasoft-handover-passport' ),
		'when_to_use'   => __( 'Someone new is taking over the day-to-day care of the site.', 'estasoft-handover-passport' ),
		'owner_role'    => 'site_owner',
		'restore_scope' => '',
		'critical'      => 0,
		'prerequisites' => array(
			array( 'what' => __( 'A recent handover snapshot taken from the Passport', 'estasoft-handover-passport' ), 'who' => 'day_to_day_admin' ),
			array( 'what' => __( 'A safe way to pass over passwords, such as a password manager share. Never e-mail them.', 'estasoft-handover-passport' ), 'who' => 'site_owner' ),
		),
		'steps'         => array(
			array( 'text' => __( 'Review the Passport together: stack, licences, domains, hosting, backups and people.', 'estasoft-handover-passport' ), 'who' => 'day_to_day_admin', 'minutes' => 60, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Transfer ownership of each account that is in the agency\'s name to the client or the new maintainer (see Ownership).', 'estasoft-handover-passport' ), 'who' => 'site_owner', 'minutes' => 60, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Add the new maintainer to the People directory with the right roles.', 'estasoft-handover-passport' ), 'who' => 'day_to_day_admin', 'minutes' => 10, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Give the new maintainer their own sign-ins; do not share the previous person\'s.', 'estasoft-handover-passport' ), 'who' => 'site_owner', 'minutes' => 20, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Remove the previous maintainer\'s access everywhere and mark them as having left.', 'estasoft-handover-passport' ), 'who' => 'site_owner', 'minutes' => 30, 'warning' => true, 'link' => '' ),
			array( 'text' => __( 'Take a new snapshot and keep a copy.', 'estasoft-handover-passport' ), 'who' => 'day_to_day_admin', 'minutes' => 5, 'warning' => false, 'link' => '' ),
		),
		'verification'  => array(
			__( 'The Findings screen shows no former person who still holds a role or access.', 'estasoft-handover-passport' ),
			__( 'The new maintainer has done a Verify on the main records.', 'estasoft-handover-passport' ),
		),
		'rollback'      => __( 'Access can be given back to the previous maintainer from the same accounts.', 'estasoft-handover-passport' ),
	),

	'contact_tree'     => array(
		'title'         => __( 'Emergency contact tree', 'estasoft-handover-passport' ),
		'purpose'       => __( 'Say who to call, in what order, when {site_url} has a serious problem and who is not available.', 'estasoft-handover-passport' ),
		'when_to_use'   => __( 'The site is down or compromised and the first person you try does not answer.', 'estasoft-handover-passport' ),
		'owner_role'    => 'site_owner',
		'restore_scope' => '',
		'critical'      => 1,
		'prerequisites' => array(
			array( 'what' => __( 'The People directory is up to date, with an urgent way to reach each emergency contact', 'estasoft-handover-passport' ), 'who' => 'day_to_day_admin' ),
		),
		'steps'         => array(
			array( 'text' => __( 'Call the first emergency contact in the Passport. If there is no answer after 10 minutes, call the next one.', 'estasoft-handover-passport' ), 'who' => 'day_to_day_admin', 'minutes' => 10, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'If the problem is with hosting, call the host\'s support line (see Hosting in the Passport) and note the ticket number.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 15, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'If the problem looks like a break-in, follow the "Recover a hacked site" runbook.', 'estasoft-handover-passport' ), 'who' => 'technical_maintainer', 'minutes' => 0, 'warning' => false, 'link' => '' ),
			array( 'text' => __( 'Tell the site owner ({owner_name}) what is known, and keep a short written log of who was called and when.', 'estasoft-handover-passport' ), 'who' => 'day_to_day_admin', 'minutes' => 5, 'warning' => false, 'link' => '' ),
		),
		'verification'  => array(
			__( 'Someone who can act has the problem and knows it is theirs.', 'estasoft-handover-passport' ),
		),
		'rollback'      => __( 'Not applicable.', 'estasoft-handover-passport' ),
	),
);
