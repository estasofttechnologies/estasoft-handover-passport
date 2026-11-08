# REST API

Base address: `/wp-json/estasoft-passport/v1` (or `/?rest_route=/estasoft-passport/v1` on a site with plain permalinks).

## Signing in

- From a script: an [application password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) sent as HTTP Basic credentials.
- From a page on the same site: the sign-in cookie plus an `X-WP-Nonce` header made with `wp_create_nonce( 'wp_rest' )`.

The person behind the credentials needs one of the Passport capabilities. **View** is enough to read, **Edit** to change, and **View Sensitive** to see or change anything marked Sensitive. Without a capability the answer is `401` (signed out) or `403`, even when the request also has other faults.

## Routes

| Route | Methods | Needs | Notes |
| --- | --- | --- | --- |
| `/passport` | GET | View | Counts and dates only: findings, readiness ("n of m"), records by kind and freshness, dates in the next 30 days. |
| `/records` | GET, POST | View / Edit | Filters: `kind`. Body to create: `kind`, `fields{}`, `sensitive`, `visible_to_client`, `visible_in_emergency`, `review_days`, `reminder_days`. |
| `/records/{id}` | GET, PATCH or PUT, DELETE | View / Edit | Only the fields sent are changed. Delete hides a record for 30 days. |
| `/records/{id}/verify` | POST | Edit | Stores today's date and the caller. |
| `/records/{id}/renew` | POST | Edit | Body: `date`, which must be later than the current one. |
| `/people`, `/people/{id}`, `/people/{id}/verify` | as records | View / Edit | Filters: `role`. |
| `/runbooks`, `/runbooks/{id}` | GET, POST, PATCH or PUT, DELETE | View / Edit | Create from a ready-made `template`. Changing a published runbook makes a new version. |
| `/runbooks/{id}/versions` | GET | View | Newest first. |
| `/runbooks/{id}/reviewed`, `/rehearsed` | POST | Edit | |
| `/tests`, `/tests/{id}` | GET, POST, DELETE | View / Edit | Restore tests. |
| `/findings`, `/findings/{id}` | GET | View | Filters: `state` (default `open`), `severity`, `rule`. |
| `/findings/{id}/snooze` | POST | Edit | Body: `days`, one of 7, 30 or 90. |
| `/findings/{id}/accept` | POST | Edit | Body: `reason`, required. |
| `/findings/{id}/reopen` | POST | Edit | |
| `/changes` | GET | View | Filters: `kind`, `subject_type`, `since`, `search`. |
| `/checks/dns` | POST | Edit | Body: `record_id` of a domain record. |
| `/checks/url` | POST | Edit | Body: `record_id` of a critical address. |
| `/checks/ssl` | POST | Edit | Body: `host`, which must be this site's own host (default). |
| `/scans/latest` | GET | View | Facts without Sensitive values. |
| `/scans` | POST | Edit | Runs a scan. |

Every collection accepts `page`, `per_page` (1 to 100, default 20), `search`, `orderby` and `order`, and returns `X-WP-Total` and `X-WP-TotalPages` headers. `OPTIONS` on a route returns its schema.

## Things that are the same everywhere

- **Sensitive.** A Sensitive record is left out of lists, totals, search results and counts for someone without the capability, and asking for it directly gives the same `404` as a record that does not exist. A Sensitive field (such as a licence's account email) is left out of the answer without the capability, and writing it is refused with `403`.
- **Money** is shown as a decimal text such as `"1234.50"`. Send either a number or a text; both mean an amount, not minor units.
- **Times** are UTC in ISO 8601, for example `2026-11-08T12:14:34Z`. Dates are `YYYY-MM-DD`.
- **Errors** have a code that starts with `estasoft_hp_` and a status. Examples: `estasoft_hp_forbidden` (401 or 403), `estasoft_hp_invalid` (400), `estasoft_hp_missing` (400), `estasoft_hp_unknown_field` (400), `estasoft_hp_secret` (400), `estasoft_hp_wait` (429). The message never repeats text that was sent, and a request that looks like a password, key or token is refused rather than stored.
- **Limits.** A scan, or a DNS, certificate or address check of the same subject, can be started once a minute. A second request gets `429` with `Retry-After` and `data.retry_after` in seconds. The same limit applies to the Scan now button and to WP-CLI.
- **History.** Changes made through the API are recorded with `rest` as their origin and the name of the signed-in person.

## Stability

Everything above lives in the `estasoft-passport/v1` namespace. Within `v1`, a release only adds routes, fields and optional parameters. It does not rename or remove one, and it does not change what a field means. If a route or a field has to go, it stays in `v1` for at least one more release after the changelog announces it, and the removal ships with `v2`. A client that ignores fields it does not know will keep working.
