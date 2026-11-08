=== Estasoft Handover Passport ===
Contributors: estasofttechnologies
Tags: documentation, handover, inventory, maintenance, agency
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A living record of your WordPress site for handovers: stack, licences, domains, hosting, backups, owners and a change log.

== Description ==

Estasoft Handover Passport keeps one living record of a WordPress site, so a client, a new developer or a colleague can take over without a week of questions.

**What it does in this version**

* Scans the site on a schedule and when the stack changes, and records WordPress, PHP, database, server, plugins, themes, scheduled tasks and administrators.
* Keeps a change log of what changed and who changed it: plugin installs, updates, activations, theme switches, new administrators and address changes. The log can be downloaded as CSV. Maintenance notes and incident reports (what happened, impact, cause, fix, time to restore, runbook used, follow-ups) sit beside it.
* Keeps registers for licences with renewal dates, domains, hosting, environments, services and backups, each with an owner and a review date.
* Lists the people involved, their roles, the order of emergency contacts and who owns which asset, with a tracker for moving each asset to the client (status, date, who handles it, notes) that feeds the handover checklist.
* Marks every value as Detected, Entered or Imported, with the time of its last change.
* Lets you mark records Sensitive so only people with the right capability can see them.
* Walks you through the first hour with a seven-step setup guide. Every step can be skipped, and skipped steps wait on the Overview as things to do. For the kind of site you choose it can add draft runbooks and the addresses worth watching.
* Refuses to store passwords, keys and tokens in any field, so the Passport is safe to share.
* Checks the record against the site and lists findings in plain words, each stating a fact and a date. A finding never says "safe", "secure" or "compliant".
* Keeps restore tests, runbooks (with twelve ready-made templates, a version history and a large-type print view) and a list of critical addresses, so the day something breaks is not the first day anyone looks for the steps.
* Can email the one-page Emergency sheet as a PDF to a few addresses you choose when a snapshot is made and once a month, so a copy exists outside the site. Off until you switch it on; the sheet never holds a Sensitive record.
* Offers the dates in a calendar file (`.ics`) from the Overview, titles and dates only, with Sensitive records left out. A Check data tool in Settings finds links that point at nothing and records of a kind this version does not know, and removes them only after you confirm.
* Adds Passport shortcuts to the New menu in the admin bar (licence, domain, person, maintenance note) for people who may edit.
* Lets you rehearse a runbook as a drill: each step gets a Done button that keeps the time and the person, followed by an after-restore checklist built from your critical addresses. A drill of a restore runbook can be kept as a restore test.
* Checks DNS, a certificate or a critical address when you press a button, and says "as seen from this server". An optional weekly check asks the critical addresses every Sunday and sends one email when one fails; it is off until you switch it on and it is not an uptime monitor.
* Emails reminders before licence, domain, hosting and service dates, and can send a short weekly summary. Each reminder goes once per date and lead time.
* Gives each person a leaver checklist: every system they can reach, their WordPress account, the assets they hold and the roles they fill, ready to print when someone stops working on the site.
* Pauses reminders on a copy of the site, so a staging site does not send every reminder a second time.
* Shows how many records changed since the last handover someone acknowledged, so a new maintainer sees drift without opening the comparison. An optional summary on the WordPress Dashboard is off until you switch it on.
* Works with WP-CLI (`wp estasoft-passport status --fail-on=needs-fixing`, `findings`, `record`, `scan`, `reminders run --dry-run`, `snapshot`, `export`, `restore-test add`) and a REST API with capability checks on every route (Sensitive records and fields stay hidden from people who may not see them).

**Built for trust**

* No account, no tracking, no ads and no upsell notices. Nothing is sent to ESTASOFT TECHNOLOGIES or any third party.
* Scans read local data only. A DNS, certificate or address check runs only when you press its button (or weekly, if you switch the weekly address check on), and it contacts only your own domain.
* Nothing is added to the front end of your site, and nothing runs there.
* Five capabilities control who can view, edit, export and configure. Administrators have them by default.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate it. You land on the Passport Overview screen.
3. Press Scan now to record the site. The first scan is the baseline; later scans list what changed.

== Frequently Asked Questions ==

= Does it send my data anywhere? =

No. Scans, the change log and the findings stay on your server. The plugin never contacts ESTASOFT TECHNOLOGIES or any third party. The only requests it makes are the checks you start yourself, and they go to your own domain.

= Does it store passwords? =

No. It refuses text that looks like a password, key or token, and tells you which field to fix. Write where a login lives (for example "the shared password manager, folder Clients"), not the login itself.

= How do reminders work? =

Once a day the plugin looks at the records that have a renewal or expiry date. At 60, 30, 14, 7 and 1 days before the date, and on the day, it sends one email to the addresses you list in Settings, Reminders, plus the account holder and payer of that record when they have an email address. You can change the days in Settings or for one record, and a record can have its reminders switched off. Pressing Renewed on a record and entering the next date starts the reminders again for the new date.

= Reminders do not arrive. =

Reminders go out through the email of your WordPress site, so they depend on it working. Open Settings, Reminders, and press Send test email. If a reminder cannot be sent, it is tried again the next day, up to three times, and the Overview says so.

= I copied the site to a staging server. Will it send every reminder again? =

No. When the same data turns up on a different address, the plugin pauses reminders, emailed copies and scheduled checks and asks whether the site moved or this is a copy. Choose "This is a copy" and the reminders stay off there.

= What does Check data do? =

Under Settings, Privacy and data, it lists links that point at a person or a record that no longer exists, and records of a kind this version does not know. These can be left by a failed import, a manual database edit or a downgrade. It changes nothing until you tick the confirmation box and press the button, then looks again and removes only what is still wrong. History notes how many rows were removed, not which.

= Does it work on a multisite network? =

Yes, one site at a time. When you activate it for the whole network, each site keeps its own Passport and gets it set up the first time someone opens that site's dashboard. Nothing is set up, and nothing runs, on a site nobody has opened yet. The network admin shows a note about this. A single view across all the sites is not part of this version.

= How do I report a security problem? =

Please do not post it in the support forum. Use the private vulnerability reporting on the plugin's GitHub page (https://github.com/estasofttechnologies/estasoft-handover-passport/security/advisories/new) or write to ESTASOFT TECHNOLOGIES through https://estasoft.com. A fix that ships is announced in the changelog. Only the latest release receives security fixes.

= Can developers extend it? =

Yes. The plugin has actions and filters for records, findings, reminders, snapshots and exports, and a REST API under `estasoft-passport/v1`. Every hook is described, with an example, in `docs/HOOKS.md`, and the routes in `docs/REST-API.md`, in the plugin folder and on GitHub (https://github.com/estasofttechnologies/estasoft-handover-passport). Changes inside `v1` only add fields and routes.

= What happens to my data if I delete the plugin? =

By default, nothing: the data stays in the database. A setting turns on full removal when the plugin is deleted.

= Who can see Passport? =

People with the `estasoft_hp_view` capability. Administrators have it by default. A separate capability controls who can see Sensitive records.

= Which other software does it include? =

The PDF pack is made by mPDF 8.3.2 (GPL-2.0-only) together with FPDI, DeepCopy and the PSR packages, in the `lib/pdf` folder, with their namespaces renamed so they cannot clash with another plugin. The PDF font is DejaVu Sans (free licence, see `lib/pdf/licenses`). The library never contacts a website and cannot read files outside the plugin. The source and licences of every file are in `lib/pdf`, and `bin/build-pdf-lib.php` rebuilds the folder from the original packages.

= The PDF shows empty boxes for Chinese, Japanese or Korean text. =

The bundled font covers Latin, Cyrillic, Greek, Hebrew and Arabic. For Chinese, Japanese or Korean, put one TrueType font file in `wp-content/uploads/estasoft-handover-passport-fonts/` and the plugin uses it for those characters. Until then, use the HTML pack, which uses your reader's own fonts.

= The PDF cannot be made on my server. =

The PDF needs the PHP extensions GD and mbstring and enough memory. When it cannot be made, the Export screen says why and offers the HTML pack and a print view you can save as PDF from your browser.

== Privacy ==

The plugin stores a record of the site and the names and work email addresses of the people you enter. Nothing is sent to ESTASOFT TECHNOLOGIES or any third party.

The plugin joins the WordPress tools under Tools > Export Personal Data and Tools > Erase Personal Data. For an email address, the export lists the person's entry in the directory, the records that name them, the handover acknowledgements that carry their name, their entry among the administrators found by the scan and the changes they made. The erase replaces the person with "Former contact" and keeps the row, so a role or a record that pointed at them still shows that someone held it. It cannot change saved snapshots, which are fixed by their checksum, or free text in records, so it tells you what remains and how to deal with it.

Reminder emails go to the addresses you list and to the account holder and payer you enter on a record. The email names the record and its date, never a password or key, and a record marked Sensitive is not named in the email. The log of sent reminders keeps the date, the record and the number of recipients, not their addresses.

The emailed copy of the Emergency sheet is off until you switch it on and list at least one address. It then emails the one-page sheet as a PDF to those addresses when a snapshot is made and once a month. Email is not encrypted, so the sheet is made without any record marked Sensitive and never holds a password, key or token. The export log notes each emailed file.

== Screenshots ==

1. The Overview: findings in plain words, how ready the site is for a handover, the dates in the next 90 days and how current the records are. The data shown is made up.
2. The licence register, with renewal dates, status and a Verified badge for each record.
3. People and roles, with the order in which emergency contacts are called.
4. Findings. Each one states a fact and a date, and clears by itself when the fact stops being true.
5. Runbooks: written steps for the day something breaks, started from a template.
6. The handover checklist, filled in by the Passport from what it knows.

== Changelog ==

= 1.0.0 =
* First release: foundation, site scan, change log, capabilities, secret guard, findings, restore tests, runbooks, address checks, email reminders, REST and WP-CLI.

== Upgrade Notice ==

= 1.0.0 =
First release.
