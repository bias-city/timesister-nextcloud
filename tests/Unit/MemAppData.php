<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;

/** IAppData in memory, for the stores' unit tests. Load it before MemFolder/MemFile (same file). */
final class MemAppData implements IAppData {
	public MemFolder $root;

	public function __construct() {
		$this->root = new MemFolder('', null);
	}

	public function getFolder(string $name): ISimpleFolder {
		return $this->root->getFolder($name);
	}

	public function getDirectoryListing(): array {
		return $this->root->getDirectoryListing();
	}

	public function newFolder(string $name): ISimpleFolder {
		return $this->root->newFolder($name);
	}

	/** Every file path below the root, sorted. @return list<string> */
	public function paths(): array {
		$out = $this->root->paths('');
		sort($out);
		return $out;
	}
}

final class MemFolder implements ISimpleFolder {
	/** @var array<string,MemFolder> */
	public array $folders = [];
	/** @var array<string,MemFile> */
	public array $files = [];

	public function __construct(
		private string $name,
		private ?MemFolder $parent,
	) {
	}

	public function getDirectoryListing(): array {
		return array_values($this->files);
	}

	public function fileExists(string $name): bool {
		return isset($this->files[$name]);
	}

	public function getFile(string $name): ISimpleFile {
		return $this->files[$name] ?? throw new NotFoundException($name);
	}

	public function newFile(string $name, $content = null): ISimpleFile {
		$f = new MemFile($name, $this);
		$f->putContent($content ?? '');
		return $this->files[$name] = $f;
	}

	public function delete(): void {
		if ($this->parent !== null) {
			unset($this->parent->folders[$this->name]);
		}
	}

	public function getName(): string {
		return $this->name;
	}

	public function getFolder(string $name): ISimpleFolder {
		return $this->folders[$name] ?? throw new NotFoundException($name);
	}

	public function newFolder(string $path): ISimpleFolder {
		return $this->folders[$path] = new MemFolder($path, $this);
	}

	public function getOrCreateFolder(string $path, int $maxRetries = 5): ISimpleFolder {
		return $this->folders[$path] ?? $this->newFolder($path);
	}

	/** @return list<string> */
	public function paths(string $prefix): array {
		$out = array_map(static fn (string $n): string => $prefix . $n, array_map('strval', array_keys($this->files)));
		foreach ($this->folders as $n => $f) {
			$out = array_merge($out, $f->paths($prefix . $n . '/'));
		}
		return $out;
	}
}

final class MemFile implements ISimpleFile {
	public string $content = '';

	public function __construct(
		private string $name,
		private MemFolder $folder,
	) {
	}

	public function getName(): string {
		return $this->name;
	}

	public function getSize(): int|float {
		return strlen($this->content);
	}

	public function getETag(): string {
		return md5($this->content);
	}

	public function getMTime(): int {
		return 0;
	}

	public function getContent(): string {
		return $this->content;
	}

	public function putContent($data): void {
		$this->content = is_resource($data) ? (string)stream_get_contents($data) : (string)$data;
	}

	public function delete(): void {
		unset($this->folder->files[$this->name]);
	}

	public function getMimeType(): string {
		return 'application/octet-stream';
	}

	public function getExtension(): string {
		return pathinfo($this->name, PATHINFO_EXTENSION);
	}

	public function read() {
		$h = fopen('php://memory', 'r+b');
		fwrite($h, $this->content);
		rewind($h);
		return $h;
	}

	public function write() {
		return fopen('php://memory', 'r+b');
	}
}
