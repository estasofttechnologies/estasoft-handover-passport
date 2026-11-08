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

### Changed
- The change log hides the names and values of Sensitive records from people without the capability, including entries written before the record was marked Sensitive.
- The test email, when pressed again within a minute, says how many seconds are left.
