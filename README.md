# TimeSister für Nextcloud

Die Server-Seite der Zeiterfassung TimeSister: Stammdaten als Datensätze mit
Version, getrennte Teams über je vier Nextcloud-Gruppen (dazu optional eine
Konten-Gruppe, keine Rolle), Rechteprüfung auf dem Server, Verlauf,
Kalendersicherungen und Lebenszeichen der Clients.
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
| Übersetzungen der Admin-Seite | `l10n/de.json` (du), `l10n/de_DE.json` (Sie) |
| Konto/Gruppe gelöscht | `lib/Listener` |
| Sicherungen: Export, Ablagen, Freigabe, eigene Kopie | `lib/Service/Backup*`, `CalendarExporter`, `IcsJoiner`, `ProtectedStore`, `VisibleCopy`, `ConsentService`, `OwnCopyService`, `WeekMarks` |
| Wochensicherung (stündlich prüfen) | `lib/BackgroundJob/WeeklyBackup.php` |
| Aufbewahrung (täglich): Verlauf, Staffel der Sicherungen (`Thinning`) | `lib/BackgroundJob/Retention.php` |

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

Wochenjob und Ausdünnen prüft die Integration nur mit `TS_OCC` (Befehl für
`occ`, z. B. `docker exec -u www-data timesister-next-app-1 php occ`).

Die Integration legt zwei Teams (`pb`, `at`) mit Konten `Test-2026-<konto>!`
an und läuft nur gegen `localhost`. Mit `TS_SQL` (Befehl, der SQL auf stdin
liest) prüft sie zusätzlich die Vermerke in der Datenbank.

Neue Nextcloud-Version und App Store: [`UPDATE.md`](UPDATE.md).

## Sicherungen und Updatefestigkeit

Der Server exportiert den Zeitkalender mit `OCP\Calendar\ICalendarExport`
(seit Nextcloud 32). Laut OCP liefert `export()` Sabre-`VCalendar`-Objekte;
die App ruft davon nur `serialize()` auf und setzt die Texte selbst zusammen
(`IcsJoiner`, zeilenweise, ohne Sabre). Psalm kennt Sabre nicht, darum steht
genau diese Klasse in `psalm.xml` unter `UndefinedDocblockClass`. Ebenso
erbt `OCP\Files\IRootFolder` das nicht mitgelieferte `OC\Hooks\Emitter`;
`MissingDependency` ist nur für `lib/Service/VisibleCopy.php` aus.

Dateien in Heimen von Personen löscht die App nur, wenn sie in
`ts_backup_files` stehen und die Datei-ID noch auf denselben Pfad zeigt.

## Übersetzung

Quellsprache der Admin-Seite ist Englisch. Template und Settings-Klassen
übersetzen mit `IL10N` (`$l->t()`, `$l->n()`); was `js/admin.js` selbst
schreibt, kommt als Wörterbuch im Initial-State `l10n` vom Server, ohne
`t()`/`OC.L10N`. Nextcloud liest für PHP nur `l10n/<sprache>.json`; die
`.js`-Varianten bräuchte nur `OC.L10N`, sie fehlen mit Absicht (fehlende
Übersetzungsskripte übergeht Nextcloud still). `tests/Unit/L10nTest.php`
prüft, dass jeder Text in beiden Dateien steht und jeder Schlüssel des
Skripts geliefert wird.

Die Fehlermeldungen der Schnittstelle (`message`) bleiben deutsch, wie
`docs/API.md` es festlegt; die Mac-App zeigt sie direkt.

## Lizenz

AGPL-3.0-or-later, siehe [`LICENSE`](LICENSE). © B/IAS – Basel Institut für
angewandte Stadtforschung.
