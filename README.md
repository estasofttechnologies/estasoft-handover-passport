# Estasoft Handover Passport

A living record of a WordPress site for handovers: stack, licences, domains, hosting, backups, owners and a change log.

Built and maintained by [ESTASOFT TECHNOLOGIES](https://estasoft.com).

## Requirements

- WordPress 6.4 or later (tested on 6.4 and 7.1)
- PHP 8.1 or later (`json`, `mbstring`)

## Install

Download the release zip, then upload it in **Plugins > Add New > Upload Plugin**. To work from source:

```bash
git clone https://github.com/estasofttechnologies/estasoft-handover-passport.git
cd estasoft-handover-passport
composer install
```

## Use

After activation, **Passport** opens its setup guide: seven short steps, each of which can be skipped and picked up again from the Overview. The first step is **Scan now**.

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

Runbooks are filled in from the Passport (a step that says `{registrar}` shows the registrar on record, or "not recorded" when there is none; Sensitive records never supply a value). **Passport > Safety nets > Runbooks** has a reading view for every version, a large-type print view, and drills: press *Start a drill* and tick each step as it is done. The time and the person are kept per step, a note can be added, and *Finish* records the result and the total time. A drill of a runbook that restores the site also lists an after-restore checklist (the critical addresses, then the checks only a person can make) and can be saved as a restore test. A drill never counts as a restore test unless you save it as one.

**Emailed copy of the Emergency sheet.** Off by default. Under **Passport > Settings > Privacy and data**, tick it and list up to five addresses. The one-page Emergency sheet is then emailed as a PDF when a snapshot is made and once a month (the first daily run of each calendar month). Email is not encrypted, so the sheet is made without anyone's Sensitive rights: it holds no Sensitive record and never a password, key or token. If an email cannot be sent, the Overview says so and Passport tries again once a day, up to three more times; **Send a copy now** tests it. A copy of the site (see the copy guard) sends nothing. The export log marks each emailed file.

**Weekly address check.** Off by default. Tick it under **Passport > Settings > General** and, every Sunday morning, this server asks each address in **Safety nets > Critical addresses** once. If one does not answer as expected, one email goes to the reminder recipients. It names the address, what came back and when. A Sensitive address is counted but not named. It leaves out addresses on a host other than this site's own, it pauses on a copy of the site, and the Safety screen shows when it last ran. It is not an uptime monitor: it cannot tell you the moment the site goes down.

**Passport > History > Change log** can be downloaded as a CSV file for the filter on screen (a kind of change, or everything since a snapshot). It needs the export capability, masks Sensitive records for people who may not see them, prefixes any cell a spreadsheet would read as a formula, refuses more than 10,000 entries rather than cutting the file, and is entered in the export log.

**Personal data.** The plugin joins the WordPress tools under **Tools > Export Personal Data** and **Tools > Erase Personal Data**. For an email address, the export lists the person's directory entry (and the records that name them), the handover acknowledgements that carry their name, their entry among the administrators found by the scan, and the change-log entries they made. The erase replaces the person with *Former contact* and clears their organisation, title, email, phone, language, time zone, urgent method and note. The row stays, so a role or record that pointed at them still shows that someone held it and the checks can ask for a new person. It also blanks the name in matching acknowledgements and in the saved scans, and replaces the name of the WordPress user in the change log with *Former user*. It cannot rewrite what is fixed or free text, so it says what remains: saved snapshots (fixed by their checksum; delete the snapshot to remove them), records that mention the address in their text, and an administrator account that still exists and will be listed by the next scan. Acknowledgements hold no email address, so they are matched by name, and by organisation when both have one.

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
