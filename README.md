# TimeSister for Nextcloud

Server side of the TimeSister time tracker (macOS): master data as
versioned records, separate teams via one Nextcloud group each (roles
Team Admin, Lead and User; Team Admins are that group's group admins),
a shares matrix for the time calendars, permission checks on the server, history, calendar backups and status
reports from clients. No UI apart from an admin settings section.
API: [`docs/API.md`](docs/API.md).

## Structure

| Part | Where |
|---|---|
| OCS controller (`/ocs/v2.php/apps/timesister/api/v1`) | `lib/Controller` |
| Team, role, permissions, checks, conflicts | `lib/Service` (pure classes without Nextcloud: `AccessPolicy`, `MembershipResolver`, `ProjectAccess`, `RecordValidator`, `VersionCheck`, `*Rules`) |
| App roles and leaving (API version 2) | `MemberService`, table `ts_members` |
| Tables `ts_*`, mappers | `lib/Db`, `lib/Migration` |
| Admin page | `lib/Settings`, `templates/admin.php`, `js/admin.js`, `css/admin.css` |
| Translations (admin page, API messages) | `l10n/de.json` (informal German, "du"), `l10n/de_DE.json` (formal, "Sie") |
| Account/group deleted | `lib/Listener` |
| Backups: export, storage, consent, own copy | `lib/Service/Backup*`, `CalendarExporter`, `IcsJoiner`, `ProtectedStore`, `VisibleCopy`, `ConsentService`, `OwnCopyService`, `WeekMarks` |
| Weekly backup (checked hourly) | `lib/BackgroundJob/WeeklyBackup.php` |
| Retention (daily): history, backup tiers (`Thinning`) | `lib/BackgroundJob/Retention.php` |

Requirements: Nextcloud 33–34, PHP ≥ 8.2, no other apps.

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
`ts_backup_files` and the file ID still points to the same path.

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
