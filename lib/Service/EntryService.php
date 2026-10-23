<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Entry;
use OCA\TimeSister\Db\EntryMapper;
use OCA\TimeSister\Db\InvHourMapper;
use OCA\TimeSister\Db\Tenant;
use OCA\TimeSister\Db\TenantMapper;
use OCA\TimeSister\Db\TimeCalendar;
use OCA\TimeSister\Db\TimeCalendarMapper;
use OCA\TimeSister\Db\RecordMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Calendar\ICalendar;
use OCP\Calendar\IManager;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * The book of time entries (0.17.0): naming the time calendar, storing
 * calendar objects as received (one row per object, a revision per team),
 * tombstones and `kept` when an object vanishes, and the role-filtered
 * delta for the clients. Full reads and feeds: {@see EntryFetcher}.
 */
final class EntryService {
	/** `GET /entries?limit=` default and maximum. */
	public const PAGE = 2000;

	public function __construct(
		private TimeCalendarMapper $calendars,
		private EntryMapper $entries,
		private TenantMapper $tenants,
		private TenantService $team,
		private RecordMapper $records,
		private IManager $calendarManager,
		private IDBConnection $db,
		private ITimeFactory $time,
		private DocumentService $documents,
		private InvHourMapper $hours,
	) {
	}

	// -------------------------------------------------------------- naming

	/**
	 * `PUT /me/time-calendar`: the calendar must be one of the account's
	 * own. The same calendar again changes nothing; another one tombstones
	 * the old entries and marks the new calendar for a full read. The
	 * answer (with the immediate read) is built by {@see EntryFetcher::name}.
	 */
	public function name(Membership $m, \stdClass $body): TimeCalendar {
		$uri = EntryRules::calendarUri($body->calendar_url ?? null);
		if ($uri === null) {
			throw ApiException::invalid('“calendar_url” must be the CalDAV URL or the URI of one of your calendars.');
		}
		return $this->nameCalendar($m->tenantId, $m->uid, $uri);
	}

	/**
	 * Names `$uri` as the time calendar of `$uid` in the team (also the
	 * team ZIP import). 404 when the account has no such calendar.
	 */
	public function nameCalendar(int $tenantId, string $uid, string $uri): TimeCalendar {
		$cal = $this->ownCalendar($uid, $uri);
		if ($cal === null) {
			throw ApiException::notFound('This account has no such calendar.');
		}
		$id = (int)$cal->getKey();
		$now = $this->time->getTime();
		$row = $this->calendars->findOne($tenantId, $uid, TimeCalendar::CALENDAR);
		if ($row !== null && $row->getCalendarId() === $id) {
			return $row;
		}
		$new = $row === null;
		if ($row !== null) {
			$this->vanishAll($tenantId, $uid, TimeCalendar::CALENDAR);
		} else {
			$row = new TimeCalendar();
			$row->setTenantId($tenantId);
			$row->setUid($uid);
			$row->setSource(TimeCalendar::CALENDAR);
		}
		$row->setCalendarId($id);
		$row->setCalendarUri($uri);
		$row->setNamedAt($now);
		$row->setLastReadAt(null);
		$row->setLastFullReadAt(null);
		$row->setLastError(null);
		$row->setEtag(null);
		$row->setReread(1);
		return $new ? $this->calendars->insert($row) : $this->calendars->update($row);
	}

	/**
	 * Naming by convention: a member who has not named a time calendar gets
	 * `zeit-<uid>` – the calendar the Mac app creates itself – as soon as it
	 * exists. The person can still name another one. Nothing else of the
	 * account's calendars is looked at.
	 */
	public function nameByConvention(int $tenantId, string $uid): ?TimeCalendar {
		if ($this->calendars->findOne($tenantId, $uid, TimeCalendar::CALENDAR) !== null) {
			return null;
		}
		$uri = 'zeit-' . $uid;
		$cal = $this->ownCalendar($uid, $uri);
		if ($cal === null) {
			return null;
		}
		$row = new TimeCalendar();
		$row->setTenantId($tenantId);
		$row->setUid($uid);
		$row->setSource(TimeCalendar::CALENDAR);
		$row->setCalendarId((int)$cal->getKey());
		$row->setCalendarUri($uri);
		$row->setNamedAt($this->time->getTime());
		$row->setReread(1);
		return $this->calendars->insert($row);
	}

	/**
	 * `DELETE /me/time-calendar`: the entries become tombstones, kept ones stay.
	 *
	 * @return array{uid:string,removed:bool}
	 */
	public function unname(Membership $m): array {
		$row = $this->calendars->findOne($m->tenantId, $m->uid, TimeCalendar::CALENDAR);
		if ($row === null) {
			return ['uid' => $m->uid, 'removed' => false];
		}
		$this->vanishAll($m->tenantId, $m->uid, TimeCalendar::CALENDAR);
		$this->calendars->delete($row);
		return ['uid' => $m->uid, 'removed' => true];
	}

	/** The account's own calendar by URI, or null. */
	private function ownCalendar(string $uid, string $uri): ?ICalendar {
		foreach ($this->calendarManager->getCalendarsForPrincipal('principals/users/' . $uid, [$uri]) as $cal) {
			if ($cal->getUri() === $uri && !$cal->isDeleted() && (int)$cal->getKey() > 0) {
				return $cal;
			}
		}
		return null;
	}

	/** @return array{uid:string,calendar_uri:string,calendar_id:int,named_at:?string} */
	public function presentNamed(TimeCalendar $row): array {
		return ['uid' => $row->getUid(), 'calendar_uri' => (string)$row->getCalendarUri(), 'calendar_id' => (int)$row->getCalendarId(), 'named_at' => Time::iso($row->getNamedAt())];
	}

	/** Objects of one source in the book (not tombstones). */
	public function countOf(TimeCalendar $row): int {
		return $this->entries->countBySource($row->getTenantId())[$row->getSource() . '/' . $row->getUid()] ?? 0;
	}

	/**
	 * Whether a named calendar's member is active in the team. The
	 * calendar of a member who left is not read and not followed by the
	 * listener; the entries stay in the book (billed hours). Feeds are
	 * never affected.
	 */
	public function active(TimeCalendar $row): bool {
		if ($row->getSource() !== TimeCalendar::CALENDAR) {
			return true;
		}
		return isset($this->team->memberRoles($row->getTenantId())[$row->getUid()]);
	}

	/**
	 * `time_calendar` of GET /me.
	 *
	 * @return ?array{calendar_uri:?string,calendar_id:?int,named_at:?string,last_read_at:?string,last_error:?string}
	 */
	public function mine(Membership $m): ?array {
		$row = $this->calendars->findOne($m->tenantId, $m->uid, TimeCalendar::CALENDAR);
		if ($row === null) {
			return null;
		}
		return [
			'calendar_uri' => $row->getCalendarUri(),
			'calendar_id' => $row->getCalendarId(),
			'named_at' => Time::iso($row->getNamedAt()),
			'last_read_at' => Time::iso($row->getLastReadAt()),
			'last_error' => $row->getLastError(),
		];
	}

	/**
	 * The named calendars of the team by account, for GET /status.
	 *
	 * @return array<string,TimeCalendar>
	 */
	public function namedByUid(int $tenantId): array {
		$out = [];
		foreach ($this->calendars->findByTenant($tenantId) as $row) {
			if ($row->getSource() === TimeCalendar::CALENDAR) {
				$out[$row->getUid()] = $row;
			}
		}
		return $out;
	}

	/**
	 * `GET /entries/status` – Team Admins.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function statusList(Membership $m): array {
		$this->requireAdmin($m);
		$counts = $this->entries->countBySource($m->tenantId);
		$out = [];
		foreach ($this->calendars->findByTenant($m->tenantId) as $row) {
			$out[] = [
				'uid' => $row->getUid(),
				'source' => $row->getSource(),
				'calendar_uri' => $row->getCalendarUri(),
				'named_at' => Time::iso($row->getNamedAt()),
				'last_read_at' => Time::iso($row->getLastReadAt()),
				'last_full_read_at' => Time::iso($row->getLastFullReadAt()),
				'last_error' => $row->getLastError(),
				'count' => $counts[$row->getSource() . '/' . $row->getUid()] ?? 0,
				// 0.17.0: the member left the team; the calendar is not read until they are back.
				'left' => !$this->active($row),
			];
		}
		return $out;
	}

	/**
	 * `POST /entries/reread` – Team Admins.
	 *
	 * @return array{marked:int}
	 */
	public function markReread(Membership $m): array {
		$this->requireAdmin($m);
		return ['marked' => $this->calendars->markReread($m->tenantId)];
	}

	private function requireAdmin(Membership $m): void {
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins may do that.');
		}
	}

	// -------------------------------------------------------------- cutoff

	/** The effective cutoff of a team. */
	public static function since(Tenant $t): string {
		return EntryRules::effectiveSince($t->getEntriesSince(), $t->getCreatedAt());
	}

	/**
	 * `PUT /team/settings` (Team Admins): `entries_since` (a change marks
	 * every source for a full read), since 0.18.0 `invoicing`, since
	 * 0.19.0 `documents` (the folders are checked with the backup owner),
	 * since 0.25.1 `catalogs`.
	 *
	 * @return array<string,mixed> the settings as in GET /team
	 */
	public function setSince(Membership $m, \stdClass $body): array {
		$this->requireAdmin($m);
		$t = $this->team->tenant($m->tenantId);
		$settings = TeamRules::settings(['settings' => get_object_vars($body)], $t->getDocumentsJson(), $t->getWebhooksJson());
		if (array_key_exists('backup_required', $settings)) {
			throw ApiException::invalid(Message::of('Unknown team setting “{key}”.', ['key' => 'backup_required']));
		}
		if ($settings === []) {
			throw ApiException::missing('entries_since');
		}
		if (array_key_exists('invoicing', $settings)) {
			$t->setInvoicing($settings['invoicing'] === true ? 1 : 0);
			$this->tenants->update($t);
		}
		if (is_array($settings[DocumentRules::FIELD] ?? null)) {
			$this->documents->requireFolders($this->team->backupOwner($t), $settings[DocumentRules::FIELD], $t->getName());
			$t->setDocumentsJson(DocumentRules::encode($settings[DocumentRules::FIELD]));
			$this->tenants->update($t);
		}
		if (is_array($settings[ExportRules::FIELD] ?? null)) {
			$t->setDatevJson(ExportRules::encodeDatev($settings[ExportRules::FIELD]));
			$this->tenants->update($t);
		}
		if (array_key_exists(InvoicingModeRules::FIELD, $settings)) {
			$t->setInvoicingModeJson(InvoicingModeRules::encode($settings[InvoicingModeRules::FIELD]));
			$this->tenants->update($t);
		}
		if (array_key_exists(CatalogRules::FIELD, $settings)) {
			$t->setCatalogsJson(CatalogRules::encode($settings[CatalogRules::FIELD]));
			$this->tenants->update($t);
		}
		if (array_key_exists(WebhookRules::FIELD, $settings) && is_array($settings[WebhookRules::FIELD])) {
			$t->setWebhooksJson(WebhookRules::encode($settings[WebhookRules::FIELD]));
			$this->tenants->update($t);
		}
		if (array_key_exists('entries_since', $settings)) {
			$v = $settings['entries_since'];
			$this->applySince($t, is_string($v) ? $v : null);
		}
		return TenantService::settingsOf($t);
	}

	/** Stores a cutoff; a change marks every source for a full read. */
	public function applySince(Tenant $t, ?string $since): void {
		if ($t->getEntriesSince() === $since) {
			return;
		}
		$t->setEntriesSince($since);
		$this->tenants->update($t);
		$this->calendars->markReread($t->getId());
	}

	// ---------------------------------------------------------------- page

	/**
	 * `GET /entries?since=&limit=`: in revision order, filtered by role.
	 * Two steps (0.28.0): the rows come without `ics` and pass the role
	 * filter chunk by chunk ({@see EntryRules::pageOf()}), then only the
	 * texts of the page's hits are read - a Lead's delta no longer pulls
	 * the whole book through memory.
	 *
	 * @return array{revision:int,entries:list<array<string,mixed>>,more:bool}
	 */
	public function page(Membership $m, int $since, int $limit): array {
		$limit = max(1, min(self::PAGE, $limit));
		$since = max(0, $since);
		$led = $m->role === Role::LEAD ? $this->ledCodesOf($m) : [];
		$page = EntryRules::pageOf(
			fn (int $cursor): array => $this->entries->findChanged($m->tenantId, $cursor, $limit),
			static fn (Entry $e): bool => EntryRules::visible($m->role, $m->uid, $led, $e->getUid(), $e->codeList()),
			$since,
			$limit,
		);
		$ids = [];
		foreach ($page['entries'] as $e) {
			if ($e->getDeleted() === 0) {
				$ids[] = $e->getId();
			}
		}
		$texts = $ids === [] ? [] : $this->entries->icsByIds($ids);
		$out = [];
		foreach ($page['entries'] as $e) {
			$e->setIcs($texts[$e->getId()] ?? '');
			$out[] = $e->present();
		}
		// Two entries never share a revision, so a cut page continues cleanly with the highest one delivered.
		return ['revision' => $this->tenants->entriesRevision($m->tenantId), 'entries' => $out, 'more' => $page['more']];
	}

	/**
	 * The codes of the projects the caller leads, with their subprojects.
	 *
	 * @return list<string>
	 */
	public function ledCodesOf(Membership $m): array {
		$data = [];
		foreach ($this->team->leadOf($m->tenantId, $m->uid) as $p) {
			$r = $this->records->findOne($m->tenantId, 'project', $p['key']);
			$raw = $r?->getData();
			if ($r === null || $r->isTombstone() || $raw === null) {
				continue;
			}
			try {
				$data[] = Json::decode($raw);
			} catch (\JsonException) {
				continue;
			}
		}
		return EntryRules::ledCodes($data);
	}

	// --------------------------------------------------------------- lists

	/**
	 * `POST /entries/list` (0.28.0, Team Admins): the entries of an external
	 * person - no account, no calendar - written straight into the book.
	 * `put` stores or replaces by event UID, `delete` tombstones. Billed or
	 * locked entries stay as they are, like everywhere in the book; every
	 * item gets its own result (`ok`, `locked`, `failed`).
	 *
	 * @return array{results:list<array<string,mixed>>}
	 */
	public function writeList(Membership $m, \stdClass $body): array {
		$this->requireAdmin($m);
		$p = EntryRules::parseListBody($body);
		$uid = $p['uid'];
		// External: a live person record that is no member and names no member as
		// account (the Mac app lists the record's own key under `accounts`).
		$person = $this->records->findOne($m->tenantId, 'person', $uid);
		$members = $this->team->memberRoles($m->tenantId);
		$named = $person === null ? [] : array_filter($person->accountList(), static fn (string $a): bool => isset($members[$a]));
		if ($person === null || $person->isTombstone() || isset($members[$uid]) || $named !== []) {
			throw ApiException::invalid(Message::of('{uid} is no external person of the team.', ['uid' => $uid]));
		}
		$results = [];
		$corrections = $this->corrections();
		// Everything the book takes goes in one apply (one transaction, one read of the rows);
		// a billed or foreign booking answers for itself and stays out.
		$objects = [];
		$pending = [];
		foreach ($p['put'] as $ics) {
			$ev = EntryRules::parse($ics)['uid'] ?? '';
			$r = ['event_uid' => $ev];
			$other = $this->entries->findByEvent($m->tenantId, $uid, $ev);
			if ($other !== null && $other->getSource() !== TimeCalendar::LIST) {
				$results[] = $r + ['status' => 'failed', 'message' => Message::of('Booking {n} comes from a calendar or feed.', ['n' => $ev])->text()];
				continue;
			}
			$lock = $this->lockOf($m->tenantId, $uid, $ev);
			if ($lock !== null) {
				$results[] = $r + ['status' => 'locked', 'message' => $lock];
				continue;
			}
			// A corrected field is the lead's: a list sent again does not undo it.
			if ($corrections !== null) {
				$ics = $corrections->foldInto($m->tenantId, $uid, $ev, $ics);
			}
			$objects[] = $ics;
			$pending[] = $ev;
		}
		if ($objects !== []) {
			$this->putListAll($m->tenantId, $uid, $objects);
		}
		$rows = $this->entries->bySource($m->tenantId, $uid, TimeCalendar::LIST);
		foreach ($pending as $ev) {
			$results[] = ['event_uid' => $ev] + self::stateFrom($rows[$ev] ?? null);
		}
		foreach ($p['delete'] as $ev) {
			$r = ['event_uid' => $ev];
			$other = $this->entries->findByEvent($m->tenantId, $uid, $ev);
			if ($other !== null && $other->getSource() !== TimeCalendar::LIST && $other->isLive()) {
				$results[] = $r + ['status' => 'failed', 'message' => Message::of('Booking {n} comes from a calendar or feed.', ['n' => $ev])->text()];
				continue;
			}
			$e = $rows[$ev] ?? null;
			if ($e === null || !$e->isLive()) {
				$results[] = $r + ['status' => 'ok', 'deleted' => true];
				continue;
			}
			$lock = $this->lockOf($m->tenantId, $uid, $ev);
			if ($lock !== null) {
				$results[] = $r + ['status' => 'locked', 'message' => $lock];
				continue;
			}
			$e->setDeleted(1);
			$e->setIcs('');
			$e->setModifiedAt($this->time->getTime());
			$this->commit($m->tenantId, [[$e, false]]);
			$results[] = $r + ['status' => 'ok', 'deleted' => true];
		}
		return ['results' => $results];
	}

	/** One object of an external person into the book - also for a correction ({@see CorrectionService}). */
	public function putList(int $tenantId, string $uid, string $ics): void {
		$this->putListAll($tenantId, $uid, [$ics]);
	}

	/**
	 * Objects of an external person into the book, one transaction; the etag
	 * is the hash of the text, so the same object sent again changes nothing.
	 *
	 * @param list<string> $icsList
	 */
	public function putListAll(int $tenantId, string $uid, array $icsList): void {
		$t = $this->tenants->find($tenantId);
		if ($t === null) {
			throw ApiException::notFound('No such team.');
		}
		$src = new TimeCalendar();
		$src->setTenantId($tenantId);
		$src->setUid($uid);
		$src->setSource(TimeCalendar::LIST);
		$objects = array_map(static fn (string $ics): array => ['ics' => $ics, 'uri' => '', 'etag' => 'sha256:' . hash('sha256', $ics)], $icsList);
		$this->apply($src, $objects, self::since($t), false, []);
	}

	/** Billed (a `billing` record) or on an invoice: the message, else null. */
	private function lockOf(int $tenantId, string $uid, string $eventUid): ?string {
		$e = $this->entries->findOne($tenantId, $uid, TimeCalendar::LIST, $eventUid);
		$lock = CorrectionRules::lockedBy($this->hours->billingsOf($tenantId, $uid, $eventUid), $e !== null && $e->isLive() && $this->billed($e));
		if ($lock === null) {
			return null;
		}
		return ($lock === '' ? Message::of('Booking {n} is billed.', ['n' => $eventUid])
			: Message::of('Booking {n} is billed by invoice {number}.', ['n' => $eventUid, 'number' => $lock]))->text();
	}

	/** @return array{status:string,etag:string,revision:int,deleted?:bool} */
	private static function stateFrom(?Entry $e): array {
		if ($e === null || !$e->isLive()) {
			// Outside the cutoff: the book does not keep it.
			return ['status' => 'ok', 'etag' => '', 'revision' => 0, 'deleted' => true];
		}
		return ['status' => 'ok', 'etag' => $e->getEtag(), 'revision' => $e->getRevision()];
	}

	// ------------------------------------------------------------- storing

	/**
	 * One object as it comes from the calendar (listener) – stored when it
	 * changed and is within the cutoff. Oversized objects are skipped and
	 * noted in `last_error`.
	 */
	public function storeObject(TimeCalendar $src, string $ics, string $objectUri, string $etag): void {
		$t = $this->tenants->find($src->getTenantId());
		if ($t === null || !$this->active($src)) {
			return;
		}
		$this->apply($src, [['ics' => $ics, 'uri' => $objectUri, 'etag' => EntryRules::bareEtag($etag)]], self::since($t), false, []);
	}

	/** One object left the calendar (deleted, trashed, moved away): tombstone or kept. */
	public function vanishObject(TimeCalendar $src, string $objectUri, ?string $ics): void {
		if (!$this->active($src)) {
			return;
		}
		$uid = $ics === null ? null : (EntryRules::parse($ics)['uid'] ?? null);
		$rows = $this->entries->bySource($src->getTenantId(), $src->getUid(), $src->getSource());
		$hit = null;
		foreach ($rows as $e) {
			if (($uid !== null && $e->getEventUid() === $uid) || ($objectUri !== '' && $e->getObjectUri() === $objectUri)) {
				$hit = $e;
				break;
			}
		}
		if ($hit !== null) {
			$this->commit($src->getTenantId(), [[$this->vanished($hit), false]]);
		}
	}

	/**
	 * Every live entry of a source becomes a tombstone or kept (un-named, feed gone).
	 *
	 * @return int changed
	 */
	public function vanishAll(int $tenantId, string $uid, string $source): int {
		$changes = [];
		foreach ($this->entries->bySource($tenantId, $uid, $source) as $e) {
			$changes[] = [$this->vanished($e), false];
		}
		$this->commit($tenantId, $changes);
		return count($changes);
	}

	/**
	 * Objects of one source against the stored rows. With `$full`, live
	 * rows that are neither in `$objects` nor in `$unchanged` vanish.
	 * Every change gets its own revision, all in one transaction.
	 *
	 * @param list<array{ics:string,uri:string,etag:string}> $objects
	 * @param list<string> $unchanged event UIDs the caller found unchanged (full read by etag)
	 * @return array{changed:int,skipped:list<string>}
	 */
	public function apply(TimeCalendar $src, array $objects, string $since, bool $full, array $unchanged): array {
		$tenantId = $src->getTenantId();
		$rows = $this->entries->bySource($tenantId, $src->getUid(), $src->getSource(), true);
		$now = $this->time->getTime();
		$changes = [];
		$seen = array_fill_keys($unchanged, true);
		$skipped = [];
		foreach ($objects as $o) {
			if (strlen($o['ics']) > EntryRules::MAX_OBJECT) {
				$skipped[] = $o['uri'] !== '' ? $o['uri'] : '(feed object)';
				continue;
			}
			$p = EntryRules::parse($o['ics']);
			if ($p === null) {
				continue;
			}
			$seen[$p['uid']] = true;
			$e = $rows[$p['uid']] ?? null;
			if (!EntryRules::withinCutoff($p['last_end'], $since)) {
				if ($e !== null && $e->isLive()) {
					$changes[] = [$this->vanished($e), false];
				}
				continue;
			}
			if ($e !== null && $e->isLive() && $e->getEtag() === $o['etag']) {
				continue;
			}
			if ($e !== null && $e->isLive() && $this->sameIcs($e, $o['ics'])) {
				// Another etag for the same text (export fallback): no new revision.
				continue;
			}
			$new = $e === null;
			$e ??= new Entry();
			if ($new) {
				$e->setTenantId($tenantId);
				$e->setUid($src->getUid());
				$e->setSource($src->getSource());
				$e->setEventUid($p['uid']);
			}
			$e->setObjectUri($o['uri']);
			$e->setEtag($o['etag']);
			$e->setIcs($o['ics']);
			$e->setSummary($p['summary']);
			$e->setCodes(implode(',', $p['codes']));
			$e->setFirstStart($p['first_start']);
			$e->setLastEnd($p['last_end']);
			$e->setCancelled($p['cancelled'] ? 1 : 0);
			$e->setLevel($p['level']);
			$e->setKept(0);
			$e->setDeleted(0);
			$e->setModifiedAt($now);
			$changes[] = [$e, $new];
		}
		if ($full) {
			foreach ($rows as $uid => $e) {
				if ($e->isLive() && !isset($seen[$uid])) {
					$changes[] = [$this->vanished($e), false];
				}
			}
		}
		$this->commit($tenantId, $changes);
		// A corrected field is the lead's (0.27.0): what the person changed elsewhere is set back.
		if ($changes !== [] && $src->getSource() === TimeCalendar::CALENDAR) {
			$corrections = $this->corrections();
			foreach ($changes as [$c]) {
				if ($corrections !== null && $c->isLive()) {
					$corrections->reapply($src, $c);
				}
			}
		}
		return ['changed' => count($changes), 'skipped' => $skipped];
	}

	/** The correction service – fetched late: it depends on this one. */
	private function corrections(): ?CorrectionService {
		try {
			return \OCP\Server::get(CorrectionService::class);
		} catch (\Throwable) {
			return null;
		}
	}

	private function sameIcs(Entry $e, string $ics): bool {
		$full = $this->entries->findOne($e->getTenantId(), $e->getUid(), $e->getSource(), $e->getEventUid());
		return $full !== null && $full->getIcs() === $ics;
	}

	/** The vanished state of a live entry: kept when billed, else a tombstone without text. */
	private function vanished(Entry $e): Entry {
		if (EntryRules::keepsVanished($this->billed($e))) {
			$e->setKept(1);
		} else {
			$e->setDeleted(1);
			$e->setIcs('');
		}
		$e->setModifiedAt($this->time->getTime());
		return $e;
	}

	/** A live `billing` record for this person and event UID? Checked at the moment the object vanishes. */
	public function billed(Entry $e): bool {
		$persons = [$e->getUid()];
		foreach ($this->records->findPersonsOf($e->getUid(), $e->getTenantId(), true) as $r) {
			if (!in_array($r->getRkey(), $persons, true)) {
				$persons[] = $r->getRkey();
			}
		}
		foreach ($persons as $p) {
			$r = $this->records->findOne($e->getTenantId(), BillingRules::KIND, BillingRules::key($p, $e->getEventUid()));
			if ($r !== null && $r->getDeleted() === 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Writes the changes with consecutive revisions in one transaction;
	 * the team row is locked by the counter update, so two writers never
	 * get the same revision. A row written by the listener and the job at
	 * once: the second insert fails on the unique index and is retried as
	 * an update.
	 *
	 * @param list<array{0:Entry,1:bool}> $changes entry and whether it is new
	 */
	private function commit(int $tenantId, array $changes): void {
		if ($changes === []) {
			return;
		}
		for ($attempt = 1; ; $attempt++) {
			try {
				$this->commitOnce($tenantId, $changes);
				return;
			} catch (DbException $e) {
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION || $attempt >= 2) {
					throw $e;
				}
				foreach ($changes as $i => [$c, $new]) {
					if ($new) {
						$found = $this->entries->findOne($c->getTenantId(), $c->getUid(), $c->getSource(), $c->getEventUid());
						if ($found !== null) {
							$c->setId($found->getId());
							$changes[$i] = [$c, false];
						}
					}
				}
			}
		}
	}

	/** @param list<array{0:Entry,1:bool}> $changes */
	private function commitOnce(int $tenantId, array $changes): void {
		$this->db->beginTransaction();
		try {
			$rev = $this->tenants->bumpEntriesRevision($tenantId, count($changes));
			$first = $rev - count($changes) + 1;
			foreach ($changes as $i => [$c, $new]) {
				$c->setRevision($first + $i);
				$new ? $this->entries->insert($c) : $this->entries->update($c);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/** The error text kept on a source: short, one line. */
	public static function errorText(\Throwable $e): string {
		$text = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? $e->getMessage());
		return mb_substr($text === '' ? get_class($e) : $text, 0, 500);
	}
}
