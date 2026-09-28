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
 * own ({@see JobWeeks}); the person, Team Admins and Leads who may at least
 * view the person's time calendar read them. The server checks with them
 * whether an accepted counter-proposal fits.
 */
final class JobWeeksService {
	public function __construct(
		private ClientStatusMapper $status,
		private TenantService $tenants,
		private AccessService $access,
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
	 * Whose numbers the caller reads: their own; Team Admins everyone's;
	 * Leads those whose time calendar they may at least view.
	 *
	 * @return list<string>
	 */
	public function readable(Membership $m): array {
		$roles = $this->tenants->memberRoles($m->tenantId);
		if ($m->manages()) {
			return array_map('strval', array_keys($roles));
		}
		$out = [$m->uid];
		if (($roles[$m->uid] ?? null) === Role::LEAD) {
			$levels = $this->access->levelsOf($m);
			foreach (array_map('strval', array_keys($levels)) as $owner) {
				if (AccessRules::rank($levels[$owner]) >= AccessRules::rank(AccessRules::VIEW)) {
					$out[] = $owner;
				}
			}
		}
		return $out;
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
				throw ApiException::forbidden(Message::of('Only your own numbers – or those of someone whose time calendar you may view.'));
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
	 * the person's reported weeks. Without numbers it passes.
	 *
	 * @param array<string,mixed> $job
	 */
	public function check(int $tenantId, array $job, string $uid): void {
		$o = $this->over($tenantId, $job, $uid);
		if ($o === null || $o['weeks'] === []) {
			return;
		}
		$n = count($o['weeks']);
		throw new ApiException(422, ApiException::INVALID, Message::plural(
			'This counter-proposal does not fit the capacity of {user}: week {weeks} above the capacity line.',
			'This counter-proposal does not fit the capacity of {user}: weeks {weeks} above the capacity line.',
			$n, ['user' => $this->tenants->displayName($uid), 'weeks' => JobWeeks::weeksText($o['weeks'])]),
			['rule' => 'capacity', 'user' => $uid, 'weeks' => $o['weeks'], 'reported_at' => $o['reported_at']]);
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
