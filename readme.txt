=== Estasoft Handover Passport ===
Contributors: estasofttechnologies
Tags: documentation, handover, inventory, maintenance, agency
Requires at least: 6.4
Tested up to: 6.4
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A living record of your WordPress site for handovers: stack, licences, domains, hosting, backups, owners and a change log.

== Description ==

Estasoft Handover Passport keeps one living record of a WordPress site, so a client, a new developer or a colleague can take over without a week of questions.

**What it does in this version**

* Scans the site on a schedule and when the stack changes, and records WordPress, PHP, database, server, plugins, themes, scheduled tasks and administrators.
* Keeps a change log of what changed and who changed it: plugin installs, updates, activations, theme switches, new administrators and address changes.
* Marks every value as Detected, Entered or Imported, with the time of its last change.
* Refuses to store passwords, keys and tokens in any field, so the Passport is safe to share.
* Works with WP-CLI (`wp estasoft-passport scan`) and a REST API with capability checks on every route.

**Built for trust**

* No account, no tracking, no ads, no upsell notices.
* No request leaves your server. Scans read local data only.
* Nothing is added to the front end of your site.
* Five capabilities control who can view, edit, export and configure. Administrators have them by default.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate it. You land on the Passport Overview screen.
3. Press Scan now to record the site. The first scan is the baseline; later scans list what changed.

== Frequently Asked Questions ==

= Does it send my data anywhere? =

No. Scans and the change log stay on your server. The plugin makes no outside requests.

= Does it store passwords? =

No. It refuses text that looks like a password, key or token, and tells you which field to fix. Write where a login lives (for example "the shared password manager, folder Clients"), not the login itself.

= What happens to my data if I delete the plugin? =

By default, nothing: the data stays in the database. A setting turns on full removal when the plugin is deleted.

= Who can see Passport? =

People with the `estasoft_hp_view` capability. Administrators have it by default. A separate capability controls who can see Sensitive records.

== Privacy ==

The plugin stores a record of the site and the names and work email addresses of the people you enter. Nothing is sent to ESTASOFT TECHNOLOGIES or any third party.

== Changelog ==

= 1.0.0 =
* First release: foundation, site scan, change log, capabilities, secret guard, REST and WP-CLI scan.

== Upgrade Notice ==

= 1.0.0 =
First release.
