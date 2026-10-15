<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\ClientStatus;
use OCA\TimeSister\Db\ClientStatusMapper;
use OCA\TimeSister\Db\RecordMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;

/**
 * The weekly numbers of the persons' Jobs (0.7.2): each client reports its
 * own ({@see JobWeeks}); the person and Team Admins read them (since
 * 0.14.0 no longer Leads). The server checks with them whether an accepted
 * counter-proposal fits, and tells Leads per person only fits or not.
 */
final class JobWeeksService {
	public function __construct(
		private ClientStatusMapper $status,
		private TenantService $tenants,
		private RecordMapper $records,
		private RecordService $recordService,
		private JobAccess $jobAccess,
		private ITimeFactory $time,
	) {
	}

	/** POST /jobs/weeks – the caller's own numbers. @return array{reported_at:string} */
	public function report(Membership $m, \stdClass $body): array {
		$json = json_encode(JobWeeks::parse($body), Json::FLAGS);
		if (strlen($json) > RecordValidator::MAX_DATA_BYTES) {
			throw ApiException::tooLarge('A record may be at most 256 KB.');
		}
		$now = $this->time->getTime();
		for ($attempt = 1; ; $attempt++) {
			$found = $this->status->findOne($m->tenantId, $m->uid);
			$s = $found ?? new ClientStatus();
			if ($found === null) {
				$s->setTenantId($m->tenantId);
				$s->setUid($m->uid);
				$s->setSeenAt($now);
			}
			$s->setJobWeeks($json);
			$s->setJobWeeksAt($now);
			try {
				$found === null ? $this->status->insert($s) : $this->status->update($s);
				return ['reported_at' => (string)Time::iso($now)];
			} catch (DbException $e) {
				// Two reports at the same time: the second updates.
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION || $attempt >= 2) {
					throw $e;
				}
			}
		}
	}

	/**
	 * Whose numbers the caller reads: their own; Team Admins everyone's
	 * (0.14.0: Leads only their own too).
	 *
	 * @return list<string>
	 */
	public function readable(Membership $m): array {
		if ($m->manages()) {
			return array_map('strval', array_keys($this->tenants->memberRoles($m->tenantId)));
		}
		return [$m->uid];
	}

	/**
	 * GET /jobs/weeks – the numbers the caller may read (`uid`: only this
	 * person). Everyone readable is listed, `weeks: null` if never reported.
	 * Jobs the caller does not see are merged into `other`.
	 *
	 * @return array{people:list<array<string,mixed>>}
	 */
	public function list(Membership $m, ?string $uid = null): array {
		$readable = $this->readable($m);
		if ($uid !== null && $uid !== '') {
			if (!in_array($uid, $readable, true)) {
				throw ApiException::forbidden(Message::of('Only your own numbers; Team Admins read everyone’s.'));
			}
			$readable = [$uid];
		}
		$roles = $this->tenants->memberRoles($m->tenantId);
		$byUid = $this->status->findByTenant($m->tenantId);
		$own = $this->recordService->ownKeys($m);
		$jobs = [];
		foreach ($this->records->findLiveByKind($m->tenantId, 'job') as $r) {
			$jobs[$r->getRkey()] = $r;
		}
		$seen = [];
		$sees = function (string $key) use ($m, $jobs, $own, &$seen): bool {
			return $seen[$key] ??= isset($jobs[$key]) && $this->jobAccess->view($m, $jobs[$key], $own) === JobRules::VIEW_FULL;
		};
		$people = [];
		foreach ($readable as $u) {
			$s = $byUid[$u] ?? null;
			$report = self::decode($s);
			$people[] = [
				'uid' => $u,
				'display_name' => $this->tenants->displayName($u),
				'role' => $roles[$u] ?? Role::USER,
				'reported_at' => $report === null ? null : Time::iso($s?->getJobWeeksAt()),
				'pensum' => $report['pensum'] ?? null,
				'weeks' => $report === null ? null : JobWeeks::present($report, $sees),
			];
		}
		return ['people' => $people];
	}

	/**
	 * **Does the Job fit the person?** `null` if they never reported
	 * numbers (then nothing is blocked); otherwise the weeks above their
	 * capacity line or with a day above a full day (empty: it fits).
	 *
	 * @param array<string,mixed> $job with the values that would apply
	 * @return ?array{reported_at:?string,weeks:list<array{start:string,week:int,load:float,available:float,over:float,day?:string}>}
	 */
	public function over(int $tenantId, array $job, string $uid): ?array {
		$s = $this->status->findOne($tenantId, $uid);
		$report = self::decode($s);
		if ($report === null) {
			return null;
		}
		$valid = [];
		$extra = [];
		foreach ($this->records->findLiveByKind($tenantId, 'job') as $r) {
			$j = JobAccess::decode($r);
			if (($j['assignee'] ?? null) !== $uid || !in_array($j['state'] ?? '', [JobRules::IN_PROGRESS, JobRules::DONE], true)) {
				continue;
			}
			$valid[] = $r->getRkey();
			if (($j['state'] ?? '') === JobRules::IN_PROGRESS && !in_array($r->getRkey(), $report['jobs'], true)) {
				$extra[] = ['key' => $r->getRkey(), 'rest' => max(0.0, (float)($j['hours'] ?? 0) - (float)(((array)($j['progress'] ?? []))['hours'] ?? 0)),
					'start' => (string)($j['start'] ?? ''), 'end' => (string)($j['end'] ?? '')];
			}
		}
		$probe = ['key' => (string)($job['id'] ?? ''), 'hours' => (float)($job['hours'] ?? 0),
			'start' => (string)($job['start'] ?? ''), 'end' => (string)($job['end'] ?? '')];
		return [
			'reported_at' => Time::iso($s?->getJobWeeksAt()),
			'weeks' => JobWeeks::over($report, $probe, $valid, $extra, gmdate('Y-m-d', $this->time->getTime())),
		];
	}

	/**
	 * Throws `422 invalid` with `rule: capacity` when the Job does not fit
	 * the person's reported weeks. Without numbers it passes. Whoever is no
	 * Team Admin learns which weeks, not the hours (0.14.0).
	 *
	 * @param array<string,mixed> $job
	 */
	public function check(int $tenantId, array $job, string $uid, bool $numbers = true): void {
		$o = $this->over($tenantId, $job, $uid);
		if ($o === null || $o['weeks'] === []) {
			return;
		}
		$n = count($o['weeks']);
		throw new ApiException(422, ApiException::INVALID, Message::plural(
			'This counter-proposal does not fit the capacity of {user}: week {weeks} above the capacity line.',
			'This counter-proposal does not fit the capacity of {user}: weeks {weeks} above the capacity line.',
			$n, ['user' => $this->tenants->displayName($uid), 'weeks' => JobWeeks::weeksText($o['weeks'], $numbers)]),
			['rule' => 'capacity', 'user' => $uid, 'weeks' => $numbers ? $o['weeks'] : JobWeeks::withoutNumbers($o['weeks']), 'reported_at' => $o['reported_at']]);
	}

	/**
	 * POST /jobs/fits (0.14.0): would a Job of these hours and days fit
	 * each recipient? Per account true, false, or null without reported
	 * numbers – never the numbers. Team Admins and Leads, only for members.
	 *
	 * @return array{fits:\stdClass} account → true|false|null, an object even for numeric accounts
	 */
	public function fits(Membership $m, \stdClass $body): array {
		if (!$m->manages() && $m->role !== Role::LEAD) {
			throw ApiException::forbidden(Message::of('Only Team Admins and Leads ask whether a {job} fits.', ['job' => JobWord::JOB]));
		}
		$f = JobRules::fits($body);
		$roles = $this->tenants->memberRoles($m->tenantId);
		foreach ($f['recipients'] as $uid) {
			if (!array_key_exists($uid, $roles)) {
				throw ApiException::invalid(Message::of('{user} is not in the team.', ['user' => $uid]));
			}
		}
		// A Job being changed: its own hours do not count twice – only for whoever manages it.
		if ($f['job'] !== null) {
			$r = $this->records->findOne($m->tenantId, 'job', $f['job']);
			if ($r === null || $r->isTombstone() || !$this->jobAccess->manages($m, JobAccess::decode($r), $this->recordService->ownKeys($m))) {
				throw ApiException::notFound(Message::of('This {job} does not exist.', ['job' => JobWord::JOB]));
			}
		}
		$probe = ['id' => $f['job'] ?? '', 'hours' => $f['hours'], 'start' => $f['start'], 'end' => $f['end']];
		$out = new \stdClass();
		foreach ($f['recipients'] as $uid) {
			$out->{$uid} = JobWeeks::fitsOf($this->over($m->tenantId, $probe, $uid));
		}
		return ['fits' => $out];
	}

	/** @return ?array{pensum:?float,jobs:list<string>,weeks:list<array<string,mixed>>} */
	private static function decode(?ClientStatus $s): ?array {
		$raw = $s?->getJobWeeks();
		$v = $raw === null ? null : json_decode($raw, true);
		if (!is_array($v) || !is_array($v['weeks'] ?? null)) {
			return null;
		}
		return [
			'pensum' => is_numeric($v['pensum'] ?? null) ? (float)$v['pensum'] : null,
			'jobs' => array_values(array_map('strval', array_filter((array)($v['jobs'] ?? []), 'is_string'))),
			'weeks' => array_values(array_filter($v['weeks'], 'is_array')),
		];
	}
}
