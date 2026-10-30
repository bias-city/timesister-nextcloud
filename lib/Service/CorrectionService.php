<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\Db\Correction;
use OCA\TimeSister\Db\CorrectionMapper;
use OCA\TimeSister\Db\Entry;
use OCA\TimeSister\Db\EntryMapper;
use OCA\TimeSister\Db\InvHourMapper;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\TimeCalendar;
use OCA\TimeSister\Db\TimeCalendarMapper;
use OCA\TimeSister\Notification\Notifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

/**
 * Corrections of bookings (0.27.0): the one exception to «the server never
 * writes into a named calendar». A Team Admin or the project's Lead sends
 * title, code, billable and/or level for a booking – the person the level
 * of their own in a budget by category (0.33.0) –; the app rewrites the
 * calendar object through the dav backend (the same for own and foreign
 * bookings), stores it in the book at once and notes who did it when.
 *
 * Two ways in, one service: `POST /entries/correct` (a change for a list,
 * online) and the record `correction` (K-Offline: the target state of one
 * booking, written through the records API – applied as the record is
 * stored, outcome in the record). A record may name a `recurrence`
 * (K-Serie): then only that occurrence's exception changes.
 *
 * A corrected field is the lead's (decided 2026-10-21): when the book reads
 * the event again and a corrected field differs, the correction is written
 * back ({@see reapply()}), noted, and the person is told.
 * Rules: {@see CorrectionRules}.
 */
final class CorrectionService {
	/** `GET /entries/corrections?limit=` default and maximum. */
	public const PAGE = 500;
	public const NOTE_APPLIED = 'applied';
	public const NOTE_REAPPLIED = 'reapplied';

	/** @var array<int,array<int,array{key:string,version:int,data:\stdClass}>> live correction records per team, this request */
	private array $cache = [];
	/** @var array<int,list<\stdClass>> the live projects per team, this request (the person's right on a level) */
	private array $projects = [];
	private bool $reapplying = false;

	public function __construct(
		private EntryMapper $entries,
		private TimeCalendarMapper $calendars,
		private EntryService $service,
		private InvHourMapper $hours,
		private CorrectionMapper $log,
		private RecordMapper $records,
		private TenantService $team,
		private IManager $notifications,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * `POST /entries/correct`: one change for a list of bookings; every
	 * booking gets its own result – `ok`, `locked`, `forbidden`,
	 * `not_found` or `failed` – so one refused booking never stops the rest.
	 *
	 * @return array{results:list<array<string,mixed>>}
	 */
	public function correct(Membership $m, \stdClass $body): array {
		$p = CorrectionRules::parseBody($body);
		$personal = CorrectionRules::levelOnly($p['change']);
		if ($m->role !== Role::LEAD && !$m->manages()) {
			// 0.33.0: a member corrects the level of their own bookings in a budget by category, nothing else –
			// every booking checked before any is written; one not in the book answers not_found.
			if (!$personal) {
				throw CorrectionRules::leadOnly(array_values(array_intersect(array_keys($p['change']), CorrectionRules::LEAD_FIELDS))[0] ?? 'level');
			}
			foreach ($p['entries'] as $t) {
				if ($t['uid'] !== $m->uid) {
					throw CorrectionRules::leadOnly('uid');
				}
			}
			foreach ($p['entries'] as $t) {
				$e = $this->entries->findByEvent($m->tenantId, $t['uid'], $t['event_uid']);
				if ($e !== null && !$this->levelFree($m->tenantId, $e, null)) {
					throw CorrectionRules::leadOnly('level');
				}
			}
		}
		$led = $m->role === Role::LEAD ? $this->service->ledCodesOf($m) : [];
		$backend = $this->backend();
		$results = [];
		foreach ($p['entries'] as $t) {
			$results[] = $this->one($m, $led, $backend, $t['uid'], $t['event_uid'], $p['change'], null, $personal);
		}
		return ['results' => $results];
	}


	/**
	 * The record `correction`, inside the records transaction (K-Offline):
	 * rights and lock checked, the target state written into the event, the
	 * outcome (`status`, `message`, `by`, `at`, `etag`, `codes`) in the data
	 * that gets stored. A member's write is checked before
	 * ({@see CorrectionRules::requirePersonal()}, {@see requireLevelRight()}); `$before` is the
	 * stored record's data. The person's own write (level only against the
	 * stored record) keeps the Lead's fields it leaves out.
	 *
	 * @param list<string> $ownKeys
	 */
	public function prepare(Membership $m, string $key, \stdClass $data, ?\stdClass $before = null, array $ownKeys = []): \stdClass {
		$stored = CorrectionRules::appliedOnly($before);
		$personal = in_array($data->person ?? null, $ownKeys, true) && CorrectionRules::otherThanLevel($stored, $data) === null;
		if ($personal && !$m->manages()) {
			$data = CorrectionRules::keepLeadFields($stored, $data);
		}
		$change = CorrectionRules::changeOf($data);
		$recurrence = CorrectionRules::recurrenceOf($data);
		$person = (string)$data->person;
		$eventUid = (string)$data->event_uid;
		$now = $this->time->getTime();
		[$uid, $entry] = $this->entryOf($m->tenantId, $person, $eventUid);
		if ($entry === null) {
			return CorrectionRules::withServerFields($data, $m->uid, $now,
				['status' => 'not_found', 'message' => Message::of('Booking {n} is not in the book.', ['n' => $eventUid])->text()]);
		}
		$led = $m->role === Role::LEAD ? $this->service->ledCodesOf($m) : [];
		// The record being written is the truth: while its change goes into the event, the stored
		// version of this correction must not be reapplied over it (the listener would write the
		// record a second time in this transaction - duplicate version in the history, 500).
		$this->reapplying = true;
		try {
			$r = $this->one($m, $led, $this->backend(), $uid, $eventUid, $change, $recurrence, $personal);
		} finally {
			$this->reapplying = false;
		}
		$fields = $r['status'] === 'ok'
			? ['status' => CorrectionRules::APPLIED, 'etag' => $r['etag'] ?? '', 'codes' => $r['codes'] ?? []]
			: ['status' => (string)$r['status'], 'message' => (string)($r['message'] ?? '')];
		return CorrectionRules::withServerFields($data, $m->uid, $now, $fields);
	}

	/**
	 * The person's right on the level of their own booking (0.33.0, the
	 * record): the booking falls into a budget in the model `category`, by
	 * its codes and day in the book and the stored projects. A booking not
	 * in the book does not block (the record answers `not_found`).
	 *
	 * @throws ApiException 403 `correction_lead_only` (`field: level`)
	 */
	public function requireLevelRight(Membership $m, string $person, string $eventUid, ?string $recurrence): void {
		[, $entry] = $this->entryOf($m->tenantId, $person, $eventUid);
		if ($entry !== null && !$this->levelFree($m->tenantId, $entry, $recurrence)) {
			throw CorrectionRules::leadOnly('level');
		}
	}

	/** Does the booking fall into a budget in the model `category` (every code, on its day)? */
	private function levelFree(int $tenantId, Entry $e, ?string $recurrence): bool {
		$day = CorrectionRules::bookingDay($e->getFirstStart(), $recurrence);
		if ($day === null) {
			return false;
		}
		if (!isset($this->projects[$tenantId])) {
			$this->projects[$tenantId] = [];
			foreach ($this->records->findLiveByKind($tenantId, 'project') as $r) {
				$d = InvoiceRules::decode($r->getData());
				if ($d !== null) {
					$this->projects[$tenantId][] = $d;
				}
			}
		}
		return FeeModelRules::inCategoryModel($this->projects[$tenantId], $e->codeList(), $day);
	}

	/**
	 * The account and the entry of a person's booking: the person's key is
	 * an account, or names accounts in its person record.
	 *
	 * @return array{0:string,1:?Entry}
	 */
	private function entryOf(int $tenantId, string $person, string $eventUid): array {
		$uids = [$person];
		$p = $this->records->findOne($tenantId, 'person', $person);
		if ($p !== null && !$p->isTombstone()) {
			foreach ($p->accountList() as $a) {
				if (!in_array($a, $uids, true)) {
					$uids[] = $a;
				}
			}
		}
		foreach ($uids as $uid) {
			$e = $this->entries->findByEvent($tenantId, $uid, $eventUid);
			if ($e !== null) {
				return [$uid, $e];
			}
		}
		return [$person, null];
	}

	/**
	 * `$personal`: the person's own correction of the level (0.33.0) –
	 * allowed on a booking of the caller's own account in any role when it
	 * falls into a budget in the model `category`.
	 *
	 * @param list<string> $led
	 * @param array<string,mixed> $change
	 * @return array<string,mixed>
	 */
	private function one(Membership $m, array $led, ?CalDavBackend $backend, string $uid, string $eventUid, array $change, ?string $recurrence, bool $personal = false): array {
		$r = ['uid' => $uid, 'event_uid' => $eventUid];
		$e = $this->entries->findByEvent($m->tenantId, $uid, $eventUid);
		if ($e === null) {
			return $r + ['status' => 'not_found', 'message' => Message::of('Booking {n} is not in the book.', ['n' => $eventUid])->text()];
		}
		if (!CorrectionRules::allowed($m->role, $led, $e->codeList(), isset($change['code']) ? (string)$change['code'] : null)
			&& !($personal && $uid === $m->uid && $this->levelFree($m->tenantId, $e, $recurrence))) {
			return $r + ['status' => 'forbidden', 'message' => Message::of('Only Team Admins and the project’s Leads correct bookings.')->text()];
		}
		$lock = CorrectionRules::lockedBy($this->hours->billingsOf($m->tenantId, $uid, $eventUid), $this->service->billed($e));
		if ($lock !== null) {
			$msg = $lock === '' ? Message::of('Booking {n} is billed.', ['n' => $eventUid]) : Message::of('Booking {n} is billed by invoice {number}.', ['n' => $eventUid, 'number' => $lock]);
			return $r + ['status' => 'locked', 'message' => $msg->text()];
		}
		if ($e->getKept() === 1) {
			return $r + ['status' => 'not_found', 'message' => Message::of('The event is no longer in the calendar.')->text()];
		}
		if ($e->getSource() === TimeCalendar::LIST) {
			return $this->oneInList($m, $r, $uid, $eventUid, $change, $recurrence);
		}
		if ($e->getSource() !== TimeCalendar::CALENDAR) {
			return $r + ['status' => 'failed', 'message' => Message::of('Entries of a feed cannot be corrected.')->text()];
		}
		if ($backend === null) {
			return $r + ['status' => 'failed', 'message' => Message::of('The calendar backend is not available.')->text()];
		}
		$src = $this->calendars->findOne($m->tenantId, $uid, TimeCalendar::CALENDAR);
		if ($src === null || $src->getCalendarId() === null || $e->getObjectUri() === '') {
			return $r + ['status' => 'failed', 'message' => Message::of('The person has no named time calendar.')->text()];
		}
		try {
			$calendarId = (int)$src->getCalendarId();
			$obj = $backend->getCalendarObject($calendarId, $e->getObjectUri());
			$ics = is_array($obj) ? ($obj['calendardata'] ?? null) : null;
			if (!is_string($ics) || $ics === '') {
				return $r + ['status' => 'not_found', 'message' => Message::of('The event is no longer in the calendar.')->text()];
			}
			// Idempotent: the target state is already there – nothing to write, nothing to note.
			if (CorrectionRules::met($ics, $change, $recurrence)) {
				return $r + ['status' => 'ok', 'etag' => EntryRules::bareEtag($obj['etag'] ?? $e->getEtag()), 'revision' => $e->getRevision(),
					'codes' => $e->codeList(), 'level' => $e->getLevel(), 'ics' => $ics];
			}
			$now = $this->time->getTime();
			$new = CorrectionRules::apply($ics, $change, gmdate('Ymd\THis\Z', $now), $recurrence);
			$etag = EntryRules::bareEtag($backend->updateCalendarObject($calendarId, $e->getObjectUri(), $new));
			// The listener has stored it already; this makes sure, and is a no-op on the same etag.
			$this->service->storeObject($src, $new, $e->getObjectUri(), $etag);
			$this->note($m->tenantId, $uid, $eventUid, $m->uid, $change, $now, $recurrence, self::NOTE_APPLIED);
			$after = $this->entries->findByEvent($m->tenantId, $uid, $eventUid) ?? $e;
			return $r + ['status' => 'ok', 'etag' => $etag, 'revision' => $after->getRevision(), 'codes' => $after->codeList(),
				'level' => $after->getLevel(), 'ics' => $new];
		} catch (\Throwable $x) {
			// The cause (also a database message) stays in the log; the client gets a plain text.
			$this->logger->warning('TimeSister: correction not written', ['app' => 'timesister', 'uid' => $uid, 'event_uid' => $eventUid, 'exception' => $x]);
			return $r + ['status' => 'failed', 'message' => Message::of('The correction could not be written; see the server log.')->text()];
		}
	}

	/**
	 * 0.28.0: a booking of an external person - the book is its source, so
	 * the change goes into the entry itself; nothing to reapply later.
	 *
	 * @param array<string,mixed> $r
	 * @param array<string,mixed> $change
	 * @return array<string,mixed>
	 */
	private function oneInList(Membership $m, array $r, string $uid, string $eventUid, array $change, ?string $recurrence): array {
		$full = $this->entries->findOne($m->tenantId, $uid, TimeCalendar::LIST, $eventUid);
		$ics = $full?->getIcs() ?? '';
		if ($ics === '') {
			return $r + ['status' => 'not_found', 'message' => Message::of('Booking {n} is not in the book.', ['n' => $eventUid])->text()];
		}
		if (!CorrectionRules::met($ics, $change, $recurrence)) {
			$now = $this->time->getTime();
			$ics = CorrectionRules::apply($ics, $change, gmdate('Ymd\THis\Z', $now), $recurrence);
			$this->service->putList($m->tenantId, $uid, $ics);
			$this->note($m->tenantId, $uid, $eventUid, $m->uid, $change, $now, $recurrence, self::NOTE_APPLIED);
		}
		$after = $this->entries->findOne($m->tenantId, $uid, TimeCalendar::LIST, $eventUid);
		if ($after === null) {
			return $r + ['status' => 'not_found', 'message' => Message::of('Booking {n} is not in the book.', ['n' => $eventUid])->text()];
		}
		return $r + ['status' => 'ok', 'etag' => $after->getEtag(), 'revision' => $after->getRevision(),
			'codes' => $after->codeList(), 'level' => $after->getLevel(), 'ics' => $ics];
	}

	/** @param array<string,mixed> $change */
	private function note(int $tenantId, string $uid, string $eventUid, string $actor, array $change, int $now, ?string $recurrence, string $kind): void {
		$row = new Correction();
		$row->setTenantId($tenantId);
		$row->setUid($uid);
		$row->setEventUid($eventUid);
		$row->setActor($actor);
		$row->setAt($now);
		$row->setChanges(json_encode($change, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		$row->setRecurrence($recurrence);
		$row->setKind($kind);
		$this->log->insert($row);
	}

	// ----------------------------------------------------------- reapply

	/**
	 * **Lists sent again keep the lead's corrections** (0.28.0): every applied
	 * correction of this person and event is laid onto the incoming object
	 * before the book stores it; what it already carries stays as it is.
	 * The `correction` records come first, then the protocol's notes in
	 * their order - `POST /entries/correct` leaves only those -, so the
	 * newest state of every field wins.
	 */
	public function foldInto(int $tenantId, string $uid, string $eventUid, string $ics): string {
		$changes = [];
		foreach ($this->correctionsFor($tenantId, $uid, $eventUid) as $c) {
			$data = $c['data'];
			if (($data->status ?? null) === CorrectionRules::APPLIED) {
				$changes[] = [$data, CorrectionRules::recurrenceOf($data)];
			}
		}
		foreach (array_reverse($this->log->find($tenantId, $uid, $eventUid, self::PAGE)) as $note) {
			try {
				$data = Json::decode($note->getChanges());
			} catch (\JsonException) {
				continue;
			}
			if ($data instanceof \stdClass) {
				$changes[] = [$data, $note->getRecurrence()];
			}
		}
		foreach ($changes as [$data, $recurrence]) {
			try {
				$change = CorrectionRules::changeOf($data);
			} catch (ApiException) {
				continue;
			}
			if (!CorrectionRules::met($ics, $change, $recurrence)) {
				$ics = CorrectionRules::apply($ics, $change, gmdate('Ymd\THis\Z', $this->time->getTime()), $recurrence);
			}
		}
		return $ics;
	}

	/**
	 * **A corrected field is the lead's.** After the book stored a live entry
	 * of a named calendar: every applied correction of this person and event
	 * whose target state the event no longer carries (changed in another
	 * calendar app) is written back, noted as `reapplied`, counted on the
	 * record, and the person gets a notification. Uncorrected fields, date,
	 * start and duration stay as the person wrote them. Called by
	 * {@see EntryService::apply()}; the write it causes stores again and
	 * finds everything met.
	 */
	public function reapply(TimeCalendar $src, Entry $e): void {
		if ($this->reapplying || $src->getSource() !== TimeCalendar::CALENDAR || !$e->isLive() || $src->getCalendarId() === null) {
			return;
		}
		$list = $this->correctionsFor($src->getTenantId(), $src->getUid(), $e->getEventUid());
		if ($list === []) {
			return;
		}
		$backend = $this->backend();
		if ($backend === null) {
			return;
		}
		$this->reapplying = true;
		try {
			foreach ($list as $i => $c) {
				$data = $c['data'];
				if (($data->status ?? null) !== CorrectionRules::APPLIED) {
					continue;
				}
				try {
					$change = CorrectionRules::changeOf($data);
				} catch (ApiException) {
					continue;
				}
				$recurrence = CorrectionRules::recurrenceOf($data);
				$calendarId = (int)$src->getCalendarId();
				$obj = $backend->getCalendarObject($calendarId, $e->getObjectUri());
				$ics = is_array($obj) ? ($obj['calendardata'] ?? null) : null;
				if (!is_string($ics) || $ics === '' || CorrectionRules::met($ics, $change, $recurrence)) {
					continue;
				}
				$now = $this->time->getTime();
				$new = CorrectionRules::apply($ics, $change, gmdate('Ymd\THis\Z', $now), $recurrence);
				$etag = EntryRules::bareEtag($backend->updateCalendarObject($calendarId, $e->getObjectUri(), $new));
				$this->service->storeObject($src, $new, $e->getObjectUri(), $etag);
				$by = is_string($data->by ?? null) && $data->by !== '' ? (string)$data->by : 'system';
				$this->note($src->getTenantId(), $src->getUid(), $e->getEventUid(), $by, $change, $now, $recurrence, self::NOTE_REAPPLIED);
				$after = $this->entries->findByEvent($src->getTenantId(), $src->getUid(), $e->getEventUid()) ?? $e;
				$this->cache[$src->getTenantId()][$i]['version'] = $this->bump($src->getTenantId(), $c, $by, $etag, $after->codeList(), $now);
				$this->notify($src->getUid(), $c['key'], $by, $after->getSummary() !== '' ? $after->getSummary() : $e->getEventUid());
			}
		} catch (\Throwable $x) {
			$this->logger->warning('TimeSister: correction not restored', ['app' => 'timesister', 'uid' => $src->getUid(), 'event_uid' => $e->getEventUid(), 'exception' => $x]);
		} finally {
			$this->reapplying = false;
		}
	}

	/**
	 * The live correction records of this account's bookings of one event –
	 * the account itself and the person keys naming it. Loaded once per team
	 * and request.
	 *
	 * @return array<int,array{key:string,version:int,data:\stdClass}> index as in the cache
	 */
	private function correctionsFor(int $tenantId, string $uid, string $eventUid): array {
		if (!isset($this->cache[$tenantId])) {
			$this->cache[$tenantId] = [];
			foreach ($this->records->findLiveByKind($tenantId, CorrectionRules::KIND) as $r) {
				$d = InvoiceRules::decode($r->getData());
				if ($d instanceof \stdClass) {
					$this->cache[$tenantId][] = ['key' => $r->getRkey(), 'version' => $r->getVersion(), 'data' => $d];
				}
			}
		}
		if ($this->cache[$tenantId] === []) {
			return [];
		}
		$persons = [$uid];
		foreach ($this->records->findPersonsOf($uid, $tenantId, true) as $p) {
			if (!in_array($p->getRkey(), $persons, true)) {
				$persons[] = $p->getRkey();
			}
		}
		$out = [];
		foreach ($this->cache[$tenantId] as $i => $c) {
			if (($c['data']->event_uid ?? null) === $eventUid && in_array($c['data']->person ?? null, $persons, true)) {
				$out[$i] = $c;
			}
		}
		return $out;
	}

	/**
	 * The record after a reapply: `reapplied` counted up, the new etag – a
	 * system write with the lead's name on it.
	 *
	 * @param array{key:string,version:int,data:\stdClass} $c
	 * @param list<string> $codes
	 * @return int the record's version afterwards
	 */
	private function bump(int $tenantId, array $c, string $by, string $etag, array $codes, int $now): int {
		$data = CorrectionRules::withServerFields($c['data'], $by, $now, [
			'status' => CorrectionRules::APPLIED, 'etag' => $etag, 'codes' => $codes,
			'reapplied' => (int)($c['data']->reapplied ?? 0) + 1,
		]);
		// The first application's time stays; `reapplied_at` says when it was restored.
		$data->at = is_string($c['data']->at ?? null) ? $c['data']->at : $data->at;
		$m = new Membership($by, $tenantId, Role::ADMIN);
		$out = $this->recordService()->writeAsServer($m, CorrectionRules::KIND, $c['key'], $c['version'], $data);
		return (int)($out['version'] ?? $c['version'] + 1);
	}

	/** Tell the person: the lead's correction holds again. One per booking; a newer replaces the older. */
	private function notify(string $uid, string $key, string $by, string $title): void {
		if ($uid === '' || $uid === $by) {
			return;
		}
		try {
			$base = fn (): INotification => $this->notifications->createNotification()->setApp(Application::APP_ID)->setUser($uid)->setObject(Notifier::CORRECTION_OBJECT, substr($key, 0, 64));
			$this->notifications->markProcessed($base());
			$n = $base()->setSubject(CorrectionMessages::REAPPLIED, ['title' => mb_substr($title, 0, 120), 'user' => $this->team->displayName($by)])
				->setDateTime(new \DateTime('@' . $this->time->getTime()));
			$this->notifications->notify($n);
		} catch (\Throwable $e) {
			$this->logger->warning('TimeSister: correction notification not sent: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}

	/**
	 * `GET /entries/corrections?uid=&event_uid=&limit=`: the protocol,
	 * newest first – Team Admins all of it, Leads the bookings they see.
	 *
	 * @return array{corrections:list<array<string,mixed>>}
	 */
	public function list(Membership $m, ?string $uid, ?string $eventUid, int $limit): array {
		if ($m->role !== Role::LEAD && !$m->manages()) {
			throw ApiException::forbidden('Only Team Admins and the project’s Leads read corrections.');
		}
		$limit = max(1, min(self::PAGE, $limit));
		$uid = $uid !== null && trim($uid) !== '' ? trim($uid) : null;
		$eventUid = $eventUid !== null && trim($eventUid) !== '' ? trim($eventUid) : null;
		$led = $m->role === Role::LEAD ? $this->service->ledCodesOf($m) : [];
		$out = [];
		$seen = [];
		foreach ($this->log->find($m->tenantId, $uid, $eventUid, $limit) as $c) {
			if ($m->role === Role::LEAD) {
				$k = $c->getUid() . "\0" . $c->getEventUid();
				if (!array_key_exists($k, $seen)) {
					$e = $this->entries->findByEvent($m->tenantId, $c->getUid(), $c->getEventUid());
					$seen[$k] = $e !== null && EntryRules::visible($m->role, $m->uid, $led, $e->getUid(), $e->codeList());
				}
				if (!$seen[$k]) {
					continue;
				}
			}
			$out[] = $c->present();
		}
		return ['corrections' => $out];
	}

	/** The CalDAV backend of the dav app, or null if it is not there. */
	private function backend(): ?CalDavBackend {
		try {
			return \OCP\Server::get(CalDavBackend::class);
		} catch (\Throwable) {
			return null;
		}
	}

	/** The record service – fetched late: it depends on this one. */
	private function recordService(): RecordService {
		return \OCP\Server::get(RecordService::class);
	}
}
