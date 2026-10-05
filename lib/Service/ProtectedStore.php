<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * The protected store in the app's IAppData: `t<team>/<account>/<day>.ics`.
 * The app forms the names itself; only the day comes from the request,
 * and it is checked.
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

	/** Deletes the file; if it is missing, that is not an error. */
	public function delete(int $tenantId, string $uid, string $day): void {
		try {
			$this->folder($tenantId, $uid, false)->getFile(BackupRules::fileFor($day))->delete();
		} catch (NotFoundException) {
		}
	}

	/** Deleting a team (0.10.1): its whole folder; a missing one is not an error. */
	public function deleteTeam(int $tenantId): void {
		try {
			$this->appData->getFolder('t' . $tenantId)->delete();
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
