<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Backup;
use OCA\TimeSister\Db\BackupFile;
use OCA\TimeSister\Db\BackupFileMapper;
use OCA\TimeSister\Db\BackupMapper;
use OCA\TimeSister\Db\TenantMapper;
use Psr\Log\LoggerInterface;

/**
 * Dünnt alle drei Ablagen nach der Staffel aus (`Thinning`). Gelöscht wird
 * nur, was die App selbst angelegt und sich gemerkt hat; Dateien in Heimen
 * von Personen nur, solange ihre Datei-ID noch auf denselben Pfad zeigt.
 */
final class BackupThinner {
	public function __construct(
		private BackupMapper $backups,
		private BackupFileMapper $files,
		private TenantMapper $tenants,
		private ProtectedStore $store,
		private VisibleCopy $visible,
		private LoggerInterface $logger,
	) {
	}

	/** @return int entfernte Sicherungen und eigene Kopien */
	public function thin(string $today): int {
		$n = 0;
		// Geschützte Ablage je Konto, mit ihren Kopien beim Admin.
		foreach ($this->tenants->findAll() as $t) {
			foreach (self::byUid($this->backups->listByTenant($t->getId())) as $rows) {
				$keep = array_flip(Thinning::keep(array_map(static fn (Backup $b) => $b->getTakenOn(), $rows), $today));
				foreach ($rows as $b) {
					if (!isset($keep[$b->getTakenOn()])) {
						$this->dropBackup($b);
						$n++;
					}
				}
			}
		}
		// Eigene Ordner, je Konto.
		foreach (self::byUid($this->files->allOwn()) as $rows) {
			$keep = array_flip(Thinning::keep(array_map(static fn (BackupFile $f) => $f->getTakenOn(), $rows), $today));
			foreach ($rows as $f) {
				if (!isset($keep[$f->getTakenOn()])) {
					$this->dropFile($f);
					$n++;
				}
			}
		}
		return $n;
	}

	private function dropBackup(Backup $b): void {
		$this->store->delete($b->getTenantId(), $b->getUid(), $b->getTakenOn());
		foreach ($this->files->adminCopies($b->getTenantId(), $b->getUid(), $b->getTakenOn()) as $f) {
			$this->dropFile($f);
		}
		$this->backups->delete($b);
	}

	/** Die Datei nur, wenn sie noch dort liegt; die Zeile der Merkliste immer. */
	private function dropFile(BackupFile $f): void {
		try {
			$this->visible->deleteTracked($f->getOwner(), $f->getFileId(), $f->getPath());
		} catch (\Exception $e) {
			// Konto weg oder Ablage gesperrt: die Datei bleibt, die Zeile geht.
			$this->logger->warning('TimeSister: Sicherungsdatei nicht gelöscht', ['app' => 'timesister', 'exception' => $e]);
		}
		$this->files->delete($f);
	}

	/**
	 * @template T of Backup|BackupFile
	 * @param list<T> $rows
	 * @return array<string,list<T>>
	 */
	private static function byUid(array $rows): array {
		$out = [];
		foreach ($rows as $r) {
			$out[$r->getUid()][] = $r;
		}
		return $out;
	}
}
