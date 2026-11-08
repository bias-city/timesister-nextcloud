<?php

declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * `README.md` in every team ZIP (0.43.0): what the files are and how the
 * data is structured, so that a person or an AI can read the backup without
 * the app. Plain Markdown, English, built from what the ZIP actually holds.
 */
final class ZipReadme {
	/**
	 * @param array{name:string,slug:string,app_version:string,api:int,taken_at:string,members:int,
	 *   kinds:array<string,int>,calendars:int,lists:int,vacation:bool,templates:list<string>,ledger:int,invoices:int} $i
	 */
	public static function build(array $i): string {
		$kinds = '';
		ksort($i['kinds']);
		foreach ($i['kinds'] as $kind => $n) {
			$kinds .= sprintf("| `%s` | %d | %s |\n", $kind, $n, self::KIND_WORDS[$kind] ?? 'see API.md, section “Records”');
		}
		if ($kinds === '') {
			$kinds = "| – | 0 | no records |\n";
		}
		$vacation = $i['vacation'] ? 'yes' : 'no';
		$templates = $i['templates'] === [] ? 'none' : implode(', ', array_map(static fn (string $t): string => '`' . $t . '`', $i['templates']));
		return <<<MD
# TimeSister team backup

Team **{$i['name']}** (`{$i['slug']}`), taken {$i['taken_at']} by the TimeSister Nextcloud app {$i['app_version']} (API {$i['api']}).
This file explains the archive so that it can be read without the app. All text files are UTF-8 JSON, all
times are ISO 8601 in UTC (`2026-11-08T18:49:48Z`), all days are `YYYY-MM-DD`, money is a decimal string
(`"1234.50"`), hours are decimal (`1.5` = one hour and a half).

## Files

| Path | What it is |
|---|---|
| `manifest.json` | Format `format`, `app_version`, `api`, `team` {id, name, slug, group}, `taken_at`, and `files`: for every file its `sha256` and `size`; calendar files carry the account `uid`, missing calendars are listed with `missing: true`. |
| `team.json` | The team: name, slug, settings, `members` (uid, display name, role `admin`/`lead`/`user`, left date), `consents` (who agreed to server backups, since/revoked), `time_calendars` (which named calendar holds whose hours). |
| `status.json` | Per account: the last status report of the Mac app (`seen_at`, `app_version`, `last_sync`, `calendar_url`, job weeks). |
| `absences.json` | Per account: absences the apps reported (`kind`, `start`, `end`) – the source of the shared vacation calendar. |
| `records/<kind>/<key>.json` | One master-data record each, with its full history (see below). {$i['members']} members, records by kind in the table below. |
| `ledger.json` | The invoicing ledger: an append-only chain of entries (`seq`, `prev_hash`, `hash`) – issue, reverse, paid. Read-only evidence; an import never recreates it. |
| `invoices.json` | The frozen (issued) invoices as they were fixed: number, customer, positions, amounts, VAT, hashes of the filed PDF/XML. {$i['invoices']} invoices, {$i['ledger']} ledger entries. |
| `templates/*.docx` + `*.json` | Word templates for invoices and quotes with their meta data. Present: {$templates}. |
| `calendars/<uid>.ics` | The time calendar of a member: every event is one booked hour block (iCalendar, RFC 5545). {$i['calendars']} calendars. |
| `calendars/list-<uid>.ics` | Hours of external people that came from lists (CSV), kept only in the app's book. {$i['lists']} lists. |
| `vacation.ics` | The team's shared vacation calendar as the app mirrored it (present: {$vacation}). |

## Records

A record is the unit of master data. Each `records/<kind>/<key>.json` holds:

```json
{
  "kind": "project", "key": "D196",
  "version": 7, "revision": 1234, "deleted": false,
  "modified_by": "uid", "modified_at": "2026-11-08T18:49:48Z",
  "data": { "...": "the record itself, fields by kind" },
  "accounts": ["uid"], "account_deleted_at": null,
  "history": [ { "version": 1, "deleted": false, "modified_by": "uid", "modified_at": "...", "data": { } } ]
}
```

`version` counts per record, `revision` is the team-wide sync counter. `deleted: true` is a tombstone (`data` is
null). `history` lists every earlier version, oldest first. The fields of `data` depend on the kind:

| Kind | Count | Meaning |
{$kinds}
The exact field lists per kind are in the app's `API.md` (section “Records”) and in the Mac app's
`core/src/stammdaten/`. Keys that look like codes (`D196`, `D196.4`) are project or work-package codes; a work
package carries its code and takes its name from it.

## Calendars

A time calendar is a normal CalDAV calendar named `zeit-<uid>` in the person's own Nextcloud account. Each
`VEVENT` is one block of worked time: `DTSTART`/`DTEND` are the hours, `SUMMARY` holds the project code
(`#D196.4 Title`), `CATEGORIES` may carry marks (billable, billed), `DESCRIPTION` the note. Recurring blocks
use `RRULE`; corrections by a lead are stored as records of kind `correction`, never by editing the event.

## Privacy

The archive holds personal data of the team's members (names, hours, absences, consents). It is the last copy
after a deletion of the team or of the app; keep it as the team's data protection notice requires, delete it
when it is no longer needed.
MD;
	}

	/** Short words per kind ({@see RecordValidator::KINDS}); the field lists are in API.md. */
	private const KIND_WORDS = [
		'person' => 'a person: name, initials, capacity, entry/exit, location, levels (rates) over time, external flag, absence settings',
		'region' => 'a region: the public holidays and weekly hours a location follows',
		'project' => 'a project: code, name, customer, lead, status, work packages with codes and outline levels, templates used',
		'customer' => 'a customer: name, address, type, tags',
		'setting' => 'team settings by key: `settings` (currency, rounding, check levels, vacation calendar, documents root), `targethours`, `privacy` (the data protection notice), `office_costs` (fixed costs, balances, reserve)',
		'job' => 'a job: a planned block of work for a person with weekly hours; written only through /jobs',
		'billing' => 'billing marks per person and project (which hours count as billable)',
		'booking-report' => 'a monthly report of bookings per person and project (hours, marks)',
		'billable' => 'a single billable mark of one booked block',
		'invoice' => 'an invoice as a record (draft, plan, assignment); issued invoices are frozen in invoices.json',
		'quote' => 'a quote (offer) or budget: fee model, work packages with hours or amounts, cost positions, invoice plan, texts',
		'receipt' => 'a receipt (expense document): amount, VAT, category, approval state, link to a budget or expense report',
		'expense_rule' => 'a recurring expense rule (rent, licences) with period and currency',
		'expense_report' => 'an expense report of a person, fixed with a number once settled',
		'template' => 'a project or budget template (catalogues such as SIA/HOAI, own templates)',
		'correction' => 'a correction of a booked time block by a lead (who, when, what changed)',
		'exchange_rate' => 'cached exchange rates (ECB) by day and currency',
		'bank' => 'bank entries of one account and year (imported statement lines and their assignment)',
	];
}
