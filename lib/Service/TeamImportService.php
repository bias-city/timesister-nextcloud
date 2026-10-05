<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Absence;
use OCA\TimeSister\Db\AbsenceMapper;
use OCA\TimeSister\Db\Access;
use OCA\TimeSister\Db\AccessMapper;
use OCA\TimeSister\Db\BackupConsent;
use OCA\TimeSister\Db\BackupConsentMapper;
use OCA\TimeSister\Db\ClientStatus;
use OCA\TimeSister\Db\ClientStatusMapper;
use OCA\TimeSister\Db\History;
use OCA\TimeSister\Db\HistoryMapper;
use OCA\TimeSister\Db\Member;
use OCA\TimeSister\Db\MemberMapper;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\Tenant;
use OCA\TimeSister\Db\TenantMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Group\ISubAdmin;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Restoring or moving a team from a ZIP (0.10.0): preview with the
 * accounts of this Nextcloud, then import with a mapping of old to new
 * accounts, `merge` or `replace`. Reading and the rules: {@see ZipReader},
 * {@see ZipRules}. Calendars become backups per person, never calendar
 * events.
 */
final class TeamImportService {
	private const ACCOUNT_LIMIT = 500;

	public function __construct(
		private IDBConnection $db,
		private IAppData $appData,
		private ITempManager $temp,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private ISubAdmin $subAdmin,
		private TenantMapper $tenantMapper,
		private TenantService $tenants,
		private RecordMapper $records,
		private HistoryMapper $history,
		private MemberMapper $members,
		private AccessMapper $access,
		private BackupConsentMapper $consents,
		private AbsenceMapper $absences,
		private ClientStatusMapper $status,
		private TeamExportService $export,
		private BackupService $backups,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * POST /admin/teams/{id}/import/preview: the uploaded ZIP checked and
	 * kept under a token; what it holds and which accounts exist here.
	 *
	 * @return array<string,mixed>
	 */
	public function preview(Tenant $t, mixed $upload): array {
		if (!is_array($upload) || ($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)) {
			$code = is_array($upload) ? ($upload['error'] ?? null) : null;
			if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
				throw ApiException::tooLarge('The backup may be at most 200 MB.');
			}
			throw ApiException::badRequest('Upload the backup as the file field “file”.');
		}
		$tmp = $upload['tmp_name'];
		$zip = ZipFile::open($tmp);
		try {
			$b = ZipReader::read($zip);
		} finally {
			$zip->close();
		}
		$folder = $this->folder($this->appData, 'import');
		$this->tidy($folder);
		$token = bin2hex(random_bytes(16));
		$in = fopen($tmp, 'rb');
		if ($in === false) {
			throw new \RuntimeException('The upload cannot be read.');
		}
		// newFile() consumes and closes the stream itself.
		$folder->newFile($token . '.zip', $in);
		return ['token' => $token] + $this->summary($t, $b);
	}

	/**
	 * POST /admin/teams/{id}/import.
	 *
	 * @param array<string,mixed> $in `token`, `mapping`, `mode`
	 * @return array<string,mixed>
	 */
	public function import(Tenant $t, string $actor, array $in): array {
		$token = ZipRules::checkToken($in['token'] ?? null);
		$mode = ZipRules::checkMode($in['mode'] ?? null);
		$path = $this->fetch($token);
		$zip = ZipFile::open($path);
		try {
			$b = ZipReader::read($zip);
			$mapping = ZipRules::checkMapping($in['mapping'] ?? null, ZipReader::accounts($b));
			foreach ($mapping as $old => $new) {
				if ($new !== null && !$this->userManager->userExists($new)) {
					throw ApiException::invalid(Message::of('The account “{uid}” does not exist here.', ['uid' => $new]));
				}
			}
			$tid = $t->getId();
			$pre = $this->hasContent($tid) ? $this->keep($t) : null;
			$plan = $this->plan($tid, $b, $mapping, $mode);
			$now = $this->time->getTime();
			$this->db->beginTransaction();
			try {
				$counts = $this->writeRecords($tid, $plan, $actor, $now);
				$counts['members'] = $this->writeMembers($tid, $b, $mapping, $mode, $actor, $now);
				$counts['access'] = $this->writeAccess($tid, $b, $mapping, $mode, $now);
				$counts['consents'] = $this->writeConsents($tid, $b, $mapping, $mode);
				$counts['absences'] = $this->writeAbsences($tid, $b, $mapping, $mode, $now);
				$counts['status'] = $this->writeStatus($tid, $b, $mapping, $mode, $now);
				$this->db->commit();
			} catch (\Throwable $e) {
				if ($this->db->inTransaction()) {
					$this->db->rollBack();
				}
				throw $e;
			}
			$this->tenants->reset();
			$groups = $this->joinGroup($tid, $b, $mapping);
			$calendars = $this->storeCalendars($tid, $zip, $b, $mapping);
		} finally {
			$zip->close();
			@unlink($path);
		}
		$this->forget($token);
		$this->tenants->reset();
		$without = [];
		foreach ($mapping as $old => $new) {
			if ($new === null) {
				$without[] = $old;
			}
		}
		return [
			'team' => ['id' => $t->getId(), 'name' => $t->getName()],
			'mode' => $mode,
			'source' => ['name' => $b['manifest']['team']['name'], 'taken_at' => $b['manifest']['taken_at']],
			'pre_backup' => $pre,
			'records' => $counts['records'],
			'members' => $counts['members'] + $groups,
			'access' => $counts['access'],
			'consents' => $counts['consents'],
			'absences' => $counts['absences'],
			'status' => $counts['status'],
			'without_account' => $without,
			'calendars' => $calendars,
		];
	}

	/**
	 * @param array<string,mixed> $b from {@see ZipReader::read}
	 * @return array<string,mixed>
	 */
	private function summary(Tenant $t, array $b): array {
		$tid = $t->getId();
		$here = $this->tenants->members($tid);
		$persons = [];
		foreach ($b['team']['members'] as $m) {
			$persons[] = $m + [
				'exists' => $this->userManager->userExists($m['uid']),
				'member' => isset($here[$m['uid']]),
			];
		}
		$counts = [];
		foreach ($b['records'] as $r) {
			$counts[$r['kind']] = ($counts[$r['kind']] ?? 0) + 1;
		}
		$accounts = [];
		foreach ($this->userManager->searchDisplayName('', self::ACCOUNT_LIMIT) as $u) {
			$accounts[] = ['uid' => $u->getUID(), 'display_name' => $u->getDisplayName()];
		}
		usort($accounts, static fn (array $a, array $c) => strcasecmp($a['display_name'], $c['display_name']));
		return [
			'format' => $b['manifest']['format'],
			'app_version' => $b['manifest']['app_version'],
			'taken_at' => $b['manifest']['taken_at'],
			'team' => $b['manifest']['team'],
			'records' => $counts,
			'persons' => $persons,
			'calendars' => $b['calendars'],
			'absences' => count($b['absences']),
			'target' => ['id' => $tid, 'name' => $t->getName(), 'empty' => !$this->hasContent($tid)],
			'accounts' => $accounts,
		];
	}

	/** Records, rows or members: then a backup is taken first. */
	private function hasContent(int $tid): bool {
		return $this->records->stats($tid)['all'] > 0
			|| $this->members->entriesByTenant($tid) !== []
			|| $this->access->findByTenant($tid) !== []
			|| $this->consents->findByTenant($tid) !== []
			|| $this->absences->byTenant($tid) !== [];
	}

	/** @return array{protected:string,visible:?string} */
	private function keep(Tenant $t): array {
		try {
			return $this->export->keepBeforeImport($t);
		} catch (\Throwable $e) {
			$this->logger->error('TimeSister: backup before import into team {team} failed', ['app' => 'timesister', 'team' => $t->getId(), 'exception' => $e]);
			throw ApiException::conflict('The backup before importing could not be written; nothing was changed.');
		}
	}

	private function folder(IAppData|ISimpleFolder $parent, string $name): ISimpleFolder {
		try {
			return $parent->getFolder($name);
		} catch (NotFoundException) {
			return $parent->newFolder($name);
		}
	}

	/** The kept upload as a temporary file. */
	private function fetch(string $token): string {
		try {
			$file = $this->appData->getFolder('import')->getFile($token . '.zip');
		} catch (NotFoundException) {
			throw ApiException::notFound('The preview has expired. Upload the backup again.');
		}
		$path = $this->temp->getTemporaryFile('.zip');
		$in = $file->read();
		$out = $path === false ? false : fopen($path, 'wb');
		if ($in === false || $out === false || $path === false) {
			throw new \RuntimeException('The kept upload cannot be read.');
		}
		stream_copy_to_stream($in, $out);
		fclose($in);
		fclose($out);
		return $path;
	}

	private function forget(string $token): void {
		try {
			$this->appData->getFolder('import')->getFile($token . '.zip')->delete();
		} catch (NotFoundException) {
		}
	}

	/** Uploads older than a day go. */
	private function tidy(ISimpleFolder $folder): void {
		$limit = $this->time->getTime() - ZipRules::TOKEN_TTL;
		foreach ($folder->getDirectoryListing() as $f) {
			if ($f->getMTime() < $limit) {
				$f->delete();
			}
		}
	}

	/**
	 * Which records are written how. Keys and accounts are already mapped;
	 * every record is validated like a client write.
	 *
	 * @param array<string,mixed> $b
	 * @param array<string,?string> $mapping
	 * @return array{ops:list<array<string,mixed>>,tombstones:list<Record>}
	 */
	private function plan(int $tid, array $b, array $mapping, string $mode): array {
		$current = [];
		foreach ($this->records->findAll($tid) as $r) {
			$current[$r->getKind() . '/' . $r->getRkey()] = $r;
		}
		$ops = [];
		$seen = [];
		foreach ($b['records'] as $src) {
			$kind = $src['kind'];
			$key = ZipRules::mapKey($kind, $src['key'], $mapping);
			$id = $kind . '/' . $key;
			if (isset($seen[$id])) {
				throw ApiException::invalid(Message::of('Two records of the backup would become “{id}” here. Change the mapping.', ['id' => $id]));
			}
			$seen[$id] = true;
			$cur = $current[$id] ?? null;
			$mapped = $this->mapRecord($src, $key, $mapping);
			$decision = ZipRules::decide(
				$cur === null ? null : ['modified_at' => $cur->getModifiedAt(), 'deleted' => $cur->isTombstone(), 'data' => $cur->getData()],
				['modified_at' => $src['modified_at'], 'deleted' => $src['deleted'], 'data' => $mapped['json']],
				$mode,
			);
			if ($decision === 'skip') {
				continue;
			}
			$ops[] = ['do' => $decision, 'kind' => $kind, 'key' => $key, 'cur' => $cur, 'src' => $src] + $mapped;
		}
		$tombstones = [];
		if ($mode === ZipRules::MODE_REPLACE) {
			foreach ($current as $id => $r) {
				if (!isset($seen[$id]) && !$r->isTombstone()) {
					$tombstones[] = $r;
				}
			}
		}
		return ['ops' => $ops, 'tombstones' => $tombstones];
	}

	/**
	 * @param array<string,mixed> $src from {@see ZipRules::checkRecordFile}
	 * @param array<string,?string> $mapping
	 * @return array{json:?string,accounts:list<string>,history:list<array{version:int,deleted:bool,modified_by:string,modified_at:int,json:?string}>}
	 */
	private function mapRecord(array $src, string $key, array $mapping): array {
		$kind = (string)$src['kind'];
		$convert = static function (mixed $data, bool $deleted) use ($kind, $key, $mapping): array {
			if ($deleted || $data === null) {
				return ['json' => null, 'accounts' => []];
			}
			try {
				$v = RecordValidator::validate($kind, $key, ZipRules::mapData($data, $mapping));
			} catch (ApiException $e) {
				throw ApiException::invalid(Message::of('Record “{id}” of the backup: {message}', ['id' => $kind . '/' . $key, 'message' => $e->getText()]));
			}
			return $v;
		};
		$cur = $convert($src['data'], (bool)$src['deleted']);
		$accounts = $cur['accounts'];
		if ($cur['json'] === null) {
			// A tombstone keeps the accounts, mapped.
			foreach ((array)$src['accounts'] as $a) {
				$new = ZipRules::mapUid((string)$a, $mapping);
				if ($new !== null && !in_array($new, $accounts, true)) {
					$accounts[] = $new;
				}
			}
		}
		$history = [];
		foreach ((array)$src['history'] as $h) {
			$history[] = [
				'version' => (int)$h['version'], 'deleted' => (bool)$h['deleted'],
				'modified_by' => ZipRules::keepUid((string)$h['modified_by'], $mapping), 'modified_at' => (int)$h['modified_at'],
				'json' => $convert($h['data'], (bool)$h['deleted'])['json'],
			];
		}
		return ['json' => $cur['json'], 'accounts' => $accounts, 'history' => $history];
	}

	/**
	 * In the open transaction: the team's revision first (locks the row),
	 * then every record and its versions.
	 *
	 * @param array{ops:list<array<string,mixed>>,tombstones:list<Record>} $plan
	 * @return array{records:array<string,mixed>}
	 */
	private function writeRecords(int $tid, array $plan, string $actor, int $now): array {
		$n = count($plan['ops']) + count($plan['tombstones']);
		$c = ['inserted' => 0, 'updated' => 0, 'tombstoned' => 0, 'versions' => 0, 'by_kind' => new \stdClass()];
		if ($n === 0) {
			return ['records' => $c];
		}
		$rev = $this->tenantMapper->bumpRevision($tid, $n) - $n;
		foreach ($plan['ops'] as $op) {
			$rev++;
			/** @var ?Record $cur */
			$cur = $op['cur'];
			/** @var array<string,mixed> $src */
			$src = $op['src'];
			$kind = (string)$op['kind'];
			$key = (string)$op['key'];
			$json = is_string($op['json']) ? $op['json'] : null;
			/** @var list<string> $accounts */
			$accounts = $op['accounts'];
			$modifiedBy = (string)$src['modified_by'];
			$modifiedAt = (int)$src['modified_at'];
			$version = $cur === null ? (int)$src['version'] : $cur->getVersion() + 1;
			$this->putRecord($tid, $cur, $kind, $key, $json, $accounts, $version, $rev, $modifiedBy, $modifiedAt);
			/** @var list<array{version:int,deleted:bool,modified_by:string,modified_at:int,json:?string}> $history */
			$history = $op['history'];
			if ($cur === null) {
				// Verbatim, every version up to the current one.
				$have = false;
				foreach ($history as $h) {
					if ($h['version'] > $version) {
						continue;
					}
					$have = $have || $h['version'] === $version;
					$this->putHistory($tid, $kind, $key, $h['version'], $h['json'], $h['modified_by'], $h['modified_at']);
					$c['versions']++;
				}
				if (!$have) {
					$this->putHistory($tid, $kind, $key, $version, $json, $modifiedBy, $modifiedAt);
					$c['versions']++;
				}
				$c['inserted']++;
			} else {
				$this->putHistory($tid, $kind, $key, $version, $json, $modifiedBy, $modifiedAt);
				$c['versions']++;
				$c['updated']++;
			}
			$c['by_kind']->{$kind} = (int)($c['by_kind']->{$kind} ?? 0) + 1;
		}
		foreach ($plan['tombstones'] as $r) {
			$rev++;
			$version = $r->getVersion() + 1;
			$this->putRecord($tid, $r, $r->getKind(), $r->getRkey(), null, $r->accountList(), $version, $rev, $actor, $now);
			$this->putHistory($tid, $r->getKind(), $r->getRkey(), $version, null, $actor, $now);
			$c['tombstoned']++;
			$c['versions']++;
		}
		return ['records' => $c];
	}

	/** @param list<string> $accounts */
	private function putRecord(int $tid, ?Record $cur, string $kind, string $key, ?string $json, array $accounts, int $version, int $rev, string $by, int $at): void {
		$r = $cur ?? new Record();
		if ($cur === null) {
			$r->setTenantId($tid);
			$r->setKind($kind);
			$r->setRkey($key);
		}
		$r->setData($json);
		if ($json !== null || $cur === null) {
			$r->setAccounts($accounts === [] ? null : Json::encode($accounts));
		}
		$r->setVersion($version);
		$r->setRevision($rev);
		$r->setDeleted($json === null ? 1 : 0);
		$r->setModifiedBy($by);
		$r->setModifiedAt($at);
		$cur === null ? $this->records->insert($r) : $this->records->update($r);
	}

	private function putHistory(int $tid, string $kind, string $key, int $version, ?string $json, string $by, int $at): void {
		$h = new History();
		$h->setTenantId($tid);
		$h->setKind($kind);
		$h->setRkey($key);
		$h->setVersion($version);
		$h->setDeleted($json === null ? 1 : 0);
		$h->setData($json);
		$h->setModifiedBy($by);
		$h->setModifiedAt($at);
		$this->history->insert($h);
	}

	/**
	 * Role rows (`lead`, leaving, overriding). Merge keeps existing rows.
	 *
	 * @param array<string,mixed> $b
	 * @param array<string,?string> $mapping
	 * @return array{rows:int}
	 */
	private function writeMembers(int $tid, array $b, array $mapping, string $mode, string $actor, int $now): array {
		$n = 0;
		foreach ($b['team']['members'] as $m) {
			$uid = ZipRules::mapUid($m['uid'], $mapping);
			if ($uid === null) {
				continue;
			}
			$found = $this->members->findOne($tid, $uid);
			if ($found !== null && $mode === ZipRules::MODE_MERGE) {
				continue;
			}
			$row = $found ?? new Member();
			if ($found === null) {
				$row->setTenantId($tid);
				$row->setUid($uid);
			}
			$row->setRole($m['role'] === Role::LEAD ? Role::LEAD : null);
			$row->setLeftAt($m['left_at']);
			$row->setMayOverride($m['may_override'] ? 1 : 0);
			$row->setUpdatedBy($actor);
			$row->setUpdatedAt($now);
			$found === null ? $this->members->insert($row) : $this->members->update($row);
			$n++;
		}
		return ['rows' => $n];
	}

	/**
	 * @param array<string,mixed> $b
	 * @param array<string,?string> $mapping
	 * @return array{rows:int}
	 */
	private function writeAccess(int $tid, array $b, array $mapping, string $mode, int $now): array {
		if ($mode === ZipRules::MODE_REPLACE) {
			$this->access->deleteByTenant($tid);
		}
		$n = 0;
		foreach ($b['team']['access'] as $f) {
			$viewer = ZipRules::mapUid($f['viewer'], $mapping);
			$owner = ZipRules::mapUid($f['owner'], $mapping);
			if ($viewer === null || $owner === null || $viewer === $owner) {
				continue;
			}
			if ($mode === ZipRules::MODE_MERGE && $this->access->findOne($tid, $viewer, $owner, $f['source']) !== null) {
				continue;
			}
			$a = new Access();
			$a->setTenantId($tid);
			$a->setViewer($viewer);
			$a->setOwner($owner);
			$a->setSource($f['source']);
			$a->setLevel($f['level']);
			$a->setUpdatedBy(ZipRules::keepUid($f['updated_by'], $mapping));
			$a->setUpdatedAt($f['updated_at'] ?? $now);
			$this->access->insert($a);
			$n++;
		}
		return ['rows' => $n];
	}

	/**
	 * @param array<string,mixed> $b
	 * @param array<string,?string> $mapping
	 * @return array{rows:int}
	 */
	private function writeConsents(int $tid, array $b, array $mapping, string $mode): array {
		if ($mode === ZipRules::MODE_REPLACE) {
			$this->consents->deleteByTenant($tid);
		}
		$n = 0;
		foreach ($b['team']['consents'] as $c) {
			$uid = ZipRules::mapUid($c['uid'], $mapping);
			if ($uid === null || ($mode === ZipRules::MODE_MERGE && $this->consents->findOne($tid, $uid) !== null)) {
				continue;
			}
			$row = new BackupConsent();
			$row->setTenantId($tid);
			$row->setUid($uid);
			$row->setConsent($c['consent'] ? 1 : 0);
			$row->setSince($c['since']);
			$row->setRevokedAt($c['revoked_at']);
			$row->setNotice($c['notice']);
			$row->setNoticeAt($c['notice_at']);
			$this->consents->insert($row);
			$n++;
		}
		return ['rows' => $n];
	}

	/**
	 * @param array<string,mixed> $b
	 * @param array<string,?string> $mapping
	 * @return array{rows:int}
	 */
	private function writeAbsences(int $tid, array $b, array $mapping, string $mode, int $now): array {
		$have = [];
		if ($mode === ZipRules::MODE_REPLACE) {
			$this->absences->deleteByTenant($tid);
		} else {
			foreach ($this->absences->byTenant($tid) as $a) {
				$have[$a->getUid()] = true;
			}
		}
		$n = 0;
		foreach ($b['absences'] as $old => $items) {
			$uid = ZipRules::mapUid((string)$old, $mapping);
			if ($uid === null || isset($have[$uid])) {
				continue;
			}
			foreach ($items as $i) {
				$a = new Absence();
				$a->setTenantId($tid);
				$a->setUid($uid);
				$a->setSourceUid($i['source_uid']);
				$a->setKind($i['kind']);
				$a->setStart($i['start']);
				$a->setEnd($i['end']);
				$a->setUpdatedAt($now);
				$this->absences->insert($a);
				$n++;
			}
		}
		return ['rows' => $n];
	}

	/**
	 * Status rows (sign of life, calendar address, weekly numbers of the
	 * Jobs). Merge only fills missing rows.
	 *
	 * @param array<string,mixed> $b
	 * @param array<string,?string> $mapping
	 * @return array{rows:int}
	 */
	private function writeStatus(int $tid, array $b, array $mapping, string $mode, int $now): array {
		$n = 0;
		foreach ($b['status'] as $old => $s) {
			$uid = ZipRules::mapUid((string)$old, $mapping);
			if ($uid === null) {
				continue;
			}
			$found = $this->status->findOne($tid, $uid);
			if ($found !== null && $mode === ZipRules::MODE_MERGE) {
				continue;
			}
			$row = $found ?? new ClientStatus();
			if ($found === null) {
				$row->setTenantId($tid);
				$row->setUid($uid);
			}
			$row->setSeenAt($s['seen_at'] ?? $now);
			$row->setAppVersion($s['app_version']);
			$row->setLastSync($s['last_sync']);
			$row->setCalendarUrl($s['calendar_url']);
			$row->setCalendarShared($s['calendar_shared']);
			$row->setJobWeeks($s['job_weeks']);
			$row->setJobWeeksAt($s['job_weeks_at']);
			$found === null ? $this->status->insert($row) : $this->status->update($row);
			$n++;
		}
		return ['rows' => $n];
	}

	/**
	 * After the transaction: mapped members into the team group (never out
	 * of it), Team Admins as group admins.
	 *
	 * @param array<string,mixed> $b
	 * @param array<string,?string> $mapping
	 * @return array{added_to_group:int,group_admins:int}
	 */
	private function joinGroup(int $tid, array $b, array $mapping): array {
		$gid = $this->tenants->teamGroupOf($tid);
		$group = $gid === null ? null : $this->groupManager->get($gid);
		$c = ['added_to_group' => 0, 'group_admins' => 0];
		if ($group === null) {
			return $c;
		}
		foreach ($b['team']['members'] as $m) {
			$uid = ZipRules::mapUid($m['uid'], $mapping);
			$user = $uid === null ? null : $this->userManager->get($uid);
			if (!$user instanceof IUser) {
				continue;
			}
			if (!$group->inGroup($user)) {
				$group->addUser($user);
				$c['added_to_group']++;
			}
			if ($m['role'] === Role::ADMIN && !$this->subAdmin->isSubAdminOfGroup($user, $group)) {
				$this->subAdmin->createSubAdmin($user, $group);
				$c['group_admins']++;
			}
		}
		return $c;
	}

	/**
	 * The calendars as backups per person on the day the backup was taken.
	 *
	 * @param array<string,mixed> $b
	 * @param array<string,?string> $mapping
	 * @return list<array<string,mixed>>
	 */
	private function storeCalendars(int $tid, ZipFile $zip, array $b, array $mapping): array {
		$day = substr((string)$b['manifest']['taken_at'], 0, 10);
		if (!Time::isDay($day)) {
			$day = gmdate('Y-m-d', $this->time->getTime());
		}
		$out = [];
		foreach ($b['calendars'] as $cal) {
			$uid = ZipRules::mapUid((string)$cal['uid'], $mapping);
			$missing = $cal['missing'] === true;
			if ($missing || $uid === null) {
				$out[] = ['uid' => $cal['uid'], 'stored' => false, 'reason' => $missing ? 'missing' : 'no_account'];
				continue;
			}
			try {
				$ics = $zip->read($cal['path']);
				if (!BackupRules::isCalendar($ics)) {
					$out[] = ['uid' => $uid, 'stored' => false, 'reason' => 'not_a_calendar'];
					continue;
				}
				$r = $this->backups->storeImported($tid, $uid, $day, $ics);
				$out[] = ['uid' => $uid, 'stored' => !$r['kept'], 'kept_existing' => $r['kept'], 'backup' => $r['backup']];
			} catch (\Throwable $e) {
				$this->logger->error('TimeSister: imported calendar of {uid} not stored', ['app' => 'timesister', 'uid' => $uid, 'exception' => $e]);
				$out[] = ['uid' => $uid, 'stored' => false, 'reason' => 'failed'];
			}
		}
		return $out;
	}
}
