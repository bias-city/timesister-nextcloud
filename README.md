# TimeSister for Nextcloud

Server side of the TimeSister time tracker (macOS): master data as
versioned records, separate teams via one Nextcloud group each (roles
Team Admin, Lead and User; Team Admins are that group's group admins),
the team's time entries read from the members' named calendars and feeds
(0.17.0; no calendar shares) – only the hours of external persons that a
Team Admin imports from a list live in the app's database (0.28.0) –, Jobs (shares of a project or work package
offered to people of the team), invoices issued on the server with an
append-only ledger and a SHA-256 chain (0.18.0), permission checks on the
server, history, calendar backups and status
reports from clients. No UI apart from an admin settings section.
API: [`docs/API.md`](docs/API.md).

## Structure

| Part | Where |
|---|---|
| OCS controller (`/ocs/v2.php/apps/timesister/api/v1`) | `lib/Controller` |
| Team, role, permissions, checks, conflicts | `lib/Service` (pure classes without Nextcloud: `AccessPolicy`, `MembershipResolver`, `ProjectAccess`, `RecordValidator`, `VersionCheck`, `*Rules`) |
| App roles and leaving (API version 2) | `MemberService`, table `ts_members` |
| Jobs: rules, transitions, who sees, notifications | `JobRules`, `JobFlow` (pure), `JobAccess`, `JobService`, `JobNotifications`, `JobMessages`, `JobWord`; records of kind `job` |
| Invoices: content, Lead rule, number pattern, counter | `InvoiceRules`, `InvoiceNumbering` (pure), `InvoiceService`, `CounterMapper` (table `ts_counters`); records of kind `invoice` |
| Quotes: content with budget, Lead rule (`leads` or led project) | `QuoteRules` (pure); records of kind `quote` |
| Invoice template (one Word file per team) | `InvoiceTemplateRules` (pure), `InvoiceTemplateService`, `InvoiceTemplateStore` (app data `templates/t<team>/`), `TemplateController` |
| Tables `ts_*`, mappers | `lib/Db`, `lib/Migration` |
| Admin page | `lib/Settings`, `templates/admin.php`, `js/admin.js`, `css/admin.css` |
| Translations (admin page, API messages) | `l10n/de.json` (informal German, "du"), `l10n/de_DE.json` (formal, "Sie") |
| Account/group deleted | `lib/Listener` |
| Backups: export, storage, consent, own copy | `lib/Service/Backup*`, `CalendarExporter`, `IcsJoiner`, `ProtectedStore`, `VisibleCopy`, `ConsentService`, `OwnCopyService`, `WeekMarks` |
| Expenses (0.30.0): fields, settling, the expense table, documents, ECB rates | `ExpenseFields`, `ExpenseTable`, `ExpenseReportRules`, `ExchangeRateRules` (pure), `ExpenseReportService`, `ExchangeRateService`, `lib/BackgroundJob/ExchangeRates.php` |
| Weekly backup (checked hourly) | `lib/BackgroundJob/WeeklyBackup.php` |
| Retention (daily): history, backup tiers (`Thinning`) | `lib/BackgroundJob/Retention.php` |

Requirements: Nextcloud 33–35, PHP ≥ 8.2, no other apps.

## Development

```sh
composer install
composer run lint           # php -l
composer run psalm          # against the stubs from composer.json
composer run psalm:all      # against every version from info.xml, and master
composer run test:unit      # pure classes, without Nextcloud
NC_URL=http://localhost:8081 composer run test:integration   # against a test Nextcloud
```

The integration test checks the weekly job and thinning only with `TS_OCC`
(command for `occ`, e.g. `docker exec -u www-data timesister-next-app-1 php occ`).

The integration test creates two teams (`pb`, `at`) with accounts
`Test-2026-<account>!` and only runs against `localhost`. With `TS_SQL` (a
command that reads SQL on stdin) it also checks the entries in the database.

New Nextcloud version and App Store: [`UPDATE.md`](UPDATE.md).

## Backups and update resilience

The server exports the time calendar with `OCP\Calendar\ICalendarExport`
(since Nextcloud 32). Per OCP, `export()` returns Sabre `VCalendar` objects;
the app only calls `serialize()` on them and assembles the text itself
(`IcsJoiner`, line by line, without Sabre). Psalm doesn't know Sabre, so
exactly this class is listed in `psalm.xml` under `UndefinedDocblockClass`.
Likewise, `OCP\Files\IRootFolder` inherits the not-included
`OC\Hooks\Emitter`; `MissingDependency` is suppressed only for
`lib/Service/VisibleCopy.php`.

The app only deletes files in people's home storage when they are listed in
`ts_backup_files` and the file ID still points to the same path. Invoice
documents and chain head files (0.19.0) are written, never deleted. In the
document tree (0.22.0) a receipt's or quote's file that is replaced under
another name, and the file of a deleted open receipt, go to the backup
owner's trash (`files_trashbin`), never deleted outright.

Runtime libraries: none since 0.32.0 – the Mac app renders every
document (one renderer); the server files the app's PDF and XML, hashes
them into the ledger and serves them. `composer.json` `require` holds only
`php`; `vendor/` of the release archive carries Composer's autoloader and
nothing else (`.nextcloudignore` leaves the dev packages out).

## Exchange rates (0.30.0)

With the team setting `settings.exchange_rates` on, the hourly job
`ExchangeRates` asks Frankfurter (reference rates of the European Central
Bank) for the days on which reimbursed expenses in a foreign currency wait
for a rate. A request names only the team's currency, the foreign
currencies and the day – no person, amount or receipt. The rates become
records of kind `exchange_rate`, written by the server alone; the privacy
notice names the service, the currencies and the days. The address is the
app config `exchange_rates_url` (default `https://api.frankfurter.dev`); an
own Frankfurter may run in the local network:

```sh
occ config:app:set timesister exchange_rates_url --value=https://rates.example.org
```

The integration test points it to a local stub (`TS_OCC` needed) and
removes it afterwards; it never asks the real service.

## Translations

Source texts are English. The admin page translates with `IL10N`
(`$l->t()`, `$l->n()`); what `js/admin.js` writes itself comes as a
dictionary in the initial state `l10n` from the server, without
`OC.L10N`. Error messages of the API are English sources in `ApiException`
and `Message` (placeholders as `{name}`), translated only when the answer
goes out. Nextcloud reads `l10n/<language>.json`: `de.json` (informal
German, "du") and `de_DE.json` (formal, "Sie"); the `.js` variants would
only matter for `OC.L10N` and are left out on purpose.
`tests/Unit/L10nTest.php` checks that every source text is in both files
and that no translation is left over. Log messages stay English.

To add a language: create `l10n/<lang>.json` in the same format and add
the language to `L10nTest::LANGUAGES`.

The API's error messages (`message`, see [`docs/API.md`](docs/API.md)) come
in the account's language (Nextcloud language setting, else
`Accept-Language`, else English). Clients decide by `error`, never by
wording.

## License

AGPL-3.0-or-later, see [`LICENSE`](LICENSE). © B/IAS – Basel Institut für
angewandte Stadtforschung.
</content>
