<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/**
 * The document tree in the backup owner's files (0.19.0, tree since
 * 0.22.0): resolving a path segment by segment (a group folder lies at the
 * root there), creating what is missing, checking that the root exists or
 * can be created and is writable, writing, reading, renaming a file once
 * and moving a replaced one to the owner's trash.
 */
final class DocumentFolder {
	public function __construct(
		private IRootFolder $root,
	) {
	}

	public const FOUND = 'found';
	public const CREATABLE = 'creatable';
	public const NO_OWNER = 'no_owner';
	public const INVALID = 'invalid';
	public const FILE = 'file';
	public const NOT_WRITABLE = 'not_writable';

	/**
	 * Does the folder exist or can it be created, and is it writable? The
	 * code says which ({@see message()} puts it into words); nothing is
	 * created.
	 *
	 * @return array{ok:bool,code:string,path:string}
	 */
	public function check(?string $owner, string $path): array {
		$r = static fn (bool $ok, string $code): array => ['ok' => $ok, 'code' => $code, 'path' => $path];
		if ($owner === null) {
			return $r(false, self::NO_OWNER);
		}
		if (!DocumentRules::isPath($path)) {
			return $r(false, self::INVALID);
		}
		try {
			$node = $this->root->getUserFolder($owner);
			$missing = [];
			foreach (explode('/', $path) as $segment) {
				if ($missing === [] && $node->nodeExists($segment)) {
					$child = $node->get($segment);
					if (!$child instanceof Folder) {
						return $r(false, self::FILE);
					}
					$node = $child;
				} else {
					$missing[] = $segment;
				}
			}
			if (!$node->isCreatable()) {
				return $r(false, self::NOT_WRITABLE);
			}
			return $r(true, $missing === [] ? self::FOUND : self::CREATABLE);
		} catch (\Throwable) {
			return $r(false, self::NOT_WRITABLE);
		}
	}

	/** The result of {@see check()} in words. @param array{ok:bool,code:string,path:string} $r */
	public function message(array $r): Message {
		$p = ['path' => $r['path']];
		return match ($r['code']) {
			self::FOUND => Message::of('Folder found, writable.'),
			self::CREATABLE => Message::of('The folder will be created: “{path}”.', $p),
			self::NO_OWNER => Message::of('The team has no backup owner; documents need one.'),
			self::INVALID => Message::of('The folder “{path}” is not a valid path.', $p),
			self::FILE => Message::of('A file is in the place of the folder “{path}”.', $p),
			default => Message::of('The folder “{path}” is not writable for the backup owner.', $p),
		};
	}

	/** The check as the settings write wants it: 422 when it fails. */
	public function require(?string $owner, string $path): void {
		$r = $this->check($owner, $path);
		$p = ['path' => $path];
		switch ($r['ok'] ? '' : $r['code']) {
			case '':
				return;
			case self::NO_OWNER:
				throw ApiException::invalid('The team has no backup owner; documents need one.');
			case self::INVALID:
				throw ApiException::invalid(Message::of('The folder “{path}” is not a valid path.', $p));
			case self::FILE:
				throw ApiException::invalid(Message::of('A file is in the place of the folder “{path}”.', $p));
			default:
				throw ApiException::invalid(Message::of('The folder “{path}” is not writable for the backup owner.', $p));
		}
	}

	/**
	 * Writes or replaces a file under `<path>/<name>` in the owner's files,
	 * creating the folders.
	 *
	 * @return array{path:string,file_id:int,size:int} path relative to the owner's home
	 */
	public function write(string $owner, string $path, string $name, string $content): array {
		$dir = $this->resolve($owner, $path, true);
		if ($dir->nodeExists($name)) {
			$node = $dir->get($name);
			if (!$node instanceof File) {
				throw new \RuntimeException('A folder is in the place of the document.');
			}
			$node->putContent($content);
		} else {
			$node = $dir->newFile($name, $content);
		}
		return ['path' => $path . '/' . $name, 'file_id' => $node->getId(), 'size' => strlen($content)];
	}

	/** The file at a path relative to the owner's home, or null. */
	public function read(string $owner, string $path): ?File {
		try {
			$node = $this->root->getUserFolder($owner)->get($path);
		} catch (NotFoundException) {
			return null;
		}
		return $node instanceof File ? $node : null;
	}

	/** A name in the folder not taken yet ({@see DocumentTree::unique()}); the folder need not exist. */
	public function freeName(string $owner, string $folder, string $name): string {
		try {
			$dir = $this->resolve($owner, $folder, false);
		} catch (NotFoundException) {
			return $name;
		}
		return DocumentTree::unique($name, static fn (string $n): bool => $dir->nodeExists($n));
	}

	/**
	 * Writes a new file under a free name in the folder (`_2` …), unless
	 * `$replace` names the file this one replaces: then that path is
	 * overwritten when the name is the same, else it goes to the trash.
	 *
	 * @return array{path:string,file_id:int,size:int}
	 */
	public function store(string $owner, string $folder, string $name, string $content, ?string $replace): array {
		if ($replace !== null && $replace === $folder . '/' . $name && $this->read($owner, $replace) !== null) {
			return $this->write($owner, $folder, $name, $content);
		}
		$written = $this->write($owner, $folder, $this->freeName($owner, $folder, $name), $content);
		if ($replace !== null && $replace !== $written['path']) {
			$this->trash($owner, $replace);
		}
		return $written;
	}

	/** Moves a file to `<folder>/<name>` (a free name), creating the folders. @return string the new path */
	public function move(string $owner, string $path, string $folder, string $name): string {
		if (DocumentTree::isPlaced($path, $folder, $name)) {
			return $path;
		}
		$file = $this->read($owner, $path) ?? throw new NotFoundException($path);
		$dir = $this->resolve($owner, $folder, true);
		$free = DocumentTree::unique($name, static fn (string $n): bool => $dir->nodeExists($n));
		$file->move($dir->getPath() . '/' . $free);
		return $folder . '/' . $free;
	}

	/** The file goes to the owner's trash (files_trashbin); a missing file is fine. */
	public function trash(string $owner, string $path): void {
		$file = $this->read($owner, $path);
		if ($file !== null) {
			$file->delete();
		}
	}

	/** The folder at a path, created when `$create`; NotFoundException otherwise. */
	private function resolve(string $owner, string $path, bool $create): Folder {
		$node = $this->root->getUserFolder($owner);
		foreach (explode('/', $path) as $segment) {
			if ($node->nodeExists($segment)) {
				$child = $node->get($segment);
				if (!$child instanceof Folder) {
					throw new \RuntimeException('A file is in the place of the folder “' . $path . '”.');
				}
				$node = $child;
			} elseif ($create) {
				$node = $node->newFolder($segment);
			} else {
				throw new NotFoundException($path);
			}
		}
		return $node;
	}
}
