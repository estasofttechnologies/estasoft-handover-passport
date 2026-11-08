# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- Foundation: requirements guard, autoloader, capabilities, settings, database schema and upgrade routine.
- Site scan with baseline and differences, stored facts, graceful gaps and a time limit.
- Change log with live capture of plugin, theme, administrator and address changes.
- Secret guard and outbound guard.
- Overview screen, `wp estasoft-passport scan` and REST routes for scans.
- Inventory screen with the Stack view (core, plugins, themes, must-use plugins, scheduled tasks) and registers for licences, domains, hosting, environments, layers and services.
- Backups register on the Safety screen.
- People screen with the directory, roles, the emergency contact order, an ownership gap table and an access list.
- History screen with a filterable change log and maintenance notes.
- Settings screen with review periods, scan schedule, access grants per role and privacy options.
- Source, review and licence status badges; Verify, soft delete with Undo, and add-from-suggestion shortcuts.
- Sensitive records hidden from people without the capability.
- Daily retention clean-up and privacy policy text.
- Findings: a rules engine with stable rule IDs, plain-language results that state a fact and a date, "Not checked" instead of a silent pass, snooze and accept with a reason, and a readiness count for a handover.
- DNS, certificate and critical-address checks started by a button or the command line, limited to once a minute per subject and to the site's own host.
- Restore tests, runbooks with twelve templates and immutable published versions, and a list of critical addresses.
- Domains tab with what this server sees, email DNS facts and a zone comparison.
- Overview cards for findings, readiness, the next 90 days, how current the records are and the safety nets.
- Email reminders at 60, 30, 14, 7 and 1 days and on the day, with a per-record override, one email per record, date and lead time, recipients from the Settings list plus the account holder and payer, retries the next day up to three times, and a delivery check with a test email.
- Renewed button on records with a date: the next date starts a new reminder cycle and History records it.
- Optional weekly summary of dates, findings that need fixing and changes.
- Copy guard: a copy of the site on another address pauses reminders and asks whether the site moved or this is a copy.
- `wp estasoft-passport reminders run --dry-run`, `digest` and `test --to=`.
- Site Health: a test for how recently the Passport was reviewed, a test for whether scans are running, and a debug information section with counts and dates only.
- `wp estasoft-passport status` with counts and dates only and `--fail-on=needs-fixing|worth-fixing` for build scripts; `findings list|snooze|accept|reopen` and `record list|verify|renew`. Commands that change the Passport need `--user`, so History names the person; changes show "cli" as their origin.

- REST API under `estasoft-passport/v1`: the summary, records, people, runbooks, restore tests, findings, change log, DNS/certificate/address checks and scans. Collections page with `page` and `per_page` (at most 100) and return totals in headers; every route publishes a schema; errors carry `estasoft_hp_` codes that never repeat what was sent; Sensitive records and fields are left out for people without the capability. See `docs/REST-API.md`.
- A shared summary of counts and dates used by the API and `wp estasoft-passport status`.

- Handover: a checklist that fills itself from the Passport, snapshots fixed by a SHA-256 fingerprint, a compare view, successor notes and an export log.
- Export packs as PDF, HTML, Markdown, JSON, CSV and a calendar file (dated items only), each with a client, an emergency or a full profile; Sensitive records are left out unless the person exporting may see them and asks for them. `wp estasoft-passport snapshot` and `export`.
- A bundled PDF library with its own namespace prefix, so it cannot clash with another plugin; an optional TrueType font for Chinese, Japanese and Korean text.
- Runbook reading view and a large-type print view, drills with a Done button that keeps the time and the person, and an after-restore checklist built from the critical addresses.
- Incident records and an ownership transfer tracker that feeds the handover checklist.
- The change log can be downloaded as CSV (a cell that starts like a formula is neutralised).
- WordPress personal-data export and erase tools, with a plain account of what an erase cannot change (saved snapshots, free text in records).
- Uninstall: an opt-in setting removes every table, option, transient, user meta, scheduled task, capability and the plugin's temporary folder when the plugin is deleted.
- A translation template (`languages/estasoft-handover-passport.pot`), plugin directory icons and banners, and a release workflow that builds the zip from a version tag.
- A setup guide of seven steps (scan, about the site, people, domains and hosting, licences, backups, review). Each step can be skipped and picked up again; a step counts as done when the Passport already holds what it asks for. Skipped and unfinished steps appear on the Overview as things to do. The first visit after activation opens the guide for people who may edit.
- The about step records the site name, who it belongs to, what it is for, the person's role, a brand colour and a logo from the Media Library. A change to these details is written to the change log.
- For the kind of site chosen (brochure, WooCommerce store, membership, multilingual, blog) the backups step can add draft runbooks and the addresses worth watching, once, without duplicating what the Passport already holds.
- An optional summary on the WordPress Dashboard (off by default, switched on in Settings, General): the next five dates and the number of findings that need fixing, for people who may view the Passport. Records marked Sensitive stay out of it for people without the Sensitive capability.
- A leaver checklist for each active person on the People tab (Offboard): the systems in the access register where they have access, the matching WordPress account, the assets they hold, pay for or administer, their roles and what to do in the Passport, as a page to read and print. It changes nothing, and Sensitive entries stay hidden from people without the Sensitive capability.
- "Since the handover" on the Overview: how many records were added, removed or changed since the newest snapshot somebody acknowledged, with a link to the comparison. Records marked Sensitive and the findings and checklist worked out from the records are not counted.
- An optional weekly check of the critical addresses (off by default, switched on in Settings, General): every Sunday morning this server asks each address once and sends one email to the reminder recipients if any fails. It skips addresses on another host, pauses on a copy of the site, keeps a Sensitive address's name out of the email and says plainly that it is not an uptime monitor. The Safety screen shows when it last ran.

### Fixed
- The tables could not be created on MySQL and MariaDB: `sensitive` is a reserved word there. The column is now written with backticks in the schema and in every query, and a unit test refuses any column named with a reserved word without quotes.

### Changed
- The monthly emailed-copy event is no longer scheduled: nothing used it. A leftover event from an earlier build is removed.
- Scanning is limited to once a minute from the Scan now button, WP-CLI and the REST API alike, and the notices for scans, checks and test emails say how many seconds are left.
- `wp estasoft-passport status` leaves Sensitive records out of its counts unless `--user` holds the Sensitive capability.
- The change log hides the names and values of Sensitive records from people without the capability, including entries written before the record was marked Sensitive.
- The test email, when pressed again within a minute, says how many seconds are left.
- Findings about a Sensitive record no longer name it for people without the Sensitive capability.
- The message for a note or reason that looks like a secret names the field once, not twice.
