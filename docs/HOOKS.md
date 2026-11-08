# Hooks

Passport has a small set of actions and filters. Their names and arguments do not change within a major version. A change adds a new hook and deprecates the old one with a notice in debug mode. Every name starts with `estasoft_hp_`.

Records that reach a hook are shown the way a person without the Sensitive capability would see them: a record marked Sensitive has its values removed. Nothing here passes a password, key or token, because Passport never stores one.

## Actions

### `estasoft_hp_record_saved`

Fires after a record is added, changed, verified, renewed, deleted or restored, and after a runbook, restore test or person is saved.

| Argument | Type | Meaning |
| --- | --- | --- |
| `$id` | int | Record ID. |
| `$kind` | string | Kind such as `domain`, `licence`, `host`, or `person`, `runbook`, `test`, `role`. |
| `$before` | array | The record before the change, empty when it was added. |
| `$after` | array | The record after the change, empty when it was deleted. |

```php
add_action( 'estasoft_hp_record_saved', function ( $id, $kind, $before, $after ) {
	if ( 'domain' === $kind && array() === $before ) {
		error_log( 'A domain was added to the Passport.' );
	}
}, 10, 4 );
```

### `estasoft_hp_scan_completed`

Fires after a site scan finishes.

| Argument | Type | Meaning |
| --- | --- | --- |
| `$scan_id` | int | Scan ID. |
| `$facts` | array | What the scan found, with Sensitive values removed. |
| `$changes` | int | Number of changes written to the change log. |

```php
add_action( 'estasoft_hp_scan_completed', function ( $scan_id, $facts, $changes ) {
	if ( $changes > 0 ) {
		wp_mail( 'ops@example.com', 'The stack changed', "{$changes} changes were recorded." );
	}
}, 10, 3 );
```

### `estasoft_hp_finding_opened` and `estasoft_hp_finding_resolved`

Fire when a finding appears and when it stops being true. The one argument is the finding as stored: `id`, `rule_id`, `subject_type`, `subject_id`, `subject_key`, `severity`, `state`, `message_values`, `first_seen`, `last_seen`, `snoozed_until`, `accepted_reason`, `accepted_by` and `resolved_at`. For a finding about a record marked Sensitive, `message_values` is empty.

```php
add_action( 'estasoft_hp_finding_opened', function ( $finding ) {
	if ( 'HP-DOM-001' === $finding['rule_id'] ) {
		// A domain has expired or is about to.
	}
} );
```

### `estasoft_hp_snapshot_created` and `estasoft_hp_snapshot_acknowledged`

Fire when a snapshot is made and when someone records that a person received it.

| Hook | Arguments |
| --- | --- |
| `estasoft_hp_snapshot_created` | `$snapshot_id` (int) |
| `estasoft_hp_snapshot_acknowledged` | `$snapshot_id` (int), `$acknowledgement` (array: name, organisation, role, date, method, note, recorded_by, recorded_at) |

### `estasoft_hp_export_created`

Fires after a pack is made. The file itself is not passed on.

| Argument | Type | Meaning |
| --- | --- | --- |
| `$profile` | string | Profile key: `client`, `technical`, `full` or `emergency`. |
| `$format` | string | `pdf`, `html`, `md`, `json`, `csv`, `ics` or `print`. |
| `$snapshot` | int | Snapshot ID, 0 for the live Passport. |
| `$user_id` | int | Person who made it, 0 for an automatic one. |

### `estasoft_hp_reminder_sent`

Fires after a reminder email was accepted for delivery. Arguments: `$record_id` (int) and `$lead_days` (int, 0 for the day itself).

### `estasoft_hp_site_host_changed` and `estasoft_hp_site_moved`

The copy guard saw the Passport on a different address than the one it was set up on (`estasoft_hp_site_host_changed`), and someone answered that the site moved (`estasoft_hp_site_moved`).

| Hook | Arguments |
| --- | --- |
| `estasoft_hp_site_host_changed` | `$stored_host` (string), `$current_host` (string) |
| `estasoft_hp_site_moved` | `$current_host` (string) |

### `estasoft_hp_retention_run`

Fires after the daily clean-up. The argument is the settings object. Use it to trim data your own code keeps next to the Passport.

## Filters

### `estasoft_hp_secret_patterns`

Add regular expressions that the secret guard refuses in any field, snapshot or runbook. Built-in patterns always apply; a filter cannot remove them. The array is keyed by an identifier and holds a full pattern with delimiters. An identifier that is already a built-in one is ignored.

```php
add_filter( 'estasoft_hp_secret_patterns', function ( $patterns ) {
	$patterns['acme_token'] = '/\bacme_[A-Za-z0-9]{24,}\b/';
	return $patterns;
} );
```

### `estasoft_hp_service_map`

The list that turns a detected plugin into a suggested Services or Backups record. The key is the plugin folder, the value is `array( $category, $label )`.

```php
add_filter( 'estasoft_hp_service_map', function ( $map ) {
	$map['acme-forms'] = array( 'forms', 'Acme Forms' );
	return $map;
} );
```

### `estasoft_hp_mail_providers`

The table that names an email provider from the host names of the MX records. The key is a host name suffix, the value the provider's name.

```php
add_filter( 'estasoft_hp_mail_providers', function ( $table ) {
	$table['mail.acme.example'] = 'Acme Mail';
	return $table;
} );
```

### `estasoft_hp_rules`

The list of finding rules. Add a `Estasoft\HandoverPassport\Findings\Rule` object to add a rule, or remove an entry to drop one. Entries that are not rule objects, or that reuse an ID, are ignored.

### `estasoft_hp_review_days`

How many days a record may go without a check before it is Due. Arguments: `$days` (int) and `$section` (string, such as `licences`).

```php
add_filter( 'estasoft_hp_review_days', function ( $days, $section ) {
	return 'licences' === $section ? 60 : $days;
}, 10, 2 );
```

### `estasoft_hp_reminder_days`

The lead times for one record, as a list of days before its date. Arguments: `$days` (array of int) and `$record` (array, with Sensitive values removed).

### `estasoft_hp_reminder_recipients`

Who receives a reminder. Arguments: `$addresses` (array of strings) and `$record` (array, empty for the weekly summary and the weekly address check). Invalid addresses are dropped after the filter runs.

```php
add_filter( 'estasoft_hp_reminder_recipients', function ( $addresses ) {
	$addresses[] = 'ops@example.com';
	return $addresses;
} );
```

### `estasoft_hp_export_sections`

The chapters of a pack before it is rendered. Arguments: `$sections` (array) and `$profile` (the profile object). The pack is built from what the person who makes it may see, and the secret guard runs on the result.

### `estasoft_hp_export_filename`

The name of a downloaded pack, without the extension. Arguments: `$name` (string), `$profile` (string) and `$snapshot` (int).

### `estasoft_hp_pdf_config`

The settings given to the PDF library. Arguments: `$config` (array) and `$content` (the pack). The limits on the network and on the files the library may read are set after the filter runs and cannot be turned off.

### `estasoft_hp_pdf_fonts_dir`

The folder that holds extra TrueType fonts for the PDF, for scripts the shipped fonts do not cover, such as Chinese, Japanese and Korean. The default is a folder in the uploads directory. The argument is the path.
