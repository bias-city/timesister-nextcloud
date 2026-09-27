<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Backup;
use OCA\TimeSister\Db\BackupFile;
use OCA\TimeSister\Db\BackupFileMapper;
use OCA\TimeSister\Db\BackupMapper;
use OCA\TimeSister\Db\ClientStatusMapper;
use OCA\TimeSister\Db\TenantMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Calendar backups. With the person's consent: protected in IAppData (the
 * API reads from there) and visible with the backup owner. Independently
 * of that, optionally a copy in the own home. The server only backs up
 * to a given store when the checksum has changed since the last time.
 */
final class BackupService {
	public function __construct(
		private BackupMapper $backups,
		private BackupFileMapper $files,
		private TenantMapper $tenantMapper,
		private ClientStatusMapper $status,
		private TenantService $tenants,
		private ConsentService $consent,
		private OwnCopyService $ownCopy,
		private WeekMarks $marks,
		private CalendarExporter $exporter,
		private ProtectedStore $store,
		private VisibleCopy $visible,
		private AccessPolicy $policy,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/** @return array{id:int,uid:string,taken_on:string,size:int,sha256:string,source:string,file_path:?string} */
	public function present(Backup $b): array {
		return [
			'id' => $b->getId(),
			'uid' => $b->getUid(),
			'taken_on' => $b->getTakenOn(),
			'size' => $b->getSize(),
			'sha256' => $b->getSha256(),
			'source' => $b->getSource(),
			'file_path' => $b->getFilePath(),
		];
	}

	/**
	 * POST /backups: the own backup of one day, from the client (API
	 * version 1: always saved, a second one on the same day replaces the
	 * first).
	 *
	 * @return array{id:int,uid:string,taken_on:string,size:int,sha256:string,source:string,file_path:?string}
	 */
	public function upload(Membership $m, mixed $day, mixed $b64): array {
		$this->consent->require($m->tenantId, $m->uid);
		$day = BackupRules::checkDay($day);
		$bin = BackupRules::decode($b64);
		return $this->present($this->store($m->tenantId, $m->uid, $day, $bin, BackupRules::SOURCE_CLIENT));
	}

	/**
	 * POST /backups/now: back up immediately. Other accounts of the team
	 * only for manager and admin, and only with consent. The own account
	 * also without consent, when the own copy is on; then only that is
	 * made.
	 *
	 * @return array<string,mixed> entry as in GET /backups (plus `own_copy` when on) or `{own_copy}`
	 */
	public function now(Membership $m, mixed $uid): array {
		$uid = ($uid === null || $uid === '') ? $m->uid : $uid;
		if (!is_string($uid)) {
			throw ApiException::invalidField('uid', true);
		}
		if (!$this->policy->canSeeBackupsOf($m, $uid)) {
			throw ApiException::forbidden('Only the team’s managers and admins back up other accounts.');
		}
		if ($uid !== $m->uid) {
			if (!array_key_exists($uid, $this->tenants->memberRoles($m->tenantId))) {
				throw ApiException::notFound('This account is not part of the team.');
			}
			$this->consent->require($m->tenantId, $uid);
			// Only the weekly job writes into another person's home.
			[$b] = $this->backupFor($m->tenantId, $uid, true, false);
			return $b === null ? [] : $this->present($b);
		}
		$admin = $this->consent->has($m->tenantId, $uid);
		$own = $this->ownCopy->enabled($uid);
		if (!$admin && !$own) {
			throw ConsentService::refused();
		}
		[$b, $path] = $this->backupFor($m->tenantId, $uid, $admin, $own);
		if ($b === null) {
			return ['own_copy' => $path];
		}
		return $this->present($b) + ($path === null ? [] : ['own_copy' => $path]);
	}

	/**
	 * Background job: at most one check per member, store and ISO week –
	 * with the admin only with consent, in the own folder only when on.
	 * Errors per account go to the log, then it continues. At most `$max`
	 * accounts per run; the rest in the next one.
	 *
	 * @return array{done:int,failed:int,no_calendar:int}
	 */
	public function weekly(int $max): array {
		$today = $this->today();
		$n = ['done' => 0, 'failed' => 0, 'no_calendar' => 0];
		$ownUsers = $this->ownCopy->enabledUsers();
		foreach ($this->tenantMapper->findAll() as $t) {
			$tid = $t->getId();
			$consents = $this->consent->byTenant($tid);
			// Purely numeric identifiers come as int keys.
			foreach (array_map('strval', array_keys($this->tenants->memberRoles($tid))) as $uid) {
				$admin = ConsentService::granted($consents[$uid] ?? null) && !$this->marks->checked($uid, WeekMarks::ADMIN, $today);
				$own = isset($ownUsers[$uid]) && !$this->marks->checked($uid, WeekMarks::OWN, $today);
				if (!$admin && !$own) {
					continue;
				}
				if (array_sum($n) >= $max) {
					return $n;
				}
				try {
					$this->backupFor($tid, $uid, $admin, $own);
					$n['done']++;
				} catch (ApiException $e) {
					// No time calendar (never synced yet) is not an error.
					$missing = $e->getStatus() === 404;
					$n[$missing ? 'no_calendar' : 'failed']++;
					$this->logger->log($missing ? 'info' : 'warning', 'TimeSister: no backup for {uid}: {msg}', [
						'app' => 'timesister', 'uid' => $uid, 'msg' => $e->getMessage(),
					]);
				} catch (\Throwable $e) {
					$n['failed']++;
					$this->logger->error('TimeSister: backup for {uid} (team {team}) failed', [
						'app' => 'timesister', 'uid' => $uid, 'team' => $tid, 'exception' => $e,
					]);
				}
			}
		}
		return $n;
	}

	/** @return list<array{id:int,uid:string,taken_on:string,size:int,sha256:string,source:string,file_path:?string}> */
	public function list(Membership $m, ?string $uid): array {
		$uid = ($uid === null || $uid === '') ? $m->uid : $uid;
		if (!$this->policy->canSeeBackupsOf($m, $uid)) {
			throw ApiException::forbidden('Only the team’s managers and admins see other people’s backups.');
		}
		return array_map(fn (Backup $b) => $this->present($b), $this->backups->listFor($m->tenantId, $uid));
	}

	/** @return array<string,mixed> */
	public function get(Membership $m, int $id): array {
		$b = $this->backups->findInTenant($m->tenantId, $id);
		if ($b === null) {
			throw ApiException::notFound('This backup does not exist.');
		}
		if (!$this->policy->canSeeBackupsOf($m, $b->getUid())) {
			throw ApiException::forbidden('Only the team’s managers and admins see other people’s backups.');
		}
		try {
			$content = $this->store->get($b->getTenantId(), $b->getUid(), $b->getTakenOn());
		} catch (NotFoundException) {
			throw ApiException::notFound('The file of this backup is missing.');
		}
		return $this->present($b) + ['ics_base64' => base64_encode($content)];
	}

	/**
	 * Export the time calendar once and store it per store only on a
	 * changed checksum: with the admin (`$admin`, with the protected
	 * copy) and in the own home (`$own`). 404 without a calendar. If the
	 * own copy fails alongside a backup with the admin, that one stays.
	 *
	 * @return array{0:?Backup,1:?string} backup with the admin, path of the own copy
	 */
	private function backupFor(int $tenantId, string $uid, bool $admin, bool $own): array {
		$url = $this->status->findOne($tenantId, $uid)?->getCalendarUrl();
		$ics = $this->exporter->export($uid, $url);
		if ($ics === null) {
			throw ApiException::notFound('This account has no time calendar.');
		}
		if (strlen($ics) > BackupRules::MAX_BYTES) {
			throw ApiException::tooLarge('The time calendar is larger than 20 MB and is not backed up.');
		}
		$sha = hash('sha256', $ics);
		$today = $this->today();

		$b = null;
		if ($admin) {
			$last = $this->backups->latestFor($tenantId, $uid);
			$b = ($last !== null && $last->getSha256() === $sha)
				? $last
				: $this->store($tenantId, $uid, $today, $ics, BackupRules::SOURCE_SERVER);
			$this->marks->mark($uid, WeekMarks::ADMIN, $today);
		}
		$path = null;
		if ($own) {
			try {
				// Unchanged and still there: it stays with it. If the person
				// deleted or moved it, a new one is created.
				$last = $this->files->latest($uid, BackupFile::OWN);
				if ($last !== null && $last->getSha256() === $sha
					&& $this->visible->isTracked($uid, $last->getFileId(), $last->getPath())) {
					$path = $last->getPath();
				} else {
					$w = $this->visible->writeOwn($uid, $today, $ics);
					$this->track($tenantId, $uid, BackupFile::OWN, $uid, $w, $today, $sha);
					$path = $w['path'];
				}
				$this->marks->mark($uid, WeekMarks::OWN, $today);
			} catch (\Exception $e) {
				if ($b === null) {
					throw $e;
				}
				$this->logger->warning('TimeSister: own copy for {uid} not written', ['app' => 'timesister', 'uid' => $uid, 'exception' => $e]);
			}
		}
		return [$b, $path];
	}

	/** Protected, with the admin, and the row. One backup per account and day: a second replaces the first. */
	private function store(int $tenantId, string $uid, string $day, string $bin, string $source): Backup {
		$size = strlen($bin);
		$sha = hash('sha256', $bin);
		$this->store->put($tenantId, $uid, $day, $bin);
		[$owner, $path] = $this->writeVisible($tenantId, $uid, $day, $bin, $sha);
		unset($bin);

		$b = $this->backups->findDay($tenantId, $uid, $day);
		$new = $b === null;
		$b ??= new Backup();
		for ($attempt = 1; ; $attempt++) {
			if ($new) {
				$b->setTenantId($tenantId);
				$b->setUid($uid);
				$b->setTakenOn($day);
			}
			$b->setSize($size);
			$b->setSha256($sha);
			$b->setSource($source);
			$b->setFilePath($path);
			$b->setFileOwner($owner);
			$b->setCreatedAt($this->time->getTime());
			try {
				return $new ? $this->backups->insert($b) : $this->backups->update($b);
			} catch (DbException $e) {
				// Two backups at the same time: the second updates.
				if (!$new || $attempt >= 2 || $e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				$b = $this->backups->findDay($tenantId, $uid, $day) ?? throw $e;
				$new = false;
			}
		}
	}

	/**
	 * The copy with the backup owner. If it fails, the protected backup
	 * stays anyway; `file_path` is then null.
	 *
	 * @return array{0:?string,1:?string} account, path
	 */
	private function writeVisible(int $tenantId, string $uid, string $day, string $bin, string $sha): array {
		$owner = $this->tenants->backupOwner($this->tenants->tenant($tenantId));
		if ($owner === null) {
			$this->logger->info('TimeSister: team {team} has no admin account for the visible copy', ['app' => 'timesister', 'team' => $tenantId]);
			return [null, null];
		}
		try {
			$folder = BackupRules::personFolder($this->tenants->displayName($uid), $uid);
			$w = $this->visible->write($owner, $folder, $day, $bin);
			$this->track($tenantId, $uid, BackupFile::ADMIN, $owner, $w, $day, $sha);
			return [$owner, $w['path']];
		} catch (\Exception $e) {
			$this->logger->warning('TimeSister: visible copy for {uid} not written', ['app' => 'timesister', 'uid' => $uid, 'exception' => $e]);
			return [null, null];
		}
	}

	/**
	 * Into the tracking list: thinning may only delete what is listed here.
	 *
	 * @param array{path:string,file_id:int} $w
	 */
	private function track(int $tenantId, string $uid, string $target, string $owner, array $w, string $day, string $sha): void {
		$f = $this->files->findAt($owner, $w['path']);
		$new = $f === null;
		$f ??= new BackupFile();
		$f->setTenantId($tenantId);
		$f->setUid($uid);
		$f->setTarget($target);
		$f->setOwner($owner);
		$f->setFileId($w['file_id']);
		$f->setPath($w['path']);
		$f->setTakenOn($day);
		$f->setSha256($sha);
		$f->setCreatedAt($this->time->getTime());
		$new ? $this->files->insert($f) : $this->files->update($f);
	}

	/** Today in UTC, like all days of the API. */
	private function today(): string {
		return gmdate('Y-m-d', $this->time->getTime());
	}
}
