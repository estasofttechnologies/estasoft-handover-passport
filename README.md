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

wp estasoft-passport status                          # counts and dates only, safe for a build log
wp estasoft-passport status --fail-on=needs-fixing   # exit 1 when such findings are open

wp estasoft-passport findings list --severity=needs-fixing
wp estasoft-passport findings snooze 12 --days=30 --user=admin
wp estasoft-passport findings accept 12 --reason="The client renews this by hand" --user=admin

wp estasoft-passport record list --kind=domain
wp estasoft-passport record verify 7 --user=admin
wp estasoft-passport record renew 7 2027-03-01 --user=admin

wp estasoft-passport reminders run --dry-run

wp estasoft-passport snapshot create --name="Handover to Acme, May 2027" --pack=client --user=admin
wp estasoft-passport snapshot list
wp estasoft-passport snapshot verify 3                # exit 1 when the stored content no longer matches its hash
wp estasoft-passport snapshot compare 3 live

wp estasoft-passport export client --format=pdf --file=/home/me/client-pack.pdf
wp estasoft-passport export technical --format=md > technical.md
wp estasoft-passport export --snapshot=3 --format=html --file=/tmp/snapshot-3.html
wp estasoft-passport export full --format=json --file=/private/full.json --include-sensitive --user=admin

wp estasoft-passport restore-test add --result=pass --duration=42 --scope=both --place=staging --who="Nightly job" --user=ci
```

Commands that change the Passport need `--user` with someone who may edit it, so the change log names that person; `snapshot create` needs someone who may export. Reading needs no user, and without one nothing Sensitive is shown.

`export` never puts a Sensitive record in a file unless you add `--include-sensitive` and name a user who may see Sensitive records; the Full internal pack always needs both. `pdf` and `csv` are written to `--file`; the text formats print to standard output when `--file` is left out. A file is not replaced without `--force`, is written readable by its owner only, and you are warned if it lands inside the WordPress folder.

The REST API lives under `estasoft-passport/v1`. Every route checks a capability, and Sensitive records and fields stay hidden from people without the Sensitive capability. See [docs/REST-API.md](docs/REST-API.md).

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
