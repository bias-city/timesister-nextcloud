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
- Ein Konto gehört zu dem Team, in dessen Rollen-Gruppen es steht. Steht es in
  Gruppen von zwei Teams: `409 ambiguous_team`. In keiner: `403 no_team`.
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
              "subadmin": "pb-verwaltung", "admin": "pb-admin" },
  "person_key": "alice", "revision": 118, "server_time": "2026-09-26T08:15:00Z" }
```

`person_key` ist `null`, wenn es keinen eigenen Personendatensatz gibt.

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
  "groups": { "user": "…", "lead": "…", "subadmin": "…", "admin": "…" },
  "members": { "user": ["carol"], "lead": ["alice"], "subadmin": [], "admin": ["pbadmin"] } }
```

`members` nennt jedes Konto nur unter seiner stärksten Rolle.

## Verwaltung der Teams (nur Nextcloud-Admins)

Für die Admin-Seite und die Einrichtungsskripte, unter demselben Basis-Pfad:

| Methode | Pfad | Rumpf |
|---|---|---|
| GET | `/admin/teams` | – → Liste wie `GET /team`, ohne `members`, mit `counts` je Rolle |
| POST | `/admin/teams` | `{ "name", "slug", "groups": { "user", "lead", "subadmin", "admin" } }` |
| PUT | `/admin/teams/{id}` | wie POST |
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
  markiert das Team als „Zuordnung gebrochen“ (Admin-Seite, rot).
- **Drosselung:** Schreibende Endpunkte höchstens 300 Aufrufe je Minute und
  Konto, `POST /backups` 60; darüber antwortet Nextcloud mit `429`.
