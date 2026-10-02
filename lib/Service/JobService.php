<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\History;
use OCA\TimeSister\Db\HistoryMapper;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\TenantMapper;
use OCA\TimeSister\Notification\Notifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * Jobs as records of kind `job` (0.6.0): every transition in one
 * transaction under the team's lock (the revision row), so two people
 * accepting at the same time are served one after the other – the first
 * wins. Rules in {@see JobRules}, transitions in {@see JobFlow}.
 */
final class JobService {
	public function __construct(
		private IDBConnection $db,
		private TenantMapper $tenantMapper,
		private RecordMapper $records,
		private HistoryMapper $history,
		private RecordService $recordService,
		private TenantService $tenants,
		private JobAccess $access,
		private JobNotifications $notify,
		private JobWeeksService $weeks,
		private ITimeFactory $time,
	) {
	}

	// ------------------------------------------------------------ offer

	/** @return array<string,mixed> the new Job record */
	public function offer(Membership $m, string $key, \stdClass $body): array {
		JobRules::checkKey($key);
		$f = JobRules::offer($body);
		$project = $this->project($m, $f['project']);
		if ($project === null) {
			throw ApiException::invalid(Message::of('The project {project} does not exist.', ['project' => $f['project']]));
		}
		if (!$this->canManage($m, $f['project'])) {
			throw ApiException::forbidden(Message::of('Only Team Admins and the project’s Leads offer a {job} for it.', ['job' => JobWord::JOB]));
		}
		$this->requireMembers($m, $f['recipients']);
		$target = JobRules::target($project, $f['code'], $f['budget'], $f['work_package']);
		return $this->locked($m, function (int $rev, int $ts) use ($m, $key, $f, $target): array {
			$cur = $this->records->findOne($m->tenantId, 'job', $key);
			if ($cur !== null) {
				// The same offer sent again (answer lost): as it is.
				if (!$cur->isTombstone() && self::sameOffer(JobAccess::decode($cur), $f, $m->uid)) {
					return ['write' => false, 'record' => $cur, 'after' => null];
				}
				throw ApiException::conflict(Message::of('A {job} with this key already exists.', ['job' => JobWord::JOB]));
			}
			$job = JobFlow::create($key, $f, $target, $m->uid, Time::iso($ts) ?? '');
			// Offered only to oneself: taken at once, nobody to notify.
			$selbst = $f['recipients'] === [$m->uid];
			if ($selbst) {
				$job = JobFlow::accept($job, $m->uid, Time::iso($ts) ?? '');
			}
			// Volume is no longer a rule (0.7.5): above the work package is
			// allowed, the clients show it.
			JobRules::checkOverlap($job, $f['recipients'], $this->others($m), $this->name(...));
			$rec = $this->store($m, null, $key, $job, $rev, $ts);
			return ['write' => true, 'record' => $rec,
				'after' => $selbst ? null : fn () => $this->notify->send(Notifier::JOB_OFFERED, $job, $f['recipients'], $m->uid)];
		});
	}

	/** Same offer: same sender and fields. @param array<string,mixed> $job @param array<string,mixed> $f */
	private static function sameOffer(array $job, array $f, string $uid): bool {
		if (($job['sender'] ?? null) !== $uid) {
			return false;
		}
		foreach (['project', 'code', 'budget', 'work_package', 'description', 'start', 'end', 'recipients'] as $k) {
			if (($job[$k] ?? null) !== $f[$k]) {
				return false;
			}
		}
		return abs((float)($job['hours'] ?? 0) - $f['hours']) < JobRules::EPS;
	}

	// ------------------------------------------------------ transitions

	/** @return array<string,mixed> */
	public function change(Membership $m, string $key, \stdClass $body): array {
		$c = JobRules::change($body);
		if (isset($c['recipients']) && is_array($c['recipients'])) {
			$this->requireMembers($m, array_values(array_map('strval', $c['recipients'])));
		}
		return $this->transition($m, $key, function (array $job, ?array $project, string $now) use ($m, $c): array {
			$this->requireManage($m, $job);
			if ($project === null) {
				throw ApiException::invalid(Message::of('The project {project} does not exist.', ['project' => (string)$job['project']]));
			}
			$codes = isset($c['code']) ? JobRules::target($project, (string)$c['code'], $job['budget'], $job['work_package'])['codes'] : null;
			$new = JobFlow::change($job, $c, $codes, $m->uid, $now);
			$this->checkRules($m, $new);
			return [$new, function () use ($m, $job, $new): void {
				$before = self::activeUids($job);
				$after = self::activeUids($new);
				$fresh = $job['state'] !== JobRules::OFFERED && $new['state'] === JobRules::OFFERED;
				$added = $fresh ? $after : array_values(array_diff($after, $before));
				$this->notify->send(Notifier::JOB_OFFERED, $new, $added, $m->uid);
				$this->notify->send(Notifier::JOB_CHANGED, $new, array_values(array_diff($after, $added)), $m->uid);
				$this->notify->clear((string)$new['id'], array_values(array_diff($before, $after)));
			}];
		});
	}

	/** @return array<string,mixed> */
	public function accept(Membership $m, string $key): array {
		return $this->transition($m, $key, function (array $job, ?array $project, string $now) use ($m): array {
			$new = JobFlow::accept($job, $m->uid, $now);
			if ($new !== $job) {
				JobRules::checkOverlap($new, [$m->uid], $this->others($m), $this->name(...));
			}
			return [$new, fn () => $this->taken($job, $new, $m->uid)];
		});
	}

	/** @return array<string,mixed> */
	public function counter(Membership $m, string $key, \stdClass $body): array {
		$c = JobRules::counter($body);
		return $this->transition($m, $key, function (array $job, ?array $project, string $now) use ($m, $c): array {
			$new = JobFlow::counter($job, $c, $m->uid, $now);
			return [$new, function () use ($m, $new): void {
				$this->notify->clear((string)$new['id'], [$m->uid]);
				$this->notify->send(Notifier::JOB_COUNTER, $new, [(string)$new['sender']], $m->uid);
			}];
		});
	}

	/**
	 * Accept or reject a counter-proposal; `by` names whose while several wait.
	 *
	 * @return array<string,mixed>
	 */
	public function answerCounter(Membership $m, string $key, bool $accept, ?\stdClass $body = null): array {
		$by = JobRules::counterAnswer($body);
		return $this->transition($m, $key, function (array $job, ?array $project, string $now) use ($m, $key, $accept, $by): array {
			$this->requireManage($m, $job);
			$new = $accept ? JobFlow::acceptCounter($job, $by, $m->uid, $now) : JobFlow::rejectCounter($job, $by, $m->uid, $now);
			if ($accept && $project !== null && $new !== $job) {
				$this->checkRules($m, $new);
			}
			// The person cannot check it here: the server does, with their reported weeks (0.7.2).
			if ($accept && $new !== $job && ($new['state'] ?? '') === JobRules::IN_PROGRESS) {
				$this->weeks->check($m->tenantId, ['id' => $key] + $new, (string)$new['assignee']);
			}
			return [$new, function () use ($m, $job, $new, $accept): void {
				$who = (string)(self::lastLog($new)['from'] ?? '');
				if (JobRules::pendingCounters($new) === []) {
					$this->notify->clear((string)$new['id'], [(string)$new['sender'], $m->uid]);
				}
				if ($accept) {
					$this->taken($job, $new, $m->uid);
				}
				$this->notify->send($accept ? Notifier::JOB_COUNTER_ACCEPTED : Notifier::JOB_COUNTER_REJECTED, $new, [$who], $m->uid);
			}];
		});
	}

	/**
	 * GET /jobs/{key}/capacity – would accepting the counter-proposal of
	 * `by` (the only one waiting, if left out) fit that person's reported
	 * weeks? For whoever answers it. `reported: false` if the person's
	 * client never reported numbers (then accepting is not blocked).
	 *
	 * @return array<string,mixed>
	 */
	public function capacity(Membership $m, string $key, ?string $by): array {
		JobRules::checkKey($key);
		$cur = $this->records->findOne($m->tenantId, 'job', $key);
		if ($cur === null || $cur->isTombstone() || $this->access->view($m, $cur, $this->recordService->ownKeys($m)) === JobRules::VIEW_NONE) {
			throw ApiException::notFound(Message::of('This {job} does not exist.', ['job' => JobWord::JOB]));
		}
		$job = JobAccess::decode($cur);
		$this->requireManage($m, $job);
		$waiting = JobRules::pendingCounters($job);
		$c = null;
		foreach ($waiting as $x) {
			if ($by === null ? count($waiting) === 1 : ($x['by'] ?? null) === $by) {
				$c = $x;
			}
		}
		if ($c === null) {
			throw ApiException::conflict(Message::of('There is no counter-proposal to answer.'));
		}
		$uid = (string)($c['by'] ?? '');
		$probe = ['id' => $key, 'hours' => $c['hours'] ?? $job['hours'] ?? 0, 'start' => $c['start'] ?? $job['start'] ?? '', 'end' => $c['end'] ?? $job['end'] ?? ''];
		$o = $this->weeks->over($m->tenantId, $probe, $uid);
		return [
			'user' => $uid,
			'display_name' => $this->name($uid),
			'reported' => $o !== null,
			'reported_at' => $o['reported_at'] ?? null,
			'fits' => $o === null || $o['weeks'] === [],
			'weeks' => $o['weeks'] ?? [],
		];
	}

	/**
	 * Someone has the Job now: gone from every Inbox; whose counter-proposal
	 * lapsed hears so, and the sender has nothing left to answer.
	 *
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 */
	private function taken(array $before, array $after, string $actor): void {
		$this->notify->clear((string)$after['id'], array_values(array_map('strval', (array)$before['recipients'])));
		$lapsed = (array)(self::lastLog($after)['lapsed'] ?? []);
		if ($lapsed !== []) {
			$this->notify->clear((string)$after['id'], [(string)$after['sender']]);
			$this->notify->send(Notifier::JOB_COUNTER_LAPSED, $after, array_values(array_map('strval', $lapsed)), $actor);
		}
	}

	/** @return array<string,mixed> */
	public function decline(Membership $m, string $key): array {
		return $this->transition($m, $key, function (array $job, ?array $project, string $now) use ($m): array {
			return [JobFlow::decline($job, $m->uid, $now), fn () => $this->notify->clear((string)$job['id'], [$m->uid])];
		});
	}

	/** @return array<string,mixed> */
	public function giveBack(Membership $m, string $key, ?\stdClass $body): array {
		$note = JobRules::text($body?->note ?? null, 'note', JobRules::MAX_NOTE);
		return $this->transition($m, $key, function (array $job, ?array $project, string $now) use ($m, $note): array {
			$new = JobFlow::giveBack($job, $note, $m->uid, $now);
			return [$new, fn () => $this->notify->send(Notifier::JOB_RETURNED, $new, [(string)$new['sender']], $m->uid)];
		});
	}

	/** @return array<string,mixed> */
	public function done(Membership $m, string $key): array {
		return $this->transition($m, $key, fn (array $job, ?array $project, string $now): array => [JobFlow::done($job, $m->uid, $now), null]);
	}

	/** @return array<string,mixed> */
	public function paid(Membership $m, string $key, ?\stdClass $body): array {
		if (!$m->manages()) {
			throw ApiException::forbidden(Message::of('Only Team Admins mark a {job} as paid.', ['job' => JobWord::JOB]));
		}
		$paid = $body?->paid ?? true;
		if (!is_bool($paid)) {
			throw ApiException::notBool('paid');
		}
		return $this->transition($m, $key, fn (array $job, ?array $project, string $now): array => [JobFlow::paid($job, $paid, $m->uid, $now), null]);
	}

	/**
	 * Delete: only whoever created the Job. The history stays.
	 *
	 * @return array<string,mixed> the tombstone
	 */
	public function delete(Membership $m, string $key): array {
		JobRules::checkKey($key);
		return $this->locked($m, function (int $rev, int $ts) use ($m, $key): array {
			$cur = $this->records->findOne($m->tenantId, 'job', $key);
			if ($cur === null || $cur->isTombstone() || $this->access->view($m, $cur, $this->recordService->ownKeys($m)) !== JobRules::VIEW_FULL) {
				throw ApiException::notFound(Message::of('This {job} does not exist.', ['job' => JobWord::JOB]));
			}
			$job = JobAccess::decode($cur);
			if (($job['sender'] ?? null) !== $m->uid) {
				throw ApiException::forbidden(Message::of('Only the person who created this {job} deletes it.', ['job' => JobWord::JOB]));
			}
			$rec = $this->store($m, $cur, $key, $job, $rev, $ts, true);
			return ['write' => true, 'record' => $rec, 'after' => function () use ($m, $job, $cur): void {
				$active = self::activeUids($job);
				$this->notify->clear((string)$job['id'], array_values(array_diff($cur->accountList(), $active)));
				$this->notify->send(Notifier::JOB_DELETED, $job, $active, $m->uid);
			}];
		});
	}

	// --------------------------------------------------------- progress

	/**
	 * The booked hours per Job from the assignee's client. Jobs that are not
	 * the caller's (any more) are skipped, not an error.
	 *
	 * @return array{revision:int,records:list<array<string,mixed>>,skipped:list<string>}
	 */
	public function progress(Membership $m, \stdClass $body): array {
		$in = JobRules::progress($body);
		if ($in === []) {
			return ['revision' => $this->tenantMapper->revision($m->tenantId), 'records' => [], 'skipped' => []];
		}
		$result = ['revision' => 0, 'records' => [], 'skipped' => []];
		$this->locked($m, function (int $rev, int $ts) use ($m, $in, &$result): array {
			$now = Time::iso($ts) ?? '';
			$changes = [];
			$skipped = [];
			foreach ($in as $p) {
				$key = $p['key'];
				$cur = $this->records->findOne($m->tenantId, 'job', $key);
				$job = $cur === null || $cur->isTombstone() ? null : JobAccess::decode($cur);
				$new = $job === null ? null : JobFlow::progress($job, $p['hours'], $p['invoiced'], $m->uid, $now);
				if ($cur === null || $job === null || $new === null) {
					$skipped[] = $key;
				} elseif ($new !== $job) {
					$changes[] = [$cur, $new];
				}
			}
			$result['skipped'] = $skipped;
			if ($changes === []) {
				$result['revision'] = $rev - 1;
				return ['write' => false, 'record' => null, 'after' => null];
			}
			$top = count($changes) > 1 ? $this->tenantMapper->bumpRevision($m->tenantId, count($changes) - 1) : $rev;
			$r = $rev;
			foreach ($changes as [$cur, $new]) {
				$written = $this->store($m, $cur, $cur->getRkey(), $new, $r++, $ts);
				$result['records'][] = $this->access->presentFor($m, $written, $this->recordService->ownKeys($m), $this->recordService->present($written));
			}
			$result['revision'] = $top;
			return ['write' => true, 'record' => null, 'after' => null];
		});
		return $result;
	}

	// ------------------------------------------------------------ inside

	/**
	 * One transition of one Job under the lock. `$step` gets the Job, its
	 * project (null if gone) and the time, and returns the new Job plus
	 * what to do after the commit. The answer is the record as the caller
	 * now sees it – a tombstone if it left their view.
	 *
	 * @param callable(array<string,mixed>,?array<string,mixed>,string):array{0:array<string,mixed>,1:?callable} $step
	 * @return array<string,mixed>
	 */
	private function transition(Membership $m, string $key, callable $step): array {
		JobRules::checkKey($key);
		return $this->locked($m, function (int $rev, int $ts) use ($m, $key, $step): array {
			$cur = $this->records->findOne($m->tenantId, 'job', $key);
			$own = $this->recordService->ownKeys($m);
			$view = ($cur === null || $cur->isTombstone()) ? JobRules::VIEW_NONE : $this->access->view($m, $cur, $own);
			if ($cur === null || $view === JobRules::VIEW_NONE) {
				throw ApiException::notFound(Message::of('This {job} does not exist.', ['job' => JobWord::JOB]));
			}
			$job = JobAccess::decode($cur);
			try {
				[$new, $after] = $step($job, $this->project($m, (string)($job['project'] ?? '')), Time::iso($ts) ?? '');
			} catch (ApiException $e) {
				if ($e->getStatus() !== 409 || $view !== JobRules::VIEW_FULL) {
					throw $e;
				}
				throw new ApiException(409, ApiException::CONFLICT, $e->getText(), ['current' => $this->access->presentFor($m, $cur, $own, $this->recordService->present($cur))]);
			}
			if ($new === $job) {
				return ['write' => false, 'record' => $cur, 'after' => null];
			}
			return ['write' => true, 'record' => $this->store($m, $cur, $key, $new, $rev, $ts), 'after' => $after];
		});
	}

	/**
	 * Runs `$fn` in one transaction; the revision is raised first, which
	 * locks the team row until the commit. Nothing written: rolled back,
	 * the revision too. Retried on a deadlock.
	 *
	 * @param callable(int,int):array{write:bool,record:?Record,after:?callable} $fn
	 * @return array<string,mixed> the record as the caller sees it
	 */
	private function locked(Membership $m, callable $fn): array {
		for ($attempt = 1; ; $attempt++) {
			try {
				return $this->once($m, $fn);
			} catch (DbException $e) {
				$retry = in_array($e->getReason(), [DbException::REASON_DEADLOCK, DbException::REASON_LOCK_WAIT_TIMEOUT], true);
				if (!$retry || $attempt >= 3) {
					throw $e;
				}
				usleep(50000 * $attempt);
			}
		}
	}

	/**
	 * @param callable(int,int):array{write:bool,record:?Record,after:?callable} $fn
	 * @return array<string,mixed>
	 */
	private function once(Membership $m, callable $fn): array {
		$this->db->beginTransaction();
		try {
			$rev = $this->tenantMapper->bumpRevision($m->tenantId, 1);
			$r = $fn($rev, $this->time->getTime());
			if ($r['write']) {
				$this->db->commit();
			} else {
				$this->db->rollBack();
			}
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		if ($r['after'] !== null) {
			($r['after'])();
		}
		return $r['record'] === null ? [] : $this->presentFor($m, $r['record']);
	}

	/** @return array<string,mixed> */
	private function presentFor(Membership $m, Record $r): array {
		if ($r->isTombstone()) {
			return $this->recordService->present($r);
		}
		$own = $this->recordService->ownKeys($m);
		$view = $this->access->view($m, $r, $own);
		return $view === JobRules::VIEW_FULL ? $this->access->presentFor($m, $r, $own, $this->recordService->present($r)) : JobAccess::gone($r);
	}

	/**
	 * Overlap for the persons the Job counts for. Volume is no longer a
	 * rule (0.7.5): going above a work package is allowed.
	 *
	 * @param array<string,mixed> $job
	 */
	private function checkRules(Membership $m, array $job): void {
		JobRules::checkOverlap($job, self::activeUids($job), $this->others($m), $this->name(...));
	}

	/**
	 * The newest entry of the Job's log.
	 *
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private static function lastLog(array $job): array {
		$log = (array)($job['log'] ?? []);
		return $log === [] ? [] : (array)$log[array_key_last($log)];
	}

	/**
	 * The persons a Job counts for now.
	 *
	 * @param array<string,mixed> $job
	 * @return list<string>
	 */
	private static function activeUids(array $job): array {
		return match ($job['state'] ?? '') {
			JobRules::OFFERED => array_values(array_unique(array_merge(JobRules::openRecipients($job),
				array_map(static fn (array $c) => (string)($c['by'] ?? ''), JobRules::pendingCounters($job))))),
			JobRules::COUNTER => array_map(static fn (array $c) => (string)($c['by'] ?? ''), JobRules::pendingCounters($job)),
			JobRules::IN_PROGRESS => [(string)$job['assignee']],
			default => [],
		};
	}

	/** @param array<string,mixed> $job */
	private function requireManage(Membership $m, array $job): void {
		if (!$this->canManage($m, (string)($job['project'] ?? ''))) {
			throw ApiException::forbidden(Message::of('Only Team Admins and the project’s Leads change a {job}.', ['job' => JobWord::JOB]));
		}
	}

	private function canManage(Membership $m, string $project): bool {
		return $m->manages() || $this->access->isLead($m, $project, $this->recordService->ownKeys($m));
	}

	/** @param list<string> $uids */
	private function requireMembers(Membership $m, array $uids): void {
		$roles = $this->tenants->memberRoles($m->tenantId);
		foreach ($uids as $uid) {
			if (!array_key_exists($uid, $roles)) {
				throw ApiException::invalid(Message::of('{user} is not in the team.', ['user' => $uid]));
			}
		}
	}

	/** @return ?array<string,mixed> the project's `data`, null if it does not exist */
	private function project(Membership $m, string $id): ?array {
		$r = $this->records->findOne($m->tenantId, 'project', $id);
		return ($r === null || $r->isTombstone()) ? null : JobAccess::decode($r);
	}

	/** @return list<array<string,mixed>> all live Jobs of the team */
	private function others(Membership $m): array {
		$out = [];
		foreach ($this->records->findLiveByKind($m->tenantId, 'job') as $r) {
			$out[] = ['id' => $r->getRkey()] + JobAccess::decode($r);
		}
		return $out;
	}

	private function name(string $uid): string {
		return $this->tenants->displayName($uid);
	}

	/**
	 * Writes the Job (or its tombstone) with version and revision, and a
	 * version in the history. The tombstone keeps the last `data` so the
	 * delta knows whom to tell; the API never shows it.
	 *
	 * @param array<string,mixed> $job
	 */
	private function store(Membership $m, ?Record $cur, string $key, array $job, int $rev, int $ts, bool $delete = false): Record {
		$names = [];
		foreach (array_merge([(string)($job['sender'] ?? '')], array_map('strval', (array)($job['recipients'] ?? [])),
			[(string)($job['assignee'] ?? '')]) as $uid) {
			if ($uid !== '' && !isset($names[$uid])) {
				$names[$uid] = $this->name($uid);
			}
		}
		$job['names'] = $names;
		$json = json_encode($job, Json::FLAGS);
		if (strlen($json) > RecordValidator::MAX_DATA_BYTES) {
			throw ApiException::tooLarge('A record may be at most 256 KB.');
		}
		$r = $cur ?? new Record();
		if ($cur === null) {
			$r->setTenantId($m->tenantId);
			$r->setKind('job');
			$r->setRkey($key);
		}
		$r->setData($json);
		$r->setAccounts(json_encode(JobRules::accounts($job, $cur?->accountList() ?? []), Json::FLAGS));
		$r->setVersion(($cur?->getVersion() ?? 0) + 1);
		$r->setRevision($rev);
		$r->setDeleted($delete ? 1 : 0);
		$r->setModifiedBy($m->uid);
		$r->setModifiedAt($ts);
		$r = $cur === null ? $this->records->insert($r) : $this->records->update($r);

		$h = new History();
		$h->setTenantId($m->tenantId);
		$h->setKind('job');
		$h->setRkey($key);
		$h->setVersion($r->getVersion());
		$h->setDeleted($delete ? 1 : 0);
		$h->setData($delete ? null : $json);
		$h->setModifiedBy($m->uid);
		$h->setModifiedAt($ts);
		$this->history->insert($h);
		return $r;
	}
}
