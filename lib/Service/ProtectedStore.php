<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Die geschützte Ablage in IAppData der App: `t<Team>/<Konto>/<Tag>.ics`.
 * Die Namen bildet die App selbst; aus der Anfrage kommt nur der Tag, und
 * der ist geprüft.
 */
final class ProtectedStore {
	public function __construct(
		private IAppData $appData,
	) {
	}

	public function put(int $tenantId, string $uid, string $day, string $content): void {
		$folder = $this->folder($tenantId, $uid, true);
		$name = BackupRules::fileFor($day);
		if ($folder->fileExists($name)) {
			$folder->getFile($name)->putContent($content);
		} else {
			$folder->newFile($name, $content);
		}
	}

	/** @throws NotFoundException */
	public function get(int $tenantId, string $uid, string $day): string {
		return $this->folder($tenantId, $uid, false)->getFile(BackupRules::fileFor($day))->getContent();
	}

	/** Löscht die Datei; fehlt sie, ist das kein Fehler. */
	public function delete(int $tenantId, string $uid, string $day): void {
		try {
			$this->folder($tenantId, $uid, false)->getFile(BackupRules::fileFor($day))->delete();
		} catch (NotFoundException) {
		}
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
