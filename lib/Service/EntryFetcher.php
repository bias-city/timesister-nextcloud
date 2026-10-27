<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\TenantMapper;
use OCA\TimeSister\Db\TimeCalendar;
use OCA\TimeSister\Db\TimeCalendarMapper;
use OCA\TimeSister\Db\EntryMapper;
use OCA\TimeSister\Db\RecordMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Calendar\CalendarExportOptions;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarExport;
use OCP\Calendar\IManager;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * The net under the calendar events (0.17.0): full reads of named
 * calendars – through the dav backend by etag when it is there, else
 * through `ICalendarExport` by content – and the feeds of external
 * persons. Run by the job `EntryReader`; the immediate read after
 * naming (`PUT /me/time-calendar`) runs here too. Calendars of members
 * who left are skipped.
 */
final class EntryFetcher {
	/** A full read at least once a day, even without a mark. */
	public const FULL_READ_AFTER = 86400;

	public function __construct(
		private TimeCalendarMapper $calendars,
		private EntryMapper $entries,
		private TenantMapper $tenants,
		private RecordMapper $records,
		private EntryService $service,
		private TenantService $team,
		private IManager $calendarManager,
		private IClientService $http,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Every source of every team that is due. One failing does not stop
	 * the others; the error stays on the source.
	 *
	 * @return array{read:int,failed:int,changed:int}
	 */
	public function readAll(): array {
		$n = ['read' => 0, 'failed' => 0, 'changed' => 0];
		$now = $this->time->getTime();
		foreach ($this->tenants->findAll() as $t) {
			$this->nameByConvention($t->getId());
			$this->syncFeeds($t->getId());
			$since = EntryService::since($t);
			foreach ($this->calendars->findByTenant($t->getId()) as $src) {
				if (!$this->service->active($src)) {
					$this->skipLeft($src);
					continue;
				}
				if (!$this->due($src, $now)) {
					continue;
				}
				try {
					$changed = $src->getSource() === TimeCalendar::FEED ? $this->readFeed($src, $since) : $this->readCalendar($src, $since);
					$n['read']++;
					$n['changed'] += $changed;
				} catch (\Throwable $e) {
					$n['failed']++;
					$src->setLastError(EntryService::readFailed());
					$this->calendars->update($src);
					$this->logger->warning('TimeSister: time entries of team {team} ({source}) not read', [
						'app' => 'timesister', 'team' => $t->getId(), 'source' => $src->getSource(), 'exception' => $e,
					]);
				}
			}
		}
		return $n;
	}

	/**
	 * The member left: the calendar is not read, the row stays with
	 * `last_error` «member left» and is marked, so the first run after the
	 * person is back reads it in full.
	 */
	private function skipLeft(TimeCalendar $src): void {
		if ($src->getLastError() === EntryRules::LEFT && $src->getReread() === 1) {
			return;
		}
		$src->setLastError(EntryRules::LEFT);
		$src->setReread(1);
		$this->calendars->update($src);
	}

	/** Active members without a named time calendar: `zeit-<uid>`, if it exists. */
	private function nameByConvention(int $tenantId): void {
		// Purely numeric identifiers come as int keys.
		foreach (array_map('strval', array_keys($this->team->memberRoles($tenantId))) as $uid) {
			try {
				$this->service->nameByConvention($tenantId, $uid);
			} catch (\Throwable $e) {
				$this->logger->warning('TimeSister: time calendar of {uid} not named by convention', [
					'app' => 'timesister', 'uid' => $uid, 'exception' => $e,
				]);
			}
		}
	}

	/** Calendars: marked, never read, or a day old. Feeds: every run. */
	private function due(TimeCalendar $src, int $now): bool {
		if ($src->getSource() === TimeCalendar::FEED || $src->getReread() === 1) {
			return true;
		}
		$last = $src->getLastFullReadAt();
		return $last === null || $last < $now - self::FULL_READ_AFTER;
	}

	// ------------------------------------------------------------ calendar

	/**
	 * `PUT /me/time-calendar`: names the calendar and reads it at once
	 * when it is small enough ({@see readNow}).
	 *
	 * @return array{uid:string,calendar_uri:string,calendar_id:int,named_at:?string,read_now:bool,count:int}
	 */
	public function name(Membership $m, \stdClass $body): array {
		$row = $this->service->name($m, $body);
		return $this->service->presentNamed($row) + $this->readNow($row);
	}

	/**
	 * The immediate read after naming: with the dav backend and at most
	 * `READ_NOW_MAX` objects the calendar is read in full right now;
	 * otherwise (or when it fails – the error stays on the row) the job
	 * reads it within minutes. Nothing happens when no read is pending.
	 *
	 * @return array{read_now:bool,count:int}
	 */
	public function readNow(TimeCalendar $src): array {
		$done = false;
		$backend = $src->getReread() === 1 && $src->getSource() === TimeCalendar::CALENDAR ? $this->backend() : null;
		if ($backend !== null) {
			try {
				$listed = $backend->getCalendarObjects((int)$src->getCalendarId());
				if (count($listed) <= EntryRules::READ_NOW_MAX) {
					$t = $this->tenants->find($src->getTenantId());
					if ($t !== null) {
						$this->readCalendar($src, EntryService::since($t), $listed);
						$done = true;
					}
				}
			} catch (\Throwable $e) {
				$src->setLastError(EntryService::readFailed());
				$this->calendars->update($src);
				$this->logger->warning('TimeSister: time calendar of {uid} not read after naming', ['app' => 'timesister', 'uid' => $src->getUid(), 'exception' => $e]);
			}
		}
		return ['read_now' => $done, 'count' => $this->service->countOf($src)];
	}

	/**
	 * A full read of one named calendar.
	 *
	 * @param ?list<array<string,mixed>> $listed the backend's object list, when the caller has it already
	 * @return int changed rows
	 */
	public function readCalendar(TimeCalendar $src, string $since, ?array $listed = null): int {
		$cal = $this->calendar($src);
		if ($cal === null) {
			throw new \RuntimeException('The named calendar is not there any more.');
		}
		$id = (int)$cal->getKey();
		if ($id !== $src->getCalendarId()) {
			// Deleted and created again under the same URI: follow it.
			$src->setCalendarId($id);
		}
		$backend = $this->backend();
		$result = $backend !== null
			? $this->readWithBackend($backend, $src, $id, $since, $listed)
			: $this->readWithExport($cal, $src, $since);
		$now = $this->time->getTime();
		$src->setLastReadAt($now);
		$src->setLastFullReadAt($now);
		$src->setReread(0);
		$src->setLastError($result['skipped'] === [] ? null : 'Skipped, larger than 256 KB: ' . implode(', ', array_slice($result['skipped'], 0, 20)));
		$this->calendars->update($src);
		return $result['changed'];
	}

	/**
	 * By etag: only objects whose etag is new are loaded; live rows whose
	 * etag still matches count as unchanged.
	 *
	 * @param ?list<array<string,mixed>> $listed
	 * @return array{changed:int,skipped:list<string>}
	 */
	private function readWithBackend(CalDavBackend $backend, TimeCalendar $src, int $calendarId, string $since, ?array $listed = null): array {
		$listed ??= $backend->getCalendarObjects($calendarId);
		if (count($listed) > EntryRules::MAX_OBJECTS) {
			throw new \RuntimeException('More than 100 000 objects in the calendar.');
		}
		$byUri = [];
		foreach ($this->entries->bySource($src->getTenantId(), $src->getUid(), $src->getSource()) as $e) {
			if ($e->getObjectUri() !== '') {
				$byUri[$e->getObjectUri()] = $e;
			}
		}
		$unchanged = [];
		$fetch = [];
		foreach ($listed as $row) {
			$uri = (string)($row['uri'] ?? '');
			$etag = EntryRules::bareEtag($row['etag'] ?? '');
			if ($uri === '') {
				continue;
			}
			$e = $byUri[$uri] ?? null;
			if ($e !== null && $e->getEtag() === $etag) {
				// Unchanged, but a moved cutoff may have put it outside: then it is neither
				// unchanged nor fetched, so the full read lets it vanish.
				if (EntryRules::withinCutoff($e->getLastEnd(), $since)) {
					$unchanged[] = $e->getEventUid();
				}
			} else {
				$fetch[] = $uri;
			}
		}
		$objects = [];
		foreach (array_chunk($fetch, 200) as $uris) {
			foreach ($backend->getMultipleCalendarObjects($calendarId, $uris) as $row) {
				$data = $row['calendardata'] ?? null;
				if (!is_string($data)) {
					continue;
				}
				$objects[] = ['ics' => $data, 'uri' => (string)($row['uri'] ?? ''), 'etag' => EntryRules::bareEtag($row['etag'] ?? '')];
			}
		}
		return $this->service->apply($src, $objects, $since, true, $unchanged);
	}

	/**
	 * Without the backend: every object through the public export, compared
	 * by a hash of its text. Object URIs are unknown here.
	 *
	 * @return array{changed:int,skipped:list<string>}
	 */
	private function readWithExport(ICalendar $cal, TimeCalendar $src, string $since): array {
		if (!($cal instanceof ICalendarExport)) {
			throw new \RuntimeException('The calendar cannot be exported.');
		}
		$objects = [];
		foreach ($cal->export(new CalendarExportOptions()) as $vcal) {
			$ics = (string)$vcal->serialize();
			$objects[] = ['ics' => $ics, 'uri' => '', 'etag' => 'sha256:' . hash('sha256', $ics)];
			if (count($objects) > EntryRules::MAX_OBJECTS) {
				throw new \RuntimeException('More than 100 000 objects in the calendar.');
			}
		}
		return $this->service->apply($src, $objects, $since, true, []);
	}

	/** The named calendar in the account's own home, or null. */
	private function calendar(TimeCalendar $src): ?ICalendar {
		$uri = (string)$src->getCalendarUri();
		if ($uri === '') {
			return null;
		}
		foreach ($this->calendarManager->getCalendarsForPrincipal('principals/users/' . $src->getUid(), [$uri]) as $cal) {
			if ($cal->getUri() === $uri && !$cal->isDeleted()) {
				return $cal;
			}
		}
		return null;
	}

	/** The CalDAV backend of the dav app, or null if it is not there. */
	private function backend(): ?CalDavBackend {
		try {
			return \OCP\Server::get(CalDavBackend::class);
		} catch (\Throwable) {
			return null;
		}
	}

	// ---------------------------------------------------------------- feed

	/**
	 * The feed rows follow the person records: every live person with an
	 * http(s) `feed` has one; a changed address is read again; a feed that
	 * is gone tombstones its entries.
	 */
	private function syncFeeds(int $tenantId): void {
		$wanted = [];
		$persons = $this->records->findLiveByKind($tenantId, 'person');
		foreach ($persons as $p) {
			$url = self::feedOf($p);
			if ($url !== null) {
				$wanted[$p->getRkey()] = $url;
			}
		}
		$now = $this->time->getTime();
		foreach ($this->calendars->findByTenant($tenantId) as $row) {
			if ($row->getSource() !== TimeCalendar::FEED) {
				continue;
			}
			$url = $wanted[$row->getUid()] ?? null;
			unset($wanted[$row->getUid()]);
			if ($url === null) {
				$this->service->vanishAll($tenantId, $row->getUid(), TimeCalendar::FEED);
				$this->calendars->delete($row);
			} elseif ($row->getFeedUrl() !== $url) {
				$row->setFeedUrl($url);
				$row->setEtag(null);
				$row->setReread(1);
				$this->calendars->update($row);
			}
		}
		// Over the records again: a purely numeric key would come back as an int from the map.
		foreach ($persons as $p) {
			$url = $wanted[$p->getRkey()] ?? null;
			if ($url === null) {
				continue;
			}
			unset($wanted[$p->getRkey()]);
			$row = new TimeCalendar();
			$row->setTenantId($tenantId);
			$row->setUid($p->getRkey());
			$row->setSource(TimeCalendar::FEED);
			$row->setFeedUrl($url);
			$row->setNamedAt($now);
			$row->setReread(1);
			$this->calendars->insert($row);
		}
	}

	private static function feedOf(Record $p): ?string {
		try {
			$d = Json::decode($p->getData() ?? 'null');
		} catch (\JsonException) {
			return null;
		}
		$url = $d instanceof \stdClass && isset($d->feed) && is_string($d->feed) ? trim($d->feed) : null;
		return $url !== null && EntryRules::isFeedUrl($url) ? $url : null;
	}

	/**
	 * One feed: `GET` with `If-None-Match`; `304` ends it. Otherwise the
	 * text is split per UID and applied like a full read.
	 *
	 * @return int changed rows
	 */
	public function readFeed(TimeCalendar $src, string $since): int {
		$url = (string)$src->getFeedUrl();
		if (!EntryRules::isFeedUrl($url)) {
			throw new \RuntimeException('The feed address is not http(s).');
		}
		$headers = ['Accept' => 'text/calendar, */*;q=0.5'];
		$etag = $src->getEtag();
		if ($etag !== null && $etag !== '' && $src->getReread() === 0) {
			$headers['If-None-Match'] = $etag;
		}
		$response = $this->http->newClient()->get($url, [
			'timeout' => EntryRules::FEED_TIMEOUT,
			'connect_timeout' => 10,
			'headers' => $headers,
			'stream' => true,
		]);
		$now = $this->time->getTime();
		$status = $response->getStatusCode();
		if ($status === 304) {
			$src->setLastReadAt($now);
			$src->setLastError(null);
			$this->calendars->update($src);
			return 0;
		}
		if ($status !== 200) {
			throw new \RuntimeException('The feed answered ' . $status . '.');
		}
		$body = self::readBody($response->getBody());
		if (!BackupRules::isCalendar($body)) {
			// A login page or an error text with 200: the entries stay as they are.
			throw new \RuntimeException('The feed is not an iCalendar file.');
		}
		$newEtag = trim($response->getHeader('ETag'));
		if ($newEtag === '') {
			$newEtag = 'sha256:' . hash('sha256', $body);
		}
		$changed = 0;
		if ($src->getReread() === 1 || $newEtag !== $etag) {
			$objects = [];
			foreach (EntryRules::splitFeed($body) as $ics) {
				$objects[] = ['ics' => $ics, 'uri' => '', 'etag' => 'sha256:' . hash('sha256', $ics)];
				if (count($objects) > EntryRules::MAX_OBJECTS) {
					throw new \RuntimeException('More than 100 000 objects in the feed.');
				}
			}
			$r = $this->service->apply($src, $objects, $since, true, []);
			$changed = $r['changed'];
			$src->setLastError($r['skipped'] === [] ? null : 'Skipped, larger than 256 KB: ' . count($r['skipped']) . ' object(s)');
		} else {
			$src->setLastError(null);
		}
		$src->setEtag($newEtag);
		$src->setLastReadAt($now);
		$src->setLastFullReadAt($now);
		$src->setReread(0);
		$this->calendars->update($src);
		return $changed;
	}

	/** At most 20 MB, from a stream or a string. */
	private static function readBody(mixed $body): string {
		if (is_resource($body)) {
			$out = '';
			while (!feof($body) && strlen($out) <= EntryRules::FEED_MAX) {
				$chunk = fread($body, 65536);
				if ($chunk === false) {
					break;
				}
				$out .= $chunk;
			}
			fclose($body);
		} elseif (is_string($body)) {
			$out = $body;
		} else {
			throw new \RuntimeException('The feed has no body.');
		}
		if (strlen($out) > EntryRules::FEED_MAX) {
			throw new \RuntimeException('The feed is larger than 20 MB.');
		}
		return $out;
	}
}
