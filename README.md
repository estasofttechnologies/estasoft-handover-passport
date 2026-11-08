# Estasoft Handover Passport

A living record of a WordPress site for handovers: stack, licences, domains, hosting, backups, owners and a change log.

Built and maintained by [ESTASOFT TECHNOLOGIES](https://estasoft.com).

## Requirements

- WordPress 6.4 or later
- PHP 8.1 or later (`json`, `mbstring`)

## Install

Download the release zip, then upload it in **Plugins > Add New > Upload Plugin**. To work from source:

```bash
git clone https://github.com/estasofttechnologies/estasoft-handover-passport.git
cd estasoft-handover-passport
composer install
```

## Use

After activation, open **Passport** in the admin menu and press **Scan now**.

```bash
wp estasoft-passport scan
wp estasoft-passport scan --format=json
```

REST routes live under `estasoft-passport/v1` and always check a capability.

## Principles

- No outside requests during scans. Nothing is added to the front end.
- No passwords, keys or tokens are stored; fields that look like secrets are refused.
- Five capabilities (`estasoft_hp_view`, `estasoft_hp_view_sensitive`, `estasoft_hp_edit`, `estasoft_hp_export`, `estasoft_hp_configure`) control access. Administrators hold them by default.
- Data is kept when the plugin is deleted unless you turn on removal in Settings.

## Develop

```bash
composer install
composer lint     # PHP syntax check
composer cs       # WordPress coding standards
composer test     # PHPUnit unit tests
```

Every pull request should keep `composer cs` and `composer test` green. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

Report a vulnerability privately. See [SECURITY.md](SECURITY.md).

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
