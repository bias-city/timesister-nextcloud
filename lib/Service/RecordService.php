<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\History;
use OCA\TimeSister\Db\HistoryMapper;
use OCA\TimeSister\Db\InvInvoiceMapper;
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
 * RecordRights checks the rights per kind (AccessPolicy for the plain
 * master data, Jobs through JobAccess).
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
		private RecordRights $rights,
		private TenantService $team,
		private InvInvoiceMapper $invoices,
		private CostWrites $costs,
	) {
	}

	/** `setting/settings`: a vacation calendar only in a Team Admin's own account – the job writes there without rights checks. */
	private function checkSettings(Membership $m, string $kind, string $key, mixed $data): void {
		if ($kind !== 'setting' || $key !== 'settings') {
			return;
		}
		$vc = AbsenceRules::vacationCalendar($data);
		if ($vc !== null) {
			AbsenceRules::checkVacationCalendar($vc, $this->team->memberRoles($m->tenantId), true);
		}
	}

	/** Jobs change only through /jobs (JobService), never as records. */
	/** A project's `team` (0.16.0) names only accounts of the team – also ones who left. */
	private function checkTeam(Membership $m, string $kind, mixed $data): void {
		if ($kind === 'project' && $data instanceof \stdClass && is_array($data->{ProjectRules::TEAM} ?? null)) {
			ProjectRules::requireTeamMembers($data, array_map('strval', array_keys($this->team->members($m->tenantId))));
		}
	}

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
	 * Like present(), in the form the caller may read ({@see RecordRights::shape()}).
	 *
	 * @param list<string> $ownKeys the caller's person keys
	 * @return array<string,mixed>
	 */
	private function presentFor(Membership $m, Record $r, array $ownKeys): array {
		$out = $this->present($r);
		$out['data'] = $this->rights->shape($m, $r, $out['data'], $ownKeys);
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
			if ($this->rights->canRead($m, $r->getKind(), $r->getRkey(), $r->accountList(), $own, $r)) {
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
		if (!$this->rights->canRead($m, $kind, $key, $r?->accountList() ?? [], $this->ownKeys($m), $r)) {
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
			$this->rights->requireWrite($m, $kind, $key);
		}
		$version = RecordValidator::checkVersion($body->version ?? null);
		if (!property_exists($body, 'data')) {
			throw ApiException::missing('data');
		}
		if ($kind === 'project' && !$this->policy->canWrite($m)) {
			$this->requireProjectLead($m, $key);
		}
		$this->checkTeam($m, $kind, $body->data);
		$v = RecordValidator::validate($kind, $key, $body->data);
		$this->checkSettings($m, $kind, $key, $body->data);
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
		$this->rights->requireWrite($m, $kind, $key);
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
		// Everyone else's batch may hold per-record kinds only (marks, booking reports); checked per write.
		if (!$this->policy->canWrite($m) && !RecordRights::perRecordOnly($writes)) {
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
				if (in_array($kind, RecordRights::PER_RECORD, true)) {
					$this->rights->requireWrite($m, $kind, $key);
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
					$ops[] = ['kind' => $kind, 'key' => $key, 'version' => $version, 'json' => null, 'accounts' => null, 'index' => $i];
				} else {
					$this->checkTeam($m, $kind, $w->data);
					$v = RecordValidator::validate($kind, $key, $w->data);
					$this->checkSettings($m, $kind, $key, $w->data);
					$ops[] = ['kind' => $kind, 'key' => $key, 'version' => $version, 'json' => $v['json'], 'accounts' => $v['accounts'], 'index' => $i];
				}
			} catch (ApiException $e) {
				throw self::inWrite($e, $i);
			}
		}
		return $this->write($m, $ops, true);
	}

	/** An error of the batch's write number `$i + 1`. */
	private static function inWrite(ApiException $e, int $i): ApiException {
		return new ApiException($e->getStatus(), $e->getErrorCode(), Message::of('Write {n}: {message}', ['n' => $i + 1, 'message' => $e->getText()]), ['index' => $i]);
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
		// Project: also its leads (by the current version). Marks, booking reports, invoices, quotes: whoever reads them.
		$raw = $r?->getData();
		$lead = $kind === 'project' && $r !== null && !$r->isTombstone() && $raw !== null
			&& $this->policy->canSeeFullProject($m, Json::decode($raw), $this->ownKeys($m));
		if (in_array($kind, RecordRights::PER_RECORD, true)) {
			$lead = $this->rights->canRead($m, $kind, $key, [], $this->ownKeys($m), $r);
		}
		if (!$lead && $kind !== RecordValidator::JOB && !$this->policy->canReadHistory($m, $kind, $key, $r?->accountList() ?? [])) {
			throw ApiException::forbidden('This account may not read the history of this record.');
		}
		if ($r === null) {
			throw ApiException::notFound('This record does not exist.');
		}
		$own = $this->ownKeys($m);
		return array_map(function (History $h) use ($m, $r, $own, $kind): array {
			$raw = $h->getData();
			$data = ($h->getDeleted() === 1 || $raw === null) ? null : Json::decode($raw);
			return [
				'version' => $h->getVersion(),
				'deleted' => $h->getDeleted() === 1,
				'modified_by' => $h->getModifiedBy(),
				'modified_at' => Time::iso($h->getModifiedAt()),
				'data' => $kind === 'project' ? $data : $this->rights->shape($m, $r, $data, $own),
			];
		}, $this->history->findFor($m->tenantId, $kind, $key, self::HISTORY_LIMIT));
	}

	/** @return array<string,mixed> the new record */
	public function restore(Membership $m, string $kind, string $key, \stdClass $body): array {
		self::noJob($kind);
		RecordValidator::checkAddress($kind, $key);
		$this->rights->requireWrite($m, $kind, $key);
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
		$data = Json::decode($raw);
		$v = RecordValidator::validate($kind, $key, $data);
		$this->checkSettings($m, $kind, $key, $data);
		return $this->write($m, [[
			'kind' => $kind, 'key' => $key, 'version' => $current,
			'json' => $v['json'], 'accounts' => $v['accounts'],
		]], false)['records'][0];
	}

	/**
	 * The server's own write of one record (0.22.0: approving, filing a
	 * receipt, a quote's document): its fields as given, on the version
	 * read before – a write in between is a conflict.
	 *
	 * @return array<string,mixed> the new record
	 */
	public function writeAsServer(Membership $m, string $kind, string $key, int $version, \stdClass $data): array {
		$v = RecordValidator::validate($kind, $key, $data);
		$ops = [['kind' => $kind, 'key' => $key, 'version' => $version, 'json' => $v['json'], 'accounts' => $v['accounts']]];
		$this->db->beginTransaction();
		try {
			$out = $this->applyWithin($m, $ops, false, null, true);
			$this->db->commit();
			return $out['records'][0];
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
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
	 * @param list<array{kind:string,key:string,version:int,json:?string,accounts:?list<string>,index?:int}> $ops
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
	 * @param list<array{kind:string,key:string,version:int,json:?string,accounts:?list<string>,index?:int}> $ops
	 * @return array{revision:int,records:list<array<string,mixed>>}
	 */
	private function writeOnce(Membership $m, array $ops, bool $batch): array {
		$this->db->beginTransaction();
		try {
			$out = $this->applyWithin($m, $ops, $batch);
			$this->db->commit();
			$this->costs->afterCommit();
			return $out;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			$this->costs->discard();
			throw $e;
		}
	}

	/**
	 * The writes inside an open transaction (0.18.0: IssueService writes
	 * the invoice record and its billing marks together with the frozen
	 * invoice and the ledger). `$top` is the team's revision when the
	 * caller already raised it by `count($ops)` – otherwise it is raised
	 * here. `$system`: the server's own write, which may change an issued
	 * invoice (reversal, paid); a client's write to one is `409 issued`.
	 *
	 * @param list<array{kind:string,key:string,version:int,json:?string,accounts:?list<string>,index?:int}> $ops
	 * @return array{revision:int,records:list<array<string,mixed>>}
	 */
	public function applyWithin(Membership $m, array $ops, bool $batch = false, ?int $top = null, bool $system = false): array {
		$n = count($ops);
		$now = $this->time->getTime();
		$top ??= $this->tenants->bumpRevision($m->tenantId, $n);
		$plans = [];
		$problems = [];
		$own = null;
		$costs = new CostBatch();
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
			if ($op['kind'] === TemplateRules::KIND) {
				// 0.25.0: a Lead's template against the stored one, under the team lock.
				try {
					$this->rights->requireTemplateWrite($m, $cur);
				} catch (ApiException $e) {
					throw isset($op['index']) ? self::inWrite($e, $op['index']) : $e;
				}
			}
			if ($op['kind'] === QuoteRules::KIND || $op['kind'] === InvoiceRules::KIND) {
				// A Lead's invoice or quote against the stored state, under the team lock.
				try {
					if ($op['kind'] === QuoteRules::KIND) {
						$this->rights->requireQuoteWrite($m, $cur, $op['json'], $own ??= $this->ownKeys($m));
					} else {
						$this->rights->requireInvoiceWrite($m, $cur, $op['json']);
					}
				} catch (ApiException $e) {
					throw isset($op['index']) ? self::inWrite($e, $op['index']) : $e;
				}
			}
			if (!$system && CostWrites::handles($op['kind'])) {
				// 0.22.0: receipts, expense rules and reports against the stored record; the server's fields kept.
				try {
					$op['json'] = $this->costs->prepare($m, $op, $cur, $costs);
				} catch (ApiException $e) {
					throw isset($op['index']) ? self::inWrite($e, $op['index']) : $e;
				}
			}
			if ($op['kind'] === InvoiceRules::KIND && !$system) {
				// 0.24.0: a handed-over invoice is frozen until revoked; only the server sets the status external.
				try {
					HandoverRules::checkClientWrite($cur === null || $cur->isTombstone() ? null : InvoiceRules::decode($cur->getData()),
						$op['json'] === null ? null : InvoiceRules::decode($op['json']));
				} catch (ApiException $e) {
					throw isset($op['index']) ? self::inWrite($e, $op['index']) : $e;
				}
			}
			if ($op['kind'] === InvoiceRules::KIND && !$system && $this->invoices->findByKey($m->tenantId, $op['key']) !== null) {
				// Issued on the server (0.18.0): frozen; only a reversal changes it.
				$e = ApiException::issued();
				throw isset($op['index']) ? self::inWrite($e, $op['index']) : $e;
			}
			// The project as it was: its leads and customer decide whom to deliver again.
			$old = $op['kind'] === 'project' && $cur !== null && !$cur->isTombstone() && $cur->getData() !== null
				? Json::decode((string)$cur->getData()) : null;
			$plans[] = [$op, $cur, $newVersion, $old instanceof \stdClass ? $old : null];
		}
		if ($problems !== []) {
			throw $this->problemError($problems, $batch);
		}

		$rev = $top - $n;
		$out = [];
		$scope = ['customers' => [], 'projects' => [], 'leads' => []];
		foreach ($plans as [$op, $cur, $newVersion, $old]) {
			if ($op['kind'] === 'project') {
				$new = $op['json'] === null ? null : Json::decode($op['json']);
				$c = ProjectAccess::scopeChange($old, $new instanceof \stdClass ? $new : null);
				$scope['customers'] = [...$scope['customers'], ...$c['customers']];
				if ($c['bookings']) {
					$scope['projects'][] = $op['key'];
					$scope['leads'] = [...$scope['leads'], ...self::leadKeys($old), ...self::leadKeys($new instanceof \stdClass ? $new : null)];
				}
			}
			$rev++;
			$record = $this->present($this->store($m, $op, $cur, $newVersion, $rev, $now));
			if ($op['kind'] === ReceiptRules::KIND && isset($costs->missing[$op['key']])) {
				// 0.23.0: an approver's write that could not approve says what is missing.
				$record['missing'] = $costs->missing[$op['key']];
			}
			$out[] = $record;
		}
		$top = $this->deliverAgain($m->tenantId, $top, $scope);
		return ['revision' => $top, 'records' => $out];
	}

	/**
	 * One record and its history row.
	 *
	 * @param array{kind:string,key:string,json:?string,accounts:?list<string>,...} $op
	 */
	private function store(Membership $m, array $op, ?Record $cur, int $newVersion, int $rev, int $now): Record {
		$isDelete = $op['json'] === null;
		$r = $cur ?? new Record();
		if ($cur === null) {
			$r->setTenantId($m->tenantId);
			$r->setKind($op['kind']);
			$r->setRkey($op['key']);
		}
		// An invoice's, quote's or receipt's tombstone keeps its last data (never handed out): who may read it.
		$r->setData($isDelete && in_array($op['kind'], RecordRights::AGAINST_STORED, true) ? $cur?->getData() : $op['json']);
		if (!$isDelete && $op['accounts'] !== null) {
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
		return $r;
	}

	/**
	 * A project's leads, customer or active state changed (0.14.0): its
	 * customers, booking reports, billable marks, invoices and quotes get a new revision, so
	 * whoever may read them now finds them in the next delta – also the
	 * quotes naming one of the old or new leads, whose Lead role may have
	 * begun. Who no longer may, drops them by the rule (API.md); nothing
	 * goes out as deleted.
	 *
	 * @param array{customers:list<string>,projects:list<string>,leads:list<string>} $scope
	 * @return int the team's revision afterwards
	 */
	private function deliverAgain(int $tenantId, int $top, array $scope): int {
		$customers = array_values(array_unique($scope['customers']));
		$projects = array_values(array_unique($scope['projects']));
		if ($customers === [] && $projects === []) {
			return $top;
		}
		$rev = $this->tenants->bumpRevision($tenantId, 1);
		foreach ($customers as $key) {
			$r = $this->records->findOne($tenantId, 'customer', $key);
			if ($r !== null) {
				$this->records->touchRevision($r->getId(), $rev);
			}
		}
		foreach ($projects as $project) {
			$this->records->touchByProject($tenantId, BookingRules::KINDS, $project, $rev);
		}
		if ($projects !== []) {
			foreach ($this->records->findLiveByKind($tenantId, InvoiceRules::KIND) as $r) {
				if (in_array(InvoiceRules::projectOf($r->getData()), $projects, true)) {
					$this->records->touchRevision($r->getId(), $rev);
				}
			}
			$names = $this->namesOf($tenantId, array_values(array_unique($scope['leads'])));
			foreach ($this->records->findLiveByKind($tenantId, QuoteRules::KIND) as $r) {
				if (in_array(InvoiceRules::projectOf($r->getData()), $projects, true) || QuoteRules::namesAny($r->getData(), $names)) {
					$this->records->touchRevision($r->getId(), $rev);
				}
			}
		}
		return $rev;
	}

	/**
	 * The lead keys of a project's data.
	 *
	 * @return list<string>
	 */
	private static function leadKeys(?\stdClass $d): array {
		$out = [];
		foreach ((array)($d?->{ProjectAccess::LEADS} ?? []) as $k) {
			if (is_string($k)) {
				$out[] = $k;
			}
		}
		return $out;
	}

	/**
	 * Every key a quote's `leads` may name these project leads by: the
	 * key, the accounts of its person and every person key of these
	 * accounts ({@see ownKeys()}).
	 *
	 * @param list<string> $leads
	 * @return array<string,true>
	 */
	private function namesOf(int $tenantId, array $leads): array {
		$out = [];
		foreach ($leads as $k) {
			$p = $this->records->findOne($tenantId, 'person', $k);
			foreach ([$k, ...($p !== null && !$p->isTombstone() ? $p->accountList() : [])] as $uid) {
				$out[$uid] = true;
				foreach ($this->records->findPersonsOf($uid, $tenantId, true) as $r) {
					$out[$r->getRkey()] = true;
				}
			}
		}
		return $out;
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
