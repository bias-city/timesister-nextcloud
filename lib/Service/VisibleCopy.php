<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;

/**
 * Sichtbare Kopien einer Sicherung: beim Sicherungs-Konto unter
 * `TimeSister-Sicherungen/<Anzeigename> (<uid>)/<Tag>.ics`, und wahlweise
 * im eigenen Heim der Person unter `TimeSister-Sicherungen/<Tag>.ics`.
 */
final class VisibleCopy {
	public function __construct(
		private IRootFolder $root,
		private IFilenameValidator $names,
	) {
	}

	/**
	 * Schreibt oder ersetzt die Kopie beim Sicherungs-Konto.
	 *
	 * @return array{path:string,file_id:int} Pfad relativ zum Heim von `$owner`
	 */
	public function write(string $owner, string $personFolder, string $day, string $content): array {
		$root = $this->folder($this->root->getUserFolder($owner), BackupRules::VISIBLE_ROOT);
		// Dazu Nextclouds eigene Namensregeln dieser Instanz.
		$dir = $this->folder($root, $this->names->sanitizeFilename($personFolder));
		$name = BackupRules::fileFor($day);
		return ['path' => BackupRules::VISIBLE_ROOT . '/' . $dir->getName() . '/' . $name, 'file_id' => $this->put($dir, $name, $content)];
	}

	/**
	 * Die Kopie im eigenen Heim der Person.
	 *
	 * @return array{path:string,file_id:int} Pfad relativ zum Heim von `$uid`
	 */
	public function writeOwn(string $uid, string $day, string $content): array {
		$dir = $this->folder($this->root->getUserFolder($uid), BackupRules::VISIBLE_ROOT);
		$name = BackupRules::fileFor($day);
		return ['path' => BackupRules::VISIBLE_ROOT . '/' . $name, 'file_id' => $this->put($dir, $name, $content)];
	}

	/**
	 * Löscht eine gemerkte Datei, aber nur, wenn ihre Datei-ID noch auf
	 * genau diesen Pfad zeigt; verschobene oder umbenannte bleiben. Nie
	 * Ordner. Über die Node-API, also in den Papierkorb der Person.
	 *
	 * @return bool gelöscht
	 */
	public function deleteTracked(string $owner, int $fileId, string $path): bool {
		$node = $this->tracked($owner, $fileId, $path);
		if ($node === null) {
			return false;
		}
		$node->delete();
		return true;
	}

	/** Liegt die gemerkte Datei noch unverändert an ihrem Pfad? */
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

	/** @return int Datei-ID */
	private function put(Folder $dir, string $name, string $content): int {
		if ($dir->nodeExists($name)) {
			$node = $dir->get($name);
			if (!$node instanceof File) {
				throw new \RuntimeException('An der Stelle der Sicherung liegt ein Ordner.');
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
			throw new \RuntimeException('An der Stelle des Sicherungsordners liegt eine Datei.');
		}
		return $parent->newFolder($name);
	}
}
