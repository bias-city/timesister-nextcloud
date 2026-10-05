<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCP\IL10N;

/**
 * The privacy notice (0.10.2) as a tree of sections and blocks, built from
 * the facts of {@see PrivacyFacts} in the language of the IL10N. Pure.
 * {@see PrivacyFormat} renders the tree as Markdown or HTML.
 *
 * The legal references are a template: the document itself says so and
 * the controller checks it. Empty fields show a placeholder, so the
 * notice never looks complete when it is not.
 *
 * Blocks: `{type: p, text}`, `{type: list, items}`, `{type: table, head,
 * rows}`, `{type: note, text}`.
 */
final class PrivacyDocument {
	public function __construct(
		private IL10N $l,
	) {
	}

	/**
	 * @param array<string,mixed> $f from {@see PrivacyFacts::collect}
	 * @return array{title:string,intro:list<string>,sections:list<array{title:string,blocks:list<array<string,mixed>>}>}
	 */
	public function build(array $f): array {
		/** @var array{name:string,slug:string,group:string} $team */
		$team = $f['team'];
		$date = (string)$f['date'];
		return [
			'title' => $this->fill($this->l->t('Privacy notice – {team}'), ['team' => $team['name']]),
			'intro' => [
				$this->fill($this->l->t('Information on the processing of personal data in the time tracker TimeSister for the team “{team}”, generated on {date} from the team’s settings (Nextcloud app {version}).'), ['team' => $team['name'], 'date' => $date, 'version' => (string)$f['app_version']]),
				$this->l->t('This is a template from the team’s settings, not legal advice. The controller checks and completes it.'),
			],
			'sections' => [
				$this->controller($f),
				$this->purpose($f),
				$this->categories($f),
				$this->locations($f),
				$this->access($f),
				$this->backups($f),
				$this->retention($f),
				$this->rights($f),
				$this->note($f),
			],
		];
	}

	/** {name} placeholders filled after translating; values never pass through vsprintf. */
	private function fill(string $text, array $vars): string {
		$map = [];
		foreach ($vars as $k => $v) {
			$map['{' . $k . '}'] = (string)$v;
		}
		return strtr($text, $map);
	}

	private function blank(): string {
		return $this->l->t('[to be completed]');
	}

	private function value(string $v): string {
		return $v === '' ? $this->blank() : $v;
	}

	/** @param list<string> $items */
	private function join(array $items): string {
		return $items === [] ? '–' : implode(', ', $items);
	}

	/** @param array<string,mixed> $f */
	private function controller(array $f): array {
		/** @var array{controller:array{name:string,address:string,contact:string},privacy_contact:string,hosting:array{provider:string,location:string}} $s */
		$s = $f['settings'];
		/** @var array{name:string,slug:string,group:string} $team */
		$team = $f['team'];
		return ['title' => $this->l->t('Controller and contact'), 'blocks' => [
			['type' => 'table', 'head' => [$this->l->t('Item'), $this->l->t('Entry')], 'rows' => [
				[$this->l->t('Controller'), $this->value($s['controller']['name'])],
				[$this->l->t('Address'), $this->value($s['controller']['address'])],
				[$this->l->t('Contact'), $this->value($s['controller']['contact'])],
				[$this->l->t('Privacy contact'), $this->value($s['privacy_contact'])],
				[$this->l->t('Hosting of the Nextcloud'), $this->value(trim($s['hosting']['provider'] . ($s['hosting']['location'] === '' ? '' : ', ' . $s['hosting']['location'])))],
				[$this->l->t('Team'), $this->fill($this->l->t('{name} ({slug}), Nextcloud group “{group}”'), ['name' => $team['name'], 'slug' => $team['slug'], 'group' => $team['group'] === '' ? '–' : $team['group']])],
				[$this->l->t('Date'), (string)$f['date']],
				[$this->l->t('App version'), $this->fill($this->l->t('TimeSister Nextcloud app {version}'), ['version' => (string)$f['app_version']])],
			]],
		]];
	}

	/** @param array<string,mixed> $f */
	private function purpose(array $f): array {
		/** @var array{jobs:bool,budgets:bool,customers:bool} $m */
		$m = $f['modules'];
		$items = [
			$this->l->t('Recording working time and assigning it to projects'),
			$this->l->t('Vacation and absences'),
			$this->l->t('Target hours and balance per person'),
			$this->l->t('Capacity of the team'),
		];
		if ($m['jobs']) {
			$items[] = $this->fill($this->l->t('{jobs}: shares of a project or work package offered to people of the team (module switched on)'), ['jobs' => JobWord::JOB . 's']);
		}
		if ($m['budgets']) {
			$items[] = $this->l->t('Budgets: planned hours per project and comparison with the bookings (module switched on)');
		}
		if ($m['customers']) {
			$items[] = $this->l->t('Customers: the customer book with contact persons (module switched on)');
		}
		return ['title' => $this->l->t('Purpose'), 'blocks' => [
			['type' => 'p', 'text' => $this->l->t('TimeSister processes personal data for these purposes only:')],
			['type' => 'list', 'items' => $items],
		]];
	}

	/** @param array<string,mixed> $f */
	private function categories(array $f): array {
		/** @var list<string> $kinds */
		$kinds = $f['absence_kinds'];
		$labels = array_map(fn (string $k) => AbsenceRules::label($this->l, $k), $kinds);
		$mirror = $f['vacation_calendar'] === true
			? $this->fill($this->l->t('mirrored as all-day events (category · initials name) into the team’s vacation calendar for the categories {kinds}, only for people who have not left or opted out'), ['kinds' => $this->join($labels)])
			: $this->l->t('no shared vacation calendar is set up');
		$externals = (int)$f['externals'];
		$rows = [
			[$this->l->t('Master data of the person'),
				$this->l->t('Name, initials, Nextcloud account, entry and exit, workload, location and holiday region, vacation entitlement, carry-over and corrections, colour'),
				RoleName::TEAM_ADMIN,
				$this->l->t('App tables of the Nextcloud (record with history)')],
			[$this->l->t('Time data'),
				$this->l->t('Events of the time calendar (start, end, title with project code, notes), bookings per project, billable and billed marks'),
				$this->l->t('The person (Mac app, Apple Calendar, phone)'),
				$this->l->t('CalDAV calendar “TimeSister – name” in the person’s account')],
			[$this->l->t('Absences and vacation'),
				$this->fill($this->l->t('Events with an absence code; {mirror}'), ['mirror' => $mirror]),
				$this->l->t('The person'),
				$this->l->t('Own time calendar; vacation calendar in a Team Admin’s account')],
			[$this->fill($this->l->t('Projects, customers, budgets, {jobs}'), ['jobs' => JobWord::JOB . 's']),
				$this->fill($this->l->t('Operating data; customers may hold contact persons (name, e-mail, phone) – third parties. Currently {n} customer records.'), ['n' => (int)$f['customers']]),
				$this->l->t('Team Admins and Leads'),
				$this->l->t('App tables of the Nextcloud')],
			[$this->l->t('External persons'),
				$this->fill($this->l->t('Name, initials, private feed address (a secret); their events are read, never written. Currently {n}.'), ['n' => $externals]),
				RoleName::TEAM_ADMIN,
				$this->l->t('App tables; the feed address only for those who may see it')],
			[$this->l->t('Technical data'),
				$this->fill($this->l->t('Sign of life of the Mac app (version, last sync, calendar address), consent to backups, notifications ({jobs}, shares)'), ['jobs' => JobWord::JOB . 's']),
				$this->l->t('The Mac app'),
				$this->l->t('App tables; Nextcloud notifications')],
		];
		return ['title' => $this->l->t('Which data, which categories'), 'blocks' => [
			['type' => 'table', 'head' => [$this->l->t('Category'), $this->l->t('Examples'), $this->l->t('Source'), $this->l->t('Location')], 'rows' => $rows],
			['type' => 'p', 'text' => $this->l->t('Not recorded: hourly rates, salaries, expenses, location data, health data – the absence “Sickness” is only a category without a reason.')],
		]];
	}

	/** @param array<string,mixed> $f */
	private function locations(array $f): array {
		/** @var array{hosting:array{provider:string,location:string}} $s */
		$s = $f['settings'];
		$hosting = trim($s['hosting']['provider'] . ($s['hosting']['location'] === '' ? '' : ', ' . $s['hosting']['location']));
		return ['title' => $this->l->t('Where the data is stored'), 'blocks' => [
			['type' => 'list', 'items' => [
				$this->fill($this->l->t('Nextcloud of the team: {hosting}'), ['hosting' => $this->value($hosting)]),
				$this->l->t('Time calendars in each person’s Nextcloud account (CalDAV)'),
				$this->l->t('App tables of TimeSister (ts_*) for master data with history, roles, shares, status, absences, consents'),
				$this->l->t('Protected app storage of the Nextcloud for backups'),
				$this->l->t('On every Mac a local copy: SQLite under ~/Library/Application Support/TimeSister, the app password in the keychain, backups in the folder “Backups”'),
				$this->l->t('Transport only over https (plain text only to localhost)'),
			]],
		]];
	}

	/** @param array<string,mixed> $f */
	private function access(array $f): array {
		/** @var list<string> $admins */
		$admins = $f['admins'];
		/** @var list<array{uid:string,name:string,projects:list<string>}> $leads */
		$leads = $f['leads'];
		/** @var list<array{uid:string,name:string,role:string,sees:list<string>,edits:list<string>}> $matrix */
		$matrix = $f['matrix'];
		$leadRows = array_map(fn (array $ld) => [$ld['name'], $this->join($ld['projects'])], $leads);
		$matrixRows = array_map(fn (array $m) => [$m['name'], RoleName::of($m['role']), $this->join($m['sees']), $this->join($m['edits'])], $matrix);
		return ['title' => $this->l->t('Who has access'), 'blocks' => [
			['type' => 'list', 'items' => [
				$this->l->t('Nextcloud admins: everything, including the accounts and this notice'),
				$this->fill($this->l->t('Team Admins (group admins of the team group): all master data, all time calendars according to the shares matrix, backups, export, deletion. Currently: {names}'), ['names' => $this->join($admins)]),
				$this->fill($this->l->t('Leads: their projects, the bookings of everyone on these projects, time calendars according to the shares matrix, the customer book. Currently: {names}'), ['names' => $this->join(array_map(static fn (array $ld) => $ld['name'], $leads))]),
				$this->l->t('Users: their own data; the team’s capacity with the initials of everyone'),
				$this->l->t('External persons: nothing – their feed is only read'),
			]],
			['type' => 'table', 'head' => [RoleName::LEAD, $this->l->t('Projects')], 'rows' => $leadRows],
			['type' => 'table', 'head' => [$this->l->t('Person'), $this->l->t('Role'), $this->l->t('sees the time calendar of'), $this->l->t('edits the time calendar of')], 'rows' => $matrixRows],
		]];
	}

	/** @param array<string,mixed> $f */
	private function backups(array $f): array {
		/** @var ?array{uid:string,name:string} $owner */
		$owner = $f['backup_owner'];
		/** @var array{yes:int,no:int} $c */
		$c = $f['consents'];
		return ['title' => $this->l->t('Backups'), 'blocks' => [
			['type' => 'list', 'items' => [
				$this->fill($this->l->t('Weekly backup of the time calendar per person, only with consent: protected in the app data and visible in the folder “{folder}” of the backup owner. Currently: {owner}'), ['folder' => BackupRules::VISIBLE_ROOT, 'owner' => $owner === null ? $this->l->t('no backup owner') : $owner['name']]),
				$this->fill($this->l->t('Consents: {yes} given, {no} not given'), ['yes' => $c['yes'], 'no' => $c['no']]),
				$this->fill($this->l->t('Team ZIP (manual, before importing and before deleting a team) under “{folder}”'), ['folder' => BackupRules::VISIBLE_ROOT . '/' . BackupRules::TEAM_FOLDER]),
				$this->fill($this->l->t('Thinning: every backup of the last {weeks} weeks, then one per month for {months} months, then one per year for {years} years'), ['weeks' => Thinning::WEEKS, 'months' => Thinning::MONTHS, 'years' => Thinning::JAHRE]),
				$this->l->t('Local copies on the Macs of the people (folder “Backups”)'),
			]],
		]];
	}

	/** @param array<string,mixed> $f */
	private function retention(array $f): array {
		/** @var array{retention:array{time_years:int,billing_years:int,note:string},law:string} $s */
		$s = $f['settings'];
		$law = [];
		if ($s['law'] !== 'eu') {
			$law[] = $this->l->t('Switzerland: working time must be recorded (ArG Art. 46 with ArGV 1 Art. 73) and the records kept for 5 years; accounting records for 10 years (OR Art. 958f). DSG Art. 6 (proportionality), Art. 19 (information), Art. 25 (access), Art. 32 (rectification and erasure – except where the law requires retention).');
		}
		if ($s['law'] !== 'ch') {
			$law[] = $this->l->t('EU: GDPR Art. 6(1)(b) and (c) (contract, legal obligation), Art. 13 (information), Art. 15 (access), Art. 17(3)(b) (no erasure while a legal obligation applies), Art. 5(1)(e) (storage limitation), Art. 30 (records of processing), Art. 32 (security).');
		}
		$period = $this->fill($this->l->t('Retention periods of this team: time records {time} years, billing records {billing} years.'), ['time' => $s['retention']['time_years'], 'billing' => $s['retention']['billing_years']]);
		if ($s['retention']['note'] !== '') {
			$period .= ' ' . $s['retention']['note'];
		}
		return ['title' => $this->l->t('Retention and deletion'), 'blocks' => [
			['type' => 'p', 'text' => $this->l->t('Legal basis (template):')],
			['type' => 'list', 'items' => $law],
			['type' => 'p', 'text' => $period],
			['type' => 'p', 'text' => $this->l->t('What can be deleted, and how:')],
			['type' => 'list', 'items' => [
				$this->l->t('Leaving: the person stays as a record, the share of the time calendar is withdrawn'),
				$this->l->t('Events: the person deletes them in their own time calendar'),
				$this->l->t('Account: only a Nextcloud admin deletes it'),
				$this->l->t('Team: a Nextcloud admin deletes it – a ZIP is stored first, then everything goes from the app tables; calendars and accounts stay'),
				$this->l->t('Backups: thinning is automatic; the files with the backup owner are deleted by hand'),
				$this->l->t('Local copies: every person on their own Mac (“Remove everything local”)'),
			]],
			['type' => 'p', 'text' => $this->fill($this->l->t('Not deleted while the period runs: time data and master data for {time} years; afterwards a Team Admin deletes them.'), ['time' => $s['retention']['time_years']])],
		]];
	}

	/** @param array<string,mixed> $f */
	private function rights(array $f): array {
		/** @var array{authority:string,law:string} $s */
		$s = $f['settings'];
		$authority = $s['authority'];
		if ($authority === '') {
			$authority = $this->blank() . ($s['law'] !== 'eu' ? ' – ' . $this->l->t('Switzerland: Federal Data Protection and Information Commissioner (FDPIC)') : '');
		}
		return ['title' => $this->l->t('Rights of the persons'), 'blocks' => [
			['type' => 'list', 'items' => [
				$this->l->t('Access: export of the own data – the team ZIP holds each person’s time calendar and record; the Mac app exports the own time as PDF or CSV'),
				$this->l->t('Rectification: time entries in the own calendar; master data through a Team Admin'),
				$this->l->t('Erasure: see “Retention and deletion”'),
				$this->l->t('Objection to backups: withdraw the consent in the Mac app'),
				$this->fill($this->l->t('Complaint to the supervisory authority: {authority}'), ['authority' => $authority]),
			]],
		]];
	}

	/** @param array<string,mixed> $f */
	private function note(array $f): array {
		return ['title' => $this->l->t('Note'), 'blocks' => [
			['type' => 'note', 'text' => $this->fill($this->l->t('Template from the settings of the team “{team}” on {date}; no legal advice. The controller checks and completes this notice.'), ['team' => (string)$f['team']['name'], 'date' => (string)$f['date']])],
		]];
	}
}
