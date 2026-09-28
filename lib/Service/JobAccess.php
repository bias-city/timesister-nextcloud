<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;

/**
 * Who sees a Job ({@see JobRules::view()}), with the project's Leads read
 * from the project record – once per request and project.
 */
final class JobAccess {
	/** @var array<string,list<string>> tenant:project → leads (person keys) */
	private array $leads = [];

	public function __construct(
		private RecordMapper $records,
	) {
	}

	/** @param list<string> $ownKeys the caller's person keys */
	public function isLead(Membership $m, string $project, array $ownKeys): bool {
		$id = $m->tenantId . ':' . $project;
		if (!isset($this->leads[$id])) {
			$r = $this->records->findOne($m->tenantId, 'project', $project);
			$raw = $r?->getData();
			$data = ($r === null || $r->isTombstone() || $raw === null) ? [] : (array)json_decode($raw, true);
			$this->leads[$id] = array_values(array_filter((array)($data['leads'] ?? []), 'is_string'));
		}
		return array_intersect($this->leads[$id], $ownKeys) !== [];
	}

	/**
	 * `full`, `gone` or `none` for the caller.
	 *
	 * @param list<string> $ownKeys
	 */
	public function view(Membership $m, Record $r, array $ownKeys): string {
		$job = self::decode($r);
		$lead = $this->isLead($m, (string)($job['project'] ?? ''), $ownKeys);
		if ($r->isTombstone()) {
			// A deleted Job: whoever saw it learns that it is gone.
			return ($m->manages() || $lead || in_array($m->uid, $r->accountList(), true)) ? JobRules::VIEW_GONE : JobRules::VIEW_NONE;
		}
		return JobRules::view($job, $m->uid, $m->manages(), $lead, $r->accountList());
	}

	/**
	 * Does the caller manage the Job – Team Admin, Lead of its project, sender?
	 *
	 * @param array<string,mixed> $job
	 * @param list<string> $ownKeys
	 */
	public function manages(Membership $m, array $job, array $ownKeys): bool {
		return $m->manages() || ($job['sender'] ?? null) === $m->uid
			|| $this->isLead($m, (string)($job['project'] ?? ''), $ownKeys);
	}

	/**
	 * A presented Job as the caller may read it: whoever does not manage it
	 * sees only their own counter-proposal ({@see JobRules::trimView()}).
	 *
	 * @param array<string,mixed> $presented from {@see RecordService::present()}
	 * @param list<string> $ownKeys
	 * @return array<string,mixed>
	 */
	public function presentFor(Membership $m, Record $r, array $ownKeys, array $presented): array {
		$data = $presented['data'] ?? null;
		if ($r->isTombstone() || !($data instanceof \stdClass) || $this->manages($m, self::decode($r), $ownKeys)) {
			return $presented;
		}
		$presented['data'] = JobRules::trimView($data, $m->uid);
		return $presented;
	}

	/** @return array<string,mixed> the Job's `data`; a tombstone keeps its last one */
	public static function decode(Record $r): array {
		$raw = $r->getData();
		$v = $raw === null ? null : json_decode($raw, true);
		return is_array($v) ? $v : [];
	}

	/**
	 * A Job the caller no longer sees, as a tombstone: their client drops it.
	 *
	 * @return array<string,mixed>
	 */
	public static function gone(Record $r): array {
		return [
			'kind' => $r->getKind(),
			'key' => $r->getRkey(),
			'version' => $r->getVersion(),
			'revision' => $r->getRevision(),
			'deleted' => true,
			'modified_by' => $r->getModifiedBy(),
			'modified_at' => Time::iso($r->getModifiedAt()),
			'data' => null,
		];
	}
}
