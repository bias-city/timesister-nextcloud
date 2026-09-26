# TimeSister für Nextcloud

Die Server-Seite der Zeiterfassung TimeSister: Stammdaten als Datensätze mit
Version, getrennte Teams über je vier Nextcloud-Gruppen, Rechteprüfung auf dem
Server, Verlauf, Kalendersicherungen und Lebenszeichen der Clients.
Schnittstelle: [`docs/API.md`](docs/API.md).

*Server side of the TimeSister time tracker (macOS). OCS API for versioned
master data, teams and roles; no UI apart from an admin settings section.*

## Aufbau

| Teil | Wo |
|---|---|
| OCS-Controller (`/ocs/v2.php/apps/timesister/api/v1`) | `lib/Controller` |
| Team, Rolle, Rechte, Prüfungen, Konflikte | `lib/Service` (reine Klassen ohne Nextcloud: `AccessPolicy`, `MembershipResolver`, `RecordValidator`, `VersionCheck`, `*Rules`) |
| Tabellen `ts_*`, Mapper | `lib/Db`, `lib/Migration` |
| Admin-Seite | `lib/Settings`, `templates/admin.php`, `js/admin.js`, `css/admin.css` |
| Konto/Gruppe gelöscht | `lib/Listener` |
| Aufbewahrung (täglich) | `lib/BackgroundJob/Retention.php` |

Voraussetzungen: Nextcloud 33–34, PHP ≥ 8.2, keine anderen Apps.

## Entwickeln

```sh
composer install
composer run lint           # php -l
composer run psalm          # gegen die Stubs aus composer.json
composer run psalm:all      # gegen jede Version aus info.xml und master
composer run test:unit      # reine Klassen, ohne Nextcloud
NC_URL=http://localhost:8081 composer run test:integration   # gegen eine Test-Nextcloud
```

Die Integration legt zwei Teams (`pb`, `at`) mit Konten `Test-2026-<konto>!`
an und läuft nur gegen `localhost`. Mit `TS_SQL` (Befehl, der SQL auf stdin
liest) prüft sie zusätzlich die Vermerke in der Datenbank.

Neue Nextcloud-Version: [`UPDATE.md`](UPDATE.md).

## Lizenz

AGPL-3.0-or-later, siehe [`LICENSE`](LICENSE). © B/IAS – Basel Institut für
angewandte Stadtforschung.
