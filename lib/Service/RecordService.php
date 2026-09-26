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
 * Datensätze mit Fassung und Revision, Verlauf, Wiederherstellen.
 *
 * Jede Methode bekommt die Mitgliedschaft des Aufrufers und filtert nach
 * seinem Team; die Rechte prüft die AccessPolicy.
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
	) {
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
	 * Wie present(); ein Projekt voll nur für Verwaltung, Admin und seine
	 * Leitungen, sonst nur der Buchungskatalog.
	 *
	 * @param list<string> $ownKeys Personenschlüssel des Aufrufers
	 * @return array<string,mixed>
	 */
	private function presentFor(Membership $m, Record $r, array $ownKeys): array {
		$out = $this->present($r);
		$data = $out['data'];
		if ($r->getKind() === 'project' && $data instanceof \stdClass && !$this->policy->canSeeFullProject($m, $data, $ownKeys)) {
			$out['data'] = ProjectAccess::catalog($data);
		}
		return $out;
	}

	/**
	 * Die Personenschlüssel des Aufrufers wie bei „eigene Person“: die
	 * Kennung selbst und jede Person mit ihr in `accounts`.
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
		// Erst die Revision, dann die Datensätze bis zu ihr: Was danach
		// geschrieben wird, kommt mit dem nächsten Delta.
		$rev = $this->tenants->revision($m->tenantId);
		$own = $this->ownKeys($m);
		$out = [];
		foreach ($this->records->findChanged($m->tenantId, $since, $rev) as $r) {
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
		if (!$this->policy->canRead($m, $kind, $key, $r?->accountList() ?? [])) {
			throw ApiException::forbidden('Diesen Datensatz darf dieses Konto nicht lesen.');
		}
		if ($r === null || $r->isTombstone()) {
			throw ApiException::notFound('Diesen Datensatz gibt es nicht.');
		}
		return $this->presentFor($m, $r, $this->ownKeys($m));
	}

	/** Schlüssel des eigenen Personendatensatzes, sonst null. */
	public function personKey(Membership $m): ?string {
		$found = $this->records->findPersonsOf($m->uid, $m->tenantId, true);
		foreach ($found as $r) {
			if ($r->getRkey() === $m->uid) {
				return $r->getRkey();
			}
		}
		return $found === [] ? null : $found[0]->getRkey();
	}

	/** @return array<string,mixed> der neue Datensatz */
	public function put(Membership $m, string $kind, string $key, \stdClass $body): array {
		if ($kind !== 'project') {
			$this->policy->requireWrite($m);
		}
		RecordValidator::checkAddress($kind, $key);
		$version = RecordValidator::checkVersion($body->version ?? null);
		if (!property_exists($body, 'data')) {
			throw ApiException::invalid('„data“ fehlt.');
		}
		if (!$this->policy->canWrite($m)) {
			$this->requireProjectLead($m, $key);
		}
		$v = RecordValidator::validate($kind, $key, $body->data);
		return $this->write($m, [[
			'kind' => $kind, 'key' => $key, 'version' => $version,
			'json' => $v['json'], 'accounts' => $v['accounts'],
		]], false)['records'][0];
	}

	/**
	 * Die Leitung der aktuellen Fassung darf ihr Projekt ganz ändern, auch
	 * sich selbst aus `leads` nehmen. Neue Projekte (auch auf einem
	 * Grabstein) legen nur Verwaltung und Admin an. Sonst 403.
	 */
	private function requireProjectLead(Membership $m, string $key): void {
		$cur = $this->records->findOne($m->tenantId, 'project', $key);
		$raw = $cur?->getData();
		$old = ($cur === null || $cur->isTombstone() || $raw === null) ? null : Json::decode($raw);
		if (!ProjectAccess::isLead($old, $this->ownKeys($m))) {
			throw ApiException::forbidden(ProjectAccess::FORBIDDEN);
		}
	}

	/** @return array<string,mixed> der Grabstein */
	public function delete(Membership $m, string $kind, string $key, mixed $version): array {
		$this->policy->requireWrite($m);
		RecordValidator::checkAddress($kind, $key);
		if ($version === null || $version === '') {
			throw ApiException::badRequest('„version“ fehlt.');
		}
		$version = RecordValidator::checkVersion($version);
		return $this->write($m, [[
			'kind' => $kind, 'key' => $key, 'version' => $version, 'json' => null, 'accounts' => null,
		]], false)['records'][0];
	}

	/** @return array{revision:int,records:list<array<string,mixed>>} */
	public function batch(Membership $m, \stdClass $body): array {
		$this->policy->requireWrite($m);
		$writes = $body->writes ?? null;
		if (!is_array($writes) || !array_is_list($writes)) {
			throw ApiException::badRequest('„writes“ muss eine Liste sein.');
		}
		if (count($writes) > self::BATCH_LIMIT) {
			throw ApiException::tooLarge('Höchstens ' . self::BATCH_LIMIT . ' Schreibungen je Aufruf.');
		}
		$ops = [];
		$seen = [];
		foreach ($writes as $i => $w) {
			$n = $i + 1;
			try {
				if (!($w instanceof \stdClass)) {
					throw ApiException::badRequest('Jede Schreibung muss ein JSON-Objekt sein.');
				}
				$kind = $w->kind ?? null;
				$key = $w->key ?? null;
				if (!is_string($kind) || !is_string($key)) {
					throw ApiException::badRequest('Jede Schreibung braucht „kind“ und „key“ als Text.');
				}
				RecordValidator::checkAddress($kind, $key);
				$version = RecordValidator::checkVersion($w->version ?? null);
				if (!property_exists($w, 'data')) {
					throw ApiException::invalid('„data“ fehlt (null heisst löschen).');
				}
				$id = $kind . '/' . $key;
				if (isset($seen[$id])) {
					throw ApiException::invalid('Derselbe Datensatz steht zweimal in der Liste.');
				}
				$seen[$id] = true;
				if ($w->data === null) {
					$ops[] = ['kind' => $kind, 'key' => $key, 'version' => $version, 'json' => null, 'accounts' => null];
				} else {
					$v = RecordValidator::validate($kind, $key, $w->data);
					$ops[] = ['kind' => $kind, 'key' => $key, 'version' => $version, 'json' => $v['json'], 'accounts' => $v['accounts']];
				}
			} catch (ApiException $e) {
				throw new ApiException($e->getStatus(), $e->getErrorCode(), "Schreibung {$n}: " . $e->getMessage(), ['index' => $i]);
			}
		}
		return $this->write($m, $ops, true);
	}

	/** @return list<array<string,mixed>> neueste zuerst, höchstens 100 */
	public function history(Membership $m, string $kind, string $key): array {
		RecordValidator::checkAddress($kind, $key);
		$r = $this->records->findOne($m->tenantId, $kind, $key);
		// Projekt: auch seine Leitungen (nach der aktuellen Fassung).
		$raw = $r?->getData();
		$lead = $kind === 'project' && $r !== null && !$r->isTombstone() && $raw !== null
			&& $this->policy->canSeeFullProject($m, Json::decode($raw), $this->ownKeys($m));
		if (!$lead && !$this->policy->canReadHistory($m, $kind, $key, $r?->accountList() ?? [])) {
			throw ApiException::forbidden('Den Verlauf dieses Datensatzes darf dieses Konto nicht lesen.');
		}
		if ($r === null) {
			throw ApiException::notFound('Diesen Datensatz gibt es nicht.');
		}
		return array_map(static function (History $h): array {
			$raw = $h->getData();
			return [
				'version' => $h->getVersion(),
				'deleted' => $h->getDeleted() === 1,
				'modified_by' => $h->getModifiedBy(),
				'modified_at' => Time::iso($h->getModifiedAt()),
				'data' => ($h->getDeleted() === 1 || $raw === null) ? null : Json::decode($raw),
			];
		}, $this->history->findFor($m->tenantId, $kind, $key, self::HISTORY_LIMIT));
	}

	/** @return array<string,mixed> der neue Datensatz */
	public function restore(Membership $m, string $kind, string $key, \stdClass $body): array {
		$this->policy->requireWrite($m);
		RecordValidator::checkAddress($kind, $key);
		$version = RecordValidator::checkVersion($body->version ?? null, 'version');
		$current = RecordValidator::checkVersion($body->current ?? null, 'current');
		$h = $this->history->findVersion($m->tenantId, $kind, $key, $version);
		if ($h === null) {
			throw ApiException::notFound('Diese Fassung gibt es nicht (mehr).');
		}
		$raw = $h->getData();
		if ($h->getDeleted() === 1 || $raw === null) {
			throw ApiException::invalid('Diese Fassung ist eine Löschung und lässt sich nicht wiederherstellen.');
		}
		$v = RecordValidator::validate($kind, $key, Json::decode($raw));
		return $this->write($m, [[
			'kind' => $kind, 'key' => $key, 'version' => $current,
			'json' => $v['json'], 'accounts' => $v['accounts'],
		]], false)['records'][0];
	}

	/** Vermerk „Konto gelöscht“ an den Personen eines Kontos, über alle Teams. */
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
	 * Schreibungen in **einer** Transaktion: alles oder nichts.
	 *
	 * Zuerst wird die Revision des Teams erhöht. Das sperrt die Team-Zeile
	 * bis zum Commit, darum sehen alle Prüfungen danach einen festen Stand
	 * und zwei Schreibungen bekommen nie dieselbe Revision. Bei einem
	 * Konflikt rollt alles zurück – auch die Revision.
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
					// Ein Grabstein behält die Konten: So erfährt die Person, dass ihr Datensatz weg ist.
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
				count($problems) === 1
					? 'Ein Datensatz wurde inzwischen geändert; nichts wurde geschrieben.'
					: count($problems) . ' Datensätze wurden inzwischen geändert; nichts wurde geschrieben.',
				['conflicts' => array_map(static fn (array $p) => [
					'kind' => $p['op']['kind'],
					'key' => $p['op']['key'],
					'current' => $p['current'],
				], $problems)],
			);
		}
		$p = $problems[0];
		if ($p['outcome'] === VersionCheck::NOT_FOUND) {
			return ApiException::notFound('Diesen Datensatz gibt es nicht.');
		}
		$who = $p['current'] === null ? '' : ' von ' . $p['current']['modified_by'];
		return ApiException::conflict(
			$p['current'] === null
				? 'Diesen Datensatz gibt es noch nicht; zum Anlegen „version“: 0 senden.'
				: "Der Datensatz wurde inzwischen{$who} geändert.",
			['current' => $p['current']],
		);
	}
}
