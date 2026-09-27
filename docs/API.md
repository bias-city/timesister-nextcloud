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
