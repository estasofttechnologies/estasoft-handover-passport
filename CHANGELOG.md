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

### Changed
- Scanning is limited to once a minute from the Scan now button, WP-CLI and the REST API alike, and the notices for scans, checks and test emails say how many seconds are left.
- `wp estasoft-passport status` leaves Sensitive records out of its counts unless `--user` holds the Sensitive capability.
- The change log hides the names and values of Sensitive records from people without the capability, including entries written before the record was marked Sensitive.
- The test email, when pressed again within a minute, says how many seconds are left.
- Findings about a Sensitive record no longer name it for people without the Sensitive capability.
- The message for a note or reason that looks like a secret names the field once, not twice.
