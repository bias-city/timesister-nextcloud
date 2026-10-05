<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;

/**
 * Visible copies of a backup: with the backup owner under
 * `TimeSister Backups/<display name> (<uid>)/<day>.ics`, and
 * optionally in the person's own home under `TimeSister Backups/<day>.ics`
 * (folder name: BackupRules::VISIBLE_ROOT).
 */
final class VisibleCopy {
	public function __construct(
		private IRootFolder $root,
		private IFilenameValidator $names,
	) {
	}

	/**
	 * Writes or replaces the copy with the backup owner.
	 *
	 * @return array{path:string,file_id:int} path relative to `$owner`'s home
	 */
	public function write(string $owner, string $personFolder, string $day, string $content): array {
		$root = $this->folder($this->root->getUserFolder($owner), BackupRules::VISIBLE_ROOT);
		// Plus this instance's own Nextcloud naming rules.
		$dir = $this->folder($root, $this->names->sanitizeFilename($personFolder));
		$name = BackupRules::fileFor($day);
		return ['path' => BackupRules::VISIBLE_ROOT . '/' . $dir->getName() . '/' . $name, 'file_id' => $this->put($dir, $name, $content)];
	}

	/**
	 * A team backup (ZIP) with the backup owner under
	 * `TimeSister Backups/_team/<name>`; not tracked, so thinning leaves it.
	 *
	 * @return array{path:string,file_id:int} path relative to `$owner`'s home
	 */
	public function writeTeamFile(string $owner, string $name, string $content): array {
		$root = $this->folder($this->root->getUserFolder($owner), BackupRules::VISIBLE_ROOT);
		$dir = $this->folder($root, BackupRules::TEAM_FOLDER);
		return ['path' => BackupRules::VISIBLE_ROOT . '/' . BackupRules::TEAM_FOLDER . '/' . $name, 'file_id' => $this->put($dir, $name, $content)];
	}

	/**
	 * The copy in the person's own home.
	 *
	 * @return array{path:string,file_id:int} path relative to `$uid`'s home
	 */
	public function writeOwn(string $uid, string $day, string $content): array {
		$dir = $this->folder($this->root->getUserFolder($uid), BackupRules::VISIBLE_ROOT);
		$name = BackupRules::fileFor($day);
		return ['path' => BackupRules::VISIBLE_ROOT . '/' . $name, 'file_id' => $this->put($dir, $name, $content)];
	}

	/**
	 * Deletes a tracked file, but only if its file ID still points to
	 * exactly this path; moved or renamed ones stay. Never folders.
	 * Through the node API, so into the person's trash bin.
	 *
	 * @return bool deleted
	 */
	public function deleteTracked(string $owner, int $fileId, string $path): bool {
		$node = $this->tracked($owner, $fileId, $path);
		if ($node === null) {
			return false;
		}
		$node->delete();
		return true;
	}

	/** Is the tracked file still unchanged at its path? */
	public function isTracked(string $owner, int $fileId, string $path): bool {
		return $this->tracked($owner, $fileId, $path) !== null;
	}

	private function tracked(string $owner, int $fileId, string $path): ?File {
		$home = $this->root->getUserFolder($owner);
		$node = $home->getFirstNodeById($fileId);
		if (!$node instanceof File || $home->getRelativePath($node->getPath()) !== '/' . $path) {
			return null;
		}
		return $node;
	}

	/** @return int file ID */
	private function put(Folder $dir, string $name, string $content): int {
		if ($dir->nodeExists($name)) {
			$node = $dir->get($name);
			if (!$node instanceof File) {
				throw new \RuntimeException('A folder is in the place of the backup file.');
			}
			$node->putContent($content);
			return $node->getId();
		}
		return $dir->newFile($name, $content)->getId();
	}

	private function folder(Folder $parent, string $name): Folder {
		if ($parent->nodeExists($name)) {
			$node = $parent->get($name);
			if ($node instanceof Folder) {
				return $node;
			}
			throw new \RuntimeException('A file is in the place of the backup folder.');
		}
		return $parent->newFolder($name);
	}
}
