<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Backup;
use OCA\TimeSister\Db\BackupMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Kalendersicherungen in IAppData der App: `t<Team>/<Konto>/<Tag>.ics`.
 * Die Namen bildet die App selbst; aus der Anfrage kommt nur der Tag, und
 * der ist geprüft.
 */
final class BackupService {
	public function __construct(
		private IAppData $appData,
		private BackupMapper $backups,
		private AccessPolicy $policy,
		private ITimeFactory $time,
	) {
	}

	/** @return array{id:int,uid:string,taken_on:string,size:int,sha256:string} */
	public function present(Backup $b): array {
		return [
			'id' => $b->getId(),
			'uid' => $b->getUid(),
			'taken_on' => $b->getTakenOn(),
			'size' => $b->getSize(),
			'sha256' => $b->getSha256(),
		];
	}

	/** @return array{id:int,uid:string,taken_on:string,size:int,sha256:string} */
	public function upload(Membership $m, mixed $day, mixed $b64): array {
		$day = BackupRules::checkDay($day);
		$bin = BackupRules::decode($b64);
		$size = strlen($bin);
		$sha = hash('sha256', $bin);

		$folder = $this->folder($m->tenantId, $m->uid, true);
		$name = BackupRules::fileFor($day);
		if ($folder->fileExists($name)) {
			$folder->getFile($name)->putContent($bin);
		} else {
			$folder->newFile($name, $bin);
		}
		unset($bin);

		// Eine Sicherung je Konto und Tag: eine zweite ersetzt die erste.
		$b = $this->backups->findDay($m->tenantId, $m->uid, $day);
		if ($b === null) {
			$b = new Backup();
			$b->setTenantId($m->tenantId);
			$b->setUid($m->uid);
			$b->setTakenOn($day);
			$b->setSize($size);
			$b->setSha256($sha);
			$b->setCreatedAt($this->time->getTime());
			try {
				return $this->present($this->backups->insert($b));
			} catch (DbException $e) {
				// Zwei Uploads gleichzeitig: der zweite aktualisiert.
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				$b = $this->backups->findDay($m->tenantId, $m->uid, $day) ?? throw $e;
			}
		}
		$b->setSize($size);
		$b->setSha256($sha);
		$b->setCreatedAt($this->time->getTime());
		$b = $this->backups->update($b);
		return $this->present($b);
	}

	/** @return list<array{id:int,uid:string,taken_on:string,size:int,sha256:string}> */
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
			$content = $this->folder($b->getTenantId(), $b->getUid(), false)
				->getFile(BackupRules::fileFor($b->getTakenOn()))
				->getContent();
		} catch (NotFoundException) {
			throw ApiException::notFound('Die Datei dieser Sicherung fehlt.');
		}
		return $this->present($b) + ['ics_base64' => base64_encode($content)];
	}

	/** Sicherungen vor diesem Tag löschen (Datei und Zeile). Nur Dateien der App. */
	public function purgeBefore(string $day): int {
		$n = 0;
		foreach ($this->backups->findBefore($day) as $b) {
			try {
				$this->folder($b->getTenantId(), $b->getUid(), false)
					->getFile(BackupRules::fileFor($b->getTakenOn()))
					->delete();
			} catch (NotFoundException) {
				// Datei schon weg: nur die Zeile aufräumen.
			}
			$this->backups->delete($b);
			$n++;
		}
		return $n;
	}

	private function folder(int $tenantId, string $uid, bool $create): ISimpleFolder {
		$teamName = 't' . $tenantId;
		try {
			$team = $this->appData->getFolder($teamName);
		} catch (NotFoundException $e) {
			if (!$create) {
				throw $e;
			}
			$team = $this->appData->newFolder($teamName);
		}
		$userName = BackupRules::folderFor($uid);
		try {
			return $team->getFolder($userName);
		} catch (NotFoundException $e) {
			if (!$create) {
				throw $e;
			}
			return $team->newFolder($userName);
		}
	}
}
