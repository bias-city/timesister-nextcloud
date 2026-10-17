> **Copy.** The authoritative version is `nc-app/API.md` in the monorepo
> until the repository `bias-city/timesister-nextcloud` is split off. After that, this file applies.

# TimeSister Server – API Version 1 (Contract)

As of 2026-09-27. This contract applies to the Nextcloud app `timesister`
(`nc-app/timesister/`) and the Mac app "TimeSister" (`core/`). Whoever
deviates from it changes this file first. Plan and rationale: `PLAN-NC-APP.md`.

## General

- Base: `/ocs/v2.php/apps/timesister/api/v1`
- Login: HTTP Basic with Nextcloud account and app password, as today.
- Required headers: `OCS-APIRequest: true`, `Accept: application/json`.
  Bodies as `Content-Type: application/json`.
- Response in the OCS envelope: `{"ocs":{"meta":{"status","statuscode","message"},"data":…}}`.
  With OCS v2 the HTTP status matches `statuscode`. Below, only `data` is
  described.
- Errors: HTTP 400, 403, 404, 409, 413 or 422, with
  `data = { "error": "<code>", "message": "<sentence in the account's language>", …}`.
  Codes: `no_team`, `ambiguous_team`, `forbidden`, `not_found`, `conflict`,
  `invalid`, `too_large`. `message` is a sentence in the account's language:
  Nextcloud's language setting for the account, else the `Accept-Language`
  header, else English. English is the source text; German comes from
  `l10n/de.json` (informal) and `de_DE.json` (formal). Clients decide on the
  `error` code, never on the wording.
- Times as ISO 8601 in UTC (`2026-09-26T08:15:00Z`), days as `YYYY-MM-DD`.

## Capability

Nextcloud's capabilities (`/ocs/v1.php/cloud/capabilities`) contain:

```json
"timesister": { "api": 1, "version": "0.1.0" }
```

If the entry is missing, the app is not installed or is disabled. Later
versions add flags (`jobs`, `billing`, …); the current set is listed under
“Visibility per role, 0.14.0”.

## Teams and roles

- A **team** has `id`, `name`, `slug` and exactly four role groups:
  `user`, `lead`, `subadmin`, `admin`, each an existing Nextcloud group.
  A group belongs to at most one team.
- Optionally also an **accounts group** `accounts`, likewise an existing
  Nextcloud group. All of the team's accounts are in it; it is **not** a
  role. The team admins also manage it as group admins. This way every
  account stays in one of its groups, and they can remove any role group
  (Nextcloud rule OCS 105, see addenda). It may not belong to any other
  team, either as a role or as the accounts group (`409 conflict`), and may
  not be one of the same team's four role groups (`422 invalid`).
- An account belongs to the team whose role groups it is in. If it is in
  groups of two teams: `409 ambiguous_team`. In none: `403 no_team`. The
  accounts group never counts for this: whoever is only in it gets
  `403 no_team`; whoever is in team A's accounts group and in a role group
  of team B belongs to B.
- `groups` in `/me`, `/team` and `/admin/teams` names `accounts` only if the
  team has an accounts group; otherwise the field is missing.
- The role is the strongest role from these groups:
  `admin` > `subadmin` > `lead` > `user`.
- Nextcloud's admin is **not** automatically a role in a team.
- The team is **always** derived from the caller's membership, never from
  the request.

## Records

| `kind` | key | content (`data`) |
|---|---|---|
| `person` | person's `login` (as today the file names in `people/`) | person, English fields as in `marke::FELDER` |
| `region` | `id` | location with `holidays` |
| `project` | `id` | project including `subprojects`, `budgets`, `milestones` |
| `customer` | `id` | customer including `contacts`, `cost_centers`, `quotes` |
| `setting` | `targethours` or `settings` | target hours or settings (`vacation_code`) |

- Key: `^[A-Za-z0-9@._+-]{1,128}$`.
- `data` is a JSON object, at most 256 KB. Required: for `person` a field
  `login` equal to the key, for `region`, `project`, `customer` a field `id`
  equal to the key. Otherwise `422 invalid`.
- **Own person:** the record whose key equals the caller's Nextcloud user
  ID, or whose `data.accounts` contains it.

A record in responses:

```json
{ "kind": "person", "key": "alice", "version": 3, "revision": 118,
  "deleted": false, "modified_by": "admin", "modified_at": "2026-09-26T08:15:00Z",
  "data": { … } }
```

`data` is `null` for tombstones (`deleted: true`). `version` counts per
record from 1. `revision` is the team's state after this write; it
increases with every write in the team.

## Permissions

| | user | lead | subadmin, admin |
|---|---|---|---|
| `setting`, `region`, `project` | read | read | read, write |
| `person`, own | read | read | read, write |
| `person`, others' | – | read | read, write |
| `customer` | – | read | read, write |
| History | own person | own person | all |
| Calendar backups | own | own | all |
| Submit status report | own | own | own |
| Read status reports, read team | – | – | yes |

Unreadable items do not appear in lists; direct access gives `403 forbidden`.
A person's `cost_rates` (0.16.0) are read only by subadmin and admin – not even on the own person; see Cost rates on the person.

> Since 0.14.0 this table is superseded by the one under “Visibility per
> role, 0.14.0” (Leads: name cards of others, only the customers of their
> projects, booking reports and billable marks).

## Endpoints

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

`person_key` is `null` if there is no own person record. `groups.accounts`
is missing if the team has no accounts group.

### `GET /records?since=<revision>`

All records readable by the caller with `revision > since`, including
tombstones. `since` missing or 0: everything, without tombstones.

```json
{ "revision": 118, "records": [ … ] }
```

### `GET /records/{kind}/{key}`

One record. `404 not_found`, also for tombstones.

### `PUT /records/{kind}/{key}`

```json
{ "version": 3, "data": { … } }
```

- `version` is the version the change is based on. `0`: create new – if the
  record already exists (also as a tombstone with an older version), this
  is a conflict, unless it is a tombstone; then it is created anew with
  `version = tombstone.version + 1`.
- If `version` does not match: `409 conflict` with
  `data = { "error": "conflict", "message": …, "current": <record> }`.
- Success: `200` with the new record.

### `DELETE /records/{kind}/{key}?version=<n>`

Creates a tombstone. `409 conflict` as above. Success: the tombstone.

### `POST /records/batch`

```json
{ "writes": [ { "kind": "project", "key": "D200", "version": 0, "data": { … } },
              { "kind": "region", "key": "CH-BS", "version": 2, "data": null } ] }
```

`data: null` means delete. All or nothing in **one** transaction. On
conflicts: `409` with `data.conflicts = [ { "kind", "key", "current" } ]`,
nothing written. Success: `{ "revision": 131, "records": [ … ] }`. At most
500 writes per call. subadmin and admin only.

### `GET /records/{kind}/{key}/history`

The versions, newest first, at most 100:

```json
[ { "version": 3, "deleted": false, "modified_by": "admin",
    "modified_at": "…", "data": { … } } ]
```

### `POST /records/{kind}/{key}/restore`

```json
{ "version": 2, "current": 3 }
```

Writes version 2 as the new version 4. `current` like `version` in `PUT`.
subadmin and admin only.

### `POST /backups`

```json
{ "taken_on": "2026-09-22", "ics_base64": "QkVHSU46VkNBTEVOREFS…" }
```

Calendar backup of the caller. At most 20 MB after decoding
(`413 too_large`). Must start with `BEGIN:VCALENDAR` (`422 invalid`). One
backup per account and day; a second on the same day replaces the first.

```json
{ "id": 17, "uid": "alice", "taken_on": "2026-09-22", "size": 48213, "sha256": "…" }
```

### `GET /backups?uid=<uid>`

List without content, newest first. Without `uid`: the caller's own.
Others' only for subadmin and admin.

### `GET /backups/{id}`

As above, plus `ics_base64`.

### `POST /status`

```json
{ "app_version": "0.2.0", "last_sync": "…", "last_backup": "2026-09-22",
  "calendar_url": "https://…/remote.php/dav/calendars/alice/zeit-alice/" }
```

Status report of the caller. Response: `{ "seen_at": "…" }`.

### `GET /status`

All team members, including those without a status report:

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

`members` names each account only under its strongest role. Accounts that
are only in the accounts group are not members and are missing here.
`groups.accounts` as in `/me`.

## Team administration (Nextcloud admins only)

For the admin page and the setup scripts, under the same base path:

| Method | Path | Body |
|---|---|---|
| GET | `/admin/teams` | – → list as in `GET /team`, without `members`, with `counts` per role, plus `counts.accounts` (members of the accounts group), if set |
| POST | `/admin/teams` | `{ "name", "slug", "groups": { "user", "lead", "subadmin", "admin", "accounts"? } }` |
| PUT | `/admin/teams/{id}` | as POST; if `accounts` is missing or empty, the accounts group is removed |
| DELETE | `/admin/teams/{id}` | `{ "confirm": "<team name>" }`; since 0.10.1 also with content, a ZIP is stored first (see below) |

The groups must exist (`422 invalid`) and may not belong to any other team
(`409 conflict`). `slug`: `^[a-z0-9-]{2,32}$`, unique.

## Addenda from implementation (2026-09-26)

Clarifications the app implements this way. Nothing stated above changes.

- **Admin endpoints for non-admins:** the `403` comes from Nextcloud itself,
  before the app runs. `data` is then empty (`[]`), the message is in
  `meta.message`. All other errors have `data.error`.
- **Sizes:** `data` over 256 KB → `413 too_large`. Body of a `PUT` or
  restore at most 1 MB, of a batch at most 50 MB, more than 500 writes →
  `413 too_large`.
- **Body:** `kind`, `key` and `id` are in the path; if they are in the body
  → `400 invalid`. Not a JSON object, `version` missing or not an integer
  ≥ 0 → `400 invalid`. Wrong key or kind → `400 invalid`.
- **`PUT` on a tombstone:** `version` may be `0` or the tombstone's version;
  the new one is then tombstone + 1.
- **`DELETE`:** `version` missing → `400`. Unknown or already a tombstone →
  `404`.
- **Batch:** the same record twice → `422 invalid`. Deleting an unknown or
  already deleted record is a conflict (`current` is `null` or the
  tombstone, respectively). Errors of an individual write name its number
  in `message` and the index in `data.index`. Empty list: nothing written,
  response with the current revision. Each write gets its own revision,
  sequentially.
- **Restore:** version unknown → `404`; the version is a tombstone → `422`.
- **Tombstones of a person** keep the accounts from `data.accounts`: the
  person gets their own tombstone in the delta.
- **`person_key`:** if there are several own persons, first the one with
  key = user ID, otherwise the first by key.
- **`POST /status`:** only the fields sent change; `null` clears a field.
  `calendar_url` only `http(s)`, at most 1000 characters; `app_version` at
  most 32 characters `A–Z a–z 0–9 . _ + -`.
- **`GET /backups?uid=`** for an account not in the team: empty list.
  Base64 may contain line breaks. The file may start with a BOM or blank
  lines before `BEGIN:VCALENDAR`.
- **Account deleted:** person records remain unchanged (`data`, version,
  revision); the app notes it internally (`account_deleted_at`). This does
  not yet appear in the API.
- **Administration:** `POST`/`PUT /admin/teams` respond with the team as in
  the list (with `counts`), `DELETE` with `{ "id", "deleted": true }`.
  Deletion only without records (including tombstones) **and without
  backups**, otherwise `409`. Every role needs its own group (`422`). A
  deleted role group marks the team as "mapping broken" (admin page, red).
  A deleted accounts group only loses its mapping; the team does **not**
  count as broken, Nextcloud's log notes it.
- **Throttling:** writing endpoints at most 300 calls per minute and
  account, `POST /backups` 60; beyond that Nextcloud responds with `429`.

- **Form of settings** (from the fork, 2026-09-26): `setting/targethours`
  has as `data` `{"entries": [{"from", "weekly_hours", "note"}]}`,
  `setting/settings` has `{"vacation_code": …}`.
- **Group admins:** team admins and managers need Nextcloud group-admin
  rights on their team's four groups and on the accounts group. Roles,
  accounts and leaving run through Nextcloud's provisioning API. Nextcloud
  does not let group admins remove anyone from the last group they manage
  (OCS 105). With the accounts group, the account stays in a managed
  group: all four role groups can be removed, after which `/me` returns
  `403 no_team` for the account. Without an accounts group, only a
  Nextcloud admin can remove the last role.

## Backups, version 1.1 (2026-09-26)

User's requirement: backups are created on the server, stored as files per
person in a folder at the admin's, and can be restored.

- **The server backs up itself.** A background job exports each team
  member's time calendar once per calendar week using Nextcloud's public
  API `OCP\Calendar\ICalendarExport` (since Nextcloud 32). Which calendar:
  `calendar_url` from the last status report, otherwise the calendar with
  URI `zeit-<uid>` in the account's home. `source` of such a backup is
  `"server"`, uploaded ones have `"client"`. `POST /backups` remains for
  clients, the Mac fork no longer uses it.
- **Two locations.** Protected as before in `IAppData` (the API reads from
  there), plus **visible** as a file in the folder of the team's backup
  owner: `TimeSister Backups/<display name> (<uid>)/<YYYY-MM-DD>.ics`
  (previously `TimeSister-Sicherungen/…`; existing files stay in the old
  folder, their recorded `file_path` remains valid, and thinning deletes
  them as before; new copies go into the new folder). The Nextcloud admin
  chooses the backup owner (`backup_owner`) on the admin page among
  accounts with role `admin`; without a choice, the team's first `admin`
  by user ID. The visible copy may be deleted there; the protected one
  remains.
- `GET /backups` and `GET /backups/{id}` additionally name `source` and
  `file_path` (path within the backup owner's folder, or `null`).
  `GET /team` names `backup_owner`.
- `POST /backups/now` with `{ "uid"? }`: back up immediately. Without `uid`
  the caller's own account; others' only subadmin and admin. Response like
  an entry from `GET /backups`.
- **No restoring on the server** (user's decision, 2026-09-26: "the route
  via old appointments is entirely sufficient"). The person restores a
  backup in their Mac app via "Old Appointments", which replaces the time
  calendar with their own rights; the Mac app fetches the file for this
  with `GET /backups/{id}`. Admins find the files in the backup owner's
  folder.
- **Consent by the person** (user's requirement, 2026-09-26: "the NC user
  must generally have a button that gives the admin consent for the
  backup. They can withdraw it; appointments already saved by then remain
  in the backup"). Default is **no** consent.
  - `GET /backups/consent` → `{ "consent": bool, "since": "…"|null, "revoked_at": "…"|null }`
    for the caller's own account.
  - `PUT /backups/consent` with `{ "consent": true|false }`, only for the
    caller's own account. Response like `GET`.
  - Without consent, the background job does not back up the account, and
    `POST /backups/now` and `POST /backups` respond `403 forbidden` with
    the sentence "The person has not agreed to backups with the Team Admin." (until 0.4.0 "… with the admin.")
    (translated per account language; clients act on `error: forbidden`,
    never on the wording).
  - Withdrawing only stops future backups. Existing ones remain in both
    locations and are subject to normal retention.
  - `GET /status` names `backup_consent` (bool) and `backup_consent_since`
    per member.
- **Confirmed disclosure** (user's requirement, 2026-09-26: enabling only
  via an explicit dialog in which the person types "consent" and is
  informed about their personal data). `PUT /backups/consent` with
  `consent: true` additionally requires `notice` (identifier of the
  version of the disclosure text, e.g. `"2026-09-26"`, at most 32
  characters, otherwise `422 invalid`). The server stores it with a
  timestamp; `GET /backups/consent` and `GET /status` name it as `notice`
  and `backup_consent_notice`, respectively. Withdrawing needs no `notice`.
- **Copy in the person's own folder** (user's suggestion, 2026-09-26:
  "optionally an additional ics for the user as a copy in a folder").
  Independent of consent with the admin, without a dialog, default off.
  - `GET /backups/own-copy` → `{ "enabled": bool }`, `PUT /backups/own-copy`
    with `{ "enabled": bool }`, only for the caller's own account.
  - If it is on, the weekly job additionally places the backup as
    `TimeSister Backups/<YYYY-MM-DD>.ics` (previously
    `TimeSister-Sicherungen/…`, see above) in the person's own files.
    Without consent with the admin, **only** this copy is created, nothing
    in `IAppData` or at the admin's.
  - `POST /backups/now` without `uid` also backs up when only the own copy
    is on, and then creates only that; the response then names
    `{ "own_copy": "<path>" }` instead of a list entry.
  - The server never deletes files in the person's own folder; the person
    manages them.
  - `GET /status` does not name `own_copy` (bool) per member – that is
    none of the administration's business.
- **Only on changes, then thinned** (user's requirement, 2026-09-26:
  "backups should also be incremental … they need to be cleaned up").
  Applies to all three locations (protected, at the admin's, own folder)
  and replaces "the server never deletes files in the own folder".
  - A new backup is created only if the calendar has changed since the
    last one in this location (checksum comparison). Otherwise the
    existing one stays.
  - Thinning: all of the last 8 weeks; after that the newest per calendar
    month up to 12 months; after that the newest per calendar year.
  - Retention period for the protected copy and the one at the admin's:
    team setting `backup_retention_years` (1 to 10, default 1), on the
    admin page; `GET /team` names it. Older ones are deleted. In the own
    folder, the yearly backups remain without a limit.
  - Only files the app itself created and whose file ID it has recorded
    are deleted. Moved or renamed files and everything else remain
    untouched; folders are never deleted. Deleting files owned by persons
    goes through Nextcloud's trash.
- **Final tiering** (user, 2026-09-26: "by incremental I mean the complete
  ics calendars as files. 4 weeks, 12 months, 5 years"). Replaces the
  numbers and `backup_retention_years` from the previous point; the team
  setting is removed. Every backup is a **complete** `.ics` file. The same
  for all three locations:
  - all backups of the last **4 weeks**;
  - after that the newest per calendar month back to **12 months**;
  - after that the newest per calendar year back to **5 years**;
  - older than 5 years: deleted.
  Still applies: new backup only on change; only what the app itself
  created and recorded is deleted.
- **10-year yearly tier** (user, 2026-09-26: "make it 10 years but at
  least what the law requires"). Replaces "up to 5 years": the newest per
  calendar year back to **10 years**, older deleted. The limit is a
  constant (`JAHRE = 10`); if an applicable law requires more, it is
  raised, never below the legal minimum retention period.

## Addenda to 1.1 from implementation (2026-09-26)

Clarifications the app (0.2.1) implements this way. Nothing stated above
changes.

- **Days and weeks** in UTC; the ISO week runs Monday to Sunday.
- **Which calendar:** `calendar_url` only counts if it is in the account's
  own home (`…/remote.php/dav/calendars/<uid>/<uri>/`); otherwise
  `zeit-<uid>` applies. This way the server never backs up a calendar the
  account cannot see.
- **Export:** `ICalendarExport` returns a separate VCALENDAR per
  appointment; the server assembles a complete `.ics` from these (own
  header, `PRODID:-//B/IAS//TimeSister Server Backup//EN`, each VTIMEZONE
  once, then all appointments). It runs without a user context (weekly
  job) and in the request context of each authorized account. An empty
  calendar yields a valid empty backup. No time calendar → `404 not_found`
  ("This account has no time calendar."), over 20 MB → `413 too_large`.
- **Only on change:** the checksum of the whole `.ics` is compared with
  the last backup written in the same location. The copy at the admin's is
  always created together with the protected one. The own copy counts as
  existing only as long as the recorded file is still at its path; if the
  person deleted or moved it, a new one is created.
- **`POST /backups/now`:** body may be omitted. Another account: user and
  lead `403`, not in the team `404`, without consent `403` with the
  sentence above. If the calendar is unchanged, it responds with the
  existing last entry (`taken_on` may be older). Own account with consent
  **and** own copy: the entry plus `own_copy`. For another account, the
  endpoint never writes to that account's own folder; only the weekly job
  does that. Throttling 20 calls per minute.
- **`POST /backups`** (client) remains version 1: always stored, one per
  day, without checksum comparison; `source: "client"`. This too creates
  the copy at the backup owner's.
- **`file_path`** is relative to the backup owner's home. `null` if the
  team has no account with role `admin` or the copy could not be written
  (e.g. storage space); the protected backup is created regardless. A
  change of backup owner does not move existing files.
- **Weekly job:** hourly; at most one check per account and location per
  ISO week (recorded as a Nextcloud setting of the account), at most 100
  accounts per run. Whoever grants consent or enables the own copy
  mid-week is backed up on the next hourly run.
- **`backup_owner`** in `GET /team` and `GET /admin/teams` is the
  effective account: the chosen one, as long as it is `admin`, otherwise
  the first `admin` by user ID, without `admin` `null`. `POST`/`PUT
  /admin/teams` accept `backup_owner`: if missing, the choice stays;
  `null` or empty means automatic; otherwise the account must be in the
  team's `admin` group (`422 invalid`).
- **`PUT /backups/consent`:** `consent` not a boolean → `400 invalid`;
  `uid` in the body → `400 invalid`; `notice` missing, empty, longer than
  32 characters or with control characters → `422 invalid`. Granting
  consent again replaces `notice` and keeps `since`. Withdrawing sets
  `since` to `null` and `revoked_at`; `notice` stays. Consent applies per
  team; if the account is deleted, it lapses (a new account with the same
  user ID must give consent again).
- **`PUT /backups/own-copy`:** `enabled` not a boolean or `uid` in the
  body → `400 invalid`. Stored as a Nextcloud setting of the account; it
  lapses with the account. The admin page does not show it.
- **Thinning:** daily in the retention job, inclusive limits: today − 28
  days, today − 12 months, today − 10 years (`Thinning::JAHRE`). The
  newest per month or year counts within its tier. Days in the future
  remain. The protected location is thinned per team and account (row,
  file in `IAppData` and its copies at the admin's), own copies per
  account. Replaces the previous one-year retention period.
- **Record list:** table `ts_backup_files` with account, location
  (`admin`/`own`), owner of the file, file ID, path, day and checksum. A
  file is deleted only if its file ID still points to exactly this path,
  via Nextcloud's node API (trash). Folders never.
- **Admin page:** per team "store backups at" (automatic or a team admin)
  and in the status: count with consent, of those without a backup this
  week (checked and unchanged counts as backed up), latest server backup.
- **`POST /backups/{id}/restore`** does not exist (restoring is done by
  the Mac app). Finding from an attempt before this decision:
  `ICreateFromString::createFromString` writes via
  `getCalendarsForPrincipal('principals/users/<uid>')` without a user
  context into the account's own calendar.

## Team settings, version 1.2 (2026-09-26)

User's decisions after the privacy report:

- A team's `settings`, in `GET /me`, `GET /team` and `GET /admin/teams`,
  settable via `POST`/`PUT /admin/teams` (missing = unchanged):
  - `leads_see_calendars` (bool, default **true**): the leads are given
    the time calendars of all team members shared with them. Off: clients
    withdraw the share to the lead group on the next sync (the one to the
    admin group remains).
  - `backup_required` (bool, default **false**): management has mandated
    consent for backups with the admin. The Mac app shows this in the
    consent dialog and when switching it off.
- New version of the disclosure: `notice` `"2026-09-26.2"` (wording from
  `BERICHT-DATENSCHUTZ-2026-09-26.md`, 3.1, with the tiering up to 10
  years).

## Addenda to 1.2 from implementation (2026-09-26)

- `settings` = `{ "leads_see_calendars": bool, "backup_required": bool }`,
  always with both keys. In `GET /me` it is at the top level (like
  `groups`), in `GET /team` and `GET /admin/teams` inside the team object.
- `POST`/`PUT /admin/teams`: if `settings` is missing or `null`, it stays
  (on creation: default values); if a key is missing, it stays. Unknown
  key, not a boolean or not an object → `422 invalid`.
- Stored in `ts_tenants` (migration 1003, app 0.2.2); existing teams have
  the default values.
- `notice` `"2026-09-26.2"` is accepted; the rule remains: 1 to 32
  characters without control characters.

## Teams and roles, version 2 (2026-09-26) – replaces the role groups

User's decision: only **two** groups per team, roles live in the app, the
app enforces the owner's shares. `api` rises to **2** (capabilities
`timesister: { api: 2, … }`). Version 1 only ever held test data; there is
no migration from version 1.

**Groups per team** (`groups`):
- `team`: all members. Team admins are group admins of this group (create
  accounts).
- `zeit`: the app admins. Each person shares their time calendar with this
  group. Team admins are also group admins here.
- Both are existing Nextcloud groups, different, not assigned to any other
  team (`409`), otherwise `422`. The role groups `user`, `lead`,
  `subadmin`, `admin` and the accounts group `accounts` are removed.

**Membership and role:**
- A member is whoever is in `team` or `zeit` and is not recorded as having
  left (`left_at`). Otherwise `403 no_team`; in groups of two teams
  `409 ambiguous_team`.
- Role: `admin` for whoever is in `zeit`. Otherwise the app role
  `subadmin` or `lead`, otherwise `user`.
- The app stores app roles and leaving (table, additive). They do not
  appear in Nextcloud's user management.

**Endpoints:**
- `GET /me`: as before, `groups` = `{ team, zeit }`, plus `leads`: the
  user IDs of all members with role `lead` (for the shares), and
  `calendar_share` (see below).
- `GET /team`: `members: [ { uid, display_name, role, left_at } ]` (also
  those who left, with date), `groups`, `settings`, `backup_owner`.
- `PUT /team/members/{uid}` with `{ "role"?: "user"|"lead"|"subadmin", "left"?: bool }`:
  subadmin and admin; only admin grants `subadmin`. The account must be in
  `team` or `zeit` (otherwise `404`). `admin` is not an app role: that is
  membership in `zeit` (via Nextcloud). `left: true` records leaving
  (account stays in the groups but no longer belongs to the team); `left:
  false` rejoins. Response: the entry as in `/team`.
- `GET /me/calendar-share` → `{ "enabled": bool }`, `PUT` with
  `{ "enabled": bool }`: the person's "share time calendar with the team"
  switch, default **true**, own account only, stored on the server.
- `POST /status` additionally accepts `calendar_shared` (bool, actual
  state), `GET /status` names it per member.
- `POST`/`PUT /admin/teams`: `{ name, slug, groups: { team, zeit }, settings?, backup_owner? }`.

**Shares (the owner's Mac app's task, on every sync):**
- If `calendar_share` is on: share with the group `zeit` and, if
  `settings.leads_see_calendars`, with every user ID from `leads`; each
  with the right as before to the admin or lead group. Missing or changed
  app shares are restored, even if the person removed them in the
  Nextcloud interface. Shares to user IDs no longer authorized, which the
  app itself set, are removed. Other shares by the person remain
  untouched.
- If it is off: the app removes its shares and sets no new ones.

### Addendum to version 2: no time group (2026-09-26)

The user: "does the time group even need to exist for the admins?!" – no.
Replaces the `zeit` group in all points above:

- One group per team: `groups` = `{ team }`.
- **The team's admin is whoever is a Nextcloud group admin of the team
  group** (`OCP\Group\ISubAdmin`). This is needed anyway to create
  accounts. The Nextcloud admin appoints admins in user management; the
  app does not grant `admin`.
- A member is whoever is in `team` or is a group admin of `team`, and has
  not left.
- `/me` also names `admins` (user IDs) alongside `leads`. The owner's Mac
  app shares its time calendar **individually** with every user ID from
  `admins` (right as before to the admin group) and, if
  `leads_see_calendars`, with every one from `leads`. The customer address
  book likewise.
- `PUT /team/members/{uid}`: `role` only `user`, `lead`, `subadmin`; only
  admins grant `subadmin`.
- `POST`/`PUT /admin/teams`: `groups: { team }`.

### Addendum to version 2: budgets inherit the project's permissions (2026-09-26)

The user: "budgets belonging to a project inherit its permissions."
Budgets are in the project record (`data.budgets`); a project's leads are
in `data.leads` (person keys, linked to an account via the person's
`login`/`accounts`).

- **Read:** `data.budgets` is available to subadmin, admin and the leads
  of **this** project. Everyone else is served the project record by the
  server without the `budgets` field (in `/records`, `/records/{kind}/{key}`
  and in history).
- **Write:** A lead of the project may `PUT /records/project/{key}` if,
  compared to the current version, **only** `budgets` changes; otherwise
  `403 forbidden` ("Only project leads may change the budgets of their
  projects."). subadmin and admin as before. Batch and restore remain
  subadmin/admin.
- If `data.leads` changes, a new version is created anyway; newly assigned
  leads then get the project with budgets on the next delta, removed ones
  without.

### Addendum to version 2: projects only for leads and admin, otherwise booking catalog (2026-09-26)

The user: "whoever is not a PL or admin does not see a project record.
only their own data." Tightens the "budgets inherit permissions" addendum:

- **Full project record** only for admin, subadmin and the leads of
  **this** project.
- **Everyone else** (user; lead for other projects) gets only the
  **booking catalog**: `data` contains only `schema`, `id`, `name`,
  `codes`, `subprojects`, `start`, `end`, `status`, `mapping`, (since
  0.13.0) `never_billable` and (since 0.14.0) `led: true` when the project
  has at least one Lead - the client reports its bookings on such projects
  (`booking-report`) without learning who leads them. Missing are
  `description`, `cost_center`, `customer`, `quote`, `leads`, `budget`,
  `milestones`, `budgets`. Reason: without codes, subprojects and mapping
  rules the Mac app cannot book appointments.
- Applies to `/records`, the delta, `/records/project/{key}` and history
  (history of a project: only admin, subadmin, the project's leads).
- Writing unchanged: leads only `budgets` of their project; everything
  else subadmin/admin.

### Addendum to version 2: project leads are admin within the project (2026-09-26)

The user: "Yes they may. PLs are admin within the project." Replaces the
rule "leads may only change `budgets`":

- A lead of the project may `PUT /records/project/{key}` with **all**
  fields (also `leads`, `customer`, `codes`, `subprojects`, `budgets`,
  `milestones`). `id` must remain equal to the key.
- Creating (`version: 0` for a new key), deleting, batch and restore
  remain subadmin/admin.
- Checked against the leads of the **current** version on the server, not
  the new one: whoever removes themselves from `leads` may still write
  this and afterwards sees only the catalog.

## Addenda to version 2 from implementation (2026-09-26)

App 0.3.0. Clarifications; nothing stated above changes.

- **Membership:** those who left do not count for uniqueness. Whoever left
  team A and is in team B's team group belongs to B; `409 ambiguous_team`
  only for two teams without leaving. Even an admin recorded as having
  left gets `403 no_team`.
- **Old mappings:** only `team` counts. Rows from version 1 (`user`,
  `lead`, `subadmin`, `admin`, `accounts`) are ignored; `PUT
  /admin/teams/{id}` removes them from the mapping, the Nextcloud groups
  remain. A group with an old mapping to another team counts as taken
  (`409`).
- **`groups`** in `POST`/`PUT /admin/teams`: exactly `{ "team": "<gid>" }`.
  Other keys or no team group → `422 invalid`. `backup_owner` must be a
  group admin of the team group (`422`).
- **`/me`:** `admins` and `leads` are the user IDs of all members with
  this role who have not left, sorted, including the caller.
- **`/team`:** `members` by user ID; for those who left, `role` is the
  role they would have, `left_at` an ISO time, otherwise `null`. Whoever
  has an app role but is no longer in the team group is missing; if the
  account returns, the stored role applies again.
- **`GET /status`** names only those who have not left. `calendar_shared`
  is `true`, `false` or `null` (never reported); in the `POST`, `null`
  clears the value, not a boolean → `422 invalid`.
- **`PUT /team/members/{uid}`**, checked in this order:
  - user or lead → `403 forbidden`;
  - neither `role` nor `left` → `400 invalid`;
  - `role` not `user`, `lead` or `subadmin` (also `admin`), `left` not a
    boolean → `422 invalid`;
  - account neither in the team group nor its group admin →
    `404 not_found`;
  - granting `subadmin` and changing entries of managers and admins (role
    and leaving) only by admins, nobody records their own leaving →
    `403 forbidden`.

  `role: "user"` clears the app role. `left: true` on someone already
  marked as left keeps the first date.
- **Admin page:** `PUT /admin/teams/{id}/members/{uid}` with the same
  body and the same checks; the Nextcloud admin has a team admin's
  rights. Unknown team → `404`.
- **`GET /admin/teams`:** `counts` = `{ user, lead, subadmin, admin, left }`.
- **Account deleted:** app role and leaving are dropped, a new account
  with the same user ID inherits nothing. `calendar_share` is a Nextcloud
  setting of the account (`IUserConfig`, key `calendar_share`) and is
  dropped with it.
- **Projects:**
  - Person keys for `leads`: the caller's own key and the keys of living
    persons with it in `accounts`, as with "own person". Every role
    counts, even `user`.
  - Catalog: the fields from the list, where present; all others,
    including future ones, are missing.
  - History: leads per the current version, then full. For a tombstone,
    only managers and admin.
  - Without a management role, `PUT /records/project/{key}` first checks
    path and body (`400`/`422`), then the lead. No lead, tombstone or new
    key → `403 forbidden`, sentence: "Only managers, admins and the
    project's leads change a project; managers and admins create new
    ones."
  - The response to the `PUT` is full, even if the lead removes
    themselves in it. `409 conflict` names `current` in full.
- **Stored:** table `ts_members(tenant_id, uid, role, left_at, updated_by,
  updated_at)` and column `ts_client_status.calendar_shared`, migration
  1004, app 0.3.0, additive only.

## Three roles and the shares matrix, 0.5.0 (2026-09-27) – replaces `subadmin`

Still `api: 2`; only additions. Where earlier sections say `subadmin`,
manager or "subadmin and admin", read **Team Admin** (`admin`).

### Roles

- Three roles: `admin` (shown as **Team Admin**), `lead` (**Lead**), `user`
  (**User**). The words are the same in every language and are never
  translated (the app's `RoleName`, the Mac app's `marke::ROLLEN`).
- `subadmin` is gone. The migration to 0.5.0 turns every stored `subadmin`
  into `lead`; an old value that is still read counts as `lead`.
- Everything that "subadmin and admin" could do is Team Admin only: master
  data (write, delete, batch, restore), history of others, others' backups,
  `GET /status`, `GET /team`, roles. Leads keep writing their own projects
  (including budgets), as before.
- `PUT /team/members/{uid}` (Team Admins) and `PUT /admin/teams/{id}/members/{uid}`
  (Nextcloud admins): `{ "role"?: "admin"|"lead"|"user", "left"?: bool, "may_override"?: bool }`.
  `admin` makes the account group admin of the team group (`ISubAdmin`);
  another role withdraws it and keeps the account in the team group.
  `422 invalid` for `subadmin` or anything else. `409 conflict` when the
  team would lose its last Team Admin (demoting or leaving), also for
  oneself. `may_override`: "allow overriding", default off.
- `GET /team` and `GET /admin/teams` members carry `may_override`;
  `counts` = `{ user, lead, admin, left }`.

### Shares matrix

Rows: who sees (`viewer`); columns: whose time calendar (`owner`); only
active members of the own team; no diagonal. Levels `none`, `view` (read),
`edit` (write).

- **Defaults:** rows of Team Admins `edit`, everything else `none` (0.12.0
  to 0.13.0 also a Lead's row `edit` for the people on their projects;
  since 0.14.0 a row of a Lead or User is always `none`, see “Visibility
  per role, 0.14.0”). Only
  set fields are stored (`ts_access`), by source: `admin` (a Team Admin) or
  `self` (the owner).
- **Precedence:** the owner's own entry wins while `may_override` is on;
  otherwise it rests (kept, not applied) and the Team Admin's entry or the
  default applies. A Team Admin cannot remove an owner's entry.
- `GET /team/access`: Team Admins get every field; everyone else their
  column (`owner` = me) and row (`viewer` = me).

```json
{ "can_edit": true, "may_override": false,
  "members": [{ "uid": "pbadmin", "display_name": "Petra Brunner", "role": "admin",
                "may_override": false, "reported_at": "2026-09-27T10:00:00Z" }],
  "fields": [{ "viewer": "pblead", "owner": "pbuser1", "level": "view",
               "admin_level": "view", "self_level": null,
               "overridden": null, "resting": false,
               "applied": "none", "effective": false, "pending": true,
               "changed_at": "2026-09-27T09:58:00Z", "changed_by": "pbadmin" }] }
```

  `members` sorted by role (Team Admin, Lead, User), then name; for
  non-admins without the others' `may_override`/`reported_at`.
  `overridden`: `restricted` or `granted` when the owner's choice applies
  and differs from the Team Admin's; `applied`: what the owner's client
  reported (`null`: never reported); `effective`: `applied` = `level`
  (`null` while never reported); `pending`: `effective` false, or never
  reported and `level` not `none`.
- `PUT /team/access/{viewer}/{owner}` `{ "level": "none"|"view"|"edit"|"default" }`
  and `PUT /team/access` `{ "changes": [{ viewer, owner, level }] }` (all or
  none, at most 5000). Team Admins set every field (source `admin`); the
  owner only their own column (source `self`) and only with `may_override`,
  otherwise `403 forbidden` ("Your Team Admin has not allowed overriding.").
  `default` removes the entry. Diagonal `422`, accounts outside the team
  `404`. Answer: the matrix as in `GET`.
- `POST /team/access/remind` (Team Admins): a Nextcloud notification
  (`OCP\Notification`, app `timesister`, object `shares`/team id, subject
  `shares_changed`) to every owner with a pending field. Answer
  `{ "notified": [uid…] }`. Withdrawing (a field, a role, a leaving) sends it
  to the owners concerned by itself. It goes away (`markProcessed`) once the
  owner reports everything as set.

### `/me` and status

- `/me.share_targets`: `[{ "uid", "access": "read"|"write" }]`, whom the own
  time calendar is shared with, from the matrix; `/me.may_override`. The
  client sets exactly these shares on its own calendar. `admins`, `leads`,
  `calendar_share` and `settings.leads_see_calendars` stay for older
  clients; the matrix does not use them.
- `POST /status` takes `applied_shares`: `[{ uid, access }]`, the user shares
  of the own time calendar after the sync (at most 1000); `null` clears it.

## Jobs, 0.6.0 (2026-09-28)

Still `api: 2`; only additions. The capability says `jobs: 1`, from 0.7.0
`jobs: 2` (counter-proposals per recipient in a market). Plan and
decisions: `PLAN-JOBS.md`. A **Job** is a share of a project or a work
package that a Lead or Team Admin offers to people of the team. The words
**Job, Workload, Inbox, In Progress, Done, Outbox** are the same in every
language (the app's `JobWord`, the Mac app's `marke::JOBWORTE`); sentences
take them as placeholders.

### The record

Kind `job`, key `^[A-Za-z0-9@._+-]{1,64}$` (chosen by the client, so an
offer sent again is recognised). Read through `/records` like any record;
written **only** through `/jobs` – `PUT`, `DELETE`, batch and restore of a
`job` answer `400 invalid`.

```json
{ "schema": 1, "id": "j-7f3a", "project": "D200", "code": "D200.1", "codes": ["D200.1"],
  "budget": "b-2026-041", "work_package": "AP1", "title": "Basics", "description": "…",
  "hours": 40.0, "start": "2026-10-01", "end": "2026-10-31",
  "sender": "pblead", "recipients": ["pbuser1"], "declined_by": [], "assignee": null,
  "state": "offered",
  "counters": [], "change": null,
  "progress": { "hours": 0.0, "invoiced": false, "at": null },
  "done": null, "paid": null,
  "created_at": "2026-09-28T08:00:00Z",
  "log": [{ "at": "…", "by": "pblead", "action": "offer", "to": ["pbuser1"] }],
  "names": { "pblead": "Lea Planer", "pbuser1": "Mia Muster" } }
```

- `budget` and `work_package` (the work package's `no`) go together; without
  them the Job is on the project (code of the project or a subproject).
  `codes`: the work package's codes, otherwise `[code]` – where the
  person's hours count. `title` defaults to the work package's name.
- `state`: `offered` (in the Inbox of every recipient who has neither
  declined nor counter-proposed), `counter` (nobody has it in the Inbox any
  more, a counter-proposal waits for the sender), `rejected` (nobody left,
  a counter-proposal was rejected), `declined` (all recipients declined),
  `in_progress` (with `assignee`), `returned`, `done`.
- `counters` (0.7.0): one counter-proposal per recipient,
  `[{ by, at, hours?, start?, end?, description?, note, answer?, answered_at? }]`;
  `answer` is `accepted`, `rejected` or `lapsed` (someone else got the Job
  first). A Job written by 0.6.0 has a single `counter` instead; read it as
  a list of one – the next transition writes `counters`.
- `change`: the last change by a Lead or Team Admin,
  `{ by, at, fields: { <field>: { from, to } } }` – for the person's view.
- `progress`: what the assignee's client reported (booked hours, capped at
  `hours`; all invoiced?). `done`: `{ by, at, reason: declared|fulfilled }`.
  `paid`: `{ by, at }`.
- `log`: every transition, at most 200 (the first and the newest).
  `names`: display names at the time of writing.

### Who sees a Job

In full: Team Admins, the Leads of the project, the sender, and a person
while the Job is in their Inbox (open offer), waits on their
counter-proposal, is In Progress with them or is Done with them. Whoever
was involved before (declined, lost the race, returned, the counter was
rejected or lapsed) gets it as a **tombstone** (`deleted: true`, `data: null`) in
the delta, so their client drops it; `GET /records/job/{key}` answers
`404`. Deleted Jobs come as tombstones to everyone who saw them. History:
whoever manages the Job (Team Admins, the project's Leads, the sender); of a
deleted one only Team Admins.

**Trimmed for whoever does not manage it (0.7.1):** a recipient or the
assignee reads `counters` with only their own counter-proposal; of the
others only those still waiting, as `{ "other": true }` (no person, no
values, no note). The `log` leaves out the others' `counter`,
`accept_counter` and `reject_counter`, and `lapsed` names only the caller.
Since 0.14.1 also `recipients` and `declined_by` trimmed to the caller,
`names` only for the caller and the sender, and the offer entry in the
`log` only `to` the caller - a Member learns neither who else was offered
the Job nor who declined.
The same in the delta, `GET /records/job/{key}`, the answers of `/jobs`
and the `current` of a `409`.

### Endpoints

All answer with the Job record as the caller now sees it (a tombstone if
it left their view). A transition that has already happened is answered
with the unchanged record (a resent request is not an error).

| Method | Path | Who | Body |
|---|---|---|---|
| PUT | `/jobs/{key}` | Team Admin, Lead of the project | `{ project, code, budget?, work_package?, title?, description?, hours, start, end, recipients: [uid…] }` – offer |
| POST | `/jobs/{key}/change` | Team Admin, Lead of the project | any of `code, title, description, hours, start, end, recipients` |
| POST | `/jobs/{key}/accept` | a recipient | – |
| POST | `/jobs/{key}/counter` | a recipient | `{ hours?, start?, end?, description?, note? }`, at least one of the first four |
| POST | `/jobs/{key}/accept-counter` | Team Admin, Lead of the project | `{ by? }` – whose; needed while several wait (`422` otherwise) |
| POST | `/jobs/{key}/reject-counter` | Team Admin, Lead of the project | `{ by? }` |
| POST | `/jobs/{key}/decline` | a recipient | – |
| POST | `/jobs/{key}/return` | the assignee | `{ note? }` |
| POST | `/jobs/{key}/done` | the assignee; Team Admin, Lead of the project, sender (0.15.0) | – |
| POST | `/jobs/{key}/paid` | Team Admin | `{ paid?: bool }` (default true), only when Done |
| DELETE | `/jobs/{key}` | whoever created it (`sender`) | – |
| POST | `/jobs/progress` | the assignee's client | `{ jobs: { <key>: { hours, invoiced? } } }` |

- **Offer:** `recipients` 1–50 accounts of the team (`422` otherwise);
  `hours` > 0; `start` ≤ `end`, at most ten years; `description` at most
  4000, `title` 200 characters. The key taken by another offer: `409`.
- **Change:** `project`, `budget` and `work_package` never change (`422`).
  With `recipients`, a declined, rejected or returned Job is offered again
  (state `offered`, progress reset); an open offer gets the new
  recipients (whoever declined and stays, stays declined); an In Progress
  Job keeps its person (`409`). While a counter-proposal waits and once
  Done: `409`. Recipients who stay keep their answer (declined, rejected).
- **Market:** offered to several, the Job is in each of their Inboxes;
  **whoever is accepted first gets it** – by accepting the offer or by the
  sender accepting their counter-proposal. Every transition runs under the
  team's lock, so two at the same time are served one after the other; the
  second gets `409 conflict` ("Someone else has already accepted this
  Job.", or for a counter-proposal "This counter-proposal has lapsed: the
  Job has gone to someone else."). Whoever declines no longer sees it;
  once all have declined, it is `declined`.
- **Counter-proposal (0.7.0 per recipient):** the Job leaves the caller's
  Inbox and waits for the sender; offered to several, it stays open for
  the others (`state` stays `offered` while someone still has it in the
  Inbox). Accepting applies its values and puts the Job In Progress with
  the person who made it; every other counter-proposal waiting lapses
  (`answer: lapsed`). Rejecting puts that person out; the Job stays
  offered to the others, or becomes `rejected` when nobody is left. If
  someone accepts the offer first, the waiting counter-proposals lapse.
- **Progress:** per Job the assignee's booked hours on `codes` within the
  period (the client computes them; the server caps at `hours`) and whether
  they are all invoiced. Reaching `hours` makes an In Progress Job Done
  (`reason: fulfilled`). Jobs that are not the caller's (any more) are
  listed in `skipped`, not an error. Only changes are written (±0.005 h).
  Answer: `{ revision, records: [changed Jobs], skipped: [keys] }`, at most
  500 per call.

### Rules (checked by the server)

- **Volume (no longer a rule since 0.7.5):** Jobs may together go above
  the hours of a work package; the clients show by how much. Offered,
  waiting and In Progress Jobs hold their full hours; returned, declined,
  rejected and Done ones only what was booked (`progress.hours`).
- **Offered only to oneself (0.7.5):** when the recipients are exactly the
  sender, the Job is accepted at once (`in_progress`, assignee = sender),
  and nobody is notified.
- **No overlap:** no two Jobs on the same work package or a common code,
  with overlapping periods, for the same person (open offer, waiting
  counter-proposal or In Progress). Checked on offer, change, accept and
  accepting a counter-proposal. `422 invalid` with `rule: "overlap"`,
  `user` and `other` (the other Job's key).
- The code must belong to the work package (if it has codes) or the
  project: `422` with `rule: "code"`; unknown budget or work package: `rule:
  "budget"` / `"work_package"`.

### Notifications

`OCP\Notification`, app `timesister`, object `job`/key; one per person and
Job (a new one replaces the older):

| Subject | To | When |
|---|---|---|
| `job_offered` | each open recipient | offered, offered again, added as recipient |
| `job_counter` | the sender | counter-proposal |
| `job_counter_accepted` / `job_counter_rejected` | who proposed | answered |
| `job_counter_lapsed` | who proposed | someone else got the Job first (0.7.0) |
| `job_changed` | open recipients or the assignee | changed |
| `job_returned` | the sender | returned |
| `job_reopened` | the other side | reopened (0.15.0) |
| `job_done` | the assignee | declared Done by someone else (0.15.0) |
| `job_deleted` | open recipients or the assignee | deleted |

Accepting (the offer or a counter-proposal) withdraws the offer
notifications of all recipients, and the sender's about counter-proposals
that lapsed; declining and counter-proposing withdraw the caller's. Nobody
is told separately that a market offer went to someone else – it leaves
their Inbox.

### Stored

No migration: Jobs are rows of `ts_records` (kind `job`) with version,
revision and `ts_history`. `accounts` holds everyone ever involved. A
tombstone keeps the last `data` internally, so the delta knows whom to
tell; the API never shows it.

## Weekly numbers of the Jobs, 0.7.2 (2026-09-28)

Still `api: 2`; only additions. The capability says `jobs: 3`, from
0.7.4 `jobs: 4` (the non-billable hours `booked_nb`). Each
person's client reports, besides the booked hours per Job, its **weekly
numbers**: what the person can carry and what their Jobs take, week by
week. The server stores them per person and uses them to check the
capacity when a sender accepts a counter-proposal – the person's client
cannot check that step.

| Method | Path | Who | Body / answer |
|---|---|---|---|
| POST | `/jobs/weeks` | every member, for themselves | `{ pensum?, jobs: [key…], weeks: [{ start, full, available, holidays?, vacation?, days?, jobs?: { <key>: { booked, booked_nb?, planned } } }] }` → `{ reported_at }` |
| GET | `/jobs/weeks?uid=` | the person; Team Admins everyone; Leads whom they may at least view | `{ people: [{ uid, display_name, role, reported_at, pensum, weeks }] }` |
| GET | `/jobs/{key}/capacity?by=` | Team Admin, Lead of the project | `{ user, display_name, reported, reported_at, fits, weeks }` |

- **A week:** `start` its Monday; `full` the target hours of a full
  position (100 %); `available` the capacity line (target × pensum −
  holidays − vacation); `holidays` in days, `vacation` in hours; `days`
  seven weights Monday–Sunday (0–1: a holiday by its factor, a vacation
  day 0; default Monday–Friday 1) for spreading a Job; per Job the
  `booked` and `planned` hours of this week and, since 0.7.4 (`jobs: 4`),
  `booked_nb`: the non-billable part of `booked` (0 to `booked`, missing
  means 0). `jobs` at the top lists the
  Jobs the report accounts for (In Progress and Done). At most 80 weeks,
  200 Jobs per week, 500 at the top; numbers 0–1000. A new report replaces
  the old one.
- **Reading:** everyone readable is listed, `weeks: null` and
  `reported_at: null` if their client never reported. Per week `start`,
  `week` (ISO), `full`, `available`, `holidays`, `vacation`, `load` (the
  sum), since 0.7.5 `days` (the seven weights as reported, so a reader can
  split a week at a month's end; a report without them reads Monday–Friday
  1), `jobs` – only the Jobs the reader sees, each `{ booked, booked_nb,
  planned }` – and `other` with the rest merged the same way (`null` if
  nothing). Reports stored before 0.7.4 give `booked_nb: 0`. Leads read whom the shares matrix lets them
  at least view (the matrix level, not whether it is applied yet); anyone
  else `403`. **Since 0.14.0 Leads read only their own numbers.**
- **Capacity check** on `accept-counter`: the counter-proposal's hours are
  spread over the person's working days from its start (at the earliest
  today) to its end. A week from the current one on, in which it takes
  something and which the report covers, may not go above `available`
  (±0.05 h). The load is what the reported Jobs still count (Jobs no longer
  the person's are dropped) plus running Jobs the report does not know yet
  (their open hours, spread the same way). **Per day (0.7.3):** where
  the week holds, no day may go above a full day's capacity either –
  `available` over the week's working days (the sum of `days`), times the
  day's weight. A day's load is the counter-proposal's hours of that day
  plus the other Jobs' hours of the week, spread by weight over its
  working days from today (in the current week only their `planned`
  hours). So hours squeezed onto the first day after a vacation do not
  fit, nor do hours on days without weight. Otherwise `422 invalid` with
  `rule: "capacity"`, `user`, `weeks: [{ start, week, load, available,
  over, day? }]` and `reported_at`; with `day` the entry is that day's
  (its load, its capacity, the excess), the worst one of the week.
  **Without reported numbers nothing is blocked.** `GET /jobs/{key}/capacity` answers the same before accepting
  (`by`: whose counter-proposal, needed while several wait; `409` if none
  waits).
- **Stored:** columns `job_weeks` (JSON) and `job_weeks_at` in
  `ts_client_status` (migration 1006).

## Billing marks and externals, 0.7.6 (2026-10-01)

Still `api: 2`; only additions. The capability says `billing: 1`. Plan
and decisions: `PLAN-EXTERNE.md`. The billing mark leaves the event: a
Lead who may only *see* a time calendar cannot write into it, and nobody
writes into a feed.

### The record

Kind `billing`, one per person and event UID. Key: the person's key, `+`
and the first 32 hex digits of the UID's SHA-256 (`alice+3f9c…`), so the
key stays within the key pattern whatever the UID holds and the person can
be read off a tombstone.

```json
{ "person": "alice", "uid": "7f3a-…@example.test", "billed_on": "2026-09-30",
  "by": "pbadmin", "checksum": "a1b2…",
  "source": "manual", "external_id": null, "document": null }
```

- `person` must be a person key, `uid` non-empty (at most 1024 bytes), and
  the key must equal the derivation, otherwise `422 invalid`. `billed_on`
  is a date. `by` (64), `checksum` (128), `source` (64), `external_id` and
  `document` (256) are optional text: the checksum is the client's
  fingerprint of the billed state (the clients notice "changed after
  billing"); `source`, `external_id` and `document` are carried through
  untouched for a later hand-over to an accounting system
  (`PLAN-BACKLOG.md`, item 2).
- A series counts as one event (one UID). Undoing deletes the record; the
  history says who did what when.

### Rights (checked by the server)

| | user | lead | admin |
|---|---|---|---|
| write (`PUT`, `DELETE`, batch, restore) | – | persons they may at least **view** in the shares matrix (members and externals) | all |
| read (delta, `GET`, history) | own person | the same as write | all |

Since 0.14.0 Leads view no time calendars: billing marks are written by
Team Admins only and read by them and the person.

A Lead's `POST /records/batch` is accepted when every write is a billing
mark; otherwise `403 forbidden` as before. Marks of the own person are
readable so the client can lock the events.

### Externals in the shares matrix

An **external** is a live person record with a non-empty `feed` whose key
is no active member and whose `accounts` name none. In `GET /team/access`
they follow the team in `members` with `role: "external"` and
`external: true`; their fields carry `external: true`, `self_level` null,
`applied` null, `effective` true, `pending` false. Team Admins get every
external column, everyone else the own row.

- Levels `none` or `view`; `edit` answers `422 invalid` ("A feed is
  read-only"). Only Team Admins set them (source `admin`); `default`
  removes the entry. Defaults: Team Admins `view`, everyone else `none`.
  A key longer than 64 characters cannot be a column (`422`).
- Nothing is pending: the server hands the feed address out or not.

### Feed only for those who may

`person.data.feed` is delivered to Team Admins, the person themselves and
(until 0.13.0) Leads with at least `view` on that person; everyone else gets the record
**without** `feed` – in the delta, `GET /records/person/{key}` and the
history. Setting an external's field gives their person record a new
`revision` (the `version` stays), so every client receives it again with
the next delta. A client whose copy lost the address drops the feed
calendar.

## Team backup as ZIP, 0.10.0 (2026-10-05)

Still `api: 2`; only additions. The capability says `team_backup: 1`.
Plan and decisions: `PLAN-SICHERUNG.md`. Only Nextcloud admins, under the
admin prefix; the write routes carry `#[BruteForceProtection]`.

| Method | Path | Body / answer |
|---|---|---|
| GET | `/admin/teams/{id}/export` | the ZIP as a download (`application/zip`, `timesister-<slug>-<YYYY-MM-DD_HHMM>.zip`) |
| POST | `/admin/teams/{id}/import/preview` | multipart field `file` (the ZIP) → preview with `token` |
| POST | `/admin/teams/{id}/import` | `{ "token", "mapping": { "<old uid>": "<new uid>" \| null }, "mode": "merge" \| "replace" }` → result |

### The ZIP

- `manifest.json`: `{ format: 1, app_version, api, team: {id, name, slug,
  group}, taken_at, contains_secrets: true, files: { "<path>": { sha256,
  size, uid?, missing? } } }`. Every other entry is listed with its SHA-256;
  an entry the manifest does not list, a wrong size or checksum, a path
  with `..`, an absolute path or a backslash refuse the whole ZIP (`422`).
- `team.json`: the team as in `GET /admin/teams` plus `revision`,
  `members` (uid, display name, role, `left_at`, `may_override`), `access`
  (every set field of the shares matrix), `consents`.
- `records/<kind>/<key>.json`: the record as in `GET /records/{kind}/{key}`
  plus `accounts`, `account_deleted_at` and `history` (every version, as
  in `GET …/history`). Tombstones are included. Jobs are records
  (`records/job/…`); the weekly numbers live in `status.json`.
- `status.json`: uid → `{ seen_at, app_version, last_sync, calendar_url,
  calendar_shared, job_weeks, job_weeks_at }`.
- `absences.json`: uid → `[{ source_uid, kind, start, end }]`.
- `calendars/<uid>.ics`: the time calendar of every active member, exported
  when the ZIP is made (same way as the weekly backup). Not exportable:
  no file, manifest entry `missing: true`. The weekly backups stay on the
  server and are not in the ZIP.
- `templates/invoice.docx`, `templates/invoice.json` (since 0.16.0): the
  team's invoice template, if it has one – see “Invoice template, 0.16.0”.
- Limits: 200 MB per ZIP, 50 MB per entry, 600 MB unpacked, 20 000 entries.

### Preview

`{ token, format, app_version, taken_at, team, records: { <kind>: n },
persons: [{ uid, display_name, role, left_at, may_override, exists, member }],
calendars: [{ uid, path, size, missing }], absences: n, target: { id, name,
empty }, accounts: [{ uid, display_name }] }` – `exists`: an account with
this identifier exists here; `member`: it is already in the target team;
`accounts`: up to 500 accounts of this Nextcloud for the mapping. The
upload stays in the app's data folder (`import/<token>.zip`) until it is
imported, at most a day.

### Import

- `mapping`: only accounts of the backup; a target may appear once; it
  must exist here (`422`). Unmapped accounts keep their name. `null`
  means **without account**: the person record stays with its key, the
  identifier leaves `accounts`, and there is no membership, share,
  consent, absence or status for it.
- Which fields are rewritten: `login`, `accounts`, `leads`, `sender`,
  `recipients`, `declined_by`, `assignee`, `by`, `person`, `owner`, `uid`,
  `names`, `log[].to`, `modified_by`, `vacation_calendar.url`; the key of
  a person and the person part of a billing key.
- **Before writing** into a team that already has content (records, roles,
  shares, consents or absences) the server stores a ZIP of it: protected
  in the app data (`exports/t<id>/<name>.zip`) and visible with the backup
  owner under `TimeSister Backups/_team/<name>.zip`. If the protected copy
  fails: `409 conflict`, nothing changed.
- `merge`: records missing here are inserted with their versions; a record
  that exists here is written as a new version only when the backup's is
  younger and differs; rows (members, shares, consents, absences, status)
  only where none exist. `replace`: a record that differs is written as a
  new version; records not in the backup become tombstones (new version,
  restorable); rows are replaced. Both in one transaction.
- Accounts are never removed from the team group or demoted; mapped
  members are added to the group, Team Admins become group admins.
- Calendars become backups per person: `ts_backups` with
  `source: "import"`, `taken_on` = the day of `taken_at`; a backup of
  another source on that day is kept (`kept_existing: true`). People fetch
  them in the Mac app under “Old events” (`GET /backups/{id}`).
- Answer: `{ team, mode, source: {name, taken_at}, pre_backup: {protected,
  visible} | null, records: {inserted, updated, tombstoned, versions,
  by_kind}, members: {rows, added_to_group, group_admins}, access: {rows},
  consents: {rows}, absences: {rows}, status: {rows}, without_account: [uid],
  calendars: [{ uid, stored, kept_existing?, backup?, reason? }] }`.

## Delete team with content, 0.10.1 (2026-10-05)

Still `api: 2`. `DELETE /admin/teams/{id}` deletes any team, also one with
content; only Nextcloud admins, `#[BruteForceProtection]`.

- Body `{ "confirm": "<team name>" }`. The typed name is compared trimmed
  and without regard to case; missing or different → `422 confirm`.
  Also for an empty team.
- A team with content (records including tombstones, server backups or,
  since 0.16.0, an invoice template) is stored first, the same way as before an import: the ZIP protected in
  the app data under `exports/t<id>/<name>.zip` and visible with the backup
  owner under `TimeSister Backups/_team/<name>.zip`. Without a backup
  owner only the protected copy exists (`visible: null`). If the protected
  copy cannot be written: `409 conflict`, nothing deleted.
- Then, in one transaction: records (`ts_records`) with their history
  (`ts_history`), status, absences, members, shares, consents, the group
  mapping and the server backups (`ts_backups`, `ts_backup_files` rows),
  last the team itself. After the commit the protected calendar backups in
  the app data (`t<id>/`) and (since 0.16.0) the invoice template
  (`templates/t<id>/`) are removed; `exports/` stays.
- What stays: the Nextcloud group, the accounts, their time calendars and
  the visible `.ics` files in the backup owner's folder.
- Answer: `{ "id", "deleted": true, "backup": { "protected":
  "exports/t<id>/<name>.zip", "visible": "<path with the owner>" | null }
  | null, "counts": { records, versions, status, absences, members, access,
  consents, groups, backups, backup_files, counters } }` (`counters` since
  0.16.0). `backup` is `null` for an empty team.

## Privacy notice and team from ZIP, 0.10.2 (2026-10-05)

Still `api: 2`, only additions; the capability gains `privacy_notice: 1`.

### The record `setting/privacy`

A third setting beside `targethours` and `settings`; Team Admins write it
like any record (`PUT /records/setting/privacy`), everyone in the team
reads it. Fields, all optional:

```json
{ "controller": { "name": "…", "address": "…", "contact": "…" },
  "privacy_contact": "…",
  "hosting": { "provider": "…", "location": "…" },
  "retention": { "time_years": 5, "billing_years": 10, "note": "…" },
  "law": "both",
  "authority": "…" }
```

Texts at most 500 characters (no control characters), `time_years` and
`billing_years` integers 0–30, `law` one of `ch`, `eu`, `both` (the notice
takes `both` when missing), unknown fields `422 invalid`. `schema` (integer)
is allowed.

### The notice

- `GET /team/privacy?format=html|md` – every member, for their own team,
  in the language of the account. `format` missing means `html`; anything
  else `400`.
- `GET /admin/teams/{id}/privacy?format=html|md` – Nextcloud admins, any
  team.
- Both answer with a download: `Content-Disposition: attachment;
  filename="timesister-privacy-<slug>-<YYYY-MM-DD>.html|md"`, Content-Type
  `text/html; charset=utf-8` or `text/markdown; charset=utf-8`. The HTML
  is self-contained (styles inline, nothing from outside, everything
  foreign escaped); the Markdown has one table row per line.
- Content, live from the team, in nine numbered sections: 1 controller
  and contact (the record plus team, date, app version), 2 purpose (with
  the modules switched on: `settings.modules` or, unset, whether records
  of the module exist), 3 which data, which categories (table: category,
  examples, source, location; the absence categories and whether a
  vacation calendar is set; externals as a count only), 4 where the data
  is stored, 5 who has access (Nextcloud admins, Team Admins by name,
  Leads with their projects from `project.leads`, Users, externals; table
  person · role · sees the time calendar of · edits the time calendar of,
  from the shares matrix as `GET /team/access` computes it), 6 backups
  (owner by name, consents given/not given, team ZIP, thinning), 7
  retention and deletion (legal references by `law` as a template: ArG
  Art. 46 with ArGV 1 Art. 73, OR Art. 958f, DSG Art. 6/19/25/32; GDPR Art.
  5(1)(e), 6(1)(b)(c), 13, 15, 17(3)(b), 30, 32; the team's periods; what
  can be deleted and how; what stays while the period runs), 8 rights of
  the persons (access, rectification, erasure, objection to backups,
  complaint to `authority` – without one the FDPIC is named for `ch`), 9
  the note: template from the team's settings, no legal advice.
- Empty fields read `[to be completed]` / `[auszufüllen]`.

### Admin: the record and the notice

- `PUT /admin/teams/{id}/privacy` with `{ "data": { … } }` – Nextcloud
  admins write `setting/privacy` with the rights of a Team Admin, always
  against the record's current version (no `version` in the body). Answer:
  the record as `PUT /records` answers. The admin page shows the fields
  under “Edit team › Privacy” and offers both downloads.

### New team from a team ZIP

- `POST /admin/teams/import/preview` – multipart `file`, like the preview
  for an existing team but without one: the same answer, `target` is
  `null`. The upload is kept under `token` for a day.
- `POST /admin/teams/from-zip` with `{ token, name, slug, group, mapping }`
  – creates the team as `POST /admin/teams` does (`groups: { team: group }`,
  no backup owner, default settings) and imports the kept upload into it
  with `mode: merge` and the `mapping` (old account → new account or
  `null`). A taken short name or group is `409` before anything exists.
  Fails the import, the new team is deleted again (as `DELETE
  /admin/teams/{id}`) and the error carries the import's status and message,
  prefixed with “The import failed and the new team was removed again”;
  an unexpected failure is `409 conflict` without details.
- Answer: `{ "team": <as GET /admin/teams>, "import": <as POST
  /admin/teams/{id}/import> }`.

## Derived Lead role and customers for leads, 0.11.0 (2026-10-06)

Still `api: 2`; the capability gains `derived_lead: 1`. User's decision
(PLAN-BEREICHE, 06.10.2026): “Lead” meant two things – the set team role
and the lead of a project. Now there is one truth: the role follows from
the projects.

### The rule

- Set roles are only `user` and `admin`. `admin` stays the group admin of
  the team group in Nextcloud.
- A member's `role` is derived, in one place (`MembershipResolver::roleOf`,
  fed by `LeadResolver` through `TenantService::leadsOf`): `admin` if
  the account manages the team group; otherwise `lead` if the account is
  in `leads` of at least one **active** project; otherwise `user`.
- A project is active when it is live (not a tombstone) and its `status`
  is none of `completed`, `archived`, `abgeschlossen`, `archiviert` (case
  does not matter; a missing status counts as active). Project ended or
  lead removed → the rights fall away with the next request.
- A lead key names a person record (its key, or an account in its
  `accounts`) or an account directly. A person record without an account
  leads nothing: nobody logs in as them.
- Read once per request and team; no new table. The column
  `ts_members.role` stays but is empty and no longer read.

### Endpoints

- `GET /me`: `role` is the derived role; new `lead_of`:
  `[{ "kind": "project", "key", "code", "name" }]`, the active projects
  the caller leads, sorted by name (`code` = first of `codes`, else
  `null`; `name` falls back to the key). `leads` (for clients before
  0.5.0) names the derived Leads.
- `GET /team`, `GET /admin/teams` members and the answer of `PUT
  …/members/{uid}` carry `lead_of` (count) and `lead_projects` (names).
  `counts.lead` counts the derived Leads.
- `PUT /team/members/{uid}` and `PUT /admin/teams/{id}/members/{uid}`
  take `role` only as `admin` or `user`; `lead` (and `subadmin`) is
  `422 invalid` with “Lead follows from the project leads and cannot be
  set.” Everything else (`left`, `may_override`, the last-Team-Admin
  `409`) is unchanged.
- `GET /team/access`, `GET /status`, `GET /team/privacy`, Jobs, billing
  marks and weekly numbers use the same derived role; nothing else
  changes in their shape.

### Leads read customers

Since 0.14.0 only the customers of their projects (see “Visibility per
role, 0.14.0”). Unchanged in the rights matrix, now meant: a Lead reads `customer`
(list, delta, single record – contact persons included), writing stays
Team Admin (`403 forbidden`). The customers' address book (CardDAV, a
copy the Mac app writes, `core/src/kundenbuch.rs`) is shared by the Mac
app itself: write for Team Admins, read for the accounts in `/me.leads`
– since 0.11.0 the derived Leads, so the book follows the projects too.

### Migration and compatibility

- Migration `Version1008Date20261006090000` empties every stored `lead`
  (or old `subadmin`) in `ts_members` and logs the count per team. The
  rights stay through the derivation as long as the person leads an
  active project; a set Lead without a project becomes User.
- Clients that still send `role: "lead"` get `422`. The Mac app 0.5.x
  reads `role` from `/me` and shows the derived role correctly; its
  roles page (`core/src/web/verwaltung.rs`, `nutzer_rolle` →
  `serverapp::mitglied_setzen` → `PUT /team/members/{uid}`) still offers
  Lead and will see the `422` message until the Mac app of the areas
  plan (0.6.0) hides it.
- Team ZIPs keep `role` per member as information; an import no longer
  stores it.

## Leads reach the people on their projects, 0.12.0 (2026-10-06)

> **Replaced in 0.14.0:** Leads see no time calendars of others; the
> capability no longer names `lead_reach`. See “Visibility per role,
> 0.14.0”.

Still `api: 2`; the capability gains `lead_reach: 1`. User's decision
(06.10.2026): “The leads see the full calendars of the users who
contribute to the project. Leads are like small admins.”

### `POST /status`: `projects`

```json
{ "app_version": "0.6.0", "projects": ["JB1", "TK1"] }
```

- `projects`: the project keys the person booked on in the last 12
  months. A list of at most 500 strings, each 1–64 bytes without control
  characters; otherwise `400 invalid`. Duplicates are removed, the list
  is sorted and stored with the time (`ts_client_status.projects`,
  `projects_at`).
- Missing keeps the last report; `null` clears it. Not shown in `GET
  /status`.

### The rule

- A person **contributes** to a project when their client reports it in
  `projects`, or when they are a recipient (not in `declined_by`) or the
  `assignee` of a live Job in it.
- A Lead **reaches** every active member who contributes to an active
  project the Lead leads (`/me.lead_of`, see 0.11.0). Ended projects
  (`completed`, `archived`, …) give no reach; members who left are not in
  it; nobody reaches themselves.
- Default in the shares matrix: Team Admins `edit`; a Lead's row `edit`
  for the people they reach; everything else `none`. A Team Admin's entry
  replaces the default (also `none`), the owner's own entry wins while
  `may_override` is on – as before.
- Read once per request (`LeadReach`), no new table. `/me.share_targets`
  therefore names the Lead with `write`, and whatever reads the matrix
  follows: withdrawal reminders, billing marks and weekly numbers (a Lead
  with at least `view` on the person), feed of externals unchanged.
  Records and bookings are still read through the rights matrix
  (`AccessPolicy`), unchanged.

### `GET /team/access`

Every member field carries `reason`: `"contributes"` when `admin_level`
is this default, then also `projects` (the shared project keys, sorted);
otherwise `null` (external columns: always `null`). `reason` explains
`admin_level`; the owner's own entry may still change `level`.

```json
{ "viewer": "pblead", "owner": "pbuser1", "level": "edit",
  "admin_level": "edit", "self_level": null, "overridden": null,
  "resting": false, "applied": "none", "effective": false, "pending": true,
  "changed_at": null, "changed_by": null,
  "reason": "contributes", "projects": ["JB1"] }
```

### Privacy notice

The Leads' line under “Who has access” says that they see and edit the
time calendars of the people who book on their projects, unless the
shares matrix says otherwise.

### Migration

`Version1009Date20261006120000` adds `projects` (TEXT, null) and
`projects_at` (BIGINT, null) to `ts_client_status`. Additive only;
until a client reports, only Jobs give reach.

### One project, one code (0.12.0)

`PUT /records/project/{key}` (and batch, restore) refuses a record whose `codes` holds more than one non-empty entry: `422 invalid`, «A project has exactly one code; parts go into “subprojects”.». Parts are `subprojects` with codes like `D200.1`. Older records with several codes stay readable; the next write from a client carries only the one code. Since 0.16.0 a quote (`status` `draft`, `quoted`, `declined`) may have no code yet; from `active` on – also with no status – a missing code is `422 invalid`, «An active project needs a code.» (see Quotes).

### Addendum to version 2: never billable (2026-10-07)

A project record may carry `never_billable: true` (boolean; missing means
`false`). Bookings on such a project never count as open for billing –
for vacation, absences and internal work. The Mac app sets it when the
project number is created or in the project sheet and books accordingly;
the server stores it like any other field. Since 0.13.0 it is part of the
booking catalog, so every member's client knows it.


## Visibility per role, 0.14.0 (2026-10-07)

Still `api: 2`; only additions and narrower rights. Plan: `PLAN-ROLLEN.md`,
part B. Replaces the 0.12.0 rule “Leads are like small Team Admins”.

### The rule

**Members (`user`) see only themselves. Leads see themselves and the data
of the projects they lead. Team Admins see everything.** The server
enforces it in what it hands out – lists, deltas, single records, history,
weekly numbers – not only the clients' menus. “The projects they lead”:
for booking reports and billable marks the active projects in
`/me.lead_of` (0.11.0); for full project records and customers every live
project whose `leads` name the caller (as before for projects).

### Permissions

| | user | lead | admin |
|---|---|---|---|
| `setting`, `region` | read | read | read, write |
| `project` | booking catalog | their projects full (write, not create), others booking catalog | read, write |
| `person`, own | read | read | read, write |
| `person`, others' | – | **name card** | read, write |
| `customer` | – | **those of their projects**, read | read, write |
| `job` | as before (0.6.0) | as before | as before |
| `billing` (billed marks) | own, read | – | read, write |
| `booking-report` | own, read and write | **of their active projects**, read | read |
| `billable` (marks) | own, read | **of their active projects**, read and write | read, write |
| History | own person; own booking reports and marks | same as reading `booking-report`/`billable`; own person; their projects | all |
| Time calendars of others (CalDAV shares) | – | – | by the shares matrix |
| Weekly numbers (`GET /jobs/weeks`) | own | **own** | all |
| Capacity (`GET /jobs/{key}/capacity`, `422 capacity`) | – | their projects, **weeks without hours** | with hours |
| `POST /jobs/fits` | – | team members, **true/false/null** | team members |
| Status reports of others, team, backups of others | – | – | yes |

Unreadable records do not appear in lists or deltas; a single read of a
key the caller may not read gives `403 forbidden` – also when no such
record exists, so existence is not revealed.

### Shares matrix

- A row whose viewer is not a Team Admin is `none`, whatever is stored
  (members and externals as columns). `PUT /team/access` with `view` or
  `edit` in such a row: `422 invalid`, “Only Team Admins see the time
  calendars of others; this row stays “none”.”. `none` and `default` stay
  allowed. A Lead or User row reads `admin_level: "none"`.
- The owner's own entry (`self`, with `may_override`) may only **lower**
  a Team Admin's row: a level above the Team Admin level or default is
  `422 invalid`, “You can only restrict a Team Admin’s access to your time
  calendar, not extend it.”. When the Team Admin level drops later, the
  lower of the two applies. `overridden` is `restricted` or `null`, never
  `granted`.
- `reason` stays in every field of `GET /team/access`, always `null`;
  `projects` is gone.
- `/me.share_targets` therefore names Team Admins only. The owners'
  clients withdraw every other share at their next sync; the withdrawal
  reminder goes out as before.
- External columns (feeds): only Team Admins; a Lead or User row is
  `none`.

### Persons and customers

- A Lead gets person records of others as a **name card**: only `schema`,
  `login`, `first_name`, `last_name`, `initials`, `accounts` – in the delta
  and `GET /records/person/{key}`. Workload, regions, vacation, entry and
  exit, corrections, calendars, `aliases` (mail addresses) and `feed` stay
  with Team Admins and the person. Users read only their own person, as
  before. Since 0.16.0 `cost_rates` stay with Team Admins alone – also
  on the own person (see Cost rates on the person).
- A Lead reads a `customer` only when a project they lead names it in
  `customer` (older records: `kunde`). Everything else `403`.

### Booking reports (`booking-report`)

One record per account, project and month. The person's client writes it
whenever a booking on a project **with Leads** changes; the Leads' clients
read it instead of a time calendar.

- **Key:** `{uid}+{project}+{YYYY-MM}`, e.g. `mia+JB1+2026-10`. `uid` is
  the Nextcloud account (contains no `+`), `project` the project record's
  key, the month `YYYY-MM` (01–12). The first `+` ends the account, the
  last one starts the month; a project key may contain `+`. The whole key
  follows the record key pattern (at most 128 characters from
  `A–Z a–z 0–9 @ . _ + -`); otherwise `400`/`422`.
- **Data** – only these fields (anything else `422 invalid`, ““x” is not
  a field of this record.”):

```json
{ "schema": 1, "person": "mia", "project": "JB1", "month": "2026-10",
  "at": "2026-10-07T12:00:00Z",
  "bookings": [
    { "id": "7f3a-1@example.test", "date": "2026-10-07",
      "start": "08:15", "end": "10:00", "minutes": 105, "code": "JB1",
      "title": "Site visit", "notes": "First line\nSecond line",
      "billable": true, "billed": false } ] }
```

| Field | Rule |
|---|---|
| `schema` | optional integer 1–1000 |
| `person`, `project`, `month` | must equal the parts of the key |
| `at` | when the client built the report, ISO 8601 with time zone |
| `bookings` | a list, at most **2000** |
| `id` | required, 1–1024 bytes, no control characters, unique in the report; the booking's identifier as the client defines it (the event UID; for an occurrence of a series the UID plus its start). The billable mark uses the same value. |
| `date` | required, `YYYY-MM-DD`, a real day **within the month of the key** |
| `start`, `end` | `HH:MM` (00:00–23:59, `end` also `24:00`) or `null` (all day) |
| `minutes` | required integer 0–44640 |
| `code` | `null` or text up to 64 characters |
| `title` | `null` or text up to 500 characters, no control characters |
| `notes` | `null` or text up to 4000 characters; tab and line breaks allowed, other control characters not |
| `billable`, `billed` | `true`, `false` or `null` (as the person's event says) |

  A report may be at most **768 KB** (other kinds 256 KB); larger:
  `413 too_large`. An error inside a booking names it: “Booking 2: “minutes”
  is invalid.”.
- **Write** (`PUT`, `DELETE`, batch, restore): only the account in the key
  (`403 forbidden` for everyone else, Team Admins included: “Only the
  person themselves reports their bookings.”). Versions as for every record
  (`version: 0` creates, `409` on a conflict). A month without bookings any
  more: `bookings: []` or `DELETE`.
- **Read** (list, delta, `GET`, history): the account, Team Admins, and the
  current Leads of the project – Leads by `/me.lead_of`, i.e. only while
  the project is active.

### Billable marks (`billable`)

A Lead cannot write into the person's calendar; the mark goes as a record,
and the person's client applies it to its event.

- **Key:** `{uid}+{project}+{hash}`, where `hash` is the first 32 hex digits
  (lower case) of the SHA-256 of the booking `id` (UTF-8), as in the
  booking report. Example: `mia+JB1+3f9c…` (32 digits).
- **Data** – only these fields:

```json
{ "schema": 1, "person": "mia", "project": "JB1", "uid": "7f3a-1@example.test", "billable": false }
```

  `person` and `project` must equal the key's parts, `uid` is the booking
  `id` (1–1024 bytes, no control characters) and must give the key's hash,
  `billable` is `true` or `false` (required). Otherwise `422 invalid`.
- **Write:** Team Admins and the current Leads of the project in the key
  (`/me.lead_of`); not the person, not Leads of other projects (`403`,
  “Only Team Admins and the project’s Leads mark a booking as billable.”).
  `DELETE` takes the mark back: the person's own setting applies again.
- **Read:** the same, and the person in the key.
- **The person's client** applies a mark whose `project` equals the event's
  current project (otherwise it ignores it) by setting the event's billable
  flag to `billable` – once per `version` of the mark, so the person may
  still change the event afterwards; its next booking report carries the
  result. `modified_by`/`modified_at` of the record say who marked when.

### Batch

`POST /records/batch` of someone who is not a Team Admin is accepted when
every write is of kind `billing`, `booking-report` or `billable`; each
write is checked on its own (all or none, as always). A Team Admin's batch
is checked the same way for these kinds (a Team Admin cannot write
another person's booking report).

### Gaining and losing sight

- A project write that changes `leads`, `customer` or whether the project
  is active gives the customers it names (old and new) and every booking
  report and billable mark of that project a new `revision` (the `version`
  stays). Whoever may read them now gets them with the next delta.
- Nothing is sent as deleted to someone who loses sight. A Lead's client
  drops booking reports and billable marks of projects no longer in
  `/me.lead_of`, and customers no project it leads names any more; a
  client whose role became `user` drops all customers and the cards of
  others. A full read (`since=0`) always gives exactly what may be read.

### Weekly numbers and capacity

- `GET /jobs/weeks`: Team Admins everyone, everyone else (Leads included)
  only their own numbers; `?uid=` of someone else `403` (“Only your own
  numbers; Team Admins read everyone’s.”).
- `GET /jobs/{key}/capacity` and the `422` with `rule: "capacity"` on
  `accept-counter`: for Team Admins as before; for Leads `weeks` carries
  only `start`, `week` and, for a day, `day` – no `load`, `available`,
  `over` – and the message names the weeks without hours.

### `POST /jobs/fits`

Would a Job fit each recipient? The same capacity check as when a sender
accepts a counter-proposal (0.7.2/0.7.3: spread over the person's working
days from `start`, at the earliest today, to `end`; every week from the
current one at most `available`, every day at most a full day), without
numbers in the answer.

```json
POST /jobs/fits
{ "hours": 12, "start": "2026-10-12", "end": "2026-10-23",
  "recipients": ["mia", "noah", "zoe"], "job": "j-17" }
→ { "fits": { "mia": true, "noah": false, "zoe": null } }
```

- **Who:** Team Admins and Leads (role `lead`, any project); others `403`
  (“Only Team Admins and Leads ask whether a Job fits.”).
- `hours` above 0, `start`/`end` days with `end` ≥ `start` (at most ten
  years), `recipients` 1–50 accounts (duplicates removed) – as for an
  offer; otherwise `422 invalid`. Every recipient must be an active member
  of the team (`422`, “mia is not in the team.”).
- `job` (optional): the key of an existing Job being changed – its own
  hours do not count twice. Only a Job the caller manages (Team Admin, its
  project's Lead, its sender); otherwise `404`.
- Per recipient `true` (fits), `false` (a week or day above), `null`
  (their client never reported weekly numbers – then nothing would be
  blocked). Keys in the order of `recipients`.

### Privacy notice

“Who has access” says: Leads see their projects, the bookings on these
projects as the people report them (date, time, person, code, duration,
title, notes, billable, billed), the customers of these projects, names
and initials of the people – no time calendars, master data, balances or
vacation of others. Users: only their own data. New data category
“Booking reports”.

### Migration

`Version1010Date20261007120000` sets every stored level in `ts_access`
whose viewer is not a group admin of the team group to `none` (UPDATE
only, nothing deleted) and logs the count per team. A team whose group or
Team Admins cannot be read is left as it is and logged; the server reads
those rows as `none` anyway. No schema change: the new kinds live in
`ts_records`/`ts_history` (kind ≤ 16 characters).

### Capability

```json
"timesister": { "api": 2, "version": "0.14.0", "jobs": 4, "billing": 1,
  "absences": 1, "team_backup": 1, "privacy_notice": 1, "derived_lead": 1,
  "lead_scope": 1, "booking_reports": 1, "job_fits": 1 }
```

`lead_reach` is gone. `lead_scope: 1`: the rule above; `booking_reports:
1`: the kinds `booking-report` and `billable`; `job_fits: 1`: `POST
/jobs/fits`. `POST /status` still takes `projects` (0.12.0); no right
follows from it any more.

## Reopen and self-assigned Jobs, 0.15.0 (2026-10-11)

Capability `jobs: 5`; the record and the other endpoints are unchanged.

### `POST /jobs/{key}/reopen`

A Done Job goes back to In Progress with the same person; `done` becomes
`null`, `progress` stays, the log gets `reopen`. Who: the assignee, or
whoever manages the Job (Team Admin, Lead of the project, sender). Not
once `paid` (`409`), not when `progress.hours` reaches `hours` (`409` –
the next progress report would finish it again; offer a new Job instead).
Overlap is checked as on accepting (`422`, `rule: "overlap"`). Sent again
while In Progress: the unchanged record. The other side is told:
`job_reopened` to the sender when the assignee reopens, to the assignee
when a manager does.

### Members offer a Job to themselves

`PUT /jobs/{key}` with `recipients` exactly `[caller]` is open to every
member of the team, on any project of the team (the booking catalog). As
since 0.7.5 it is In Progress at once and nobody is notified. Such a Job
has `sender` = `assignee` = the member:

- `POST /jobs/{key}/change` by the sender is allowed as long as the Job
  stays theirs – `recipients`, if sent, only themselves (`403` otherwise);
  `DELETE` by the sender as before.
- Offering to others, or with several recipients, stays with Team Admins
  and the project's Leads (`403` with a message naming the rule).
- Visibility is unchanged: the member, Team Admins and the project's Leads
  see it; the history only those who manage it.

### `POST /jobs/{key}/done` by whoever manages

Done is declared by the assignee or by whoever manages the Job (Team
Admin, Lead of the project, sender), at any time – so a Lead or Team Admin
ends a Job a member took themselves. `done.by` names who did; the assignee
is told (`job_done`) when it was someone else. Already Done: the unchanged
record; not In Progress: `409`. Anyone else: `403`, or `404` when they do
not see the Job.

## Quotes, 0.16.0 (2026-10-12)

Capability `quotes: 1`. A quote is a project record (`kind: project`)
with a status before `active`; no new kind, no new endpoint. The record
and its rights are checked as before (`PUT /records/project/{key}` by
Team Admins and the project's Leads, `POST /records/batch` by Team
Admins).

> Since 2026-10-14 (`quotes: 2`) a quote is a record of its own, kind
> `quote`, see “Quotes as records, 0.16.0”. What follows still holds for
> the project record; nothing of it is removed.

### The record

| Field | Content |
|---|---|
| `status` | `draft` → `quoted` → `declined` \| `active`, then `running`, `paused`, `completed`, `archived`. Missing, `null` or empty: the client's default. Older German values are still accepted. Anything else: `422` “Unknown project status …”. |
| `codes` | While a quote (`draft`, `quoted`, `declined`) it may be empty or missing – as may `subprojects`. From `active` on (also with no status) exactly one code, as since 0.12.0: none → `422` “An active project needs a code.”; more than one → `422` “A project has exactly one code …” in every status. |
| `from_quote` | Optional boolean: the project began as a quote. It keeps it in every status, also `active`. `true`, `false` or `null`; anything else `422`. No rights hang on it. |
| `team` | Optional list of `{ "user": uid, "role": role }`: the accounts assigned to the quote or project and their role (see “The team and its roles”). At most 100, each an account of the team – also one who left (`422` “Not members of this team: …” otherwise). |
| `budgets[].currency` | Optional, three letters (`CHF`), stored upper case; `422` otherwise. |
| `budgets[].work_packages[].rate` | Optional number ≥ 0; `422` otherwise. |
| `budgets[].cap` | Optional cost cap in the budget's currency, a number ≥ 0; `null` or missing: the sum of hours × rate counts. `422` ““cap” must be null or a number ≥ 0.” otherwise. |
| `budgets[].invoice_dates` | Optional list of at most 200 days `YYYY-MM-DD`, each once: the planned invoice dates (forecast invoices – the client computes the ideal line of the burndown between them). `null` or `[]` mean none; otherwise `422` ““invoice_dates” must be a list of at most 200 days (YYYY-MM-DD), each once.” |
| `budgets[].rates` | **Not part of the record.** Rates per person are the person's `cost_rates` (see below). With content it is refused: `422` (““rates” is no longer part of a budget; cost rates live on the person.”) `null` or `{}` (a client's empty default) passes. |

Everything is nullable; what is missing means nothing. The server does
not compute with it. The Lead role follows from `leads` as since 0.11.0,
for a quote as for a running project: `draft`, `quoted` and `declined`
keep the Leads (they plan with the quote and keep reading it), only
`completed` and `archived` end the role.

### The team and its roles

Each entry of `team` is an object with `user` (the uid, required) and
`role`:

| `role` | Meaning |
|---|---|
| `member` | works on the project (the default) |
| `sparring` | sparring partner |
| `support` | supports the project |
| `qa` | quality assurance |

- A missing or `null` `role` is stored as `member`. Any other role:
  `422` ““role” in “team” is member, sparring, support or qa.”
- Strings are read as `member`: an entry `"pbuser1"` (the earlier format
  of 0.16.0) is stored as `{ "user": "pbuser1", "role": "member" }`.
  Records written before keep their strings until the next write, so a
  client reads a string entry as a member.
- Only `user` and `role` are stored; other fields of an entry are
  dropped without an error (the server keeps the shape it checks; a
  client's display fields cost nothing).
- A user twice: the first entry wins, later ones are dropped without an
  error.
- `user` missing, empty or not a string: `422` ““user” in “team” must be
  a user ID.” Not a list, or an entry neither string nor object: `422`
  ““team” must be a list of entries with “user” and “role”.”
- Members (role `user`) still do not see `team`: it is not part of the
  booking catalog (below).

```json
"team": [
  { "user": "pbuser1", "role": "member" },
  { "user": "pbuser2", "role": "qa" },
  { "user": "pbverw", "role": "support" }
]
```

### Who sees what

Unchanged in mechanism: Team Admins and the project's Leads read the full
record. Everyone else reads only the booking catalog (`schema`, `id`,
`name`, `codes`, `subprojects`, `start`, `end`, `status`, `mapping`,
`never_billable`, `led`) – without `budgets`, so without `currency` and
`rate`, and without `team` – in the single record, the list,
the delta and the history (which members may not read at all).

```json
{ "kind": "project", "key": "Q-7", "data": {
  "id": "Q-7", "name": "Harbour quote", "status": "quoted", "from_quote": true, "codes": [],
  "leads": ["pblead"], "team": [{ "user": "pbuser1", "role": "member" }, { "user": "pbuser2", "role": "qa" }],
  "budgets": [{ "id": "b1", "currency": "CHF",
                "work_packages": [{ "no": 1, "name": "Concept", "rate": 180 }] }]
} }
```

## Cost rates on the person, 0.16.0 (2026-10-12)

Capability `cost_rates: 1`. The person's hourly cost – personnel cost,
not a price – sits on the person record (`kind: person`), not on a
budget. No new kind, no new endpoint; written by Team Admins only, as
every person record.

| Field | Content |
|---|---|
| `cost_rates` | Optional list of `{ "from": "YYYY-MM-DD", "rate": number ≥ 0 }`: the rate valid from that day on, like `workload`. Each day once. `null` or `[]` mean nothing; any order, stored as sent. A non-list, an entry without a real day `from` or a number `rate` ≥ 0, or a day twice: `422`. Other fields of an entry pass. |

```json
{ "kind": "person", "key": "pbuser1", "data": {
  "login": "pbuser1", "first_name": "Mia", "workload": [{ "from": "2026-01-01", "percent": 80 }],
  "cost_rates": [{ "from": "2026-01-01", "rate": 95 }, { "from": "2026-07-01", "rate": 110 }]
} }
```

**Admin only, never in the member catalog.** Team Admins read
`cost_rates`; nobody else does – not the person on their own record, not
a Lead on the name card of others (an allowlist, which never held it).
The single record, the list, the delta and the history come without the
field for everyone who does not manage the team. The server does not
compute with it.

## Invoices, 0.16.0 (2026-10-13)

Capability `invoices: 1`. A Lead (or Team Admin) sends billable, not yet
billed hours of a budget to accounting; that makes an **invoice draft**,
which the Team Admin checks and issues. The status is set by hand. The
number follows a pattern of the team and comes from the server, so two
clients never give the same one. The server stores and checks; it does
not compute amounts.

### The record (`invoice`)

- **Key:** chosen by the client, any record key (at most 128 characters
  from `A–Z a–z 0–9 @ . _ + -`), e.g. `inv-1a2b3c4d`. The key says
  nothing about the project.
- **Data** (`schema: 1`):

```json
{ "schema": 1, "project": "d200", "budget": "b1", "from": "2026-09-01", "to": "2026-09-30",
  "status": "draft", "number": null,
  "bookings": [ { "person": "alice", "uid": "7f3a-1@example.test", "start": "2026-10-13T09:00:00" } ],
  "note": null, "created_by": "pblead", "created_on": "2026-10-13", "issued_on": null, "paid_on": null }
```

| Field | Rule |
|---|---|
| `schema` | optional integer 1–1000 |
| `project` | required, a project key (record key pattern); the rights hang on it |
| `budget` | **required**, the budget's id, 1–128 characters: every invoice belongs to a budget. Missing, `null`, empty or blank: `422` ““budget” is required: every invoice belongs to a budget.” |
| `from`, `to` | required days `YYYY-MM-DD`, `from` ≤ `to` |
| `status` | required: `draft`, `issued`, `paid` or `cancelled` |
| `number` | `null` or text of 1–64 characters (no empty string), no control characters |
| `bookings` | required list, at most **5000** (may be empty) |
| `bookings[].person` | required, 1–64 characters: the account whose event it is |
| `bookings[].uid` | required, 1–1024 characters: the event's UID |
| `bookings[].start` | required: a day `YYYY-MM-DD` (all day) or a local time `YYYY-MM-DDTHH:MM:SS` (no zone) – with `uid` it tells the occurrences of a series apart |
| `note` | `null` or text up to 2000 characters; tab and line breaks allowed |
| `created_by` | `null` or text up to 64 characters |
| `created_on`, `issued_on`, `paid_on` | `null` or a day `YYYY-MM-DD` |
| `hours`, `amount` | optional, `null` or a number ≥ 0: frozen by the client when issuing – the hours and the amount as issued |
| `currency` | optional, `null` or three letters, stored upper case: the currency of `amount` |
| `vat` | optional, `null` or a percentage 0–100: the VAT rate of this invoice (it can change during a budget; amounts are net) |

  The same (`person`, `uid`, `start`) twice: `422`. Other fields – of the
  invoice and of a booking – pass and are stored as sent, as for projects
  and billing marks. An invoice may be at most **768 KB** (other kinds 256
  KB); larger: `413 too_large`. Every error is `422 invalid` with a message
  naming the field, inside a booking with its number (“Booking 2: The same
  booking (person, uid, start) is there twice.”).

### Who reads and writes

| | user | lead | admin |
|---|---|---|---|
| read (list, delta, `GET`, history) | – | **of the active projects they lead** | all |
| create, change, delete, restore | – | **drafts without a number** of these projects | all |

- “The projects they lead” as for booking reports: the active projects in
  `/me.lead_of`. A Lead's write must hold before **and** after: the stored
  invoice (if any) and the new one (if not deleted) are both of a project
  they lead, both `status: "draft"`, both `number: null`. So a Lead
  creates, changes and deletes their drafts, but cannot issue, pay, cancel,
  number, move to another project or touch an invoice once a Team Admin
  issued it. The check runs against the stored state inside the write's
  transaction.
- `403 forbidden`: “Only Team Admins and the project’s Leads write
  invoices.” (not a Lead of the project, or a Member) and “A Lead writes
  only drafts without a number; issuing, payment and cancelling are for
  Team Admins.”
- A single read of an invoice the caller may not read is `403` – also
  when no such record exists; a Team Admin gets `404` for a missing one.
- `POST /records/batch` of a Lead may hold invoices (with billing marks,
  booking reports, billable marks); each write is checked on its own, all
  or none. An error names the write (“Write 2: …”, `index`).
- **Tombstones:** a deleted invoice keeps its project on the server (not
  handed out), so Team Admins and the project's Leads get the tombstone in
  their delta and may read its history; Members never see one.
- **Gaining sight:** a project write that changes `leads` (or whether the
  project is active) gives the project's invoices a new `revision`, as for
  booking reports; a new Lead finds them in the next delta. Nothing is sent
  as deleted to someone who loses sight; a Lead's client drops invoices of
  projects no longer in `/me.lead_of`.

### The number pattern (`setting/settings`, `invoice_number`)

```json
"invoice_number": { "pattern": "RE-{year}-{no:4}", "per_year": true, "start": 1 }
```

- `pattern`: 1–64 characters with **exactly one** number placeholder –
  `{no}`, `{no:N}`, `{nr}` or `{nr:N}` (N 1–8, the width with leading
  zeros) – and any of `{year}`, `{jahr}`, `{yy}`, `{jj}`, `{project}`,
  `{projekt}`, `{customer}`, `{kunde}`. Another placeholder: `422`
  “Unknown placeholder {month} in the invoice number pattern.”; none or two
  number placeholders: `422`; a brace outside a placeholder: `422`.
- `per_year`: optional boolean, default `true` – a counter per year;
  `false` – one counter across years.
- `start`: optional integer 1–999999999, default 1 – the first number.
- `invoice_number` missing or `null`: no pattern; the counter still works
  with the defaults. Other fields inside pass. Written by Team Admins, as
  every setting. The client fills the pattern; the server only counts.

### The invoice issuer (`setting/settings`, `issuer`)

Who issues the team's invoices – for the sender block, the note on invoices
abroad and the payment part with a QR code:

```json
"issuer": { "name": "Denkstatt sàrl", "street": "Dornacherstrasse 192", "postcode": "4053", "city": "Basel",
            "country": "CH", "email": "info@example.org", "phone": "+41 61 000 00 00", "vat_id": "CHE-123.456.789 MWST",
            "foreign_note": "…", "qr": true,
            "accounts": [{ "currency": "CHF", "iban": "CH9300762011623852957", "bic": "" },
                         { "currency": "EUR", "iban": "DE89370400440532013000", "bic": "COBADEFFXXX" }] }
```

- Texts: `name` ≤ 70, `street` ≤ 70, `postcode` ≤ 16, `city` ≤ 35,
  `country` ≤ 2 (ISO code), `email` ≤ 254, `phone` ≤ 40, `vat_id` ≤ 32,
  `foreign_note` ≤ 500 (line breaks allowed); no control characters.
- `qr`: boolean. The client puts a Swiss QR bill on CHF invoices and an EPC
  GiroCode on EUR invoices; other currencies get none.
- `accounts`: at most 10, one per `currency` (three letters); `iban` without
  spaces, `bic` empty or 8/11 characters. Otherwise `422`.
- `issuer` missing or `null`: none. Written by Team Admins, as every setting.
- A customer record may carry `vat_id` (text); it passes like other fields.

### `POST /invoices/next-number`

```json
POST /invoices/next-number
{ "year": 2026 }
→ { "number": 12, "year": 2026 }
```

- Takes the next number of the team's counter and returns it. **Atomic:**
  two calls at the same time – from two Macs – never get the same number,
  and none is skipped. A number taken stays taken, also if the client
  never uses it.
- `per_year: true`: one counter per `year`; `false`: one counter for all
  years (`year` only comes back as sent).
- The first number is `start`; never below `start` – raised later, the
  counter continues at `start`; lowered later, it continues where it was.
- `year`: integer (or digits) 1900–9999, otherwise `422` (““year” must be
  a year from 1900 to 9999.”); missing: the current year (UTC).
- Team Admins only; anyone else `403` (“Only Team Admins assign invoice
  numbers.”).

### `GET /invoices/next-number?year=2026`

The number the next `POST` would give, without taking it (preview for
the settings): `{ "number": 12, "year": 2026 }`. Same rules and rights.
Between the preview and the `POST` another client may take it.

### Stored

`Version1011Date20261013120000` adds the table `ts_counters` (team,
`name` – `invoice:2026` per year or `invoice` –, `last_no` the last number
given, `modified_at`), unique per team and name. Additive only. Invoices
live in `ts_records`/`ts_history` like every record and travel in the team
ZIP; the counters do not – after moving a team to another server, raise
`start` above the last number. Deleting a team removes its counters
(`counts.counters` in the answer of `DELETE /admin/teams/{id}`).

### Capability

```json
"timesister": { "api": 2, "version": "0.16.0", "jobs": 5, "billing": 1, "absences": 1,
  "team_backup": 1, "privacy_notice": 1, "derived_lead": 1, "lead_scope": 1,
  "booking_reports": 1, "job_fits": 1, "quotes": 1, "cost_rates": 1, "invoices": 1 }
```

`invoices: 1`: the kind `invoice`, `invoice_number` in `setting/settings`
and `/invoices/next-number`.

## Invoice template, 0.16.0 (2026-10-13)

Capability `invoice_template: 1`. The Mac app fills invoices into a Word
template (`.docx`). Without a server it keeps the template locally; in a
team, a Team Admin uploads it and the server copy is the source for every
Mac of the team: a Mac reads `/info` and downloads the file when `sha256`
differs from its copy. One template per team. The server checks that the
file is a Word document and stores it; it never opens or fills it.

### Endpoints

| Method | Path | Body / answer |
|---|---|---|
| GET | `/templates/invoice/info` | `{ name, size, sha256, modified_at, modified_by }`; none stored: `404 not_found` |
| GET | `/templates/invoice` | the same plus `base64` (the file); none stored: `404 not_found` |
| PUT | `/templates/invoice` | `{ "name": "Rechnung.docx", "base64": "…" }` → the info object; replaces the stored one |
| DELETE | `/templates/invoice` | `{ "deleted": true }`; `{ "deleted": false }` when none was stored |

```json
GET /templates/invoice/info
→ { "name": "Rechnung.docx", "size": 48213, "sha256": "9f2c…", "modified_at": "2026-10-13T12:00:00Z", "modified_by": "pbadmin" }

GET /templates/invoice
→ { "name": "Rechnung.docx", "size": 48213, "sha256": "9f2c…", "modified_at": "2026-10-13T12:00:00Z", "modified_by": "pbadmin", "base64": "UEsDBBQ…" }

GET /templates/invoice/info   (none stored)
→ 404 { "error": "not_found", "message": "The team has no invoice template." }
```

- **Team Admins only, reading too.** Leads and Members get `403 forbidden`
  “Only Team Admins manage the invoice template.” (a refused `PUT` or
  `DELETE` counts for brute-force throttling, as every refused write).
  Every account reaches only its own team's template.
- `size`: bytes of the file; `sha256`: hex of the file; `modified_at`:
  UTC, when it was uploaded; `modified_by`: the account that uploaded it.
- Rate limits per account: `PUT` and `DELETE` 30, `GET /templates/invoice`
  60 per minute.

### Checks on `PUT`

In this order: rights, body size, `name`, `base64`, file size, Word.

| Case | Answer |
|---|---|
| `name` missing | `422 invalid` ““name” is missing.” |
| `name` not 1–200 characters ending in `.docx` (any case), or with `/`, `\` or control characters | `422 invalid` ““name” must be a file name of 1 to 200 characters ending in .docx.” |
| `base64` missing | `422 invalid` ““base64” is missing.” |
| `base64` not a string or not Base64 (strict; line breaks pass) | `422 invalid` ““base64” is not valid Base64.” |
| the file over 10 MB (10 485 760 bytes), or the body over 14 MB (14 680 064 bytes) | `413 too_large` “A template may be at most 10 MB.” |
| not starting with the ZIP signature `PK\x03\x04`, or not a readable ZIP | `422 invalid` “The template must be a Word document (.docx).” |
| a ZIP without the entry `word/document.xml` | `422 invalid` “The template is not a Word document: “word/document.xml” is missing.” |
| body empty, not JSON or not an object | `400 invalid`, as everywhere |

- Only the ZIP's directory is read, nothing is unpacked. A refused `PUT`
  leaves the stored template as it was.
- **Size.** 10 MB as Base64 is about 13.4 MB of JSON. A body over 14 MB is
  refused without reading the rest. `PUT` bodies are not limited by PHP's
  `post_max_size`; the test Nextcloud (Apache, `LimitRequestBody` 1 GB)
  takes a template of just under 10 MB. A reverse proxy with a smaller
  limit (nginx `client_max_body_size`; Nextcloud's sample configuration
  sets 512 MB, nginx's default is 1 MB) answers `413` itself, before the
  app and without the OCS envelope.

### Stored

In the app's data folder (`IAppData`): `templates/t<team>/invoice.docx`
and `templates/t<team>/invoice.json` (the info object). No table, no
migration. The JSON is written after the file; a template with only one of
the two counts as none (`404`).

### Team ZIP and deleting a team

- The team ZIP (`GET /admin/teams/{id}/export`) carries the template as
  `templates/invoice.docx` and `templates/invoice.json` (the info object),
  both listed in the manifest with their checksums. ZIPs from before
  0.16.0 have none; older app versions ignore the two entries. Only one of
  the two, or a JSON that does not match the file: `422` ““templates/invoice.json”
  in the backup is malformed.”
- Preview: `template` – the info object of the ZIP's template, or `null`.
- Import, after the transaction like the calendars: `merge` stores the
  ZIP's template when the team has none or the ZIP's `modified_at` is
  younger; `replace` whenever it differs. A ZIP without a template leaves
  the team's own, also with `replace`. `modified_by` follows the account
  mapping. Answer: `template: null` (none in the ZIP), `{ name, stored:
  true }` or `{ name, stored: false, reason }` with `reason` `same`,
  `kept_existing`, `not_a_template` (fails the Word check) or `failed`.
- A template counts as a team's content: importing into the team and
  deleting it keep a team ZIP first (`pre_backup`, `backup`), also when
  the team has nothing else.
- `DELETE /admin/teams/{id}` removes `templates/t<id>/` after the commit,
  with the protected calendar backups.

### Capability

```json
"timesister": { "api": 2, "version": "0.16.0", "jobs": 5, "billing": 1, "absences": 1,
  "team_backup": 1, "privacy_notice": 1, "derived_lead": 1, "lead_scope": 1,
  "booking_reports": 1, "job_fits": 1, "quotes": 1, "cost_rates": 1, "invoices": 1,
  "invoice_template": 1 }
```

`invoice_template: 1`: `/templates/invoice` and the template in the team
ZIP.

## Quotes as records, 0.16.0 (2026-10-14)

Capability `quotes: 2`. A quote no longer creates a project: it is a
record of its own, kind `quote`, holding a budget before anyone accepted
it, optionally assigned to an existing project. On acceptance the client
writes the budget into an existing project (an ordinary project `PUT`) and
sets the quote `accepted` – two ordinary record writes, no new endpoint.
Everything of “Quotes, 0.16.0” above (project statuses `draft`, `quoted`,
`declined`, `from_quote`, `team`) stays as it is; nothing is removed.

### The record (`quote`)

- **Key:** chosen by the client, `q-` and 8 lowercase hex digits, e.g.
  `q-1a2b3c4d`. Any other key: `422` “The key of a quote is “q-” and 8
  lowercase hex digits.” (outside the general record key pattern: `400`,
  as for every kind).
- **Data** (`schema: 1`):

```json
{ "schema": 1, "name": "Harbour study", "status": "quoted", "customer": "kanton", "project": null,
  "number": "OF-2026-007", "date": "2026-10-14", "valid_until": "2026-11-30", "accepted_on": null,
  "created_on": "2026-10-14", "leads": ["pblead"], "contact": "Ms. Example", "note": null,
  "pdf": "https://cloud.example.test/s/offerte-hafen.pdf",
  "budget": { "id": "b-q1", "name": "Study", "currency": "CHF", "cap": 24000,
              "work_packages": [{ "no": 1, "name": "Concept", "hours": 40, "rate": 180 }] },
  "budget_id": null, "hours": null, "amount": null, "currency": null, "created_by": "pbadmin" }
```

| Field | Rule |
|---|---|
| `schema` | optional integer 1–1000 |
| `name` | **required**, text of 1–200 characters, no control characters |
| `status` | **required**: `draft`, `quoted`, `accepted` or `declined` |
| `customer` | `null` or a customer's key (record key pattern) |
| `project` | `null` or a project's key (record key pattern); **required** once `status` is `accepted`: `422` “An accepted quote needs “project”: the project that took over its budget.” |
| `number` | `null` or text of 1–64 characters, no control characters: the quote number |
| `date`, `valid_until`, `accepted_on`, `created_on` | `null` or a day `YYYY-MM-DD` |
| `leads` | optional, `null` or a list of at most 20 entries of 1–128 characters: accounts or person keys, as in a project's `leads`. Informative and for the rights (below); unknown accounts pass, no check against the team |
| `contact` | `null` or text up to 200 characters, no control characters |
| `note` | `null` or text up to 2000 characters; tab and line breaks allowed |
| `pdf` | `null` or an `http://` or `https://` address of at most 2000 characters, without spaces or control characters; anything else `422` |
| `budget` | `null` or an object with a text `id` of 1–128 characters (not blank). Its content is checked as a project's budget: `currency` three letters, stored upper case; `cap` `null` or a number ≥ 0; `work_packages[].rate` `null` or a number ≥ 0; `rates` with content refused (`null` or `{}` pass) – the same messages as for projects. Other fields of the budget pass |
| `budget_id` | `null` or text of 1–128 characters: the id of the budget in the project after acceptance |
| `hours`, `amount` | `null` or a number ≥ 0: frozen by the client at acceptance |
| `currency` | `null` or three letters, stored upper case: the currency of `amount` |
| `created_by` | `null` or text up to 64 characters |

  Other fields pass and are stored as sent, as for invoices and projects.
  A quote may be at most **768 KB** (a budget can be large; other kinds
  256 KB); larger: `413 too_large` (“A quote may be at most 768 KB.”).
  Every other error is `422 invalid` with a message naming the field.

An accepted quote:

```json
{ "schema": 1, "name": "Harbour study", "status": "accepted", "project": "d200", "number": "OF-2026-007",
  "accepted_on": "2026-10-20", "leads": ["pblead"], "budget": { "id": "b-q1", "currency": "CHF", "cap": 24000 },
  "budget_id": "b7", "hours": 40, "amount": 7200, "currency": "CHF", "created_by": "pbadmin" }
```

### Who reads and writes

| | user | lead | admin |
|---|---|---|---|
| read (list, delta, `GET`, history) | – | **quotes whose `leads` name them, or of a project they lead** | all |
| create, change, delete, restore | – | **these quotes, before and after the write** | all |

- A Lead is an account that leads at least one active project
  (`/me.lead_of`). A Member sees no quotes – not even one naming them in
  `leads` – until they lead a project.
- `leads` names a Lead the way a project's `leads` does: their account, or
  the key of a person whose `accounts` hold it. “A project they lead”: one
  of the active projects in `/me.lead_of`.
- A Lead's write must hold before **and** after: the stored quote (if any,
  also a tombstone) and the new one (if not deleted) are both quotes they
  read. So a Lead creates quotes naming themselves or on a project they
  lead, changes, accepts and deletes them, but cannot give one away (out
  of their `leads` and off their projects) nor take over or delete one
  they do not read. The check runs against the stored state inside the
  write's transaction. `403 forbidden`: “Only Team Admins and the quote’s
  or project’s Leads write quotes.”
- A single read of a quote the caller may not read is `403` – also when
  no such record exists; a Team Admin gets `404` for a missing one.
- `POST /records/batch` of a Lead may hold quotes (with invoices, billing
  marks, booking reports, billable marks), not projects; each write is
  checked on its own, all or none. An error names the write.
- **Tombstones:** a deleted quote keeps its last data on the server (not
  handed out), so whoever read it gets the tombstone in their delta and
  may read its history; Members never see one.
- **Gaining sight:** a project write that changes `leads` (or whether the
  project is active) gives a new `revision` to the project's quotes and
  to the quotes naming one of its old or new leads (account or person
  key) – a new Lead finds them in the next delta. Nothing is sent as
  deleted to someone who loses sight; a Lead's client drops the quotes it
  no longer reads by the rule above (all of them once `/me.lead_of` is
  empty).

### Accepting a quote

Two ordinary writes, no new endpoint:

1. `PUT /records/project/{key}` – the quote's budget into the project's
   `budgets` (with the `id` the project shall use), with the rights of
   every project write (Team Admins and the project's Leads).
2. `PUT /records/quote/{key}` – `status: "accepted"`, `project`,
   `budget_id`, `accepted_on` and the frozen `hours`, `amount`, `currency`.

The server does not link the two: if the second fails, the client repeats
it. A Team Admin may send both in one `POST /records/batch` (all or none).

### Stored

In `ts_records`/`ts_history` like every record; no migration. The team
ZIP carries quotes; moving to other accounts maps `leads` and
`created_by`.

### Capability

```json
"timesister": { "api": 2, "version": "0.16.0", "jobs": 5, "billing": 1, "absences": 1,
  "team_backup": 1, "privacy_notice": 1, "derived_lead": 1, "lead_scope": 1,
  "booking_reports": 1, "job_fits": 1, "quotes": 2, "cost_rates": 1, "invoices": 1,
  "invoice_template": 1 }
```

`quotes: 2`: the kind `quote`; what `quotes: 1` meant (project statuses
`draft`, `quoted`, `declined`, `from_quote`, `team`, currency and rates in
the budgets) holds as before. A server with `quotes: 1` knows no kind
`quote` (`400` “Unknown kind”).

## Time entries, 0.17.0 (2026-10-15) – the app holds the team's hours

Decided 2026-10-15 (`PLAN-ZEITDATEN.md`): the app is the one book of time
entries. Every person **names** their time calendar once; the app reads it
on the server (also the person's own entries – the client shows the same
truth as everyone else) and reads the `.ics` feeds of external persons
itself. Clients no longer read other members' calendars; the calendar
shares matrix becomes obsolete (removed from the clients in a later step,
the endpoints stay until then). Still `api: 2`; new capability
`entries: 1`.

### What is stored

The app stores **calendar objects**, not occurrences: one row per object
(one VEVENT with its exceptions, as CalDAV stores it; for a feed, all
components sharing one UID). The client expands recurrences and reads
codes with its own parser, exactly as it does for its own calendar. The
server parses only what it needs to filter by role: the project codes.

```text
ts_time_calendars  tenant_id, uid, source (calendar|feed), calendar_id, calendar_uri,
                   feed_url?, named_at, last_read_at, last_full_read_at, last_error,
                   reread (0|1), etag?                              UNIQUE(tenant_id, uid, source)
ts_entries         id, tenant_id, uid, source, event_uid, object_uri, etag, ics,
                   summary, codes, first_start, last_end, cancelled, kept, deleted,
                   revision, modified_at                            UNIQUE(tenant_id, uid, source, event_uid)
ts_tenants         + entries_revision, entries_since
```

- `uid`: the account of the member; for an external person the person's
  `login` (the key of the person record).
- `codes`: the `#code`s found in SUMMARY, LOCATION and DESCRIPTION and
  the values of `X-TIMESISTER-CODE`, lower-cased, comma-separated. The
  rule is the client's `buchung::codes_finden`: a `#` not preceded by a
  letter or digit, then one or more of letters, digits, `.`, `-`, `_`,
  `/`; an optional share after `:` (`60%`, `1.5h`) is dropped; trailing
  `.`, `-`, `_`, `/` are stripped.
- `first_start`/`last_end`: the object's first start and last end as
  dates; `last_end` is `null` while the recurrence has no end (or a
  `COUNT`, which the server does not expand). An object is stored when
  `last_end` is `null` or on/after the team's `entries_since`.
- `cancelled`: `STATUS:CANCELLED` on the master component.
- `kept`: the object vanished from the calendar or feed, but a `billing`
  record (`person` = `uid`, `uid` = `event_uid`) exists – the book keeps
  it; the client shows it as the book's copy, read-only. A restored object
  clears `kept`.
- `deleted`: tombstone (no `billing` record); `ics` is empty then.
- `revision`: a counter per team, separate from the records' revision,
  increased by every change; `GET /entries?since=` reads by it.

### Naming the time calendar

- `PUT /me/time-calendar` `{ "calendar_url": "<CalDAV URL or calendar URI>" }`
  (any member, own account only). The server takes the last path segment
  as the calendar URI, finds it among the account's own calendars
  (`IManager::getCalendarsForPrincipal`), stores its key and answers
  `{ "uid", "calendar_uri", "calendar_id", "named_at" }`. `404 not_found`
  when the account has no such calendar; `422 invalid` for an empty or
  malformed value. Naming again with the same calendar changes nothing;
  with another one, the old calendar's entries become tombstones and the
  new one is read in full.
- `DELETE /me/time-calendar`: un-names; the person's entries become
  tombstones, kept ones stay.
- **Naming by convention** (added 2026-10-15): before each run of the job,
  every active member without a named calendar gets `zeit-<uid>` – the
  calendar the Mac app creates – as soon as that calendar exists. The
  person can still name another one; nothing else of the account's
  calendars is looked at. A team whose members never opened the Mac app
  (or whose calendars were imported) is read without anyone naming.
- **Immediate read** (added 2026-10-15): `PUT /me/time-calendar` reads a
  newly named calendar at once when the CalDAV backend is available and
  the calendar has at most 3000 objects; larger ones, or without the
  backend, wait for the job (minutes). The answer adds `read_now` (bool:
  read in this request) and `count` (objects of this calendar in the book
  now, tombstones not counted). Naming the same calendar again: `read_now`
  false, `count` as it stands. A failing immediate read is no error for
  the client: `read_now` false, the message in `last_error`, the job
  tries again.
- `GET /me` adds `time_calendar`: `{ "calendar_uri", "calendar_id", "named_at", "last_read_at", "last_error" }`
  or `null`, `entries_revision` (the team's current counter) and
  `entries_since` (the effective cutoff date).
- `GET /status` rows add `time_calendar` (bool: named), `entries_read_at`,
  `entries_error` – this is the new “who is missing”.
- External persons: the feed from the person record (`feed`) is read by
  the server; nothing to name. `GET /entries/status` lists them too.

### Cutoff per team

- Team setting `entries_since` (`"YYYY-MM-DD"` or `null`): entries older
  than this are not read. `null` means the default, **two years before
  the team was created** (first of that month). The effective value is
  returned as `entries_since` in `/me`; the stored value in
  `settings.entries_since` of `GET /team` and `GET /admin/teams`.
  When the cutoff moves, the next run reads every source in full: entries
  ending before the new cutoff vanish from the book – a tombstone, or
  `kept` when a `billing` record exists –, entries now inside it are read
  (again), a tombstoned one comes back as a new revision.
- `PUT /team/settings` `{ "entries_since": "YYYY-MM-DD" | null }` – Team
  Admins; only this key for now (`422 invalid` for anything else or a
  malformed date). Nextcloud admins may also set it through
  `POST`/`PUT /admin/teams` `settings`. A change marks every named calendar
  and feed for a full re-read.

### Reading

- **Main path: calendar events.** The app listens to
  `OCP\Calendar\Events\CalendarObjectCreatedEvent`, `…UpdatedEvent`,
  `…DeletedEvent`, `…MovedToTrashEvent`, `…RestoredEvent` and
  `…MovedEvent`. The listener does one cheap thing: if the event's
  calendar id is a named time calendar, it stores **that one object** from
  the event's object data (`calendardata`, `uri`, `etag`); otherwise it
  returns at once. Never a full read in the listener.
- **Net: the job `EntryReader`**, every 5 minutes (`TimedJob`):
  1. named calendars marked `reread`, never read, or whose last full read
     is older than a day: a full read (all objects; what is no longer
     there becomes a tombstone or `kept`); with the CalDAV backend
     available (optional, as `AbsenceService` does it) by comparing etags,
     otherwise through `ICalendarExport` by comparing the objects' hashes;
  2. feeds: `GET` with `If-None-Match` (`OCP\Http\Client`), only `http(s)`,
     at most 20 MB, 20 s; `304` ends it; otherwise the feed is split per
     UID and stored like a calendar; UIDs no longer in the feed become
     tombstones or `kept`;
  3. one calendar or feed failing does not stop the others; the error is
     kept in `last_error` and shown in `/status` and `/entries/status`
     (a feed that answers something other than an iCalendar file – a
     login page, say – counts as failing; its entries stay);
  4. **members who left** (added 2026-10-15): the named calendar of a
     member with `left_at` is not read and not followed by the listener;
     the row stays with `last_error` «member left», the entries stay in
     the book and are delivered by `GET /entries` as before (billed
     hours). Back in the team (`left: false`), the next run reads it in
     full. Feeds of external persons are not affected.
- `POST /entries/reread` (Team Admins): marks every named calendar and
  feed for a full read on the next run. Answer `{ "marked": n }`.
- `GET /entries/status` (Team Admins): `[{ uid, source, calendar_uri?, named_at, last_read_at, last_full_read_at, last_error, count, left }]`
  – `left` (added 2026-10-15): the member left the team, always false
  for a feed.

### Reading the entries (clients)

`GET /entries?since=<revision>&limit=<n>` (default and maximum 2000; a
`limit` outside 1–2000 is refused by Nextcloud itself with `400` before
the app runs) – every member, filtered by role:

- Team Admin: everything;
- Lead: own entries, and entries whose `codes` contain a code of a
  project the Lead leads (`/me.lead_of`, including subproject codes; a
  part of such a code counts too – `d196.4.1` belongs to `D196`);
- User: own entries only.

With `since=0` tombstones are left out, as for the records; from any
later revision on they are delivered. A `since` beyond the current
revision is not an error: `entries` is empty, `more` false.

Answer `{ "revision": <current>, "entries": [ … ], "more": bool }`, entries
in `revision` order, each
`{ "uid", "source", "event_uid", "object_uri", "etag", "ics", "codes": [ … ], "cancelled", "kept", "deleted", "revision", "modified_at" }`
(`ics` empty on a tombstone). Clients remember the highest `revision`
they applied and ask with it next time; with `more` they ask again at
once. The filter is applied when reading: a Lead whose projects change
(`/me.lead_of` differs from last time) starts over with `since=0` and
drops the entries it no longer receives – the server sends nothing as
deleted to someone who merely loses sight.

### Rules

- Objects are stored as received; the server does not rewrite the iCal.
  The client's own calendar stays its editing surface; the server never
  writes into a named calendar.
- `kept` is decided from the `billing` records at the moment an object
  vanishes; a `billing` record written later does not revive an already
  tombstoned object.
- Limits: 256 KB per object (`ics`), larger objects are skipped with the
  error kept in `last_error`; at most 100 000 objects per calendar or
  feed in one full read.
- Team boundary in every query, as everywhere.

### Team ZIP (added 2026-10-15)

`team.json` carries `settings.entries_since` (the stored cutoff, or
`null`) and `time_calendars: [{ uid, calendar_uri, named_at }]` – the
named time calendars of the members. The book itself is not in the ZIP:
it is read again from the calendars. ZIPs from before 0.17.0 have neither
and import as before.

- Preview adds `entries_since` (the ZIP's stored cutoff) and
  `time_calendars` (how many are named). Accounts named only in
  `time_calendars` count for the `mapping`.
- Import, after the transaction: `replace` stores the ZIP's cutoff as it
  is (also `null`); `merge` only when the team has none stored. A changed
  cutoff marks every source for a full read. For every named calendar the
  mapped account's calendar is looked for – first the ZIP's `calendar_uri`,
  then `zeit-<uid>` – and named; `merge` keeps a calendar already named
  here; `replace` names the ZIP's (the old entries become tombstones when
  it is another calendar). One row failing does not stop the others.
- Answer: `entries_since: { value, applied }` and
  `time_calendars: [{ uid, named: true, calendar_uri } | { uid, named: false, reason }]`
  with `reason` `no_account` (mapped to `null`), `kept_existing`,
  `no_calendar` (the account has neither calendar) or `failed`.

### Capability

```json
"timesister": { … , "entries": 1 }
```

## Shares removed, 0.17.0 (2026-10-15)

With the time entries in the app (section above), nobody reads another
person's calendar any more: Team Admins, Leads and Users get the team's
hours from `GET /entries`, filtered by role on the server. The shares
matrix of 0.5.0 and everything that hung on it is gone – removed, not
switched off. The sections above describe it as it was; nothing of it
answers any more.

Gone:

- Endpoints: `GET /team/access`, `PUT /team/access`,
  `PUT /team/access/{viewer}/{owner}`, `POST /team/access/remind` (404),
  `GET`/`PUT /me/calendar-share` (404).
- `GET /me`: `share_targets`, `may_override`, `calendar_share`, `admins`,
  `leads`. `lead_of` stays.
- `POST /status`: `calendar_shared`, `applied_shares` are ignored (no
  error, nothing stored). `GET /status`: `calendar_shared`.
- `PUT /team/members/{uid}` and `PUT /admin/teams/{id}/members/{uid}`:
  `may_override` is no field any more (a body with nothing else: `400`);
  the member entries in `GET /team`, `GET /admin/teams` and the team ZIP
  carry no `may_override`.
- Team setting `leads_see_calendars` (`settings` of `/admin/teams`,
  `/team`, `/me`): gone; sending it is an unknown key (`422`). The column
  in `ts_tenants` stays unused.
- The Nextcloud notification `shares_changed` ("your calendar shares have
  changed"); the Job notifications stay.
- Externals as columns: the feed of an external person is read by the
  server (time entries), the person record carries `feed` for Team Admins
  only, as since 0.14.0. The admin overview counts
  `without_time_calendar` (members without a named time calendar) instead
  of `calendar_not_shared`.
- Team ZIP: `team.json` has no `access` list, members no `may_override`,
  `status.json` no `calendar_shared`; older ZIPs with them import fine
  (ignored). The import result has no `access` count.

Stays: the table `ts_access` (unused; rows go with the team or the
account), the columns `may_override`, `calendar_shared`, `applied_shares`,
`applied_at` and `leads_see_calendars` – the schema only grows. The
privacy notice (`GET /team/privacy`) names no matrix any more. No
capability announced the matrix, so none changes; `entries: 1` marks the
state.

Where it went: the named time calendar (`PUT /me/time-calendar`),
`GET /entries?since` by role, `time_calendar`/`entries_read_at`/
`entries_error` in `GET /status` as the new "who is missing".

## Invoicing, 0.18.0 (2026-10-15) – issuing on the server, with a ledger

Decided 2026-10-15 (`PLAN-FAKTURA.md`): invoices are **issued on the
server** in one transaction – number, frozen content, the hours they bill,
the billing marks and an append-only ledger with a SHA-256 chain. After
issuing nothing changes; corrections are a reversal or a credit note with
their own number. Documents (PDF with QR bill, ZUGFeRD, XRechnung) follow
in 0.19.0; this section is the foundation. Capability `invoicing: 1`.
Team setting `invoicing` (bool, default `true`): off, the endpoints below
answer `404 not_found` and clients issue as before (0.16.0, on the Mac).

### The draft (record `invoice`, additions)

The draft stays the record of 0.16.0. New optional fields, written by the
client while the status is `draft`:

| Field | Rule |
|---|---|
| `greeting`, `body`, `footer` | `null` or text up to 4000 characters each (tab and line breaks allowed): the free texts of the document – salutation and introduction, the text after the positions, the footer. Prefilled by the client from the team's template texts. |
| `positions` | optional list, at most 500: `{ "kind": "hours"\|"flat"\|"discount"\|"text", "text": …, "work_package"?: int, "person"?: uid, "hours"?: number ≥ 0, "rate"?: number ≥ 0, "amount": number }` – the lines as the client composed them from the hours (grouped per work package and person, or as chosen) plus flat fees, discounts (negative `amount`) and text-only lines (`amount` 0). `text` 1–500 characters. |
| `bookings[].hours`, `bookings[].rate`, `bookings[].code` | optional per booking: the hours of that occurrence, the rate applied, the code; `hours`, `rate` numbers ≥ 0, `code` up to 64 characters. The server stores them with the invoice's hours when issuing. |

An issued invoice (`number` set, status not `draft`) refuses every record
write: `409 issued` “An issued invoice does not change; reverse it.”
(before: Leads only). Deleting an issued invoice: `409 issued` too.

### Issuing

`POST /invoices/{key}/issue` – Team Admins, body `{ "issued_on"?: "YYYY-MM-DD", "due_days"?: 1–365 }`
(default: today, 30). One transaction:

1. The draft must exist, be `draft` without `number`, have a `budget`, at
   least one position or booking, and a resolvable customer
   (`project.customer`, a `customer` record). Otherwise `422 invalid`
   naming what is missing.
2. Every booking must be in the book (`ts_entries`, `uid` + `event_uid`,
   not deleted) – `422 invalid` “Booking n is not in the book.” – and must
   not carry a billing mark of another invoice: `409 conflict` “Booking n
   is billed by invoice RE-2026-0007.” A series counts per occurrence
   (`start`).
3. The number comes from the team's counter and pattern (0.16.0).
4. Frozen into `ts_inv_invoices`: `issuer_json` (the settings' `issuer`),
   `customer_json` (name, address lines, country, VAT id, contact if the
   draft names one), `positions_json`, the three texts, the period, the
   currency (the budget's), `vat` (the draft's, else the team's default,
   else 0), and the sums the **server** computes from the positions:
   `net` = Σ `amount`, `vat_amount` = round(net × vat / 100, 2),
   `gross` = net + vat_amount. The client's `amount` is taken as sent;
   the server does not recompute hours × rate.
5. `ts_inv_hours`: one row per booking (uid, event_uid, start, hours, rate,
   amount = hours × rate, code).
6. Billing marks: one `billing` record per booking (`document` = number,
   `source` = `timesister`, `by` = the issuer, `billed_on` = `issued_on`),
   written like a batch of records in the same transaction. The book keeps
   billed objects when they vanish (`kept`, 0.17.0).
7. The invoice record: `status: "issued"`, `number`, `issued_on`,
   `hours`, `amount` (= net), `currency`, `vat`, `due_on`, `ledger_seq`,
   `hash`; a new record version like any write.
8. The ledger (below): action `issued`.

Answer: the invoice record as `GET /records/invoice/{key}` plus
`"issue": { "number", "seq", "prev_hash", "hash", "net", "vat_amount", "gross", "currency", "due_on", "document_state": "pending" }`.
`document_state` stays `pending` until 0.19.0 renders documents.

### Reversal and credit note

`POST /invoices/{key}/reverse` – Team Admins, body
`{ "kind": "reversal"|"credit", "reason"?: text ≤ 500, "issued_on"? }`:

- The invoice must be `issued` or `paid`; otherwise `422`. Already
  reversed: `409 conflict`.
- `reversal`: a new invoice record (key chosen by the server, `inv-…`)
  with the same positions negated, its own number, `reverses` = the
  original's key, frozen and ledgered like an issue; the original becomes
  `status: "cancelled"`, `reversed_by` = the new key; the billing marks of
  the original are **deleted** (the hours are billable again); the
  original's `ts_inv_hours` stay (history).
- `credit`: the same, but the original stays `issued`/`paid` and keeps its
  marks; the credit note carries the negated positions (or the client's
  `positions` from the body, if given) and `credits` = the original's key.
- Answer: both records and the new invoice's `issue` block.

### Paid

`POST /invoices/{key}/paid` `{ "paid_on": "YYYY-MM-DD", "note"?: ≤ 500 }` –
Team Admins; `issued` → `paid`, ledger action `paid`. `422` when not
issued. `POST /invoices/{key}/unpaid` reverts (ledger `unpaid`), for a
mistaken entry.

### Reading

- `GET /invoices/{key}/fixed` – Team Admins and the project's Leads: the
  frozen content `{ number, status, issued_on, due_on, paid_on, currency, vat, net, vat_amount, gross, issuer, customer, positions, hours: [ … ], greeting, body, footer, chain: { seq, prev_hash, hash }, reverses, reversed_by, credits, document_state }`.
  A draft: `404 not_found` (nothing is fixed yet).
- `GET /invoices/ledger?since=<seq>&limit=<n>` (default 500, max 2000) –
  Team Admins: `{ "entries": [ { seq, at, actor, action, invoice, number, payload_hash, prev_hash, hash } ], "more" }`.
- `GET /invoices/chain/verify` – Team Admins: recomputes every hash of the
  team's ledger in order; `{ "ok": true, "entries": n, "head": hash }` or
  `{ "ok": false, "entries": n, "first_break": seq, "expected": …, "found": … }`.

### The ledger and the chain

```text
ts_inv_invoices  id, tenant_id, rkey (record key, UNIQUE per team), number (UNIQUE per team), status,
                 project, budget, customer, period_from, period_to, currency, vat,
                 issuer_json, customer_json, positions_json, greeting, body, footer,
                 net, vat_amount, gross, issued_on, issued_by, due_on, paid_on, paid_by,
                 reverses, reversed_by, credits, seq, prev_hash, hash,
                 pdf_file_id, xml_file_id, document_state, created_at, modified_at
ts_inv_hours     invoice_id, uid, event_uid, start, hours, rate, amount, code
                 UNIQUE(invoice_id, uid, event_uid, start)
ts_inv_ledger    id, tenant_id, seq, at, actor, action, invoice_id, number, payload_hash, prev_hash, hash
                 UNIQUE(tenant_id, seq)
```

- `payload_hash` = SHA-256 of the canonical JSON of the frozen invoice
  (keys sorted, no whitespace, numbers as sent) at the moment of the
  action; for `paid`/`unpaid` the JSON `{ key, number, paid_on, note }`.
- `hash` = SHA-256 of `seq ‖ "\n" ‖ at ‖ "\n" ‖ actor ‖ "\n" ‖ action ‖ "\n" ‖ number ‖ "\n" ‖ payload_hash ‖ "\n" ‖ prev_hash`;
  `prev_hash` is the previous entry's `hash` of the team, empty for the
  first. `at` is UTC `YYYY-MM-DDTHH:MM:SSZ`. Hex, lower case.
- The ledger has no update and no delete path in the code. A team is
  deleted with its ledger only through the existing team deletion (with
  ZIP first). The team ZIP carries `ledger.json` and `invoices.json`
  (frozen content) for reading; an import never recreates a ledger
  (numbers and chain belong to the server that issued them).
- 0.19.0 adds: the chain line on every document, the daily chain head
  file in Nextcloud Files, documents and their file hashes in the ledger.

### Rights

| | user | lead | admin |
|---|---|---|---|
| issue, reverse, paid, ledger, verify | – | – | yes |
| `fixed` | – | of the projects they lead | yes |

### Capability

```json
"timesister": { …, "invoicing": 1 }
```

### As built, 0.18.0 (2026-10-15)

What the server does beyond or beside the lines above, so the Mac app
can rely on it:

- **The write lock is tied to the server's issue.** `409 issued` (every
  record write, delete, restore and batch – a batch names the write with
  `index`) applies to invoices with a frozen row, i.e. issued through
  `POST /invoices/{key}/issue` (or created by a reversal or credit note).
  A record a client set to `issued` with a number itself (0.16.0, on the
  Mac) stays writable as before, also with the team setting on – nothing
  on the server could reverse it. For a Lead the 403 of 0.16.0 comes
  first. The lock holds also when `invoicing` is switched off later.
- **The record's `amount` may be negative** (a reversal's); `hours` stays
  ≥ 0. New optional record fields written by the server: `due_on` (a day),
  `ledger_seq`, `hash`, `reverses`, `credits`, `reversed_by`, `contact`
  (the draft may name a contact: the `id` of one of the customer's
  `contacts`, or free text; frozen into `customer.contact`).
- **Positions:** `amount` is any number (negative for a discount or a
  credit), `work_package` null or an integer ≥ 0; errors read
  “Position n: …”. `bookings[].hours`/`rate` null or ≥ 0, `code` ≤ 64.
- **The number:** the server fills the team's pattern itself – `{no}`/
  `{nr}` with the width, `{year}`/`{yy}` from `issued_on`, `{project}` and
  `{customer}` with the keys of the project and the customer. Without a
  pattern: `{year}-{no:4}` (`2026-0007`). A number already frozen in the
  team (a changed pattern): `409 conflict`; a result longer than 64
  characters: `422`. The counter is taken inside the transaction: a
  failed issue leaves no gap.
- **What issuing needs** (`422 invalid` naming it): a live project, the
  budget in it, a currency (the budget's, else the draft's), the project's
  customer (`customer`, older `kunde`) as a live record, and the settings'
  `issuer` (“The team has no invoice issuer; set “issuer” in the settings
  first.”). `vat`: the draft's, else `setting/settings.vat` (a
  percentage), else 0.
- **Billed already?** Per occurrence through the invoices' hours (`409`
  “Booking n is billed by invoice RE-2026-0007.”); where no live server
  invoice bills an occurrence of the event, a live `billing` record of the
  person's event counts (set by hand or by a client; `document` or `?`).
  A series has one mark per UID: `document` is the latest invoice's
  number; a reversal deletes the mark only when no other live invoice
  bills an occurrence of the event. Hours and rate missing on a booking
  count 0 (the row is written, `amount` 0).
- **`fixed`** adds `key`, `project`, `budget`, `customer_key`, `period`
  `{from, to}`, `issued_by`, `paid_by`, `hours_total`; `customer` holds
  `id`, `name`, `address_extra`, `street`, `postcode`, `city`, `country`,
  `email`, `phone`, `vat_id` (what the record has) and `contact`. A Lead
  not of the project, or an unknown key for a Lead: `403`.
- **Reversal and credit note:** the new record copies the original's
  (negated `positions`, no `bookings`, `hours` 0, `note` = `reason`,
  `created_by` the caller) with `reverses` or `credits`; the frozen row
  copies issuer, customer, texts (`body` = `reason` when given), period,
  VAT. A reversal appends **two** ledger entries – `reversal` for the new
  invoice (payload: its frozen content) and `cancelled` for the original
  (payload `{ key, number, reversed_by, reason }`); a credit note one
  (`credit`). A credit note leaves `reversed_by` empty, so a credited
  invoice can still be reversed; a cancelled one can be neither. The
  answer: `{ "record", "original", "issue" }`.
- **Paid:** the answer is the record plus
  `"ledger": { seq, prev_hash, hash }`; `unpaid` takes `{ "note"? }`.
  Payload `{ key, number, paid_on, note }` (`paid_on` null on `unpaid`).
- **The payload** (`payload_hash`) of `issued`, `reversal` and `credit` is
  the canonical JSON of
  `{ key, number, issued_on, issued_by, due_on, project, budget, customer_key, period: {from, to}, currency, vat, net, vat_amount, gross, issuer, customer, positions, hours: [ {uid, event_uid, start, hours, rate, amount, code} ], greeting, body, footer, reverses, credits }`
  – everything frozen, nothing that changes later. The ledger stores
  that text: `GET /invoices/ledger` entries carry `payload` (a string),
  and `chain/verify` also checks `payload_hash` against it and that `seq`
  goes on without a gap; a break names the `field` (`seq`, `prev_hash`,
  `payload_hash`, `hash`) with `expected` and `found`.
- **Canonical JSON:** object keys sorted by byte order, recursively;
  lists as they are; no whitespace; `/` and non-ASCII unescaped; numbers
  as given – a float keeps its fraction (`1875.0`), an integer has none
  (`1875`); server sums are floats (`200.0`); an empty object is `{}`.
- **Example values** (`tests/Unit/LedgerRulesTest.php` holds them):
  payload `{"number":"RE-2026-0007","net":1875.0,"positions":[{"kind":"hours","text":"Planung / Phase 1","hours":12.5,"rate":150,"amount":1875}],"customer":{"name":"Müller AG","city":"Basel"},"greeting":null,"key":"inv-1a2b3c4d","empty":{}}`
  → canonical
  `{"customer":{"city":"Basel","name":"Müller AG"},"empty":{},"greeting":null,"key":"inv-1a2b3c4d","net":1875.0,"number":"RE-2026-0007","positions":[{"amount":1875,"hours":12.5,"kind":"hours","rate":150,"text":"Planung / Phase 1"}]}`
  → `payload_hash` `d8858b9612db6eb93674f78402ffc2876879e675bd4a27d1a1dd1006f4640be5`.
  Entry 1: `seq` 1, `at` `2026-10-15T14:30:00Z`, `actor` `pbadmin`,
  `action` `issued`, `number` `RE-2026-0007`, `prev_hash` `""` →
  `hash` `034409014b2fa66604870366eea807323f9ecbfb759f31f8fb05234756674d55`
  (= SHA-256 of `"1\n2026-10-15T14:30:00Z\npbadmin\nissued\nRE-2026-0007\nd8858b…0be5\n"`).
  Entry 2: payload `{"key":"inv-1a2b3c4d","note":null,"number":"RE-2026-0007","paid_on":"2026-10-20"}`
  → `payload_hash` `39e5fa1f3b789d9c5220d4306da211cee579f76d7416eb5710dcdafcce5c7a9a`;
  `seq` 2, `at` `2026-10-15T15:00:00Z`, `pbadmin`, `paid`, `prev_hash` =
  entry 1's hash → `hash` `e837e0f26f98711b04d672ffd9c3adaf07d7390ed297ca50ba600b080615deac`.
- **Reading the ledger:** `since` ≥ 0, `limit` 1–2000, otherwise `422`.
- **Team setting:** `PUT /team/settings` takes `entries_since` and/or
  `invoicing` (bool; anything else `422`); `POST`/`PUT /admin/teams`
  `settings.invoicing` likewise; `settings` in `/me`, `/team` and
  `/admin/teams` carry it. Off: the seven endpoints answer `404 not_found`
  “Invoicing on the server is off for this team.”
- **Stored** (`Version1013Date20261015150000`, additive): amounts as
  `DECIMAL(14,2)`, `vat` `DECIMAL(5,2)`, hours and rates `DECIMAL(10,2)`
  (exact; the API hands floats out); `ts_inv_invoices` also `hours` (the
  total); `ts_inv_hours` also `tenant_id`; `ts_inv_ledger` also `payload`
  (TEXT). `ts_tenants.invoicing` (0/1). The ledger mapper refuses
  `update()` and `delete()`; only deleting a team removes its ledger.
- **Team ZIP:** `ledger.json` `{ "entries": [ … as GET /invoices/ledger ] }`
  and `invoices.json` `{ "invoices": [ … as fixed ] }`; the import reads
  past them. Deleting a team reports `invoices`, `invoice_hours`, `ledger`
  in `counts`.

## Invoice documents, 0.19.0 (2026-10-15) – PDF, QR bill, preview, folder

Second step of `PLAN-FAKTURA.md`: the server renders the documents of an
issued invoice, stores them in Nextcloud Files in a folder the Nextcloud
admin chooses per team, writes their hashes into the ledger and serves a
watermarked preview of a draft. Capability `invoice_documents: 1`.
ZUGFeRD/XRechnung follow in 0.20.0 (F3); 0.19.0 renders the PDF with the
Swiss QR bill when the issuer has a CHF or EUR account with a (QR-)IBAN,
and a plain PDF otherwise.

### Folder per team (Nextcloud admin page)

Team setting `documents` in `settings` of `GET /team`, `GET /admin/teams`
and `/me`:

```json
"documents": { "invoices": "TimeSister Invoices/Planungsbüro", "quotes": "TimeSister Quotes/Planungsbüro", "per_year": true }
```

- `invoices`, `quotes`: a folder path in the files of the team's **backup
  owner** (`backup_owner`; a group folder lies at the root there, e.g.
  `Buchhaltung/Rechnungen`). `null` means the default
  `TimeSister Invoices/<team name>` and `TimeSister Quotes/<team name>`.
  1–255 characters, no `..`, no leading `/`; `422 invalid` otherwise.
- `per_year`: boolean, default `true` – a subfolder `<year>` of the
  issue date (invoices) or the quote date (quotes).
- Set by Nextcloud admins through `POST`/`PUT /admin/teams` (`settings`)
  and by Team Admins through `PUT /team/settings` (`documents`). On
  writing, the server checks that the backup owner is set and that the
  folder exists or can be created and is writable; otherwise `422 invalid`
  “The folder ‘…’ is not writable for the backup owner.”
- The admin page of the app shows the two paths with a check button
  (“Folder found, writable”), the per-year switch, and the backup owner;
  no file picker (the page uses no `OC.*`), the admin types the path as it
  appears in the owner's Files.
- The Mac app shows the setting under Accounting › Settings read-only and
  writes quote PDFs (WebDAV, as today) into `quotes` (+ `<year>` when
  `per_year`), file name `<quote number>.pdf`.

### Rendering

- After `issue` (and `reverse`), the server renders `<number>.pdf` and
  stores it under `invoices[/<year>]/<number>.pdf` in the backup owner's
  files, then appends the ledger action `document` with `payload`
  `{ key, number, kind: "pdf", path, size, sha256 }`; `document_state`
  becomes `done`. On failure: `failed` with the error in
  `document_error`; the job `DocumentRetry` (every 15 minutes) retries
  `pending`/`failed` documents up to 10 times.
- Layout (A4 portrait): issuer block (name, address, VAT id, email, phone),
  customer block, title “Invoice <number>” (German/English by the team's
  language, `settings.language` if present, else the server's default
  language), date, due date, period, project and budget name, `greeting`,
  the positions table (text, hours, rate, amount; flat, discount and text
  lines as sent), net, VAT (rate and amount), gross, `body`, the hours
  appendix (one table: date, person, code, title, hours – no notes;
  Decision 2026-10-14), `footer`, and the **chain line** on every page's
  footer: `Beleg <number> · Kette <seq> <hash[0:12]> ← <prev_hash[0:12]>`.
- **Swiss QR bill** on the last page (Swiss Payment Standards, 210 × 105
  mm at the bottom): when the issuer has an account in the invoice's
  currency (`issuer.accounts[].currency` = `CHF` or `EUR`) and
  `issuer.qr` is not `false`; creditor = issuer, debtor = customer, amount
  = gross, reference: none (QR-IBAN → QRR reference derived from the
  number is a later option). Library `sprain/swiss-qr-bill`.
- PDF library: `tecnickcom/tcpdf` (LGPL) – the vendor directory ships
  with the app; `.nextcloudignore` keeps tests and docs of the libraries
  out of the archive. Fonts: DejaVu (ships with TCPDF).
- Reversal and credit note render like an invoice with the title
  “Reversal of <number>” / “Credit note”.

### Endpoints

| Method | Path | Who | Answer |
|---|---|---|---|
| GET | `/invoices/{key}/preview` | Team Admins, the project's Leads | `application/pdf` of the **draft** as it would be issued, with the watermark **VORABZUG** (German) / **DRAFT** (English) diagonally across every page, light grey, in the PDF itself; number shown as “(not yet issued)”; never stored, never in the ledger. `409 issued` for an issued invoice (use `document`). `422` when the draft could not be issued as it is (same checks as `issue`, except the number). |
| GET | `/invoices/{key}/document?kind=pdf` | Team Admins, the project's Leads | the stored file; `404` while `document_state` is not `done` |
| POST | `/invoices/{key}/render` | Team Admins | render again now (after a failure); answer `{ document_state, document_error }` |
| GET | `/invoices/chain/head` | Team Admins | `{ seq, hash, at, entries }` – the chain head |

`fixed` adds `document_state`, `document_error`, `document_path`,
`document_sha256`.

### Chain head file

Daily job `ChainHead` (TIME_INSENSITIVE): for every team with at least one
ledger entry, writes `invoices/chain-<YYYY-MM-DD>.txt` in the backup
owner's files with `team, date, entries, head seq, head hash` and, when
`settings.documents.chain_mail` (an address, optional) is set, sends the
same text by Nextcloud mail. The file is the external anchor of the
chain; the previous days' files stay.

### Capability

```json
"timesister": { …, "invoice_documents": 1 }
```

### As built, 0.19.0 (2026-10-15)

What the server does beyond or beside the lines above, so the Mac app can
rely on it:

- **Streams:** `preview` answers `200` with `Content-Type: application/pdf`
  and `Content-Disposition: inline; filename="preview-<key>.pdf"`;
  `document` answers `200` with `application/pdf` and
  `Content-Disposition: attachment; filename="<number>.pdf"`. Both are
  plain bodies, not the OCS JSON envelope; errors (403, 404, 409, 422)
  come as the usual OCS JSON. `document` takes `kind=pdf` or no `kind`;
  another kind: `422 invalid` (“Only “pdf” documents exist so far.”).
  `404 not_found` while `document_state` is not `done`, and also when the
  file is gone from the owner's files (“The document is missing in the
  files; render it again.”).
- **`issue` and `reverse` answer before the document exists:** the
  rendering runs right after the transaction, in the same request;
  `issue.document_state` in the answer is still `pending`. Read `fixed`
  afterwards: `document_state` is `done` (or `failed` with
  `document_error`) a moment later. A reversal renders its own document
  (title “Storno zu <original number> – <number>” / “Reversal of …”); the
  original's document stays as issued.
- **`render`** answers `{ document_state, document_error }`; on a `done`
  document whose file is still there it renders nothing and appends no
  second ledger entry; when the file is gone it renders and appends again.
  A draft: `404 not_found`.
- **`chain/head`:** `{ seq, hash, at, entries }`, with `seq` 0 and
  `hash`/`at` null for an empty ledger; `entries` counts the entries.
- **Ledger entry `document`:** `actor` is the issuer (right after
  `issue`/`reverse`), the Team Admin who called `render`, or `system` (the
  retry job); `payload` is the canonical JSON of
  `{ key, number, kind: "pdf", path, size, sha256 }`, `path` relative to
  the backup owner's files (e.g. `TimeSister Invoices/Planungsbüro/2026/RE-2026-0007.pdf`).
  Verify: a stored document's SHA-256 equals `sha256` and `document_sha256`.
- **`fixed`** adds `document_error`, `document_path`, `document_sha256`
  (null until `done`).
- **The document's language:** `setting/settings.language` (`de…` or
  `en…`, case-insensitive) decides, else the server's `default_language`;
  anything else renders English. Since 0.22.0 a write accepts only
  `null`, `de` or `en` (see «Document language» below). Labels, the watermark (VORABZUG/DRAFT),
  dates (`15.10.2026` / `2026-10-15`) and amounts (`1’234.50` / `1,234.50`)
  follow it. The chain line is always `Beleg <number> · Kette <seq> <hash[0:12]> ← <prev_hash[0:12]>`
  (`← –` for the first entry); a preview carries
  `VORABZUG – (noch nicht gestellt)` / `DRAFT – (not yet issued)` instead
  and shows the title “Rechnung (noch nicht gestellt)”.
- **Metadata:** the PDF's Subject is the chain line, Keywords the
  watermark (`TimeSister` when none), Title the document's title – all in
  the uncompressed XMP, so a client can grep them.
- **Layout:** the team's `footer` text sits in the page footer of every
  page (up to three lines, small) above the chain line and “Seite n von N”;
  the hours appendix starts on its own page (date, person's display name,
  code, the booking's title from the book, hours; total). The QR bill is
  its own last page with the heading “Zahlteil – …”; its footer moves above
  the payment part.
- **QR bill only when the standard accepts the data:** issuer with name,
  postcode, city, country and an account in the invoice's currency (`CHF`
  or `EUR`), `issuer.qr` not `false`, gross > 0; the debtor only when the
  customer has name, postcode, city and country. A **QR-IBAN** (IID
  30000–31999) needs a QRR reference, which 0.19.0 does not generate: the
  document is rendered **without** payment part (plain PDF). The issuer's
  `street` is split into street and house number at the last token.
- **Team setting `documents`:** a missing key keeps the stored value;
  `null` or `""` means the default again; a trailing slash is trimmed;
  `chain_mail` must look like an address (≤ 254). On `POST /admin/teams`
  without `backup_owner`, `documents` is refused with `422` (no owner to
  check against yet) – set the owner first, then the folders. The check
  never creates anything: “Folder found, writable.” or “The folder will be
  created: “…”.”; the folders come into being with the first document.
- **Admin check:** `POST /admin/teams/{id}/documents/check` takes
  `{ invoices?, quotes? }` (null: the default) and answers
  `{ invoices: { ok, message }, quotes: { ok, message } }` in the admin's
  language; a malformed path: `422 invalid`.
- **Rights:** `preview` and `document` like `fixed` (Team Admins, the
  project's Leads); `render` and `chain/head` Team Admins. All answer
  `404` when `invoicing` is off.
- **Chain head file:** written once per run of the daily job (a second run
  on the same day overwrites the day's file); `team: <name>`, `date`,
  `entries`, `head seq`, `head hash`, one per line. The mail's subject is
  `TimeSister – chain head <team> <date>`, plain text, through Nextcloud's
  mailer (no mail when the server has none configured; logged).
- **Stored** (`Version1014Date20261015180000`, additive):
  `ts_inv_invoices.document_error` TEXT, `document_path` STRING(512),
  `document_sha256` STRING(64), `document_tries` SMALLINT;
  `ts_tenants.documents_json` TEXT. Nothing in Files is ever deleted by the
  app.

## E-invoices and exports, 0.20.0 (2026-10-15) – ZUGFeRD, XRechnung, QRR, DATEV, webhooks

Third and fourth step of `PLAN-FAKTURA.md`. Capabilities `e_invoice: 1`
and `invoice_export: 1`.

### Tax case

Frozen at issue into `customer_json.tax_case` and used by every document:

| `tax_case` | When | VAT on the document | Code (EN 16931) |
|---|---|---|---|
| `domestic` | issuer and customer in the same country | the invoice's `vat` | `S` (or `Z` when `vat` = 0) |
| `eu_reverse` | both in the EU, different countries, customer has a `vat_id` | 0, note “Reverse charge” | `AE` |
| `export` | customer outside the issuer's country and not EU-reverse | 0, note “Export, not subject to VAT” | `G` (outside EU) / `E` |

Countries: `issuer.country` and `customer.country` (ISO 3166-1 alpha-2);
missing customer country counts as domestic. The draft may override with
`tax_case` (one of the three) for the odd case; `422` otherwise.
`fixed` returns `tax_case`.

### Documents

- **ZUGFeRD**: the stored PDF becomes **PDF/A-3** with the embedded
  `factur-x.xml` (profile EN 16931) through `horstoeko/zugferd`
  (`ZugferdDocumentPdfBuilder` on the TCPDF output). The chain line stays
  in the footer and in the XML as a note (`IncludedNote`, subject code
  `ACY`? – use a plain note “Beleg … Kette …”). Issued invoices get it
  always; the preview stays a plain PDF with the watermark.
- **XRechnung**: `<number>.xml` (CII, profile XRechnung 3.0) stored next
  to the PDF when the customer has `e_invoice: true` or a `leitweg_id`
  (German public customers; `customer_json.leitweg_id` → `BuyerReference`).
  `GET /invoices/{key}/document?kind=xml` serves it; `404` when none.
  Both files get their own ledger `document` entries (`kind: "zugferd"`,
  `kind: "xrechnung"`); `document_state` covers both.
- **Validation**: the integration test runs the KoSIT validator when
  `java` and `TS_KOSIT=<path to validationtool jar + scenarios>` are
  present; otherwise it checks structure (root element, profile id,
  totals) and says it skipped KoSIT. The app itself never calls anything
  outside.
- **QRR reference**: with a QR-IBAN (IID 30000–31999) the QR bill now
  carries a 27-digit QR reference: team setting `issuer.besr_id`
  (6 digits, optional) + the invoice's counter number zero-padded + the
  year, padded to 26 digits, plus the modulo-10 recursive check digit;
  the reference is frozen in `fixed.qr_reference` and printed on the
  bill. Without `besr_id` the invoice counter and year alone form the
  reference. Reversal and credit note: no bill (as before).

### Exports

| Method | Path | Who | Answer |
|---|---|---|---|
| GET | `/invoices/export?kind=csv&from=YYYY-MM-DD&to=YYYY-MM-DD` | Team Admins | `text/csv; charset=utf-8` with BOM, `;` separated: one row per issued/cancelled/paid invoice in the period (by `issued_on`): number, status, issued_on, due_on, paid_on, customer name, customer vat_id, project, budget, currency, net, vat rate, vat amount, gross, tax_case, reverses, document sha256, chain seq, chain hash |
| GET | `/invoices/export?kind=datev&from&to` | Team Admins | DATEV **Buchungsstapel** (EXTF, format version 700, UTF-8 with BOM, `;`): header lines per DATEV spec, then one row per invoice: Umsatz (gross), Soll/Haben (`S` for invoices, `H` for reversals and credits), Konto = debtor account (`customer.datev_account`, else team setting `datev.debtor_account`), Gegenkonto = revenue account by tax case (`datev.revenue_domestic`, `datev.revenue_eu`, `datev.revenue_export`), BU-Schlüssel from the tax case, Belegdatum (DDMM), Belegfeld 1 = number, Buchungstext = customer name + project; `422` when the team's `datev` setting lacks the accounts |
| GET | `/invoices/export?kind=ledger&from&to` | Team Admins | the ledger as CSV (seq, at, actor, action, number, payload_hash, prev_hash, hash) |
| POST | `/invoices/{key}/external` | Team Admins | `{ "system": "bexio"\|"lexware"\|"other", "id": ≤128, "url"?: https }` – records a hand-over to an accounting system: ledger `exported`, `fixed.external` |

Team setting `datev` (`PUT /team/settings`, `/admin/teams`):
`{ "consultant": digits ≤ 7, "client": digits ≤ 5, "fiscal_year_start": "MM-DD", "account_length": 4–8, "debtor_account": digits, "revenue_domestic": digits, "revenue_eu": digits, "revenue_export": digits }`.
Customer record: optional `datev_account` (digits), `e_invoice` (bool),
`leitweg_id` (≤ 46).

### Webhooks

Team setting `webhooks`: a list of at most 10
`{ "url": "https://…", "events": ["issued","paid","reversed","document","exported"], "secret": ≤ 128 }`.
Only `https`. After each ledger entry of a listed event the job
`WebhookDelivery` (every 5 minutes, and at once after the entry when
possible) sends `POST url` with JSON
`{ "team", "event", "at", "invoice": { key, number, status, gross, currency }, "ledger": { seq, hash } }`
and header `X-TimeSister-Signature: sha256=<HMAC-SHA256(secret, body)>`;
retries up to 10 times with growing delay; failures in
`GET /invoices/webhooks/status` (Team Admins). Delivery never blocks
issuing.

### Capabilities

```json
"timesister": { …, "e_invoice": 1, "invoice_export": 1 }
```

### As built, 0.20.0 (2026-10-15)

What the server does beyond or beside the lines above, so the Mac app can
rely on it:

- **Tax case:** `TaxRules::of(issuer.country, customer.country, customer.vat_id, draft.tax_case)`.
  No issuer country, no customer country or the same country: `domestic`.
  Both in the EU, different, and a non-blank `vat_id`: `eu_reverse`. Else
  `export`. The draft's `tax_case` (checked on every record write, `422`
  otherwise) wins. Frozen at issue into `customer_json.tax_case`; the
  frozen `vat` is then **0** for `eu_reverse` and `export` (and so are
  `vat_amount` and the ledger payload's `vat`), the invoice's own rate at
  home. The customer block also freezes `leitweg_id`, `datev_account` and
  `e_invoice` (only when true). `fixed` adds `tax_case`, `qr_reference`,
  `counter_no`, `xml_path`, `xml_sha256`, `external`. The PDF prints the
  rate of the case (0 % abroad) and, for `eu_reverse`/`export`, a note
  under the sums (`Steuerschuldnerschaft des Leistungsempfängers (Reverse
  Charge).` / `Export, nicht der Mehrwertsteuer unterstellt.`; English
  likewise). Category codes: `S` (`Z` at 0 %), `AE` (exemption code
  `VATEX-EU-AE`), `G` outside the EU (`VATEX-EU-G`), `E` for an EU customer
  without reverse charge. Invoices from before 0.20.0 count as `domestic`.
- **ZUGFeRD:** every stored PDF (issue, reversal, credit note, render
  again) is PDF/A-3 with `factur-x.xml` (CII, `urn:cen.eu:en16931:2017`)
  attached through `horstoeko/zugferd` (FPDI rewrites the TCPDF output; the
  XMP keeps the chain line as subject, the title as title, keywords
  `TimeSister, FacturX/ZUGFeRD`). The XML: type `380`, a reversal or credit
  note `384` with the original's number as `InvoiceReferencedDocument` and
  the frozen (negative) amounts; seller with VAT id, contact (name, phone,
  e-mail) and electronic address `EM` when the issuer has an e-mail;
  buyer with `id`, VAT id, e-mail and contact; the billing period; one
  line per position – hours as quantity `HUR` × rate, everything else one
  piece `C62` at the amount, a negative amount as a negative quantity
  (prices are never negative), a text line at 0; one tax breakdown; a
  credit transfer to the issuer's account in the currency (SEPA `58`,
  remittance = QR reference or number), else payment means `30`; the due
  date as the payment term; the chain line as a document note. Totals:
  line total = tax basis = `net`, tax = `vat_amount`, grand = due =
  `gross`. Ledger `document` entries carry `kind: "zugferd"` (0.19.0 wrote
  `pdf`); `document_sha256` is the hash of the final file. The library
  validates nothing at runtime; a failure of the XML (odd data) leaves the
  document `failed` with the error, like a PDF failure. The preview stays
  a plain PDF with the watermark and no XML.
- **XRechnung:** when the frozen customer has `e_invoice: true` or a
  `leitweg_id`, `<number>.xml` (profile
  `urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0`)
  is written beside the PDF in the same folder and gets its own ledger
  `document` entry (`kind: "xrechnung"`, path, size, sha256) in the same
  transaction. `BuyerReference` = `leitweg_id`, else the customer's key.
  `GET /invoices/{key}/document?kind=xml` answers `application/xml` with
  `Content-Disposition: attachment; filename="<number>.xml"`; `404` when
  the customer wants none (“This invoice has no XRechnung file”), while
  the document is not `done`, or when the file is gone. `kind` other than
  `pdf`/`xml`: `422`. The app never calls the KoSIT validator; the
  integration test does when `java` and `TS_KOSIT` are present and says
  so when it skips. Not built: the buyer's electronic address is the
  customer's `email` (XRechnung wants one; without an e-mail the file is
  written anyway and a validator will flag BR-DE-… rules).
- **QR reference:** only with a QR-IBAN (CH/LI, IID 30000–31999) in the
  invoice's currency: `<besr_id or nothing><counter zero-padded><year>`
  on 26 digits – the year is the issue year, the counter is the team's
  invoice counter value taken at issue (`counter_no`, also stored for
  reversals and credit notes) – plus the modulo 10 recursive check digit.
  Example: BESR id `123456`, counter 7, 2026 →
  `12345600000000000000072026` + check digit. `issuer.besr_id` (6 digits)
  is checked when the settings record is written. The reference is in the
  issue payload (`qr_reference`, null without QR-IBAN; reversal and credit
  note: null) and in the XML's remittance information; the bill shows it
  in blocks of five from the right. The payment part follows the standard's
  checks as in 0.19.0 (complete issuer address, positive gross).
- **Exports:** `kind` is required (`422` otherwise); `from`/`to` are
  optional days (missing: unbounded), `422` when malformed. Rows are
  every frozen invoice with `issued_on` in the period (reversals and
  credit notes are rows too, with their own number), by issue date and
  number. Files: `invoices-<from|start>-<to|end>.csv`,
  `datev-…csv`, `ledger-…csv`, `text/csv; charset=utf-8`, UTF-8 BOM, `;`,
  CRLF, fields quoted only when needed, amounts with a point and two
  decimals (`1972.83`), `vat_rate` likewise (`8.10`), `reverses` = the
  number of the reversed invoice. The ledger CSV filters by the entry's
  `at` day. **DATEV:** header `EXTF;700;21;Buchungsstapel;12;<created
  YYYYMMDDHHMMSS000>;;TS;<uid>;;<consultant>;<client>;<fiscal year
  start YYYYMMDD>;<account_length or 4>;<from>;<to>;TimeSister
  Rechnungen;;1;0;0;<currency of the first row>;…` (31 fields; the
  fiscal year start is the one at or before `from`), then the column line
  `Umsatz (ohne Soll/Haben-Kz);Soll/Haben-Kennzeichen;WKZ Umsatz;Kurs;Basis-Umsatz;WKZ Basis-Umsatz;Konto;Gegenkonto (ohne BU-Schlüssel);BU-Schlüssel;Belegdatum;Belegfeld 1;Belegfeld 2;Skonto;Buchungstext`
  (the first 14 fields of the format; DATEV takes the rest as empty), then
  one row per invoice: `|gross|` with a decimal comma, `S` for an invoice
  and `H` for a reversal, a credit note or a negative gross, the
  currency, Konto = `customer.datev_account` (digits) else
  `datev.debtor_account`, Gegenkonto = `revenue_domestic`/`revenue_eu`/
  `revenue_export` by tax case, BU-Schlüssel = `datev.bu_domestic`/
  `bu_eu`/`bu_export` (optional, empty by default – automatic accounts
  take none), Belegdatum `DDMM`, Belegfeld 1 = the number (36 characters
  of `A–Z a–z 0–9 $ & % * + - /`, others become `-`), Buchungstext =
  customer name and project name (60 characters). `422` names the missing
  account. The team setting `datev` is an object that replaces the stored
  one (`null` or a missing key clears a field): `consultant` ≤ 7 digits,
  `client` ≤ 5, `fiscal_year_start` `MM-DD`, `account_length` 4–8,
  `debtor_account`, `revenue_*` ≤ 9 digits, `bu_*` ≤ 4 digits; shown in
  `settings.datev` of `/me`, `/team`, `/admin/teams`, writable by Team
  Admins (`PUT /team/settings`) and Nextcloud admins (`/admin/teams`).
  **External:** `POST /invoices/{key}/external` takes
  `{ system: bexio|lexware|other, id: text ≤ 128 (a number is taken as
  text), url?: https ≤ 512 }`, appends ledger `exported` with payload
  `{ key, number, system, id, url }`, stores
  `external: { system, id, url, at, by }` (the latest hand-over) and
  answers `fixed` plus `ledger: { seq, prev_hash, hash }`; `404` for a
  draft, `403` for a Lead. Every endpoint of this section answers `404`
  when `invoicing` is off.
- **Webhooks:** the setting is a list that replaces the stored one (`null`
  or `[]` clears it); a hook sent without `secret` keeps the stored
  secret of the same address; the API shows hooks as
  `{ url, events, secret_set }` – a secret is never returned. Ledger
  action → event: `issued`, `reversal`, `credit` → `issued`; `cancelled`
  → `reversed`; `paid`, `unpaid` → `paid`; `document` → `document`;
  `exported` → `exported`. The queue row is written inside the ledger's
  transaction (a failure there is logged, never thrown); the body is
  `{ team: <slug>, event, at, invoice: { key, number, status, gross, currency }, ledger: { seq, hash, action } }`
  and is sent as stored. Delivery runs **only in the job**
  `WebhookDelivery` (every 5 minutes, up to 100 rows per run; nothing is
  posted inside the request – a 10-second timeout per hook would hold the
  issue): `POST` with `Content-Type: application/json`, `User-Agent:
  TimeSister` and `X-TimeSister-Signature: sha256=<hex HMAC-SHA256>` when
  the hook has a secret (no header without one); 2xx = delivered; otherwise
  `tries` + 1, `last_error` (`HTTP <status>` or the client's message) and
  the next try after 1, 2, 4, 8 … minutes (at most a day), ten tries in
  all; a hook removed from the setting meanwhile fails at once. Nextcloud's
  HTTP client applies (no local addresses, proxy settings).
  `GET /invoices/webhooks/status` answers
  `{ hooks: [ { url, events, secret_set, pending, delivered, failed, last_error, last_delivered_at, last_at } ] }`
  over the last 2000 queue rows of the team (also for addresses no longer
  configured). Deleting a team removes its queue (`counts.webhooks`).
- **Stored** (`Version1015Date20261015210000`, additive):
  `ts_inv_invoices` + `counter_no` INT, `qr_reference` STRING(27),
  `xml_path` STRING(512), `xml_sha256` STRING(64), `external_json` TEXT;
  `ts_tenants` + `datev_json`, `webhooks_json` TEXT; new
  `ts_inv_webhook_queue` (tenant_id, ledger_seq, url, event, body, tries,
  next_at, last_error, delivered_at, created_at). `xml_file_id` (0.18.0)
  is filled now.
- **Not built / open:** no inline delivery of webhooks; the PDF/A-3 file
  embeds the DejaVu subset but the QR bill's labels use TCPDF's core font
  Helvetica (not embedded) – strict PDF/A validators flag that; several
  VAT rates per invoice stay one rate; KoSIT not run in this repository's
  CI.

## Cost positions and VAT per line, 0.21.0 (2026-10-16) – wave 1 of PLAN-BELEGE

Steps B1 and B2 of `PLAN-BELEGE.md`. Capabilities `vat_lines: 1` and
`costs: 1`. Receipts (B3), invoicing from receipts (B4) and expense
reports (B4a) follow in 0.22.0 (section below).

### B1 – VAT and mode per invoice line

Every entry of `positions[]` (draft record `invoice`) may carry:

| Field | Rule |
|---|---|
| `vat` | `null` or a percentage 0–100: the VAT rate of this line; `null` = the invoice's `vat` (as before) |
| `vat_mode` | `"taxable"` (default) or `"pass_through"` – a pass-through item (*durchlaufender Posten*): billed in the customer's name and for the customer's account, outside the taxable amount, no VAT, amount as gross |
| `cost` | optional integer ≥ 1: the number of the budget's cost position (B2) the line belongs to |

New position kinds (besides `hours`, `flat`, `discount`, `text`):
`expense_percent` (an expense position computed as a percentage of the
hour positions of the same invoice; `rate` = the percentage, `amount` =
the client's result), `expense_flat` (a flat expense), `costs` (cost
lines; in 0.22.0 also from receipts).

Issuing computes, all rounded to 2 decimals:

```text
taxable lines grouped by their effective rate r:
  net_r   = Σ amount of the taxable lines with rate r   (tax_case abroad: all r = 0)
  vat_r   = round(net_r × r / 100, 2)
net         = Σ net_r
vat_amount  = Σ vat_r
gross       = net + vat_amount
pass_through = Σ amount of the pass-through lines
total       = gross + pass_through          (the amount to pay; the QR bill uses it)
```

Frozen and returned by `fixed` and the `issue` answer: `vat_lines:
[{ rate, net, vat }]` (sorted by rate), `pass_through`, `total`. `net`,
`vat_amount`, `gross` keep their meaning (taxable part only); `vat` (the
invoice's single rate) stays for old readers and is the rate of the
largest `net_r`. The record field `amount` becomes `total`. The ledger
payload carries `vat_lines`, `pass_through` and `total`.

Documents: the PDF sums show one VAT line per rate, then gross, then a
block «Pass-through items (no VAT)» with its lines and sum, then «Total».
ZUGFeRD/XRechnung: one `ApplicableTradeTax` per rate and category; the
pass-through items as category `O` is not allowed together with others
(BR-O-11) – carry them as category `E` with exemption reason «Durchlaufender
Posten / disbursement» and code `VATEX-EU-79-C` (EN 16931 allows this);
document the choice in «As built».

### B2 – cost positions in a budget (module «costs»)

Team setting `modules.costs` (bool, default `false`), like `jobs`,
`budgets`, `customers`. Off: nothing below is shown by clients; the data
stays.

Each budget of a project record may carry `costs[]`, separate from
`work_packages[]`:

| Field | Rule |
|---|---|
| `no` | integer ≥ 1, unique within the budget |
| `name` | 1–120 characters |
| `kind` | `"expense"` (Nebenkosten) or `"third_party"` (Drittkosten, Umsetzung) |
| `rule` | `expense` only: `"percent"`, `"flat"` or `"receipts"`; `third_party`: always `"receipts"` (missing = receipts) |
| `percent` | `rule: percent`: 0–100 |
| `amount` | `rule: flat`: ≥ 0; with `per`: `"invoice"` or `"budget"` |
| `cap` | ≥ 0, the cost cap in the budget's currency; required for `receipts` |
| `mode` | `"rebill"` (default; taxable, the invoice's rate or `vat`) or `"pass_through"` |
| `markup` | `rebill` only: 0–100 percent |
| `vat` | `rebill` only: `null` or 0–100 – a rate of its own for this position |
| `description` | `null` or up to 300 characters |

`422 invalid` naming the field otherwise. Consumption of a cost position
is computed by the client (0.21.0: from the issued invoices' lines with
that `cost`; 0.22.0 adds receipts). The server stores and checks only.

### As built, 0.21.0 (2026-10-16)

What the server does beyond or beside the lines above, so the Mac app can
rely on it:

- **One place for the sums:** `VatLines` (pure) computes them for
  issuing, the preview, reversal and credit note, `fixed`, the PDF, the
  e-invoice, the exports and the webhook body. Amounts are counted in
  cents: `net_r` = Σ amount of the taxable lines of rate r, rounded to
  cents once per rate (not per line); `vat_r` = net_r × r rounded **half
  away from zero**, computed exactly in integers (cents × rate in
  hundredths, /10 000) – e.g. 1825.00 at 8.1 → 147.83, 12.50 at 2.6 →
  0.33, −12.50 at 2.6 → −0.33; a rate counts with two decimals. Text
  lines (`kind: "text"`) and lines of amount 0 open no rate of their own.
  `vat_lines` is sorted by rate (ascending); a rate 0 at home is a line of
  its own. Without taxable lines `vat_lines` is `[]` and `net`,
  `vat_amount`, `gross` are 0.
- **The effective rate of a line:** abroad (`tax_case` `eu_reverse` or
  `export`) 0; else the line's `vat`; else the draft's `vat`, else
  `settings.vat`, else 0. A pass-through line's `vat` is ignored.
- **The frozen positions carry their rate and mode:** at issue every
  position gets `vat_mode` (`taxable` or `pass_through`) and a taxable
  line its effective `vat` (0 abroad; also on text lines), a pass-through
  line `vat: null`. `fixed.positions` and the ledger payload show them so;
  the invoice record keeps the positions as the client sent them. A
  reversal negates the frozen lines and keeps rate and mode; a credit
  note's own `positions` (body) take the original's frozen `vat` where
  they name no rate, and the original's tax case.
- **`vat`** (the single rate, frozen and in the record) is the rate of the
  largest |net_r|; on a tie the higher rate; without taxable lines the
  invoice's rate (0 abroad).
- **`issue` answer, `fixed`, ledger payload:** `vat_lines: [{rate, net, vat}]`,
  `pass_through`, `total` besides `net`, `vat_amount`, `gross` (floats).
  The payload of `issued`, `reversal` and `credit` adds the three keys
  (canonical JSON as before). Invoices issued before 0.21.0 answer
  `vat_lines: [{rate: vat, net, vat: vat_amount}]` (or `[]` when net and
  VAT are 0), `pass_through: 0`, `total: gross`; their stored payloads
  and hashes are unchanged.
- **Record `amount`** = `total` (before: `net`), also on a reversal or
  credit note (negative).
- **Positions:** `vat` null or 0–100 (“vat” must be null or a percentage
  from 0 to 100.), `vat_mode` null, `taxable` or `pass_through`, `cost`
  null or an integer ≥ 1, `rate` of `expense_percent` null or 0–100; the
  kinds `expense_percent`, `expense_flat`, `costs` otherwise behave like
  `flat` (the client's `amount` is taken as sent; the server does not
  recompute the percentage). Errors read “Position n: …”.
- **PDF:** the positions table holds the taxable lines; with more than
  one rate each line names its rate (“Druckkosten (MWST 2.6 %)”). Sums:
  Netto, one line per rate (“MWST 8.1 %”, with several rates “MWST 8.1 %
  auf 1’500.00”), then “Total CHF” – or, with pass-through items, “Total
  inkl. MWST CHF”, a block “Durchlaufende Posten (ohne MWST)” with its
  lines and “Summe durchlaufende Posten”, and “Total CHF” = `total`.
  English: “VAT 8.1 % on …”, “Total incl. VAT”, “Pass-through items (no
  VAT)”, “Pass-through items”. Only pass-through items: no taxable block.
  The QR bill's amount is `total` (drawn when `total` > 0).
- **ZUGFeRD / XRechnung – the category of pass-through items: `E`** with
  exemption reason code `VATEX-EU-79-C` and the text “Durchlaufender
  Posten / disbursement”. `O` (not subject to VAT) is refused by BR-O-11
  together with any other category, which a disbursement next to taxable
  work always has. Checked with the library's XSD validator and the KoSIT
  validator 1.5.0 (configuration XRechnung 3.0.2, EN 16931 CII schematron
  1.3.13): domestic with 19 %, 7 %, a text line, a discount and a
  pass-through item, 0 % (Z), reverse charge (AE + E), export outside the
  EU (G + E), export to an EU customer (E), and a reversal – all accepted,
  for EN 16931 and XRechnung; the same file with `O` is rejected
  (BR-O-02, -05, -11, -12). Each line carries its own category and rate
  (`S`/`Z`/`AE`/`G`/`E`, pass-through `E` 0 %); a line of amount 0 at a
  rate no group has takes the first group's. One tax group per category
  and rate; BR-E-01 allows one `E` group only, so an export to an EU
  customer (`E`) and pass-through items share it (basis summed, both
  reasons as text, no reason code). Totals: line total = tax basis =
  `net + pass_through`, tax = `vat_amount`, grand total = due = `total`.
- **CSV export:** four columns appended: `vat_lines` (`2.6:200.00;8.1:1500.00`
  – rate and net per rate, ascending, quoted because of the `;`),
  `vat_by_rate` (rate and VAT, same form), `pass_through`, `total`.
- **DATEV export:** one booking per rate (Umsatz = |net_r + vat_r|,
  Gegenkonto by tax case, BU key as before) and one for the pass-through
  items (Umsatz = |pass_through|, Gegenkonto = the new optional setting
  `datev.revenue_pass_through`, ≤ 9 digits, no BU key); `S`, or `H` for a
  reversal, a credit note or a negative amount. A period with
  pass-through items and no `revenue_pass_through`: `422` naming it. An
  invoice without amounts: one row of its gross, as before. All rates of
  one invoice book to the same revenue account and BU key of the tax case.
- **Webhook body:** `invoice` also carries `total`.
- **Team setting `modules`** stays in `setting/settings` (written by the
  clients, as for jobs, budgets and customers). Since 0.21.0 the server
  checks it when present: an object; `jobs`, `budgets`, `customers`,
  `kunden`, `costs` each null or a boolean (“modules.costs” must be true
  or false.); other keys pass.
- **`costs[]`** (project and quote budgets, `CostRules`): at most 200; the
  errors read “Cost position n: …” naming the field. `rule` is required
  for `expense`; `third_party` without `rule` is stored with `"receipts"`
  (any other rule: 422); `mode` missing is stored as `"rebill"`.
  `percent` is required with `rule: percent` (0–100); `amount` (≥ 0) and
  `per` (`invoice`/`budget`) are required with `rule: flat`; `cap` (≥ 0)
  is required with `rule: receipts` (so for every `third_party`);
  `markup` and `vat` (0–100) only with `mode: rebill` – on a
  pass-through position a non-null value is `422` (“markup” applies only
  when “mode” is “rebill”.). `percent`, `amount`, `per`, `cap` on other
  rules are checked for range when given and kept.
- **Stored** (`Version1016Date20261016120000`, additive):
  `ts_inv_invoices` + `vat_lines_json` TEXT, `pass_through`
  DECIMAL(14,2), `total` DECIMAL(14,2), null for invoices issued before.

## Receipts, expense reports and the document tree, 0.22.0 (2026-10-16) – wave 2 of PLAN-BELEGE

Steps B3, B4 and B4a of `PLAN-BELEGE.md` and its section «Ablage in
Nextcloud». Capabilities `receipts: 1`, `expense_reports: 1`,
`document_tree: 1`. Everything below is shown by clients only with
`modules.costs`; the server stores and checks regardless. Recognition
(B5) stays on the Mac.

### The document tree (replaces the folders of 0.19.0)

Team setting `documents` becomes:

```json
"documents": { "root": "TimeSister Example Team", "chain_mail": null }
```

- `root`: a folder path in the files of the team's **backup owner** (a
  group folder lies at the root there). `null` = `TimeSister/<team name>`.
  1–255 characters, no `..`, no leading `/`; written and checked as in
  0.19.0 (Nextcloud admin page and `PUT /team/settings`). `invoices`,
  `quotes` and `per_year` are gone (development, no transition): the
  server ignores them on write and never returns them. Documents already
  stored keep their path in their record.
- The server alone writes into the tree, always as the backup owner,
  through the normal file functions (`IRootFolder`, no Team folders API).
  Fixed scheme:

```
<root>/
  Buchhaltung/<YYYY>/Offerten/    <date>_<number>_<customer>.pdf
  Buchhaltung/<YYYY>/Rechnungen/  <date>_<number>_<customer>.pdf | .xml
  Buchhaltung/<YYYY>/Belege/      <date>_<supplier>_<gross>-<CUR>[_<project code>-K<cost>].<ext>
  Buchhaltung/<YYYY>/Spesen/<person>/  <YYYY-MM>_Spesenblatt.pdf and the person's reimbursed receipts (same name as above)
  Buchhaltung/<YYYY>/Kette/       chain-<YYYY-MM-DD>.txt
  Projekte/<project code> <project name>/  Projektakte-<YYYY-MM-DD>.zip
```

- `<YYYY>`: year of the document date (issue date, quote date, receipt
  date, end of the report period, chain date). Name parts: umlauts
  transliterated (ä→ae, ß→ss …), everything but `A–Z a–z 0–9 . -` becomes
  `-`, runs collapsed, each part at most 40 characters; amounts with two
  decimals and a dot. A name taken in the folder gets `_2`, `_3` …
  `<person>` is the display name made safe the same way.
- One class decides every path and name (`DocumentTree`, pure, unit
  tested); `DocumentFolder` writes.

### Quote documents

| Method | Path | Who | |
|---|---|---|---|
| PUT | `/quotes/{key}/document` | Team Admins, the quote's Lead | body: the PDF (`application/pdf`, ≤ 20 MB). The server stores it under `Offerten/`, replaces an earlier file of this quote, and sets `document: { path, sha256, size }` in the record. Answer: that object. |
| GET | `/quotes/{key}/document` | who reads the quote | the file; `404` without one |

The Mac app no longer writes quote PDFs over WebDAV.

### Receipts (record `receipt`, B3)

Key from the client (`rc-…`). Data:

| Field | Rule |
|---|---|
| `schema` | 1 |
| `kind` | `"receipt"` (with a file), `"trust"` (no file, approved one by one), `"recurring"` (from an `expense_rule`) |
| `date` | `YYYY-MM-DD` |
| `supplier`, `text` | 0–120 / 0–300 characters |
| `net`, `vat_rate`, `vat`, `gross` | numbers; `vat_rate` `null` or 0–100; must hold `net + vat = gross` within 0.01, `vat = round(net × vat_rate / 100, 2)` within 0.01 when `vat_rate` is set |
| `currency` | ISO code, default the team's |
| `project`, `budget`, `cost` | all `null` (general cost) or project key + budget id + cost position no of that budget (must exist) |
| `paid_by` | `"person"`, `"company"` or `"customer"` |
| `person` | uid, required with `paid_by: person`, else `null` |
| `reimburse` | bool; only `true` with `paid_by: person` |
| `invoice_effect` | `"charge"`, `"show_only"` or `"none"`; without project always `none`. Defaults when missing: `customer` → `show_only`, else `charge` |
| `note` | 0–300 characters (shown on the invoice line for `show_only`) |
| `rule`, `period` | `recurring` only: the rule key and `YYYY-MM` / `YYYY-Qn` / `YYYY` |

**Owned by the server** (ignored on write, the stored value is kept and
returned): `status`, `file`, `invoice`, `report`, `created_by`,
`created_on`, `approved_by`, `approved_on`, `reject_reason`.

- `status`: `open` → `approved` | `rejected` | `internal`; `approved` →
  `billed` (the server, at issue; back to `approved` on reversal).
  General receipts (no project) are approved to `internal`. A `recurring`
  receipt of an approved rule is `approved` at once.
- **Rights:** Team Admins everything. A Lead reads and approves the
  receipts of the projects they lead and their own. A member reads and
  writes only receipts they created or where `person` is them. Writes
  after `approved` only by Lead/Team Admin and only to `project`,
  `budget`, `cost`, `invoice_effect`, `note`; after `billed` only `note`.
  Deleting only while `open` or `rejected` (and the file goes to the
  backup owner's trash).
- Unique: one live `recurring` receipt per (`rule`, `period`) → `409
  duplicate`; the period must lie within the rule's `from`–`to`.

| Method | Path | Who | |
|---|---|---|---|
| PUT | `/receipts/{key}/file` | who may write the receipt, while `open` | body: PDF, JPEG or PNG, ≤ 20 MB. Stored under `Belege/` – or `Spesen/<person>/` when `reimburse` – named from the record; replaces an earlier file. Sets `file: { path, sha256, size, mime }`. |
| GET | `/receipts/{key}/file` | who reads the receipt | the file |
| POST | `/receipts/{key}/approve` | Lead of the project, Team Admins (general and trust: Team Admins) | renames the file once to the current name (project and cost in it), then never moves it again; status `approved` / `internal` |
| POST | `/receipts/{key}/reject` | same | body `{ reason }` (1–300 characters); status `rejected` |

### Expense rules (record `expense_rule`, B4a)

`{ schema: 1, person, text, amount, vat_rate|null, period: "monthly"|"quarterly"|"yearly", from: "YYYY-MM-DD", to: null|"YYYY-MM-DD", project|null, budget|null, cost|null }`;
server-owned `approved_by`, `approved_on`. Written by the person
(member: only for themselves) and Team Admins; `POST
/expense-rules/{key}/approve` by Team Admins (with project: also its
Lead). Changing `amount`, `vat_rate` or `period` after approval clears
the approval. Ending = setting `to`. The **client** creates the
`recurring` receipt of each period that has begun (locally as in a team);
the server only checks.

### Expense reports (record `expense_report`, B4a)

`{ schema: 1, person, from, to, receipts: [keys] }`; server-owned
`status` (`submitted` → `approved` → `paid`), `sums: { net, vat_lines,
gross }` (computed with `VatLines` from the receipts, `vat_rate null`
counts as rate 0), `submitted_on`, `approved_by`, `approved_on`,
`paid_on`, `document`.

- Written by the person (or a Team Admin for them) = **submit**: every
  receipt must be the person's, `reimburse: true`, `approved` or `billed`,
  dated within `from`–`to`, and in no other live report (`409`). The
  server sets `report` on each receipt; deleting the report (only while
  `submitted`) clears it.
- `POST /expense-reports/{key}/approve` (Team Admins; a Lead when every
  receipt belongs to a project they lead): renders the PDF and stores it
  in `Spesen/<person>/`. `POST /expense-reports/{key}/paid` `{ date }`
  (Team Admins). `GET /expense-reports/{key}/document` (who reads it).
- PDF (A4 portrait, like the invoice): head with team, person, period;
  table date, supplier, text, project, net, VAT, gross, with «without
  receipt» for `trust` and `recurring`; sums per rate; signature lines
  person and approval (name and date of `approved_by`); then the receipt
  copies. No ledger entry.
- Rights: the person reads their own; Team Admins all; a Lead those they
  may approve.

### Invoicing from receipts (B4)

Positions of an invoice draft may carry `receipts: [keys]` on kind
`costs`, and two more kinds:

| Kind | Rule |
|---|---|
| `costs` with `receipts[]` | one line per receipt or per cost position; `cost` required; the receipts must be `approved`, of this invoice's project and budget, of that `cost`, `invoice_effect` ≠ `none`, and in no other live invoice (`409`). `show_only` receipts are lines with `amount: 0`, `vat: 0` and their `note` |
| `markup` | `cost` and `rate` (percent); `amount` = round(Σ amounts of this invoice's `charge` receipt lines of that `cost` × rate / 100, 2), checked within 0.01 |
| `expense_percent` (0.21.0) | now checked: `amount` = round(Σ `hours` amounts × rate / 100, 2) within 0.01 |

On issue the server sets `status: billed` and `invoice: <key>` on every
receipt named, in the same transaction; reversal sets them back. The PDF
(and the PDF/A of ZUGFeRD) gets the **receipt copies** after the hours
appendix: PDF pages imported (FPDI), images fitted to A4, a small head
«Receipt <date> <supplier>» and the chain line on every page. The preview
shows them too. `fixed` positions keep `receipts[]`.

### Project dossier

`POST /projects/{key}/dossier` (Team Admins, the project's Lead): the
server writes `Projekte/<code> <name>/Projektakte-<date>.zip` with the
project's quote and invoice documents, the receipt files, `hours.csv`
(the project's entries from the book) and `ledger.txt` (its ledger
lines), and answers `{ path, size, sha256 }`. A second call the same day
replaces the file.

### Exports

`GET /exports/expenses?from&to&kind=csv|datev` (Team Admins; `kind` since «Member triage» below): one row
per receipt in approved or paid reports (person, report, date, supplier,
text, project, net, VAT rate, VAT, gross, without receipt). DATEV: credit
account = the person's `creditor_account` (new optional field of the
person record, 1–9 digits), debit account = `datev.expense_account`
(new setting); `422` when missing.

### Privacy

The privacy notice names the module costs (cost positions, receipts with
files, expense reports and rules, who sees them) when `modules.costs` is
on.

### Mac app, locally (no Nextcloud)

Same records in the local store (tables `beleg`, `spesenregel`,
`spesenblatt`), files in the app's own folder, same rules and sums
(`Summen`), invoice and expense report as Word; no tree, no dossier.

### As built, 0.22.0 (2026-10-16)

What the server does beyond or beside the lines above, so the Mac app can
rely on it. No schema change: receipts, rules and reports are records,
their files lie in the tree; `occ upgrade` runs no migration.

**Records.** The kinds `receipt`, `expense_rule`, `expense_report` go
through `/records` like the others (single, batch – a member's batch may
hold them –, list, delta, history, restore). A tombstone keeps its last
data, so who may read it still sees the deletion. Keys: a receipt
`rc-` plus 1–125 of `A–Z a–z 0–9 . _ + @ -` (“The key of a receipt is
“rc-” and up to 125 characters.”); rules and reports any record key.
The server's fields are overwritten from the stored record on every
client write (also on restore) and set on a new one; the answer of the
write shows them.

**Receipt – content and defaults** (422 `invalid`):
- `schema` null or 1–1000; `kind` one of the three; `date` a day;
  `supplier` ≤ 120, `text` ≤ 300, `note` ≤ 300 (null allowed).
- `net`, `vat`, `gross` numbers (“net” must be a number.); `net + vat =
  gross` and, with `vat_rate`, `vat = round(net × rate / 100, 2)`, each
  within 0.01.
- `currency` three letters (upper-cased); missing: the budget's currency,
  else `settings.currency`, else `CHF` – stored in the record.
- `project`, `budget`, `cost` all null or all set; the project must exist
  and its budget carry that cost position (checked on every write that
  sends them: “The budget of the project has no such cost position.”).
- `person` required with `paid_by: person` and an account of the team
  (also one who left): ““atuser1” is not an account of the team.”;
  otherwise it must be null and is stored as null.
- `reimburse` missing = `false`; `true` only with `paid_by: person`.
- `invoice_effect` missing: no project → `none`, `customer` → `show_only`,
  else `charge`; without a project anything but `none` is 422.
- `rule`/`period` only on `recurring`, there both (period `YYYY-MM`,
  `YYYY-Qn` or `YYYY`).

**Receipt – status and writes.**
- New: `status: open`, `created_by` = caller, `created_on` = today (UTC),
  `file`, `invoice`, `report`, `approved_*`, `reject_reason` null.
- A client write to a **rejected** receipt opens it again (`status: open`,
  `reject_reason: null`) – addition to the contract, so a member can fix
  and resubmit.
- After approval (`approved`, `internal`, `billed`): only Team Admins and a
  Lead of the project before **and** after the write (a general receipt:
  only Team Admins), else 403 “After approval only Team Admins and the
  project’s Leads change a receipt.”; other fields than `project`,
  `budget`, `cost`, `invoice_effect`, `note` (billed: only `note`) → 409
  `conflict` naming the field (“An approved receipt changes only in
  project, budget, cost, invoice effect and note; “net” stays.” / “A billed
  receipt changes only in “note”; “cost” stays.”). Moving an approved
  receipt to no project makes it `internal`, back to a project `approved`.
- Before approval a member writes what they created or paid (also
  `person` set to someone else of the team); a Lead also the receipts of
  the projects they lead, before and after.
- Delete: 409 “An approved receipt is not deleted.” or “A receipt is
  deleted only while open or rejected.”; the file goes to the backup
  owner's trash after the commit.
- **Recurring:** on create (or a new `rule`/`period`) the rule must exist
  (422), be approved (422 “The expense rule is not approved yet.”), have
  the receipt's `person` with `paid_by: person`; the period must have the
  rule's form (monthly `YYYY-MM`, quarterly `YYYY-Qn`, yearly `YYYY`) and
  **overlap** `from`–`to` (the month a rule starts in counts). One live
  receipt per rule and period, also within one batch: 409 `duplicate`
  “The receipt rc-… is already the one of this rule for 2026-02.” Status
  `approved` (with project) or `internal` (general) at once, `approved_by`
  = the writer, `approved_on` = today. The amount is not compared with
  the rule's.

**Receipt file** `PUT /receipts/{key}/file`: the type is taken from the
content (`%PDF-`, JPEG, PNG; the Content-Type header is ignored), else 422
“The file must be a PDF, JPEG or PNG.”; > 20 MB 413 “A file may be at
most 20 MB.”; only kind `receipt` (422); only while `open` (409; a member
after approval gets 403 first). Answer `{ path, sha256, size, mime,
version }` – `version` is the record's new version. Folder `Belege/` of
the receipt date's year, or `Spesen/<person display name, made safe>/`
when `reimburse`; extension `pdf`/`jpg`/`png`. A file of the same name is
overwritten; a different name (e.g. after a change of supplier) is written
anew and the old file goes to the trash; a name taken by another receipt
gets `_2`. `GET` answers the file with its `mime`; 404 “This receipt has
no file.”

**Approve / reject.** Only from `open` (409 “Only an open receipt is
approved or rejected.”). Lead of the project or Team Admins; `trust` and
general receipts Team Admins only (403). Kind `receipt` without file:
422 “The receipt has no file yet.” Approving moves the file once to its
current name and folder (project code = the project's first code, else
its key: `…_D196-K2.pdf`); if the record write then fails, the file
moves back. Sets `approved_by`, `approved_on`, `reject_reason: null`,
`status` `approved`/`internal`. Reject body `{ reason }` 1–300 characters
(422), sets `status: rejected`, `reject_reason`. Both answer the record.

**Expense rules.** Content: `person` (required), `text` 1–300, `amount` ≥ 0,
`vat_rate` null/0–100, `period`, `from`, `to` null or ≥ `from`, `project`/
`budget`/`cost` as on a receipt (checked to exist). The person writes
their own, Team Admins any (403 otherwise). A change of `amount`,
`vat_rate` or `period` clears `approved_by`/`approved_on`; a client
cannot set them. Delete only while not approved (409 “An approved
expense rule is not deleted; end it by setting “to”.”). `POST
/expense-rules/{key}/approve` (Team Admins; with a project its Leads)
answers the record; read: the person, Team Admins, the project's Leads.

**Expense reports.**
- `receipts` 1–500 distinct receipt keys. Eligible: the report's
  person, `reimburse: true`, status `approved`, **`internal`** or `billed`,
  dated within `from`–`to`. `internal` is an addition: general reimbursed
  receipts (phone, home office) are approved to `internal` and would
  otherwise never reach a report.
- In another live report (or another report of the same batch): 409
  “The receipt rc-… is in the expense report sb-….”
- `sums: { currency, net, vat_lines, gross }` – `currency` added; all
  receipts in one currency, else 422.
- Submitting, changing (while `submitted`) and deleting (while
  `submitted`) set or clear `report` on the receipts as the server's own
  writes in the same transaction (new version and revision of each
  receipt; they are not in the write's answer, they come with the next
  delta). After approval: 409 “An approved expense report does not
  change.”
- Approve: Team Admins, or a Lead when every receipt has a project they
  lead (403). The PDF goes to `Spesen/<person>/<YYYY-MM of to>_Spesenblatt.pdf`
  in the year of `to` (a taken name: `_2`); `document: { path, sha256,
  size }`. Paid: Team Admins, body `{ date }` optional (default today),
  only from `approved` (409). Document: 404 “This expense report has no
  document yet; it is written on approval.”
- PDF: A4 portrait, team, person, period; rows date, supplier, text
  (+ «ohne Beleg»/«without receipt» for trust and recurring), project code,
  net, VAT (with rate), gross; Netto, one line per rate, Total; signature
  lines “Unterschrift Person: <name>” and “Freigabe: <name>, <date>”; then
  the copies. Language as the invoice (team setting, else server).

**Invoicing from receipts.**
- Draft write: `receipts` only on kind `costs` (422 “Position n: Only a
  “costs” line names receipts.”), a list of 1–500 receipt keys; new kind
  `markup` (`rate` null or 0–100 on the draft).
- Preview and issue check (422 unless said): a line with receipts needs
  `cost`; each receipt exists, is `approved`, of the invoice's project,
  budget and that `cost`, `invoice_effect` ≠ `none`, once per invoice;
  `billed` by another invoice → 409 “Position n: The receipt rc-… is
  billed by invoice RE-….”. Lines of `show_only` receipts hold only such
  receipts and amount 0 (422 otherwise). `markup` needs `cost` and `rate`;
  its base is Σ `amount` of this invoice's lines with `charge` receipts of
  that `cost` (as sent). `expense_percent` now needs `rate` (422
  “Position n: “rate” must be a percentage from 0 to 100.”). Both:
  `amount` = round(base × rate / 100, 2) within 0.01, else “Position n:
  The amount must be 10.00.”
- Frozen: a `show_only` line gets `amount: 0`, `vat: 0` (pass-through:
  `vat: null` as every pass-through line) and the receipt's `note`
  appended to its text (“Druck – paid by the customer”) unless already in
  it. `receipts[]` stays on the frozen positions.
- Issue sets `status: billed`, `invoice: <key>` on every receipt named
  (also `show_only`), reversal sets those billed by the original back to
  `approved`, `invoice: null`; a credit note changes none. Server writes in
  the issue transaction (new versions).
- Copies after the hours appendix and before the QR bill, in the stored
  PDF/A-3 (ZUGFeRD) and the preview: head «Beleg <date> <supplier>»
  («Receipt …» in English), `(1/2)` per page of a multi-page PDF, at most
  20 pages per receipt, the chain line in the footer. Receipts without a
  file (trust, recurring) have none. **Limit:** the free FPDI parser
  cannot read PDFs with a compressed cross-reference (object streams,
  common in PDF 1.5+ from scanners and Preview); such a receipt gets a
  page naming its path and SHA-256 instead. The PDF/A conformance of
  imported pages is not checked.

**Document tree.**
- `documents` = `{ root, chain_mail }` in `/me`, `/team`,
  `/admin/teams`; `invoices`, `quotes`, `per_year` are dropped silently on
  write (other unknown keys stay 422). Stored paths of earlier documents
  are kept in their rows and records.
- `POST /admin/teams/{id}/documents/check` takes `{ root }` and answers
  `{ root: { ok, message, path } }` (`path` = the resolved root,
  `TimeSister/<team>` without one). The admin page has one field «Root
  folder» with the check button.
- Names: parts transliterated, `[^A-Za-z0-9.-]` → `-`, runs collapsed, no
  `-` at the ends, ≤ 40 characters; **empty parts are left out**
  (`2026-10-16_RE-1.pdf` without customer). Invoices
  `<issued_on>_<number>_<customer name>.pdf|.xml` (the XRechnung file
  beside it), the chain head `Buchhaltung/<year of the day>/Kette/chain-<day>.txt`,
  projects `Projekte/<first code> <name>/` (each part made safe, joined by
  a space). An invoice's document keeps its name when rendered again.

**Quote documents.** `PUT`/`GET /quotes/{key}/document`: Team Admins and
the Leads who read the quote (403 “Only Team Admins and the quote’s Leads
read and write its document.”); PDF only (422 “The document must be a
PDF.”), ≤ 20 MB (413). Name `<date>_<number>_<customer name>.pdf` with
`date` = the quote's `date`, else `created_on`, else today; `number`,
else the key; the customer's name, else its key. Answer `{ path, sha256,
size, version }`. `document` is the server's: a client write keeps the
stored value (sent or not) and cannot set one.

**Project dossier.** `POST /projects/{key}/dossier`: Team Admins and the
project's Leads (403), 404 without the project. ZIP entries:
`Offerten/<file>` (live quotes of the project with a document),
`Rechnungen/<file>` (PDF and XML of every rendered invoice of the
project, reversals and credit notes included), `Belege/<file>` (live
receipts of the project with a file; a repeated name gets `_2`),
`hours.csv` (BOM, `;`: `person;event_uid;start;end;hours;series;codes;title`
– one row per live event of the book whose codes name the project or a
work package below it; a series is **not expanded**: `series` 1 and the
hours of one occurrence; all-day events without hours) and `ledger.txt`
(one line per ledger entry of the project's invoices: `seq at actor
action number payload <hash> prev <hash> hash <hash>`). Answer `{ path,
size, sha256 }`.

**Export of expenses.** `GET /exports/expenses?from&to&kind` (`format` before «Member triage»): Team
Admins (403 “Only Team Admins export expenses.”), independent of
`invoicing`; `from`/`to` optional, filter on the **receipt's date**;
`kind` `csv` (default) or `datev` (422 otherwise). CSV head
`person;person_name;report;report_status;date;supplier;text;project;currency;net;vat_rate;vat;gross;without_receipt`
(`without_receipt` 1/0). DATEV: EXTF head as for invoices with the label
«TimeSister Spesen»; per receipt Umsatz = |gross| (S, H when negative),
Konto = `datev.expense_account`, Gegenkonto = the person's
`creditor_account`, no BU key (input VAT is not split), Belegdatum DDMM,
Belegfeld 1 = the report's key, text = supplier and text (60). 422 when
`expense_account` is missing (“… “expense_account” is missing.”) or a
person has none (“The DATEV export needs the creditor account of <name>
(“creditor_account” in the person record).”). `datev.expense_account`
(≤ 9 digits) is part of the `datev` setting; the person record's
`creditor_account` is null or a string of 1–9 digits (422 otherwise),
found under the person key = account, else the person naming the account.

**Privacy.** The notice names the module costs (purpose, a category row,
who sees receipts and reports, «not recorded» without «expenses») when
`settings.modules.costs` is true – or, not set, when receipts, rules or
reports exist.

**Errors.** New code `duplicate` (409). Classes: `DocumentTree`,
`ReceiptRules`, `ExpenseRuleRules`, `ExpenseReportRules`,
`ReceiptLineRules`, `DossierRules`, `ExpenseExportRules` (pure);
`CostWrites` (the record writes), `ReceiptService`, `ExpenseService`,
`QuoteDocumentService`, `DossierService`, `ExpenseExportService`,
`ReceiptFiles`, `ReceiptCopyPages`, `ExpenseReportPdf`, `TeamTree`.

## Member triage, 0.22.0 (2026-10-16)

Members propose, Leads and Team Admins assign. Version stays 0.22.0
(unpublished); no schema change.

**New field `cost_kind`** on `receipt`: `null`, `"expense"` or
`"third_party"` (the kinds of cost positions, 0.21.0). Only with a
project: `cost_kind` without `project` → 422 “A receipt without a project
has no “cost_kind”.”, another value → 422 “cost_kind” must be one of ….

**Position.** `project`, `budget`, `cost` all null (general), or a project
with `budget` and `cost` **both null** (triaged, not yet assigned) or
both set (422 “budget” and “cost” are both null or name …). A cost
position must exist in the budget and, when `cost_kind` is set, be of
that kind (422 “The cost position 2 is not of the receipt’s kind
“expense”.”); checked after the rights, so a billed receipt still answers
409 first.

**A member's write** (not a Team Admin, not a Lead of the receipt's
project after the write): `budget`, `cost` and `invoice_effect` are the
Lead's. The server keeps the stored values while `project` and
`cost_kind` stay (no error); with another project, or another cost kind,
`budget` and `cost` become null and `invoice_effect` its default (none
without project, `show_only` when the customer paid, else `charge`). The
client maps the member's question «expenses?» to `paid_by: person`,
`person` = the member, `reimburse: true`, else `paid_by: company`,
`reimburse: false`; the server does not force this.

**Proposal and correction.** Before approval Team Admins correct any
field; a Lead any field of receipts of the projects they lead, but moving
one to a project they do not lead is 403 (unless it is their own).
`cost_kind` joins the fields correctable after approval (`project`,
`cost_kind`, `budget`, `cost`, `invoice_effect`, `note`); an approved
receipt of a project keeps budget and cost position (422 “An approved
receipt of a project keeps a budget and a cost position.”).

**Reading.** A Lead reads (single, list, delta, history) and writes the
receipts of every project they lead as soon as `project` is set, also
without budget and cost position. Members still see no budgets, cost
positions or caps; a project they read is its booking catalog.

**Approve.** `POST /receipts/{key}/approve` of a receipt with a project
needs `budget` and `cost`: 422 “Before approval a Lead or Team Admin
assigns the receipt to a budget and a cost position.”; the cost position
must exist and match `cost_kind` (422 as above). Order: rights, status
(409), file (422), assignment (422).

**File name while open.** While a receipt is `open` (a write to a
rejected one opens it), every client write moves the file to its current
name and folder (`Belege/` or `Spesen/<person>/` by `reimburse` and
`person`) and updates `file.path` – also on a write that changes date,
supplier, gross, currency, project or cost. A name the file already
carries as `_2` … stays (no renaming back and forth). A failed batch moves
the files back. Approval moves it a last time; from `approved` on it never
moves. Name parts: no amount part while `gross` is 0
(`2026-10-02_Bahn.pdf`), a project without cost position only `_<code>`,
with one `_<code>-K<n>`.

**Export of expenses.** The parameter is now `kind`:
`GET /exports/expenses?from&to&kind=csv|datev` (422 “kind” must be one of
csv, datev.). `format` is no longer read – OCS uses it for json/xml. No
transition.

**Receipt copies (client side).** The free FPDI parser reads no PDF with
a cross-reference stream or object streams (see «As built»). The Mac app
therefore rewrites such a PDF before `PUT /receipts/{key}/file` with a
classic cross-reference table and without object streams (same pages);
if that fails, it uploads the file unchanged and the server's
placeholder page stays the fallback. `sha256` is the uploaded file's.

## Recognition fields, 0.22.0 (2026-10-16)

The Mac app reads a receipt's file itself (Apple Vision, Swiss QR code,
ZUGFeRD/XRechnung in a PDF, the local language model, a heuristic as
fallback) and fills the receipt with what it found. **The recognition
never runs on the server**; the server only stores and checks these
optional fields. Version stays 0.22.0 (unpublished); no schema change.

| Field | Rule |
|---|---|
| `review` | bool, default `false`: the mark «please check». The client sets it when the recognition is not sure (the sum does not add up, a rate is unknown, a keyword is missing). |
| `review_reasons` | list of at most 10 texts of 1–200 characters, default `[]`; stored as `[]` while `review` is `false` |
| `recognized_by` | `null`, `"zugferd"`, `"qr"`, `"model"` or `"heuristic"`: where the amounts came from |
| `qr_reference` | `null` or 1–35 characters: the reference of a Swiss QR bill |
| `vat_lines` | `null` or 1–10 lines `{ rate, net, vat }` with distinct rates (0–100), for receipts with several VAT rates (as an invoice's `vat_lines` since 0.21.0); `[]` is stored as `null` |

**VAT lines.** With `vat_lines` the receipt has no single rate:
`vat_rate` must be `null` (422 “A receipt with “vat_lines” has no single
“vat_rate”: it is null.”). Σ `net` of the lines = `net`, Σ `vat` = `vat`
(422 “The “vat_lines” add up to “net” and “vat”.”), `net + vat = gross`
as before, and on each line `vat = round(net × rate / 100, 2)` – all within
0.01 (422 “In “vat_lines” the VAT at 8.1 % must be …”). A rate twice: 422
“Each rate stands once in “vat_lines”.”

**The mark.** Every writer of an open receipt may set `review` (the
recognition of a member's client sets it). Clearing it (stored `true`,
written `false` – «checked») is for Team Admins, the Leads of the
receipt's project and who created it (`created_by`): else 403 “Only Team
Admins, the project’s Leads and who recorded the receipt mark it as
checked.” `POST /receipts/{key}/approve` clears it (`review: false`,
`review_reasons: []`) in the same write. After approval the fields are
not among the correctable ones (409 like `net`); a record from before
these fields compares as having their defaults, so writing them unchanged
is no change.

**Invoicing.** A receipt with `vat_lines` and `invoice_effect: charge` on
a taxable line (`vat_mode` not `pass_through`) stands on **one line per
rate**: each line names it in `receipts[]` and carries one of its rates in
`vat` (422 “Position n: The receipt rc-… has several VAT rates; its line
names one of them in “vat” (2.6 %, 8.1 %).”), each rate once (422 “… is on
the invoice twice.”) and every rate present (422 “The receipt rc-… stands
on one line per VAT rate; the line at 8.1 % is missing.”). The amount of
each line is the client's (the Mac app: the line's net). On a
pass-through line, and with `show_only`, the receipt stands once as
before. Markup bases sum all its lines.

**Expense reports.** `sums` count a receipt with `vat_lines` once per
rate (`VatLines`); its row in the PDF shows the rates joined
(`2.6 % / 8.1 %`). The CSV export leaves `vat_rate` empty for it.

**Another currency (`booked`).** A receipt whose `currency` differs from
the team's currency (`settings.currency`, else CHF) carries the amount actually paid in the
team's currency: `booked: { gross, currency }` – e.g. a USD invoice paid
by card in CHF, `gross` from the card statement. A `rate` sent along is
not stored. `booked` in the receipt's own currency is stored as `null`;
in another than the team's currency 422 ““booked” is the amount paid in
the team’s currency CHF.”; not an object of a number and a currency 422.
Approving a foreign receipt without it: 422 “Enter the amount in CHF that
was paid.” (after the other checks). Like the amounts it does not change
after approval. Expense reports (sums and rows, the original amount in
brackets after the text), the export of expenses (CSV, DATEV) and the
Mac app's invoice lines and cost consumption count the booked amount as
gross with the receipt's rate(s): split over its VAT lines by their share
of the gross (the last line takes the rest), net = gross / (1 + rate)
rounded half away from zero, VAT the rest. `net`, `vat`, `gross`,
`vat_rate` and `vat_lines` of the receipt stay as on the receipt, for
information. A receipt in the team's currency has `booked: null` and
everything stays as before.

**Rates.** `vat_rate` and the rates of `vat_lines` are any number from 0
to 100 – also old Swiss rates (7.7, 3.7, 2.5 until 2023) and foreign VAT;
the server checks only the arithmetic.

**As built.** Class `ReceiptRecognition` (pure: content, defaults, who
clears the mark, the receipt's amounts by rate) and `ReceiptBooking`
(pure: the team's currency, `booked`, the amounts as they count); `ReceiptRules` calls it
from `validate`, `requireWrite` and `changed`, `ReceiptLineRules` for the
lines per rate, `ExpenseReportRules::sums` and `ExpenseService` for the
report.

## Receipts against a budget in another currency, 0.22.0 (2026-10-16)

Which amount of a receipt counts depends on what it counts for. Version
stays 0.22.0 (unpublished); no schema change, the new field lives in the
record.

T is the team's currency, R the receipt's (`currency`), B the budget's
(`budgets[].currency` of the receipt's project; a budget without one uses
T). The invoice of a budget is in B.

**Team currency.** `settings.currency` is `null` or three capital letters
(422 “The team’s “currency” is null or three capital letters, for example
CHF.”); T is `settings.currency`, else CHF. The fallback to the currency
of the issuer's first account is gone (development, no transition). The
Mac app sets it under Accounting settings › Team currency, locally as in
a team.

**Document language.** `settings.language` is `null`, `de` or `en` when
written (422 “The team’s “language” is null, “de” or “en”.”); `null` or
absent leaves it to the server's `default_language`. Invoice and preview,
expense report and the e-invoice texts follow it. The Mac
app sets it under Accounting settings › Document language and fills it on
the first save of those settings with the Team Admin's app language.

**For expenses** (expense reports with sums and rows, the export of
expenses as CSV and DATEV) nothing changes: R = T the receipt's own
amounts, else `booked` (in T).

**For the budget** (the invoice line from a receipt, the consumption of
the cost position and its cap, the cost burndown):

| Case | Amount |
|---|---|
| R = B | the receipt's own amounts (gross, net, VAT, `vat_lines`) |
| R ≠ B, T = B | `booked`, as before |
| R ≠ B, T ≠ B (also R = T ≠ B) | `billed` |

**New field `billed`:** `null` or `{ gross, currency }`, the amount for
the budget in B (a `rate` sent along is not stored; not an object of a
number and a currency: 422 ““billed” is null or the amount for the budget:
{ “gross”, “currency” }.”). Net and VAT follow from it like from `booked`:
gross over the receipt's rate(s), split over its VAT lines by their share
of the gross (the last line takes the rest), net = gross / (1 + rate)
rounded half away from zero, VAT the rest.

- Needed only for a receipt with a budget when R ≠ B and T ≠ B; otherwise
  every write stores it as `null`.
- In another currency than B: 422 ““billed” is the amount for the budget
  in its currency EUR.”
- `POST /receipts/{key}/approve` without it: 422 “Enter the amount in EUR
  for the budget.” (after the check of `booked`).
- A write to an approved receipt (a Lead or Team Admin moving it to
  another budget, or a project whose budget changes its currency) is
  checked the same way: an approved receipt that needs it keeps it (422
  as at approval).
- After approval `billed` is correctable by Team Admins and the project's
  Leads until the receipt is billed (it joins `project`, `cost_kind`,
  `budget`, `cost`, `invoice_effect`, `note`; the 409 names it “amount for
  the budget”). `booked` stays fixed after approval as before.
- Member triage: like `budget` and `cost` it is the Lead's – a member's
  write keeps the stored value while project and cost kind stay, else
  `null`.

**As built.** `ReceiptBooking` is the one place that decides:
`forExpenses()` and `forBudget(team, budget)` return currency, gross,
amounts by rate and whether another amount than the receipt's own counts;
expense reports, the expense export and the expense report PDF call only
`forExpenses()`. `teamCurrency()`, `budgetCurrency()`/`budgetCurrencyOf()`,
`needsBilled()`, `requireBudgetCurrency()` (write and correction, from
`CostWrites`) and `requireApprovable()` (from `ReceiptService::approve`).
The server itself computes no budget amounts (invoice lines are the
client's); the Mac app counts with the same rule (`Beleg::fuer_spesen`,
`Beleg::fuer_budget` with `Waehrungen`): invoice lines from receipts,
cost consumption and the cost burndown in B; its receipt sheet shows a
line «For the budget in EUR» when needed, and approval asks for the
amount when it is missing.

## Simpler receipt flow and fixed expense reports, 0.23.0 (2026-10-16)

Decision of 2026-10-16: one receipt list, receipts flow on to an expense
list (per person) or to invoicing (per project). Expense reports are
**fixed** like invoices: frozen, numbered, PDF with receipt copies, in the
ledger. Development, no transition: `expense_report` records of 0.22.0
(`submitted`/`approved`) are not migrated; clients ignore them.
Capability `expense_fixing: 1` (replaces `expense_reports`).

### Receipts: assignment is approval

- A Lead (for the projects they lead) or a Team Admin who writes a
  receipt with a complete assignment approves it in the same write:
  project receipt with `budget` + `cost` → `approved`; general receipt
  (no project) → `internal`. Preconditions as for `approve` (file for
  kind `receipt`, `booked`/`billed` when needed); if one is missing the
  write is stored and the receipt stays `open` (no error), the answer
  carries `missing: [field]`.
- A member's write never approves. `POST /receipts/{key}/approve` stays
  (“confirm” of a member's proposal, same checks).
- A **general receipt paid by the company** (`paid_by: company`, no
  project) needs no approval: any write that makes it complete sets
  `internal`, also from a member.

### Expense list and expense report

The **expense list** of a person is derived, not stored: their receipts
with `reimburse: true`, status `approved`/`billed`/`internal`, and no
`report`. Record `expense_report` (schema 2):

| Field | Rule |
|---|---|
| `person` | uid |
| `status` | server-owned: `open` → `fixed` → `paid`; `reversed` |
| `ready_on` | date the person said “done” (optional) |
| `number` | server-owned, from `settings.expense_number` (pattern like `invoice_number`, default `SP-{YYYY}-{NNNN}`), at fixing |
| `lines` | server-owned, frozen at fixing: per receipt `{receipt, date, supplier, text, project, cost, vat_lines, net, vat, gross, currency, booked, without_file}` |
| `sums` | server-owned: `VatLines` over the amounts for expenses (booked in team currency), plus `currency` |
| `fixed_on`, `fixed_by`, `paid_on`, `reversed_on`, `document` | server-owned |

| Method | Path | Who | |
|---|---|---|---|
| POST | `/expense-reports/ready` `{person}` | the person, Team Admins | creates or updates the person's one `open` report with `ready_on` = today (“done, please fix”) |
| POST | `/expense-reports/fix` `{person, receipts?}` | Team Admins | freezes the person's expense list (or the given subset of it) into a new or the `open` report: number, lines, sums; sets `report` on every receipt; renders the PDF (table + receipt copies, like the invoice) into `Buchhaltung/<YYYY>/Spesen/<person>/<date>_<number>_Spesenblatt.pdf`; appends ledger action `expense_fixed` with the payload hash – one transaction. `422` without receipts. |
| POST | `/expense-reports/{key}/paid` `{date}` | Team Admins | `paid`; ledger `expense_paid` |
| POST | `/expense-reports/{key}/reverse` `{reason}` | Team Admins | only `fixed` (not `paid`): `reversed`, receipts get `report` null again; ledger `expense_reversed`; the PDF stays |
| GET | `/expense-reports/{key}/document` | the person, Team Admins | the PDF |

Writes to a fixed report other than these actions: `409`. The chain
verification (`/invoices/chain/verify`, the head file) covers the new
actions. Exports (`/exports/expenses`) take `fixed` and `paid` reports.

### Invoicing list (clients)

No new server data. The Mac app shows per project what is still to bill:
unbilled hours, approved receipts with `invoice_effect` ≠ `none` and no
`invoice`, and the expense rules of the cost positions; «Create invoice»
opens the editor with the chosen items (positions `hours`, `costs` with
`receipts[]`, `expense_percent`/`expense_flat`, `markup`), as in 0.22.0.

### As built, 0.23.0 (2026-10-16)

What the server does beyond or beside the lines above, so the Mac app can
rely on it. No schema change (`occ upgrade` runs no migration); reports
and their lines live in the record, the ledger in `ts_inv_ledger`.

**Assignment is approval.**
- An open receipt is approved in the write when the writer may approve
  it – Team Admins; a Lead of the receipt's project (after the write),
  not for kind `trust` – or when it is a general receipt the company paid
  (`paid_by: company`, no project, kind not `trust`; any writer). Status
  `approved` (project) or `internal` (general), `approved_by` = the
  writer, `approved_on` = today (UTC), `reject_reason: null`.
- Otherwise the receipt stays `open` and the write's answer (single PUT
  and each record of a batch) carries `missing`, beside `data`: a list of
  `budget` and `cost` (a project without assignment), `file` (kind
  `receipt` without file), `review` (marked «please check»), `booked`
  (another currency than the team's without the amount paid), `billed`
  (a budget in a third currency without its amount). `missing` is absent
  when the write approved the receipt or does not approve (a member's
  write of anything but a company's general receipt).
- **Addition: `review`.** A receipt marked «please check» is not approved
  by a write; clearing the mark (allowed to Team Admins, the project's
  Leads and who recorded it) is the write that approves it. The endpoint
  `approve` still clears the mark itself.
- **Addition: the file.** `PUT /receipts/{key}/file` is a write too: when
  the uploaded file completes the receipt (and the uploader may approve
  it, or it is a company's general one), it is approved. The answer now
  carries `status` and, when still open after an approver's upload,
  `missing`: `{ path, sha256, size, mime, version, status, missing? }`.
- The file follows its name while open, then is placed once more at the
  approval in the same write; after that it never moves (as before).
- `POST /receipts/{key}/approve` and `/reject` are unchanged.

**Expense reports (record `expense_report`, schema 2).**
- Record fields: `schema` (2, set by the server), `person`, `ready_on`
  (null or a day) – the only fields a client writes. Server-owned:
  `status`, `number`, `lines`, `sums`, `fixed_on`, `fixed_by`, `paid_on`,
  `reversed_on`, `reverse_reason`, `document` `{ path, sha256, size }`,
  `document_state` (`pending`, `done`, `failed`), `document_error`,
  `document_tries`, `ledger_seq`, `hash` (of the `expense_fixed` entry).
- Client writes through `/records`: only an `open` report of schema 2, by
  its person or a Team Admin (403 otherwise); the person does not change
  (409); one open report per person (409 `duplicate` “The expense report
  sb-… is already the open one of this person.”); deleting the open
  report is allowed. Any write or delete of a fixed, paid, reversed or
  0.22.0 report: 409 “A fixed expense report changes only through its
  actions (paid, reverse).”
- Reading: the person and Team Admins (Leads no longer).
- Reports of 0.22.0 (schema 1, `submitted`/`approved`) stay as they are;
  they hold no receipt: a receipt whose `report` names such a report (or
  a deleted or reversed one) is on the expense list again. **Clients
  treat `report` as set only when it names a live schema-2 report that is
  `fixed` or `paid`.**
- The **expense list** (derived): the person's live receipts with
  `reimburse: true`, status `approved`, `internal` or `billed`, held by no
  fixed or paid report.
- `POST /expense-reports/ready` `{ person }` (the person, Team Admins;
  else 403; an account outside the team 422): creates the open report
  (key `sb-` + 8 hex digits) or updates it, `ready_on` = today; answers
  the record.
- `POST /expense-reports/fix` `{ person, receipts? }` (Team Admins, 403):
  without `receipts` the whole list, with them a part of it (1–500
  distinct keys; one not on the list 422 “The receipt rc-… is not on the
  expense list of this person.”, one held by a fixed report 409 “The
  receipt rc-… is in the expense report SP-….”); nothing to fix 422 “The
  expense list of this person holds no receipts.”. One transaction under
  the team lock: the open report (else a new key), number, `lines` sorted
  by date and key, `sums`, `report` on every receipt (server writes, new
  versions), ledger `expense_fixed`. Then the PDF (below). Answer: the
  record after the PDF, so normally with `document_state: done`.
- **Number:** `settings.expense_number` is an object like
  `invoice_number` (`pattern`, `per_year`, `start`, validated the same
  way); placeholders `{year}`/`{jahr}`, `{yy}`/`{jj}`, `{no}`/`{nr}` with
  width; default `SP-{year}-{no:4}` (= the contract's
  `SP-{YYYY}-{NNNN}`, e.g. `SP-2026-0001`). Own counter `expense:<year>`
  (or `expense`); `{project}`/`{customer}` stay empty. A number another
  report already carries: 409. The year is the one of `fixed_on`.
- **A line:** `{ receipt, date, supplier, text, project, cost, vat_lines,
  net, vat, gross, currency, booked, without_file }` – the receipt's
  **own** amounts (as on the receipt), `vat_lines` always a list of
  `{ rate, net, vat }` (its `vat_lines`, else one line of its `vat_rate`,
  else `[]` = no rate), `booked` as on the receipt (`{ gross, currency }`
  or null), `without_file` for `trust` and `recurring`. The amount that
  counts for expenses follows from the line as from a receipt
  (`ReceiptBooking::forExpenses`, Mac `Beleg::fuer_spesen`).
- **`sums`:** `{ currency, net, vat_lines, gross }` – `VatLines` over the
  counted amounts (booked in the team's currency); all lines in one
  currency, else 422.
- **Ledger:** the actions share the invoices' chain (`invoice` = 0,
  `number` = the report's number). Payloads (canonical JSON, hashed like
  an invoice's): `expense_fixed` `{ key, number, person, fixed_on,
  fixed_by, ready_on, lines, sums }`; `expense_document` `{ key, number,
  kind: "pdf", path, size, sha256 }` (addition: the PDF's hash, as
  `document` for invoices); `expense_paid` `{ key, number, paid_on }`;
  `expense_reversed` `{ key, number, reversed_on, reason }`. They raise no
  webhook. `GET /invoices/ledger`, `chain/verify`, `chain/head` and the
  daily head file cover them (verify and head are action-agnostic); the
  project dossier's `ledger.txt` does not list them.
- **PDF:** `Buchhaltung/<year of fixed_on>/Spesen/<person>/<fixed_on>_<number>_Spesenblatt.pdf`
  (a taken name `_2`), A4 portrait: title «Spesenblatt <number>»,
  person, period (first to last receipt date), team; rows with the
  counted amounts (the original amount in brackets after the text when
  booked), sums per rate, signature lines «Unterschrift Person: <name>»
  and «Fixiert: <name>, <date>» (English «Fixed»); the receipt copies;
  the chain line `Beleg <number> · Kette <seq> <hash12> ← <prev12>` and
  «Spesenblatt <number> · <person>» in the footer of every page. Language
  as the invoice. Rendered right after the transaction; a failure sets
  `document_state: failed` and `document_error`, the job of 0.19.0
  (every 15 minutes, up to ten tries) renders it again (actor `system`).
- `POST /expense-reports/{key}/paid` `{ date? }` (Team Admins; default
  today): only `fixed` (409 “Only a fixed expense report is marked
  paid.”); `paid`, `paid_on`, ledger `expense_paid`. Answer: the record
  plus `ledger: { seq, prev_hash, hash }`. There is no «unpaid».
- `POST /expense-reports/{key}/reverse` `{ reason? }` (Team Admins;
  `reason` ≤ 500 characters): `paid` 409 “A paid expense report is not
  reversed.”, anything but `fixed` 409; `reversed`, `reversed_on`,
  `reverse_reason`, `report: null` on its receipts (those still naming
  it), ledger `expense_reversed`; the PDF stays. Answer as for paid.
- `GET /expense-reports/{key}/document`: the person and Team Admins (403
  otherwise); 404 “This expense report has no document yet; it is
  written when it is fixed.”
- `POST /expense-reports/{key}/approve` and the submit by record write of
  0.22.0 are gone.

**Export of expenses.** Rows are the frozen lines of `fixed` and `paid`
reports (schema 2); the column `report` and DATEV's Belegfeld 1 carry the
report's **number** (before: its key); `vat_rate` is the single rate of
the line, empty for none or several.

**Capability** `expense_fixing: 1`; `expense_reports` is gone.

**Classes.** Pure: `ReceiptRules` (`canApprove`, `needsNoApproval`,
`missing`, `approveByWrite`), `ExpenseReportRules` (rewritten: list,
`pick`, `line`, `sums`, `payload`, rights), `InvoiceNumbering`
(parametrised by field and counter), `LedgerRules` (four actions).
Services: `CostWrites` (approval in the write, the open report),
`ReceiptService` (file upload approves), `ExpenseReportService` (ready,
fix, paid, reverse, document), `ExpenseReportDocuments` (PDF, ledger
entry, retry), `Transaction` (shared with `IssueService`);
`ExpenseService` keeps the rule's approval.
