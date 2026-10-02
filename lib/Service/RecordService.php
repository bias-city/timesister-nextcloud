<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\History;
use OCA\TimeSister\Db\HistoryMapper;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\TenantMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * Records with version and revision, history, restoring.
 *
 * Every method gets the caller's membership and filters by their team;
 * AccessPolicy checks the rights.
 */
final class RecordService {
	public const HISTORY_LIMIT = 100;
	public const BATCH_LIMIT = 500;

	public function __construct(
		private IDBConnection $db,
		private TenantMapper $tenants,
		private RecordMapper $records,
		private HistoryMapper $history,
		private AccessPolicy $policy,
		private ITimeFactory $time,
		private JobAccess $jobs,
		private AccessService $access,
	) {
	}

	/** May the caller mark this person's events as billed (0.7.6)? */
	private function canBill(Membership $m, string $person): bool {
		if ($m->manages()) {
			return true;
		}
		return BillingRules::canWrite($m->role, $this->access->levelsOf($m)[$person] ?? null);
	}

	/** Billing marks: whoever may write them, and the person themselves. */
	private function canReadBilling(Membership $m, string $key, array $ownKeys): bool {
		$person = BillingRules::personOf($key);
		return $person !== null && (in_array($person, $ownKeys, true) || $this->canBill($m, $person));
	}

	/** The rights of a write: billing marks per person, everything else Team Admins. */
	private function requireWriteOf(Membership $m, string $kind, string $key): void {
		if ($kind === RecordValidator::BILLING) {
			if (!$this->canBill($m, BillingRules::requirePerson($key))) {
				throw ApiException::forbidden('Only Team Admins, and Leads who may see this person’s time calendar, mark it as billed.');
			}
			return;
		}
		$this->policy->requireWrite($m);
	}

	/** A person without `feed` for whoever may not see it (0.7.6). */
	private function trimPerson(Membership $m, Record $r, mixed $data): mixed {
		if ($r->getKind() !== 'person' || !($data instanceof \stdClass) || !isset($data->feed)
			|| $this->access->canSeeFeed($m, $r->getRkey(), $r->accountList())) {
			return $data;
		}
		$copy = clone $data;
		unset($copy->feed);
		return $copy;
	}

	/** Jobs change only through /jobs (JobService), never as records. */
	private static function noJob(string $kind): void {
		if ($kind === RecordValidator::JOB) {
			throw ApiException::badRequest(Message::of('A {job} changes only through the {job} endpoints (/jobs).', ['job' => JobWord::JOB]));
		}
	}

	/** @return array<string,mixed> */
	public function present(Record $r): array {
		$raw = $r->getData();
		$data = ($r->isTombstone() || $raw === null) ? null : Json::decode($raw);
		return [
			'kind' => $r->getKind(),
			'key' => $r->getRkey(),
			'version' => $r->getVersion(),
			'revision' => $r->getRevision(),
			'deleted' => $r->isTombstone(),
			'modified_by' => $r->getModifiedBy(),
			'modified_at' => Time::iso($r->getModifiedAt()),
			'data' => $data,
		];
	}

	/**
	 * Like present(); a project full only for Team Admins and its
	 * leads, otherwise only the booking catalog.
	 *
	 * @param list<string> $ownKeys the caller's person keys
	 * @return array<string,mixed>
	 */
	private function presentFor(Membership $m, Record $r, array $ownKeys): array {
		$out = $this->present($r);
		$data = $out['data'];
		if ($r->getKind() === 'project' && $data instanceof \stdClass && !$this->policy->canSeeFullProject($m, $data, $ownKeys)) {
			$out['data'] = ProjectAccess::catalog($data);
		}
		$out['data'] = $this->trimPerson($m, $r, $out['data']);
		return $out;
	}

	/**
	 * The caller's person keys, as for "own person": the identifier itself
	 * and every person with it in `accounts`.
	 *
	 * @return list<string>
	 */
	public function ownKeys(Membership $m): array {
		$keys = [$m->uid];
		foreach ($this->records->findPersonsOf($m->uid, $m->tenantId, true) as $r) {
			$keys[] = $r->getRkey();
		}
		return array_values(array_unique($keys));
	}

	/** @return array{revision:int,records:list<array<string,mixed>>} */
	public function list(Membership $m, int $since): array {
		// First the revision, then the records up to it: what is written
		// after that arrives with the next delta.
		$rev = $this->tenants->revision($m->tenantId);
		$own = $this->ownKeys($m);
		$out = [];
		foreach ($this->records->findChanged($m->tenantId, $since, $rev) as $r) {
			if ($r->getKind() === RecordValidator::JOB) {
				// Jobs: in full for whoever sees them, as gone for whoever did.
				$view = $this->jobs->view($m, $r, $own);
				if ($view === JobRules::VIEW_FULL) {
					$out[] = $this->jobs->presentFor($m, $r, $own, $this->present($r));
				} elseif ($view === JobRules::VIEW_GONE && $since > 0) {
					$out[] = JobAccess::gone($r);
				}
				continue;
			}
			if ($r->getKind() === RecordValidator::BILLING) {
				if ($this->canReadBilling($m, $r->getRkey(), $own)) {
					$out[] = $this->present($r);
				}
				continue;
			}
			if ($this->policy->canRead($m, $r->getKind(), $r->getRkey(), $r->accountList())) {
				$out[] = $this->presentFor($m, $r, $own);
			}
		}
		return ['revision' => $rev, 'records' => $out];
	}

	/** @return array<string,mixed> */
	public function get(Membership $m, string $kind, string $key): array {
		RecordValidator::checkAddress($kind, $key);
		$r = $this->records->findOne($m->tenantId, $kind, $key);
		if ($kind === RecordValidator::JOB) {
			$own = $this->ownKeys($m);
			if ($r === null || $r->isTombstone() || $this->jobs->view($m, $r, $own) !== JobRules::VIEW_FULL) {
				throw ApiException::notFound(Message::of('This {job} does not exist.', ['job' => JobWord::JOB]));
			}
			return $this->jobs->presentFor($m, $r, $own, $this->present($r));
		}
		$readable = $kind === RecordValidator::BILLING
			? $this->canReadBilling($m, $key, $this->ownKeys($m))
			: $this->policy->canRead($m, $kind, $key, $r?->accountList() ?? []);
		if (!$readable) {
			throw ApiException::forbidden('This account may not read this record.');
		}
		if ($r === null || $r->isTombstone()) {
			throw ApiException::notFound('This record does not exist.');
		}
		return $this->presentFor($m, $r, $this->ownKeys($m));
	}

	/** Key of the own person record, otherwise null. */
	public function personKey(Membership $m): ?string {
		$found = $this->records->findPersonsOf($m->uid, $m->tenantId, true);
		foreach ($found as $r) {
			if ($r->getRkey() === $m->uid) {
				return $r->getRkey();
			}
		}
		return $found === [] ? null : $found[0]->getRkey();
	}

	/** @return array<string,mixed> the new record */
	public function put(Membership $m, string $kind, string $key, \stdClass $body): array {
		self::noJob($kind);
		RecordValidator::checkAddress($kind, $key);
		if ($kind !== 'project') {
			$this->requireWriteOf($m, $kind, $key);
		}
		$version = RecordValidator::checkVersion($body->version ?? null);
		if (!property_exists($body, 'data')) {
			throw ApiException::missing('data');
		}
		if ($kind === 'project' && !$this->policy->canWrite($m)) {
			$this->requireProjectLead($m, $key);
		}
		$v = RecordValidator::validate($kind, $key, $body->data);
		return $this->write($m, [[
			'kind' => $kind, 'key' => $key, 'version' => $version,
			'json' => $v['json'], 'accounts' => $v['accounts'],
		]], false)['records'][0];
	}

	/**
	 * The lead of the current version may change their project entirely,
	 * even remove themselves from `leads`. Only Team Admins create
	 * new projects (including on a tombstone). Otherwise 403.
	 */
	private function requireProjectLead(Membership $m, string $key): void {
		$cur = $this->records->findOne($m->tenantId, 'project', $key);
		$raw = $cur?->getData();
		$old = ($cur === null || $cur->isTombstone() || $raw === null) ? null : Json::decode($raw);
		if (!ProjectAccess::isLead($old, $this->ownKeys($m))) {
			throw ProjectAccess::forbidden();
		}
	}

	/** @return array<string,mixed> the tombstone */
	public function delete(Membership $m, string $kind, string $key, mixed $version): array {
		self::noJob($kind);
		RecordValidator::checkAddress($kind, $key);
		$this->requireWriteOf($m, $kind, $key);
		if ($version === null || $version === '') {
			throw ApiException::missing('version', true);
		}
		$version = RecordValidator::checkVersion($version);
		return $this->write($m, [[
			'kind' => $kind, 'key' => $key, 'version' => $version, 'json' => null, 'accounts' => null,
		]], false)['records'][0];
	}

	/** @return array{revision:int,records:list<array<string,mixed>>} */
	public function batch(Membership $m, \stdClass $body): array {
		$writes = $body->writes ?? null;
		// A Lead's batch may hold billing marks only; their rights are checked per write.
		if (!$this->policy->canWrite($m) && (!is_array($writes) || $writes === []
			|| array_filter($writes, static fn (mixed $w) => !($w instanceof \stdClass) || ($w->kind ?? null) !== RecordValidator::BILLING) !== [])) {
			$this->policy->requireWrite($m);
		}
		if (!is_array($writes) || !array_is_list($writes)) {
			throw ApiException::badRequest('“writes” must be a list.');
		}
		if (count($writes) > self::BATCH_LIMIT) {
			throw ApiException::tooLarge(Message::of('At most {limit} writes per call.', ['limit' => self::BATCH_LIMIT]));
		}
		$ops = [];
		$seen = [];
		foreach ($writes as $i => $w) {
			$n = $i + 1;
			try {
				if (!($w instanceof \stdClass)) {
					throw ApiException::badRequest('Each write must be a JSON object.');
				}
				$kind = $w->kind ?? null;
				$key = $w->key ?? null;
				if (!is_string($kind) || !is_string($key)) {
					throw ApiException::badRequest('Each write needs “kind” and “key” as text.');
				}
				RecordValidator::checkAddress($kind, $key);
				self::noJob($kind);
				if ($kind === RecordValidator::BILLING) {
					$this->requireWriteOf($m, $kind, $key);
				}
				$version = RecordValidator::checkVersion($w->version ?? null);
				if (!property_exists($w, 'data')) {
					throw ApiException::invalid('“data” is missing (null means delete).');
				}
				$id = $kind . '/' . $key;
				if (isset($seen[$id])) {
					throw ApiException::invalid('The same record is in the list twice.');
				}
				$seen[$id] = true;
				if ($w->data === null) {
					$ops[] = ['kind' => $kind, 'key' => $key, 'version' => $version, 'json' => null, 'accounts' => null];
				} else {
					$v = RecordValidator::validate($kind, $key, $w->data);
					$ops[] = ['kind' => $kind, 'key' => $key, 'version' => $version, 'json' => $v['json'], 'accounts' => $v['accounts']];
				}
			} catch (ApiException $e) {
				throw new ApiException($e->getStatus(), $e->getErrorCode(), Message::of('Write {n}: {message}', ['n' => $n, 'message' => $e->getText()]), ['index' => $i]);
			}
		}
		return $this->write($m, $ops, true);
	}

	/** @return list<array<string,mixed>> newest first, at most 100 */
	public function history(Membership $m, string $kind, string $key): array {
		RecordValidator::checkAddress($kind, $key);
		$r = $this->records->findOne($m->tenantId, $kind, $key);
		if ($kind === RecordValidator::JOB) {
			// A Job: whoever manages it (the versions hold everyone's
			// counter-proposals); a deleted one only Team Admins.
			$full = $r !== null && ($r->isTombstone() ? $m->manages()
				: $this->jobs->view($m, $r, $this->ownKeys($m)) === JobRules::VIEW_FULL
					&& $this->jobs->manages($m, JobAccess::decode($r), $this->ownKeys($m)));
			if (!$full) {
				throw ApiException::forbidden('This account may not read the history of this record.');
			}
		}
		// Project: also its leads (by the current version). Billing: whoever reads the mark.
		$raw = $r?->getData();
		$lead = $kind === 'project' && $r !== null && !$r->isTombstone() && $raw !== null
			&& $this->policy->canSeeFullProject($m, Json::decode($raw), $this->ownKeys($m));
		if ($kind === RecordValidator::BILLING) {
			$lead = $this->canReadBilling($m, $key, $this->ownKeys($m));
		}
		if (!$lead && $kind !== RecordValidator::JOB && !$this->policy->canReadHistory($m, $kind, $key, $r?->accountList() ?? [])) {
			throw ApiException::forbidden('This account may not read the history of this record.');
		}
		if ($r === null) {
			throw ApiException::notFound('This record does not exist.');
		}
		return array_map(function (History $h) use ($m, $r): array {
			$raw = $h->getData();
			$data = ($h->getDeleted() === 1 || $raw === null) ? null : Json::decode($raw);
			return [
				'version' => $h->getVersion(),
				'deleted' => $h->getDeleted() === 1,
				'modified_by' => $h->getModifiedBy(),
				'modified_at' => Time::iso($h->getModifiedAt()),
				'data' => $this->trimPerson($m, $r, $data),
			];
		}, $this->history->findFor($m->tenantId, $kind, $key, self::HISTORY_LIMIT));
	}

	/** @return array<string,mixed> the new record */
	public function restore(Membership $m, string $kind, string $key, \stdClass $body): array {
		self::noJob($kind);
		RecordValidator::checkAddress($kind, $key);
		$this->requireWriteOf($m, $kind, $key);
		$version = RecordValidator::checkVersion($body->version ?? null, 'version');
		$current = RecordValidator::checkVersion($body->current ?? null, 'current');
		$h = $this->history->findVersion($m->tenantId, $kind, $key, $version);
		if ($h === null) {
			throw ApiException::notFound('This version does not exist (any more).');
		}
		$raw = $h->getData();
		if ($h->getDeleted() === 1 || $raw === null) {
			throw ApiException::invalid('This version is a deletion and cannot be restored.');
		}
		$v = RecordValidator::validate($kind, $key, Json::decode($raw));
		return $this->write($m, [[
			'kind' => $kind, 'key' => $key, 'version' => $current,
			'json' => $v['json'], 'accounts' => $v['accounts'],
		]], false)['records'][0];
	}

	/** "Account deleted" note on an account's persons, across all teams. */
	public function markAccountDeleted(string $uid): int {
		$now = $this->time->getTime();
		$n = 0;
		foreach ($this->records->findPersonsOf($uid, null) as $r) {
			$this->records->markAccountDeleted($r->getId(), $now);
			$n++;
		}
		return $n;
	}

	/**
	 * Writes in **one** transaction: all or nothing.
	 *
	 * The team's revision is increased first. That locks the team row
	 * until commit, so all checks afterwards see a fixed state and two
	 * writes never get the same revision. On a conflict everything rolls
	 * back – including the revision.
	 *
	 * @param list<array{kind:string,key:string,version:int,json:?string,accounts:?list<string>}> $ops
	 * @return array{revision:int,records:list<array<string,mixed>>}
	 */
	private function write(Membership $m, array $ops, bool $batch): array {
		if ($ops === []) {
			return ['revision' => $this->tenants->revision($m->tenantId), 'records' => []];
		}
		for ($attempt = 1; ; $attempt++) {
			try {
				return $this->writeOnce($m, $ops, $batch);
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
	 * @param list<array{kind:string,key:string,version:int,json:?string,accounts:?list<string>}> $ops
	 * @return array{revision:int,records:list<array<string,mixed>>}
	 */
	private function writeOnce(Membership $m, array $ops, bool $batch): array {
		$n = count($ops);
		$now = $this->time->getTime();
		$this->db->beginTransaction();
		try {
			$top = $this->tenants->bumpRevision($m->tenantId, $n);
			$plans = [];
			$problems = [];
			foreach ($ops as $op) {
				$cur = $this->records->findOne($m->tenantId, $op['kind'], $op['key']);
				[$outcome, $newVersion] = VersionCheck::decide(
					$cur === null ? null : ['version' => $cur->getVersion(), 'deleted' => $cur->isTombstone()],
					$op['version'],
					$op['json'] === null,
				);
				if ($outcome !== VersionCheck::OK) {
					$problems[] = ['op' => $op, 'outcome' => $outcome, 'current' => $cur === null ? null : $this->present($cur)];
					continue;
				}
				$plans[] = [$op, $cur, $newVersion];
			}
			if ($problems !== []) {
				$this->db->rollBack();
				throw $this->problemError($problems, $batch);
			}

			$rev = $top - $n;
			$out = [];
			foreach ($plans as [$op, $cur, $newVersion]) {
				$rev++;
				$isDelete = $op['json'] === null;
				$r = $cur ?? new Record();
				if ($cur === null) {
					$r->setTenantId($m->tenantId);
					$r->setKind($op['kind']);
					$r->setRkey($op['key']);
				}
				$r->setData($op['json']);
				if (!$isDelete) {
					// A tombstone keeps the accounts: this is how the person learns their record is gone.
					$r->setAccounts($op['accounts'] === [] ? null : Json::encode($op['accounts']));
				}
				$r->setVersion($newVersion);
				$r->setRevision($rev);
				$r->setDeleted($isDelete ? 1 : 0);
				$r->setModifiedBy($m->uid);
				$r->setModifiedAt($now);
				$r = $cur === null ? $this->records->insert($r) : $this->records->update($r);

				$h = new History();
				$h->setTenantId($m->tenantId);
				$h->setKind($op['kind']);
				$h->setRkey($op['key']);
				$h->setVersion($newVersion);
				$h->setDeleted($isDelete ? 1 : 0);
				$h->setData($op['json']);
				$h->setModifiedBy($m->uid);
				$h->setModifiedAt($now);
				$this->history->insert($h);

				$out[] = $this->present($r);
			}
			$this->db->commit();
			return ['revision' => $top, 'records' => $out];
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/** @param list<array{op:array<string,mixed>,outcome:string,current:?array<string,mixed>}> $problems */
	private function problemError(array $problems, bool $batch): ApiException {
		if ($batch) {
			return ApiException::conflict(
				Message::plural('A record was changed in the meantime; nothing was written.', '%n records were changed in the meantime; nothing was written.', count($problems)),
				['conflicts' => array_map(static fn (array $p) => [
					'kind' => $p['op']['kind'],
					'key' => $p['op']['key'],
					'current' => $p['current'],
				], $problems)],
			);
		}
		$p = $problems[0];
		if ($p['outcome'] === VersionCheck::NOT_FOUND) {
			return ApiException::notFound('This record does not exist.');
		}
		if ($p['current'] === null) {
			return ApiException::conflict('This record does not exist yet; send “version”: 0 to create it.', ['current' => null]);
		}
		return ApiException::conflict(
			Message::of('The record was changed in the meantime by {user}.', ['user' => (string)$p['current']['modified_by']]),
			['current' => $p['current']],
		);
	}
}
