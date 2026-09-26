> **Kopie.** Massgeblich ist `nc-app/API.md` im Monorepo, bis das Repo
> `bias-city/timesister-nextcloud` getrennt ist. Danach gilt diese Datei.

# TimeSister-Server – Schnittstelle, Fassung 1 (Vertrag)

Stand 26.09.2026. Dieser Vertrag gilt für die Nextcloud-App `timesister`
(`nc-app/timesister/`) und den Mac-Fork „TimeSister Next“ (`core/`). Wer davon
abweicht, ändert zuerst diese Datei. Plan und Begründung: `PLAN-NC-APP.md`.

## Allgemein

- Basis: `/ocs/v2.php/apps/timesister/api/v1`
- Anmeldung: HTTP Basic mit Nextcloud-Konto und App-Passwort, wie heute.
- Pflicht-Header: `OCS-APIRequest: true`, `Accept: application/json`.
  Rümpfe als `Content-Type: application/json`.
- Antwort im OCS-Umschlag: `{"ocs":{"meta":{"status","statuscode","message"},"data":…}}`.
  Mit OCS v2 entspricht der HTTP-Status dem `statuscode`. Beschrieben ist unten
  immer nur `data`.
- Fehler: HTTP 400, 403, 404, 409, 413 oder 422, mit
  `data = { "error": "<code>", "message": "<deutscher Satz>" , …}`.
  Codes: `no_team`, `ambiguous_team`, `forbidden`, `not_found`, `conflict`,
  `invalid`, `too_large`.
- Zeiten als ISO 8601 in UTC (`2026-09-26T08:15:00Z`), Tage als `YYYY-MM-DD`.

## Fähigkeit

Nextclouds Capabilities (`/ocs/v1.php/cloud/capabilities`) enthalten:

```json
"timesister": { "api": 1, "version": "0.1.0" }
```

Fehlt der Eintrag, ist die App nicht installiert oder ausgeschaltet.

## Teams und Rollen

- Ein **Team** hat `id`, `name`, `slug` und genau vier Rollen-Gruppen:
  `user`, `lead`, `subadmin`, `admin`, je eine bestehende Nextcloud-Gruppe.
  Eine Gruppe gehört zu höchstens einem Team.
- Dazu optional eine **Konten-Gruppe** `accounts`, ebenfalls eine bestehende
  Nextcloud-Gruppe. Alle Konten des Teams stehen darin; sie ist **keine**
  Rolle. Die Team-Admins verwalten sie als Gruppenadmins mit. So bleibt jedes
  Konto in einer ihrer Gruppen, und sie können jede Rollen-Gruppe entziehen
  (Nextcloud-Regel OCS 105, siehe Nachträge). Sie darf keinem anderen Team
  gehören, weder als Rolle noch als Konten-Gruppe (`409 conflict`), und keine
  der vier Rollen-Gruppen desselben Teams sein (`422 invalid`).
- Ein Konto gehört zu dem Team, in dessen Rollen-Gruppen es steht. Steht es in
  Gruppen von zwei Teams: `409 ambiguous_team`. In keiner: `403 no_team`.
  Die Konten-Gruppe zählt dafür nie: Wer nur in ihr steht, bekommt
  `403 no_team`; wer in der Konten-Gruppe von Team A und in einer Rollen-Gruppe
  von Team B steht, gehört zu B.
- `groups` in `/me`, `/team` und `/admin/teams` nennt `accounts` nur, wenn
  das Team eine Konten-Gruppe hat; sonst fehlt das Feld.
- Die Rolle ist die stärkste Rolle aus diesen Gruppen:
  `admin` > `subadmin` > `lead` > `user`.
- Nextclouds Admin ist **nicht** automatisch Rolle in einem Team.
- Das Team ergibt sich **immer** aus der Mitgliedschaft des Aufrufers, nie aus
  der Anfrage.

## Datensätze

| `kind` | Schlüssel | Inhalt (`data`) |
|---|---|---|
| `person` | `login` der Person (wie heute die Dateinamen in `people/`) | Person, englische Felder wie in `marke::FELDER` |
| `region` | `id` | Standort mit `holidays` |
| `project` | `id` | Projekt samt `subprojects`, `budgets`, `milestones` |
| `customer` | `id` | Kunde samt `contacts`, `cost_centers`, `quotes` |
| `setting` | `targethours` oder `settings` | Sollzeit bzw. Einstellungen (`vacation_code`) |

- Schlüssel: `^[A-Za-z0-9@._+-]{1,128}$`.
- `data` ist ein JSON-Objekt, höchstens 256 KB. Pflicht: bei `person` ein
  Feld `login` gleich dem Schlüssel, bei `region`, `project`, `customer` ein
  Feld `id` gleich dem Schlüssel. Sonst `422 invalid`.
- **Eigene Person:** Der Datensatz, dessen Schlüssel gleich der Nextcloud-
  Kennung des Aufrufers ist oder dessen `data.accounts` sie enthält.

Ein Datensatz in Antworten:

```json
{ "kind": "person", "key": "alice", "version": 3, "revision": 118,
  "deleted": false, "modified_by": "admin", "modified_at": "2026-09-26T08:15:00Z",
  "data": { … } }
```

`data` ist bei Grabsteinen (`deleted: true`) `null`. `version` zählt je
Datensatz ab 1. `revision` ist der Stand des Teams nach dieser Schreibung; sie
steigt mit jeder Schreibung im Team.

## Rechte

| | user | lead | subadmin, admin |
|---|---|---|---|
| `setting`, `region`, `project` | lesen | lesen | lesen, schreiben |
| `person`, eigene | lesen | lesen | lesen, schreiben |
| `person`, fremde | – | lesen | lesen, schreiben |
| `customer` | – | lesen | lesen, schreiben |
| Verlauf | eigene Person | eigene Person | alle |
| Kalendersicherungen | eigene | eigene | alle |
| Lebenszeichen melden | eigenes | eigenes | eigenes |
| Lebenszeichen lesen, Team lesen | – | – | ja |

Nicht Lesbares erscheint in Listen nicht; direkter Zugriff gibt `403 forbidden`.

## Endpunkte

### `GET /me`

```json
{ "api": 1, "uid": "alice", "display_name": "Alice Muster",
  "team": { "id": 1, "name": "Planungsbüro", "slug": "pb" },
  "role": "lead",
  "groups": { "user": "pb-mitarbeitende", "lead": "pb-leitung",
              "subadmin": "pb-verwaltung", "admin": "pb-admin",
              "accounts": "pb-konten" },
  "person_key": "alice", "revision": 118, "server_time": "2026-09-26T08:15:00Z" }
```

`person_key` ist `null`, wenn es keinen eigenen Personendatensatz gibt.
`groups.accounts` fehlt, wenn das Team keine Konten-Gruppe hat.

### `GET /records?since=<revision>`

Alle für den Aufrufer lesbaren Datensätze mit `revision > since`, samt
Grabsteinen. `since` fehlt oder 0: alles, ohne Grabsteine.

```json
{ "revision": 118, "records": [ … ] }
```

### `GET /records/{kind}/{key}`

Ein Datensatz. `404 not_found`, auch für Grabsteine.

### `PUT /records/{kind}/{key}`

```json
{ "version": 3, "data": { … } }
```

- `version` ist die Fassung, auf der die Änderung beruht. `0`: neu anlegen –
  gibt es den Datensatz schon (auch als Grabstein mit älterer Fassung), ist das
  ein Konflikt, ausser er ist ein Grabstein; dann entsteht er neu mit
  `version = grabstein.version + 1`.
- Passt `version` nicht: `409 conflict` mit
  `data = { "error": "conflict", "message": …, "current": <Datensatz> }`.
- Erfolg: `200` mit dem neuen Datensatz.

### `DELETE /records/{kind}/{key}?version=<n>`

Legt einen Grabstein an. `409 conflict` wie oben. Erfolg: der Grabstein.

### `POST /records/batch`

```json
{ "writes": [ { "kind": "project", "key": "D200", "version": 0, "data": { … } },
              { "kind": "region", "key": "CH-BS", "version": 2, "data": null } ] }
```

`data: null` heisst löschen. Alles oder nichts in **einer** Transaktion. Bei
Konflikten: `409` mit `data.conflicts = [ { "kind", "key", "current" } ]`,
nichts geschrieben. Erfolg: `{ "revision": 131, "records": [ … ] }`. Höchstens
500 Schreibungen je Aufruf. Nur subadmin und admin.

### `GET /records/{kind}/{key}/history`

Die Fassungen, neueste zuerst, höchstens 100:

```json
[ { "version": 3, "deleted": false, "modified_by": "admin",
    "modified_at": "…", "data": { … } } ]
```

### `POST /records/{kind}/{key}/restore`

```json
{ "version": 2, "current": 3 }
```

Schreibt die Fassung 2 als neue Fassung 4. `current` wie `version` beim `PUT`.
Nur subadmin und admin.

### `POST /backups`

```json
{ "taken_on": "2026-09-22", "ics_base64": "QkVHSU46VkNBTEVOREFS…" }
```

Kalendersicherung des Aufrufers. Höchstens 20 MB nach dem Dekodieren
(`413 too_large`). Muss mit `BEGIN:VCALENDAR` beginnen (`422 invalid`). Eine
Sicherung je Konto und Tag; eine zweite am selben Tag ersetzt die erste.

```json
{ "id": 17, "uid": "alice", "taken_on": "2026-09-22", "size": 48213, "sha256": "…" }
```

### `GET /backups?uid=<uid>`

Liste ohne Inhalt, neueste zuerst. Ohne `uid`: die eigenen. Fremde nur für
subadmin und admin.

### `GET /backups/{id}`

Wie oben, dazu `ics_base64`.

### `POST /status`

```json
{ "app_version": "0.2.0", "last_sync": "…", "last_backup": "2026-09-22",
  "calendar_url": "https://…/remote.php/dav/calendars/alice/zeit-alice/" }
```

Lebenszeichen des Aufrufers. Antwort: `{ "seen_at": "…" }`.

### `GET /status`

Alle Mitglieder des Teams, auch ohne Lebenszeichen:

```json
[ { "uid": "carol", "display_name": "Carol Test", "role": "user",
    "app_version": null, "last_sync": null, "last_backup": null,
    "calendar_url": null, "seen_at": null } ]
```

### `GET /team`

```json
{ "id": 1, "name": "Planungsbüro", "slug": "pb",
  "groups": { "user": "…", "lead": "…", "subadmin": "…", "admin": "…", "accounts": "…" },
  "members": { "user": ["carol"], "lead": ["alice"], "subadmin": [], "admin": ["pbadmin"] } }
```

`members` nennt jedes Konto nur unter seiner stärksten Rolle. Konten, die nur
in der Konten-Gruppe stehen, sind keine Mitglieder und fehlen hier.
`groups.accounts` wie bei `/me`.

## Verwaltung der Teams (nur Nextcloud-Admins)

Für die Admin-Seite und die Einrichtungsskripte, unter demselben Basis-Pfad:

| Methode | Pfad | Rumpf |
|---|---|---|
| GET | `/admin/teams` | – → Liste wie `GET /team`, ohne `members`, mit `counts` je Rolle, dazu `counts.accounts` (Mitglieder der Konten-Gruppe), wenn gesetzt |
| POST | `/admin/teams` | `{ "name", "slug", "groups": { "user", "lead", "subadmin", "admin", "accounts"? } }` |
| PUT | `/admin/teams/{id}` | wie POST; fehlt `accounts` oder ist es leer, entfällt die Konten-Gruppe |
| DELETE | `/admin/teams/{id}` | nur ohne Datensätze, sonst `409` |

Die Gruppen müssen existieren (`422 invalid`) und dürfen keinem anderen Team
gehören (`409 conflict`). `slug`: `^[a-z0-9-]{2,32}$`, eindeutig.

## Nachträge aus der Umsetzung (26.09.2026)

Präzisierungen, die die App so umsetzt. Nichts oben Stehendes ändert sich.

- **Admin-Endpunkte für Nicht-Admins:** Das `403` kommt von Nextcloud selbst,
  bevor die App läuft. `data` ist dann leer (`[]`), die Meldung steht in
  `meta.message`. Alle anderen Fehler haben `data.error`.
- **Grössen:** `data` über 256 KB → `413 too_large`. Rumpf eines `PUT` oder
  Restore höchstens 1 MB, eines Batch höchstens 50 MB, mehr als 500
  Schreibungen → `413 too_large`.
- **Rumpf:** `kind`, `key` und `id` stehen im Pfad; stehen sie im Rumpf →
  `400 invalid`. Kein JSON-Objekt, `version` fehlt oder ist keine ganze Zahl
  ≥ 0 → `400 invalid`. Schlüssel oder Art falsch → `400 invalid`.
- **`PUT` auf einen Grabstein:** `version` darf `0` oder die Fassung des
  Grabsteins sein; neu ist dann Grabstein + 1.
- **`DELETE`:** `version` fehlt → `400`. Unbekannt oder schon Grabstein → `404`.
- **Batch:** Derselbe Datensatz zweimal → `422 invalid`. Löschen eines
  unbekannten oder schon gelöschten Datensatzes ist ein Konflikt
  (`current` ist `null` bzw. der Grabstein). Fehler einer einzelnen Schreibung
  nennen ihre Nummer in `message` und den Index in `data.index`. Leere Liste:
  nichts geschrieben, Antwort mit der aktuellen Revision. Jede Schreibung
  bekommt eine eigene Revision, fortlaufend.
- **Restore:** Fassung unbekannt → `404`; die Fassung ist ein Grabstein → `422`.
- **Grabsteine einer Person** behalten die Konten aus `data.accounts`: Die
  Person bekommt ihren eigenen Grabstein im Delta.
- **`person_key`:** Gibt es mehrere eigene Personen, zuerst die mit Schlüssel
  = Kennung, sonst die erste nach Schlüssel.
- **`POST /status`:** Nur die geschickten Felder ändern sich; `null` löscht
  ein Feld. `calendar_url` nur `http(s)`, höchstens 1000 Zeichen;
  `app_version` höchstens 32 Zeichen `A–Z a–z 0–9 . _ + -`.
- **`GET /backups?uid=`** für ein Konto, das nicht im Team ist: leere Liste.
  Base64 darf Zeilenumbrüche enthalten. Die Datei darf mit BOM oder
  Leerzeilen vor `BEGIN:VCALENDAR` beginnen.
- **Konto gelöscht:** Personendatensätze bleiben unverändert (`data`,
  Fassung, Revision); die App vermerkt es intern (`account_deleted_at`). In
  der Schnittstelle erscheint das bisher nicht.
- **Verwaltung:** `POST`/`PUT /admin/teams` antworten mit dem Team wie in der
  Liste (mit `counts`), `DELETE` mit `{ "id", "deleted": true }`. Löschen nur
  ohne Datensätze (auch Grabsteine) **und ohne Sicherungen**, sonst `409`.
  Jede Rolle braucht eine eigene Gruppe (`422`). Eine gelöschte Rollen-Gruppe
  markiert das Team als „Zuordnung gebrochen“ (Admin-Seite, rot). Eine
  gelöschte Konten-Gruppe verliert nur ihre Zuordnung; das Team gilt **nicht**
  als gebrochen, Nextclouds Log vermerkt es.
- **Drosselung:** Schreibende Endpunkte höchstens 300 Aufrufe je Minute und
  Konto, `POST /backups` 60; darüber antwortet Nextcloud mit `429`.

- **Form der Einstellungen** (aus dem Fork, 26.09.2026): `setting/targethours`
  hat als `data` `{"entries": [{"from", "weekly_hours", "note"}]}`,
  `setting/settings` hat `{"vacation_code": …}`.
- **Gruppenadmins:** Team-Admins und Verwaltung brauchen in Nextcloud
  Gruppenadmin-Rechte auf die vier Gruppen ihres Teams und auf die
  Konten-Gruppe. Rollen, Konten und Austritte laufen über Nextclouds
  Provisioning-API. Nextcloud lässt Gruppenadmins niemanden aus der letzten
  Gruppe nehmen, die sie verwalten (OCS 105). Mit der Konten-Gruppe bleibt das
  Konto in einer verwalteten Gruppe: Alle vier Rollen-Gruppen lassen sich
  entziehen, danach gibt `/me` für das Konto `403 no_team`. Ohne
  Konten-Gruppe kann die letzte Rolle nur ein Nextcloud-Admin entziehen.

## Sicherungen, Fassung 1.1 (26.09.2026)

Vorgabe des Nutzers: Die Sicherungen entstehen auf dem Server, liegen als
Dateien je Person in einem Ordner beim Admin und lassen sich zurückspielen.

- **Der Server sichert selbst.** Ein Hintergrundjob exportiert einmal je
  Kalenderwoche den Zeitkalender jedes Teammitglieds mit Nextclouds
  öffentlicher Schnittstelle `OCP\Calendar\ICalendarExport` (seit Nextcloud
  32). Welcher Kalender: `calendar_url` aus dem letzten Lebenszeichen, sonst
  der Kalender mit der URI `zeit-<uid>` im Heim des Kontos. `source` einer
  solchen Sicherung ist `"server"`, hochgeladene haben `"client"`.
  `POST /backups` bleibt für Clients bestehen, der Mac-Fork nutzt es nicht mehr.
- **Zwei Ablagen.** Geschützt wie bisher in `IAppData` (daraus liest die
  Schnittstelle), dazu **sichtbar** als Datei im Ordner des
  Sicherungs-Kontos des Teams: `TimeSister-Sicherungen/<Anzeigename> (<uid>)/<YYYY-MM-DD>.ics`.
  Das Sicherungs-Konto (`backup_owner`) wählt der Nextcloud-Admin auf der
  Admin-Seite unter den Konten mit Rolle `admin`; ohne Wahl der erste
  `admin` des Teams nach Kennung. Die sichtbare Kopie darf dort gelöscht
  werden; die geschützte bleibt.
- `GET /backups` und `GET /backups/{id}` nennen zusätzlich `source` und
  `file_path` (Pfad im Ordner des Sicherungs-Kontos, oder `null`).
  `GET /team` nennt `backup_owner`.
- `POST /backups/now` mit `{ "uid"? }`: sofort sichern. Ohne `uid` das
  eigene Konto; fremde nur subadmin und admin. Antwort wie ein Eintrag aus
  `GET /backups`.
- **Kein Zurückspielen auf dem Server** (Entscheidung des Nutzers,
  26.09.2026: „der Weg über alte Termine reicht vollkommen“). Die Person
  spielt eine Sicherung in ihrer Mac-App über „Alte Termine“ zurück, das
  den Zeitkalender mit eigenen Rechten ersetzt; die Mac-App holt die Datei
  dafür mit `GET /backups/{id}`. Admins finden die Dateien im Ordner des
  Sicherungs-Kontos.
- **Freigabe durch die Person** (Vorgabe des Nutzers, 26.09.2026: „der NC user
  muss generell einen Button haben, der das Backup beim Admin freigibt. das
  kann er zurückziehen, bis dahin gespeicherte termine bleiben aber im
  backup“). Standard ist **keine** Freigabe.
  - `GET /backups/consent` → `{ "consent": bool, "since": "…"|null, "revoked_at": "…"|null }`
    für das eigene Konto.
  - `PUT /backups/consent` mit `{ "consent": true|false }`, nur für das
    eigene Konto. Antwort wie `GET`.
  - Ohne Freigabe sichert der Hintergrundjob das Konto nicht, und
    `POST /backups/now` sowie `POST /backups` antworten `403 forbidden` mit
    dem Satz „Die Person hat die Sicherung beim Admin nicht freigegeben.“
  - Zurückziehen hält nur künftige Sicherungen an. Vorhandene bleiben in
    beiden Ablagen und unterliegen der normalen Aufbewahrung.
  - `GET /status` nennt je Mitglied `backup_consent` (bool) und
    `backup_consent_since`.
- **Bestätigte Aufklärung** (Vorgabe des Nutzers, 26.09.2026: Einschalten nur
  über einen ausdrücklichen Dialog, in dem die Person „Freigabe“ eintippt und
  über ihre Personendaten aufgeklärt wird). `PUT /backups/consent` mit
  `consent: true` verlangt zusätzlich `notice` (Kennung der Fassung des
  Aufklärungstexts, z. B. `"2026-09-26"`, höchstens 32 Zeichen, sonst
  `422 invalid`). Der Server speichert sie mit Zeitpunkt; `GET /backups/consent`
  und `GET /status` nennen sie als `notice` bzw. `backup_consent_notice`.
  Zurückziehen braucht keine `notice`.
- **Kopie im eigenen Ordner** (Vorschlag des Nutzers, 26.09.2026: „wahlweise
  zusätzliche ics beim User als Kopie in einen Folder“). Unabhängig von der
  Freigabe beim Admin, ohne Dialog, Standard aus.
  - `GET /backups/own-copy` → `{ "enabled": bool }`, `PUT /backups/own-copy`
    mit `{ "enabled": bool }`, nur für das eigene Konto.
  - Ist sie an, legt der Wochenjob die Sicherung zusätzlich als
    `TimeSister-Sicherungen/<YYYY-MM-DD>.ics` in den eigenen Dateien der Person
    ab. Ohne Freigabe beim Admin entsteht **nur** diese Kopie, nichts in
    `IAppData` oder beim Admin.
  - `POST /backups/now` ohne `uid` sichert auch dann, wenn nur die eigene
    Kopie an ist, und legt dann nur sie an; die Antwort nennt dann
    `{ "own_copy": "<Pfad>" }` statt eines Listeneintrags.
  - Dateien im eigenen Ordner löscht der Server nie; die Person verwaltet sie.
  - `GET /status` nennt `own_copy` (bool) je Mitglied nicht - das geht die
    Verwaltung nichts an.
- **Nur bei Änderungen, dann ausdünnen** (Vorgabe des Nutzers, 26.09.2026:
  „es sollen auch inkrementelle Backups sein … sie müssen bereinigt werden“).
  Gilt für alle drei Ablagen (geschützt, beim Admin, eigener Ordner) und
  ersetzt „Dateien im eigenen Ordner löscht der Server nie“.
  - Eine neue Sicherung entsteht nur, wenn sich der Kalender seit der
    letzten dieser Ablage geändert hat (Vergleich der Prüfsumme). Sonst
    bleibt es bei der vorhandenen.
  - Ausdünnen: alle der letzten 8 Wochen; danach die jüngste je
    Kalendermonat bis 12 Monate; danach die jüngste je Kalenderjahr.
  - Frist für die geschützte Kopie und die beim Admin: Team-Einstellung
    `backup_retention_years` (1 bis 10, Standard 1), auf der Admin-Seite;
    `GET /team` nennt sie. Ältere werden gelöscht. Im eigenen Ordner bleiben
    die Jahressicherungen ohne Frist.
  - Gelöscht werden **nur** Dateien, die die App selbst angelegt hat und
    deren Datei-ID sie sich gemerkt hat. Verschobene oder umbenannte Dateien
    und alles andere bleiben unberührt; Ordner werden nie gelöscht. Löschen
    in Dateien von Personen geht über Nextclouds Papierkorb.
- **Staffel, endgültig** (Nutzer, 26.09.2026: „inkrementell meine ich die
  vollständigen ics kalender als datei. 4 Wochen, 12 Monate, 5 Jahre“).
  Ersetzt die Zahlen und `backup_retention_years` aus dem vorigen Punkt; die
  Team-Einstellung entfällt. Jede Sicherung ist eine **vollständige**
  `.ics`-Datei. Für alle drei Ablagen gleich:
  - alle Sicherungen der letzten **4 Wochen**;
  - danach die jüngste je Kalendermonat bis **12 Monate** zurück;
  - danach die jüngste je Kalenderjahr bis **5 Jahre** zurück;
  - älter als 5 Jahre: löschen.
  Weiterhin: neue Sicherung nur bei Änderung; gelöscht wird nur, was die
  App selbst angelegt und sich gemerkt hat.
- **Jahresstufe 10 Jahre** (Nutzer, 26.09.2026: „mache 10 Jahre draus aber min
  was das gesetz sagt“). Ersetzt „bis 5 Jahre“: die jüngste je Kalenderjahr bis
  **10 Jahre** zurück, älter löschen. Die Grenze ist eine Konstante
  (`JAHRE = 10`); verlangt ein anwendbares Gesetz mehr, wird sie angehoben,
  nie unter die gesetzliche Mindestfrist.

## Nachträge zu 1.1 aus der Umsetzung (26.09.2026)

Präzisierungen, die die App (0.2.1) so umsetzt. Nichts oben Stehendes ändert sich.

- **Tage und Wochen** in UTC; die ISO-Woche geht von Montag bis Sonntag.
- **Welcher Kalender:** `calendar_url` zählt nur, wenn sie im Heim des Kontos
  selbst liegt (`…/remote.php/dav/calendars/<uid>/<uri>/`); sonst gilt
  `zeit-<uid>`. So sichert der Server nie einen Kalender, den das Konto
  nicht sieht.
- **Export:** `ICalendarExport` liefert je Termin ein eigenes VCALENDAR; der
  Server setzt daraus eine vollständige `.ics` zusammen (eigener Kopf,
  `PRODID:-//B/IAS//TimeSister Server-Sicherung//DE`, jede VTIMEZONE einmal,
  dann alle Termine). Er läuft ohne Benutzerkontext (Wochenjob) und im
  Anfragekontext jedes berechtigten Kontos. Ein leerer Kalender ergibt eine
  gültige leere Sicherung. Kein Zeitkalender → `404 not_found`
  („Für dieses Konto gibt es keinen Zeitkalender.“), über 20 MB → `413 too_large`.
- **Nur bei Änderung:** Verglichen wird die Prüfsumme der ganzen `.ics` mit
  der zuletzt geschriebenen Sicherung derselben Ablage. Die Kopie beim Admin
  entsteht immer zusammen mit der geschützten. Die eigene Kopie gilt nur als
  vorhanden, solange die gemerkte Datei noch an ihrem Pfad liegt; hat die
  Person sie gelöscht oder verschoben, entsteht eine neue.
- **`POST /backups/now`:** Rumpf darf fehlen. Fremdes Konto: user und lead
  `403`, nicht im Team `404`, ohne Freigabe `403` mit dem Satz oben. Ist der
  Kalender unverändert, antwortet es mit dem vorhandenen letzten Eintrag
  (`taken_on` kann älter sein). Eigenes Konto mit Freigabe **und** eigener
  Kopie: der Eintrag und dazu `own_copy`. Für ein fremdes Konto schreibt der
  Endpunkt nie in dessen eigenen Ordner; das tut nur der Wochenjob. Drosselung
  20 Aufrufe je Minute.
- **`POST /backups`** (Client) bleibt Fassung 1: immer gespeichert, eine je
  Tag, ohne Vergleich der Prüfsumme; `source: "client"`. Auch dafür entsteht
  die Kopie beim Sicherungs-Konto.
- **`file_path`** ist relativ zum Heim des Sicherungs-Kontos. `null`, wenn das
  Team kein Konto mit Rolle `admin` hat oder die Kopie nicht geschrieben
  werden konnte (etwa Speicherplatz); die geschützte Sicherung entsteht
  trotzdem. Ein Wechsel des Sicherungs-Kontos verschiebt keine vorhandenen
  Dateien.
- **Wochenjob:** stündlich; je Konto und Ablage höchstens eine Prüfung je
  ISO-Woche (vermerkt als Nextcloud-Einstellung des Kontos), höchstens 100
  Konten je Lauf. Wer mitten in der Woche freigibt oder die eigene Kopie
  einschaltet, wird beim nächsten stündlichen Lauf gesichert.
- **`backup_owner`** in `GET /team` und `GET /admin/teams` ist das wirksame
  Konto: die Wahl, solange sie `admin` ist, sonst der erste `admin` nach
  Kennung, ohne `admin` `null`. `POST`/`PUT /admin/teams` nehmen
  `backup_owner`: fehlt es, bleibt die Wahl; `null` oder leer heisst
  automatisch; sonst muss das Konto in der `admin`-Gruppe des Teams stehen
  (`422 invalid`).
- **`PUT /backups/consent`:** `consent` kein Wahrheitswert → `400 invalid`;
  `uid` im Rumpf → `400 invalid`; `notice` fehlt, leer, länger als 32 Zeichen
  oder mit Steuerzeichen → `422 invalid`. Erneutes Freigeben ersetzt `notice`
  und behält `since`. Zurückziehen setzt `since` auf `null` und `revoked_at`;
  `notice` bleibt. Die Freigabe gilt je Team; wird das Konto gelöscht, fällt
  sie weg (ein neues Konto mit derselben Kennung muss neu freigeben).
- **`PUT /backups/own-copy`:** `enabled` kein Wahrheitswert oder `uid` im
  Rumpf → `400 invalid`. Gespeichert als Nextcloud-Einstellung des Kontos;
  sie fällt mit dem Konto weg. Die Admin-Seite zeigt sie nicht.
- **Ausdünnen:** täglich im Aufbewahrungsjob, Grenzen einschliesslich:
  heute − 28 Tage, heute − 12 Monate, heute − 10 Jahre (`Thinning::JAHRE`).
  Die jüngste je Monat bzw. Jahr zählt innerhalb ihrer Stufe. Tage in der
  Zukunft bleiben. Die geschützte Ablage wird je Team und Konto ausgedünnt
  (Zeile, Datei in `IAppData` und ihre Kopien beim Admin), die eigenen Kopien
  je Konto. Ersetzt die bisherige Frist von einem Jahr.
- **Merkliste:** Tabelle `ts_backup_files` mit Konto, Ablage (`admin`/`own`),
  Besitzer der Datei, Datei-ID, Pfad, Tag und Prüfsumme. Eine Datei wird nur
  gelöscht, wenn ihre Datei-ID noch auf genau diesen Pfad zeigt, über die
  Node-API (Papierkorb). Ordner nie.
- **Admin-Seite:** je Team „Sicherungen ablegen bei“ (automatisch oder ein
  Admin des Teams) und im Zustand: Zahl mit Freigabe, davon ohne Sicherung in
  dieser Woche (geprüft und unverändert zählt als gesichert), letzte
  Server-Sicherung.
- **`POST /backups/{id}/restore`** gibt es nicht (Zurückspielen macht die
  Mac-App). Befund aus einem Versuch vor dieser Entscheidung:
  `ICreateFromString::createFromString` schreibt über
  `getCalendarsForPrincipal('principals/users/<uid>')` ohne Benutzerkontext in
  den eigenen Kalender des Kontos.

## Team-Einstellungen, Fassung 1.2 (26.09.2026)

Entscheidungen des Nutzers nach dem Datenschutzbericht:

- `settings` eines Teams, in `GET /me`, `GET /team` und `GET /admin/teams`,
  setzbar über `POST`/`PUT /admin/teams` (fehlend = unverändert):
  - `leads_see_calendars` (bool, Standard **true**): Die Leitung bekommt die
    Zeitkalender aller Teammitglieder freigegeben. Aus: Die Clients nehmen
    die Freigabe an die Lead-Gruppe beim nächsten Abgleich zurück (die an die
    Admin-Gruppe bleibt).
  - `backup_required` (bool, Standard **false**): Die Verwaltung hat die
    Sicherung beim Admin angeordnet. Die Mac-App zeigt das im
    Freigabe-Dialog und beim Ausschalten.
- Neue Fassung der Aufklärung: `notice` `"2026-09-26.2"` (Wortlaut aus
  `BERICHT-DATENSCHUTZ-2026-09-26.md`, 3.1, mit der Staffel bis 10 Jahre).

## Nachträge zu 1.2 aus der Umsetzung (26.09.2026)

- `settings` = `{ "leads_see_calendars": bool, "backup_required": bool }`,
  immer mit beiden Schlüsseln. In `GET /me` steht es auf oberster Ebene (wie
  `groups`), in `GET /team` und `GET /admin/teams` im Team-Objekt.
- `POST`/`PUT /admin/teams`: fehlt `settings` oder ist es `null`, bleibt es
  (beim Anlegen: Standardwerte); fehlt ein Schlüssel, bleibt dieser.
  Unbekannter Schlüssel, kein Wahrheitswert oder kein Objekt → `422 invalid`.
- Gespeichert in `ts_tenants` (Migration 1003, App 0.2.2); bestehende Teams
  haben die Standardwerte.
- `notice` `"2026-09-26.2"` wird angenommen; die Regel bleibt: 1 bis 32
  Zeichen ohne Steuerzeichen.
