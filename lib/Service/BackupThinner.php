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
 * Thins all three stores by the schedule (`Thinning`). Only what the app
 * created and tracked itself is deleted; files in people's homes only as
 * long as their file ID still points to the same path.
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

	/** @return int removed backups and own copies */
	public function thin(string $today): int {
		$n = 0;
		// Protected store per account, with its copies with the admin.
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
		// Own folders, per account.
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

	/** The file only if it still lives there; the tracking list row always. */
	private function dropFile(BackupFile $f): void {
		try {
			$this->visible->deleteTracked($f->getOwner(), $f->getFileId(), $f->getPath());
		} catch (\Exception $e) {
			// Account gone or store locked: the file stays, the row goes.
			$this->logger->warning('TimeSister: backup file not deleted', ['app' => 'timesister', 'exception' => $e]);
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
