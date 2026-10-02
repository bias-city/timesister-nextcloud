# Changes

## 0.8.0 – 2026-10-02 (beta)

- First release in the Nextcloud App Store, for the beta of the TimeSister Mac app 0.4.0.
- Contains everything listed below under 0.1.0 to 0.7.6; no further changes. API version 2.
- Modules (Jobs, Budgets, Clients) need no change here: the clients keep the switches in the settings record (`setting/settings`, field `modules`).

## 0.7.6 – unreleased

- API unchanged in form (`api: 2`), only additions; the capability gains `billing: 1`.
- **Billing marks as records:** kind `billing`, one per person and event UID (key: the person's key, `+`, 32 hex digits of the UID's SHA-256); `data`: `person`, `uid`, `billed_on`, optional `by`, `checksum`, and – carried through for a later hand-over to an accounting system – `source`, `external_id`, `document`. Written by Team Admins and by Leads who may at least view the person in the shares matrix (members and externals); read by the same and by the person themselves. Users never write them. `POST /records/batch` takes a Lead's batch when it holds billing marks only.
- **Externals in the shares matrix:** a person record with a feed and no account is a column after the team (`external: true`, `role: "external"`), levels `none` or `view` only (`edit` → `422`), set by Team Admins only, Team Admins see by default, nothing pending (there is no owner's client). `GET /team/access` lists them for Team Admins and, as the own row, for everyone.
- **Feed only for those who may:** `person.data.feed` goes to Team Admins, the person themselves and Leads with at least `view` on that person; everyone else gets the record without `feed` – in the delta, single reads and the history. Changing an external's column gives their person record a new revision (same version), so clients fetch it again.
- No migration.

## 0.7.5 – unreleased

- API unchanged in form (`api: 2`), only an addition; the capability stays `jobs: 4`.
- **Day weights for readers:** `GET /jobs/weeks` returns per week also `days`, the seven weights the client reported (a holiday by its factor, a vacation day 0). With them a reader splits a week that runs over a month's end – the clients' monthly view of the team's capacity. Same read rights as the rest of the week (the person, Team Admins, Leads who may at least view); a report stored without weights reads Monday–Friday 1. Clients tell it by the field being there.
- No migration: the numbers stay JSON in `ts_client_status.job_weeks`.
- **Above the work package allowed:** the volume rule no longer refuses (`422 rule: volume` is gone); the clients show the overrun.
- **A Job offered only to oneself** is accepted at once and notifies nobody.

## 0.7.4 – unreleased

- API unchanged in form (`api: 2`), only additions; the capability names `jobs: 4`.
- **Non-billable hours in the weekly numbers:** a client reports per Job and week also `booked_nb`, the non-billable part of `booked` (at most `booked`). `GET /jobs/weeks` returns it with the same read rights and merges it like `booked` into `other` for Jobs the reader does not see. Reports of older clients, and reports stored before, read as 0. The load and the capacity check stay booked plus planned.
- No migration: the numbers stay JSON in `ts_client_status.job_weeks`.

## 0.7.3 – unreleased

- **Nextcloud 35:** `max-version` 35; Psalm clean against 33, 34, 35 and master. `IL10N::t` gets only non-empty text; a plural text without `%n` (Nextcloud returns both forms joined by `|`) is resolved by the app.
- Early warning: installs the notifications app like the PHPUnit workflow, so the reminder tests run there too.
- Integration tests: no longer rely on local test data or on the account language picked at the first login.
- **Capacity check per day:** when a counter-proposal is accepted, no day may go above a full day's capacity either (the capacity line over the week's working days, by weight), besides the week. Hours that land on the one working day after a vacation, or on days without weight, no longer pass because the week as a whole still has room. An entry of such a day carries `day`, with that day's load and capacity. No migration, API unchanged in form.

## 0.7.2 – unreleased

- API unchanged in form (`api: 2`), only additions; the capability names `jobs: 3`.
- **Weekly numbers per person:** each client reports its own weeks (`POST /jobs/weeks`): the capacity line (target × pensum − holidays − vacation), per Job the booked and planned hours, and the weight of each day. Read (`GET /jobs/weeks`) by the person, Team Admins and Leads who may at least view the person's time calendar; Jobs the reader does not see are merged into `other`.
- **Capacity check when a counter-proposal is accepted:** the server spreads the counter-proposal over the person's working days and answers `422` with `rule: capacity` and the weeks above the capacity line; without reported numbers nothing is blocked. `GET /jobs/{key}/capacity?by=` shows the same before accepting.
- Migration: two columns in `ts_client_status` (`job_weeks`, `job_weeks_at`).

## 0.7.1 – unreleased

- **Privacy in the market:** whoever does not manage a Job (recipients, the assignee) reads only their own counter-proposal; the others' waiting ones as `{ other: true }`, answered ones not at all; the log without the others' counter-proposals and their answers. Everywhere a Job is read: delta, `GET /records/job/{key}`, the answers of `/jobs`, the `current` of a `409`.
- The history of a Job only for those who manage it (Team Admins, the project's Leads, the sender).
- No migration, API unchanged in form.

## 0.7.0 – unreleased

- API unchanged in form (`api: 2`), only additions; the capability names `jobs: 2`.
- **Market:** a Job offered to several stays open for the others while one of them counter-proposes; counter-proposals are kept per recipient (`counters`, a 0.6.0 `counter` reads as a list of one). Whoever is accepted first gets the Job – by accepting the offer or through their accepted counter-proposal; the counter-proposals still waiting lapse (`answer: lapsed`, notification `job_counter_lapsed`).
- `accept-counter` and `reject-counter` take `{ by }` to say whose counter-proposal while several wait.
- Rejecting a counter-proposal in a market puts only that person out; the Job is `rejected` once nobody is left.
- No migration.

## 0.6.0 – unreleased

- API unchanged in form (`api: 2`), only additions; the capability names `jobs: 1`.
- **Jobs:** records of kind `job`, read through `/records`, written through `/jobs`: offer (`PUT /jobs/{key}`), change, accept (atomic, the first wins), counter-propose and answer, decline, return, Done, paid (Team Admin), delete (whoever created it), and the booked hours from the person's client (`POST /jobs/progress`, Done by itself once the volume is reached).
- Rules on the server: the volume of a work package, no overlapping Jobs on the same work package or code for the same person, codes of the project.
- Who sees a Job: Team Admins, the project's Leads, the sender, and the person while it is theirs; whoever was involved before gets a tombstone in the delta.
- Notifications for new offers, counter-proposals and their answer, changes, returns and deletions.
- The words Job, Workload, Inbox, In Progress, Done and Outbox are the same in every language (`JobWord`, never through the l10n).
- No migration: Jobs live in `ts_records`.

## 0.5.0 – unreleased

- API unchanged in form (`api: 2`), only additions.
- **Three roles: Team Admin · Lead · User**, the same words in every language (`RoleName`, never through the l10n). The role Manager (`subadmin`) is gone: the migration turns stored entries into `lead`.
- What managers and admins could do is Team Admin only; Leads keep writing their own projects.
- `PUT /team/members/{uid}` sets `admin` (group admin of the team group via `ISubAdmin`), `lead`, `user` and `may_override`; the team always keeps at least one Team Admin.
- Shares matrix: `GET`/`PUT /team/access`, `PUT /team/access/{viewer}/{owner}`, `POST /team/access/remind`; `/me.share_targets` and `/me.may_override`; `applied_shares` in `POST /status`. Reminders as Nextcloud notifications, withdrawn once the owner's client has set everything.
- Admin page: roles Team Admin, Lead and User selectable per member; "Leads see all time calendars" removed (the matrix decides).

## 0.4.0 – unreleased

- API unchanged (`api: 2`).
- Roles are called User, Lead, Manager and Admin in every language (admin page, app description).
- Error messages have English source texts and are translated with Nextcloud's l10n (`de`, `de_DE`); `message` comes in the account's language (Nextcloud language setting, else `Accept-Language`, else English). Clients decide by `error`, never by wording.
- New visible backup copies go to the folder "TimeSister Backups" (was "TimeSister-Sicherungen"). Existing files stay where they are; their tracked paths remain valid and thinning removes them as before.
- Server backups carry `PRODID:-//B/IAS//TimeSister Server Backup//EN`.
- Log messages, code comments and tests are in English.

## 0.3.0 – unreleased

API version 2 (`api: 2`), no transition from version 1:

- **One** team group per team (`groups: { team }`). The team's Admin is
  whoever is that team group's group admin in Nextcloud (`ISubAdmin`); the
  `lead` and `subadmin` roles and leaving live in the app (table
  `ts_members`). Role, account and time groups are dropped; old rows in
  `ts_role_groups` stay and are ignored.
- `/me` with `admins`, `leads` and `calendar_share`; `/team` with
  `members: [{ uid, display_name, role, left_at }]`, including those who
  left.
- `PUT /team/members/{uid}` (Manager and Admin; only Admins grant
  `subadmin`), plus `PUT /admin/teams/{id}/members/{uid}` for the admin
  page.
- `GET`/`PUT /me/calendar-share` (default on), `calendar_shared` in
  `POST`/`GET /status`.
- Projects in full only for Manager, Admin and the project's Leads,
  otherwise the booking catalog; a Lead can fully edit their project but
  not create new ones. A project's history is also available to its Lead.
- Admin page: one group field, Admins as a list with a note, members with
  role and leaving (settable in "Edit team"), in the status the accounts
  without a shared time calendar.
- Migration `Version1004…`, additive only (`ts_members`,
  `ts_client_status.calendar_shared`).

## 0.2.2 – unreleased

- API version 1.2: team settings `settings` (`leads_see_calendars`, default
  on; `backup_required`, default off) in `/me`, `/team` and
  `/admin/teams`, settable via `POST`/`PUT /admin/teams`.
- Admin page: two switches in the "Edit team" form, status in the team
  list.
- Migration `Version1003…`, additive only.

## 0.2.1 – unreleased

API version 1.1, backups (0.2.0 was only a development snapshot):

- The server backs up on its own: checked hourly, at most once per ISO
  week per account, export with `ICalendarExport`, a new backup only on a
  changed checksum.
- Only with the person's consent (`GET`/`PUT /backups/consent`, with a
  version of the disclosure text); withdrawing deletes nothing.
- Two storage locations: protected in `IAppData` and visible with the
  team's backup owner (`backup_owner`, selectable on the admin page).
  Optionally an own copy in a person's own folder (`/backups/own-copy`).
- `POST /backups/now`, `source` and `file_path` in the backups,
  `backup_owner` in `/team`, consent in `/status`.
- Thinning instead of a fixed retention: all of the last 4 weeks, then
  monthly up to 12 months, then yearly up to 10 years. Only what the app
  created and remembered itself (`ts_backup_files`) gets deleted, via the
  trash.
- Admin page: backup owner per team, in the status consents, backups
  missing this week and the last server backup; wide tables scroll
  horizontally.
- Migrations `Version1001…` and `Version1002…`, additive only.

## 0.1.0 – unreleased

- First version: API version 1 (`docs/API.md`), teams with four role
  groups, records with version and revision, history, restore, batch,
  calendar backups, status reports, admin page, retention.
- Optional accounts group per team (`groups.accounts`): all accounts of the
  team, no role. As group admins of this group, team Admins can revoke any
  role (Nextcloud rule OCS 105). Stored as a row `role = 'accounts'` in
  `ts_role_groups`, without a migration.
- Nextcloud 33–34.
- Admin page translatable: English as the source, German with "du" (`de`)
  and with "Sie" (`de_DE`). API error messages remained German.
- App Store prepared: English and German description, image, workflow
  `appstore-build-publish.yml` (off as long as the secrets are missing).
</content>
