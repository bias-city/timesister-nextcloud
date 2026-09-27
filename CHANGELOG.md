# Changes

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
