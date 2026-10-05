<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\Db\AbsenceMapper;
use OCA\TimeSister\Db\AccessMapper;
use OCA\TimeSister\Db\BackupConsentMapper;
use OCA\TimeSister\Db\ClientStatusMapper;
use OCA\TimeSister\Db\History;
use OCA\TimeSister\Db\HistoryMapper;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\Tenant;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * The team backup as a ZIP (0.10.0): team, members, shares, consents,
 * every record with its history, status, absences and a fresh export of
 * every active member's time calendar. Layout in {@see ZipRules}.
 */
final class TeamExportService {
	public function __construct(
		private TenantService $tenants,
		private RecordMapper $records,
		private HistoryMapper $history,
		private AccessMapper $access,
		private BackupConsentMapper $consents,
		private AbsenceMapper $absences,
		private ClientStatusMapper $status,
		private CalendarExporter $exporter,
		private IAppManager $appManager,
		private ITempManager $temp,
		private IAppData $appData,
		private VisibleCopy $visible,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Writes the ZIP to a temporary file.
	 *
	 * @return array{path:string,name:string,size:int}
	 */
	public function build(Tenant $t): array {
		$now = $this->time->getTime();
		$path = $this->temp->getTemporaryFile('.zip');
		if ($path === false) {
			throw new \RuntimeException('No temporary file for the ZIP archive.');
		}
		$zip = ZipFile::create($path);
		$files = [];
		$put = static function (string $p, string $content, array $extra = []) use ($zip, &$files): void {
			$zip->add($p, $content);
			$files[$p] = ZipRules::fileEntry($content, $extra);
		};
		$tid = $t->getId();
		$put(ZipRules::TEAM, Json::encode(self::toObject($this->team($t))));
		$put(ZipRules::STATUS, Json::encode(self::toObject($this->statusRows($tid))));
		$put(ZipRules::ABSENCES, Json::encode(self::toObject($this->absenceRows($tid))));
		foreach ($this->recordFiles($tid) as $p => $json) {
			$put($p, $json);
		}
		foreach (array_map('strval', array_keys($this->tenants->memberRoles($tid))) as $uid) {
			$p = ZipRules::calendarPath($uid);
			$ics = $this->calendar($tid, $uid);
			if ($ics === null) {
				$files[$p] = ['missing' => true, 'uid' => $uid];
			} else {
				$put($p, $ics, ['uid' => $uid]);
			}
		}
		$manifest = ZipRules::manifest(
			['id' => $tid, 'name' => $t->getName(), 'slug' => $t->getSlug(), 'group' => $this->tenants->teamGroupOf($tid)],
			$this->appManager->getAppVersion(Application::APP_ID),
			Application::API_VERSION,
			$now,
			$files,
		);
		$zip->add(ZipRules::MANIFEST, Json::encode(self::toObject($manifest)));
		$zip->close();
		$size = filesize($path);
		return ['path' => $path, 'name' => ZipRules::zipName($t->getSlug(), $now), 'size' => $size === false ? 0 : $size];
	}

	/**
	 * Before an import into a team with content: the ZIP protected in
	 * IAppData (`exports/t<id>/<name>`) and visible with the backup owner.
	 * The protected copy must succeed; the visible one may fail.
	 *
	 * @return array{protected:string,visible:?string}
	 */
	public function keepBeforeImport(Tenant $t): array {
		$built = $this->build($t);
		$content = (string)file_get_contents($built['path']);
		@unlink($built['path']);
		$name = $built['name'];
		$folder = self::folder(self::folder($this->appData, 'exports'), 't' . $t->getId());
		if ($folder->fileExists($name)) {
			$folder->getFile($name)->putContent($content);
		} else {
			$folder->newFile($name, $content);
		}
		$visible = null;
		$owner = $this->tenants->backupOwner($t);
		if ($owner !== null) {
			try {
				$visible = $this->visible->writeTeamFile($owner, $name, $content)['path'];
			} catch (\Exception $e) {
				$this->logger->warning('TimeSister: team backup for {team} not written to the backup owner', ['app' => 'timesister', 'team' => $t->getId(), 'exception' => $e]);
			}
		}
		return ['protected' => 'exports/t' . $t->getId() . '/' . $name, 'visible' => $visible];
	}

	private static function folder(IAppData|ISimpleFolder $parent, string $name): ISimpleFolder {
		try {
			return $parent->getFolder($name);
		} catch (NotFoundException) {
			return $parent->newFolder($name);
		}
	}

	/** @return array<string,mixed> */
	private function team(Tenant $t): array {
		$tid = $t->getId();
		$members = [];
		$all = $this->tenants->members($tid);
		foreach (array_map('strval', array_keys($all)) as $uid) {
			$members[] = $this->tenants->presentMember($uid, $all[$uid]);
		}
		$access = [];
		foreach ($this->access->findByTenant($tid) as $a) {
			$access[] = ['viewer' => $a->getViewer(), 'owner' => $a->getOwner(), 'source' => $a->getSource(),
				'level' => $a->getLevel(), 'updated_by' => $a->getUpdatedBy(), 'updated_at' => Time::iso($a->getUpdatedAt())];
		}
		$consents = [];
		foreach ($this->consents->findByTenant($tid) as $c) {
			$consents[] = ['uid' => $c->getUid(), 'consent' => $c->getConsent() === 1, 'since' => Time::iso($c->getSince()),
				'revoked_at' => Time::iso($c->getRevokedAt()), 'notice' => $c->getNotice(), 'notice_at' => Time::iso($c->getNoticeAt())];
		}
		return $this->tenants->presentTeam($t) + [
			'revision' => $t->getRevision(),
			'created_at' => Time::iso($t->getCreatedAt()),
			'members' => $members,
			'access' => $access,
			'consents' => $consents,
		];
	}

	/** @return array<string,array<string,mixed>> uid → status */
	private function statusRows(int $tid): array {
		$out = [];
		foreach ($this->status->findByTenant($tid) as $s) {
			$weeks = $s->getJobWeeks();
			$out[$s->getUid()] = [
				'seen_at' => Time::iso($s->getSeenAt()),
				'app_version' => $s->getAppVersion(),
				'last_sync' => Time::iso($s->getLastSync()),
				'calendar_url' => $s->getCalendarUrl(),
				'calendar_shared' => $s->getCalendarShared(),
				'job_weeks' => $weeks === null ? null : Json::decode($weeks),
				'job_weeks_at' => Time::iso($s->getJobWeeksAt()),
			];
		}
		return $out;
	}

	/** @return array<string,list<array{source_uid:string,kind:string,start:string,end:string}>> uid → absences */
	private function absenceRows(int $tid): array {
		$out = [];
		foreach ($this->absences->byTenant($tid) as $a) {
			$out[$a->getUid()][] = ['source_uid' => $a->getSourceUid(), 'kind' => $a->getKind(), 'start' => $a->getStart(), 'end' => $a->getEnd()];
		}
		return $out;
	}

	/** @return array<string,string> path → JSON of the record with its history */
	private function recordFiles(int $tid): array {
		$versions = [];
		foreach ($this->history->findByTenant($tid) as $h) {
			$versions[$h->getKind() . '/' . $h->getRkey()][] = self::version($h);
		}
		$out = [];
		foreach ($this->records->findAll($tid) as $r) {
			$raw = $r->getData();
			$row = [
				'kind' => $r->getKind(),
				'key' => $r->getRkey(),
				'version' => $r->getVersion(),
				'revision' => $r->getRevision(),
				'deleted' => $r->isTombstone(),
				'modified_by' => $r->getModifiedBy(),
				'modified_at' => Time::iso($r->getModifiedAt()),
				'data' => ($r->isTombstone() || $raw === null) ? null : Json::decode($raw),
				'accounts' => $r->accountList(),
				'account_deleted_at' => Time::iso($r->getAccountDeletedAt()),
				'history' => $versions[$r->getKind() . '/' . $r->getRkey()] ?? [],
			];
			$out[ZipRules::recordPath($r->getKind(), $r->getRkey())] = Json::encode(self::toObject($row));
		}
		return $out;
	}

	/** @return array<string,mixed> */
	private static function version(History $h): array {
		$raw = $h->getData();
		return [
			'version' => $h->getVersion(),
			'deleted' => $h->getDeleted() === 1,
			'modified_by' => $h->getModifiedBy(),
			'modified_at' => Time::iso($h->getModifiedAt()),
			'data' => ($h->getDeleted() === 1 || $raw === null) ? null : Json::decode($raw),
		];
	}

	/** The time calendar as the weekly job exports it; null when missing or failing. */
	private function calendar(int $tid, string $uid): ?string {
		try {
			$url = $this->status->findOne($tid, $uid)?->getCalendarUrl();
			$ics = $this->exporter->export($uid, $url);
			if ($ics !== null && strlen($ics) > BackupRules::MAX_BYTES) {
				$this->logger->warning('TimeSister: calendar of {uid} larger than 20 MB, not in the team backup', ['app' => 'timesister', 'uid' => $uid]);
				return null;
			}
			return $ics;
		} catch (NotFoundException|\Throwable $e) {
			$this->logger->warning('TimeSister: calendar of {uid} not exported for the team backup', ['app' => 'timesister', 'uid' => $uid, 'exception' => $e]);
			return null;
		}
	}

	/** Associative arrays as objects, so `{}` never becomes `[]` in the JSON; lists stay lists. */
	public static function toObject(array $a): \stdClass {
		$o = new \stdClass();
		foreach ($a as $k => $v) {
			$o->{(string)$k} = self::convert($v);
		}
		return $o;
	}

	private static function convert(mixed $v): mixed {
		if (!is_array($v)) {
			return $v;
		}
		return array_is_list($v) ? array_map(static fn (mixed $e): mixed => self::convert($e), $v) : self::toObject($v);
	}
}
