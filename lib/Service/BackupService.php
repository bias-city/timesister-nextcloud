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
 * Kalendersicherungen. Mit Freigabe der Person: geschützt in IAppData
 * (daraus liest die Schnittstelle) und sichtbar beim Sicherungs-Konto.
 * Unabhängig davon wahlweise eine Kopie im eigenen Heim. Der Server sichert
 * je Ablage nur, wenn sich die Prüfsumme seit der letzten geändert hat.
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
	 * POST /backups: die eigene Sicherung eines Tages, vom Client (Fassung 1:
	 * immer gespeichert, eine zweite am selben Tag ersetzt die erste).
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
	 * POST /backups/now: sofort sichern. Fremde Konten des Teams nur
	 * Verwaltung und Admin, und nur mit Freigabe. Das eigene Konto auch ohne
	 * Freigabe, wenn die eigene Kopie an ist; dann entsteht nur sie.
	 *
	 * @return array<string,mixed> Eintrag wie in GET /backups (dazu `own_copy`, wenn an) oder `{own_copy}`
	 */
	public function now(Membership $m, mixed $uid): array {
		$uid = ($uid === null || $uid === '') ? $m->uid : $uid;
		if (!is_string($uid)) {
			throw ApiException::badRequest('„uid“ ist ungültig.');
		}
		if (!$this->policy->canSeeBackupsOf($m, $uid)) {
			throw ApiException::forbidden('Fremde Konten sichern nur Verwaltung und Admin des Teams.');
		}
		if ($uid !== $m->uid) {
			if (!array_key_exists($uid, $this->tenants->memberRoles($m->tenantId))) {
				throw ApiException::notFound('Dieses Konto gehört nicht zum Team.');
			}
			$this->consent->require($m->tenantId, $uid);
			// Ins Heim einer anderen Person schreibt nur der Wochenjob.
			[$b] = $this->backupFor($m->tenantId, $uid, true, false);
			return $b === null ? [] : $this->present($b);
		}
		$admin = $this->consent->has($m->tenantId, $uid);
		$own = $this->ownCopy->enabled($uid);
		if (!$admin && !$own) {
			throw ApiException::forbidden(ConsentService::REFUSED);
		}
		[$b, $path] = $this->backupFor($m->tenantId, $uid, $admin, $own);
		if ($b === null) {
			return ['own_copy' => $path];
		}
		return $this->present($b) + ($path === null ? [] : ['own_copy' => $path]);
	}

	/**
	 * Hintergrundjob: je Mitglied, Ablage und ISO-Woche höchstens eine
	 * Prüfung – beim Admin nur mit Freigabe, im eigenen Ordner nur wenn an.
	 * Fehler je Konto ins Protokoll, dann weiter. Höchstens `$max` Konten je
	 * Lauf; der Rest im nächsten.
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
			// Rein numerische Kennungen kommen als int-Schlüssel.
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
					// Kein Zeitkalender (noch nie synchronisiert) ist kein Fehler.
					$missing = $e->getStatus() === 404;
					$n[$missing ? 'no_calendar' : 'failed']++;
					$this->logger->log($missing ? 'info' : 'warning', 'TimeSister: keine Sicherung für {uid}: {msg}', [
						'app' => 'timesister', 'uid' => $uid, 'msg' => $e->getMessage(),
					]);
				} catch (\Throwable $e) {
					$n['failed']++;
					$this->logger->error('TimeSister: Sicherung für {uid} (Team {team}) fehlgeschlagen', [
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
			throw ApiException::forbidden('Fremde Sicherungen sehen nur Verwaltung und Admin des Teams.');
		}
		return array_map(fn (Backup $b) => $this->present($b), $this->backups->listFor($m->tenantId, $uid));
	}

	/** @return array<string,mixed> */
	public function get(Membership $m, int $id): array {
		$b = $this->backups->findInTenant($m->tenantId, $id);
		if ($b === null) {
			throw ApiException::notFound('Diese Sicherung gibt es nicht.');
		}
		if (!$this->policy->canSeeBackupsOf($m, $b->getUid())) {
			throw ApiException::forbidden('Fremde Sicherungen sehen nur Verwaltung und Admin des Teams.');
		}
		try {
			$content = $this->store->get($b->getTenantId(), $b->getUid(), $b->getTakenOn());
		} catch (NotFoundException) {
			throw ApiException::notFound('Die Datei dieser Sicherung fehlt.');
		}
		return $this->present($b) + ['ics_base64' => base64_encode($content)];
	}

	/**
	 * Den Zeitkalender einmal exportieren und je Ablage nur bei geänderter
	 * Prüfsumme ablegen: beim Admin (`$admin`, mit der geschützten Kopie) und
	 * im eigenen Heim (`$own`). 404 ohne Kalender. Scheitert die eigene Kopie
	 * neben einer Sicherung beim Admin, bleibt diese.
	 *
	 * @return array{0:?Backup,1:?string} Sicherung beim Admin, Pfad der eigenen Kopie
	 */
	private function backupFor(int $tenantId, string $uid, bool $admin, bool $own): array {
		$url = $this->status->findOne($tenantId, $uid)?->getCalendarUrl();
		$ics = $this->exporter->export($uid, $url);
		if ($ics === null) {
			throw ApiException::notFound('Für dieses Konto gibt es keinen Zeitkalender.');
		}
		if (strlen($ics) > BackupRules::MAX_BYTES) {
			throw ApiException::tooLarge('Der Zeitkalender ist grösser als 20 MB und wird nicht gesichert.');
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
				// Unverändert und noch da: es bleibt bei ihr. Hat die Person sie
				// gelöscht oder verschoben, entsteht eine neue.
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
				$this->logger->warning('TimeSister: eigene Kopie für {uid} nicht geschrieben', ['app' => 'timesister', 'uid' => $uid, 'exception' => $e]);
			}
		}
		return [$b, $path];
	}

	/** Geschützt, beim Admin und die Zeile. Eine Sicherung je Konto und Tag: eine zweite ersetzt die erste. */
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
				// Zwei Sicherungen gleichzeitig: die zweite aktualisiert.
				if (!$new || $attempt >= 2 || $e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				$b = $this->backups->findDay($tenantId, $uid, $day) ?? throw $e;
				$new = false;
			}
		}
	}

	/**
	 * Die Kopie beim Sicherungs-Konto. Scheitert sie, bleibt die geschützte
	 * Sicherung trotzdem; `file_path` ist dann null.
	 *
	 * @return array{0:?string,1:?string} Konto, Pfad
	 */
	private function writeVisible(int $tenantId, string $uid, string $day, string $bin, string $sha): array {
		$owner = $this->tenants->backupOwner($this->tenants->tenant($tenantId));
		if ($owner === null) {
			$this->logger->info('TimeSister: Team {team} hat kein admin-Konto für die sichtbare Kopie', ['app' => 'timesister', 'team' => $tenantId]);
			return [null, null];
		}
		try {
			$folder = BackupRules::personFolder($this->tenants->displayName($uid), $uid);
			$w = $this->visible->write($owner, $folder, $day, $bin);
			$this->track($tenantId, $uid, BackupFile::ADMIN, $owner, $w, $day, $sha);
			return [$owner, $w['path']];
		} catch (\Exception $e) {
			$this->logger->warning('TimeSister: sichtbare Kopie für {uid} nicht geschrieben', ['app' => 'timesister', 'uid' => $uid, 'exception' => $e]);
			return [null, null];
		}
	}

	/**
	 * In die Merkliste: nur was hier steht, darf das Ausdünnen löschen.
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

	/** Heute in UTC, wie alle Tage der Schnittstelle. */
	private function today(): string {
		return gmdate('Y-m-d', $this->time->getTime());
	}
}
