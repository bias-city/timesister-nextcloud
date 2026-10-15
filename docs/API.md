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

If the entry is missing, the app is not installed or is disabled.

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
| DELETE | `/admin/teams/{id}` | only without records, otherwise `409` |

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
  `codes`, `subprojects`, `start`, `end`, `status`, `mapping`. Missing are
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

- **Defaults:** rows of Team Admins `edit`, everything else `none`. Only
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
Since 0.14.1 also `recipients`, `declined_by` and the offer's `to` trimmed
to the caller, `names` only for the caller and the sender; a Job offered to
several carries `market: true` instead.
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
  else `403`.
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
Leads with at least `view` on that person; everyone else gets the record
**without** `feed` – in the delta, `GET /records/person/{key}` and the
history. Setting an external's field gives their person record a new
`revision` (the `version` stays), so every client receives it again with
the next delta. A client whose copy lost the address drops the feed
calendar.

## The shared vacation calendar, 0.9.0 (2026-10-04)

Still `api: 2`; only additions. The capability says `absences: 1`. Plan:
`PLAN-ABSENZEN.md`, section 4 (Mac app).

### The calendar

A Team Admin creates «TimeSister – Vacation» (URI `timesister-ferien`) in
their own Nextcloud account from the Mac app and shares it: the team group
**read-only**, every Team Admin **with write access** (the owner's Mac keeps
the shares current). The settings record names it:

```json
{ "vacation_calendar": { "url": "https://…/remote.php/dav/calendars/atadmin/timesister-ferien/", "owner": "atadmin" },
  "absence_calendar_kinds": ["vacation", "sickness"] }
```

Since 0.9.1 the server accepts `vacation_calendar` only when `owner` is a
Team Admin of the team and `url` is a calendar of that owner
(`…/remote.php/dav/calendars/<owner>/<calendar>/`), otherwise `422 invalid`;
the background job skips a team whose owner has left or whose URL no longer
matches, with a warning in the log.

`absence_calendar_kinds` lists the categories that appear – `vacation`,
`sickness`, `parental`, `civil_service`, `unpaid`; missing means
`["vacation"]`, an empty list means none. Unknown names are ignored.

### `PUT /me/absences`

Every member, own account only. Body: the **complete** state of the own
absences for this and next year.

```json
{ "items": [ { "source_uid": "7f3a-…@example.test", "kind": "vacation", "start": "2026-07-06", "end": "2026-07-11" } ] }
```

- `source_uid`: the source event's UID (1–255 characters; a recurrence
  instance as `<uid>/<recurrence-id>`); the same source twice: the last one
  counts. `kind` one of the categories (missing: `vacation`). `start` and
  `end` are days, `end` exclusive and after `start`.
- At most 400 items (`413 too_large`); invalid items `422 invalid`; control
  characters in a field `400 invalid` (0.9.1).
- The server replaces the person's rows (table `ts_absences`). Answer:
  `{ "stored": 3, "opted_out": false }`.
- Opt-out: a person record with `vacation_calendar_optout: true` (set by the
  person in the Mac app) is stored nowhere; what was there is deleted, the
  answer says `"opted_out": true`.

### Written by the server

A background job (every 15 minutes) goes through every team with a
`vacation_calendar`, finds the owner's calendar by URI
(`getCalendarsForPrincipal`) and writes one all-day event per row whose
category is in `absence_calendar_kinds` and whose person has not opted out:

```
UID:ferien-<24 hex of sha256(lowercase uid + "\n" + source_uid + "\n" + kind)>@timesister
DTSTART;VALUE=DATE / DTEND;VALUE=DATE (exclusive)
SUMMARY:<Category> · <Initials Name>        – from the person record (initials, first_name, last_name), in the server's language
X-TIMESISTER-QUELLE:<source_uid>  X-TIMESISTER-PERSON:<uid>  X-TIMESISTER-ART:<kind>
```

Object name `<UID>.ics`, written with `createFromString` (PUT semantics:
the same name overwrites). Our events that are no longer wanted (row gone,
category switched off, opt-out) are written again with `STATUS:CANCELLED`;
the public API cannot delete. A Team Admin's Mac deletes cancelled marked
events in this calendar when it syncs; every Mac hides them. Events without
the `X-TIMESISTER-*` marks are never touched. An account deletion drops the
person's rows; the next run cancels their events.

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
