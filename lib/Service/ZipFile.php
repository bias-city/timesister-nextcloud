<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * A ZIP on disk with the limits of {@see ZipRules}: entries are listed
 * and read only through safe paths, never larger than allowed. Pure
 * (PHP's zip extension), so the rules are testable without a server.
 */
final class ZipFile {
	private function __construct(
		private \ZipArchive $zip,
	) {
	}

	/** Opens for reading; the file itself must not exceed MAX_ZIP. */
	public static function open(string $path): self {
		$size = @filesize($path);
		if ($size === false || $size > ZipRules::MAX_ZIP) {
			throw ApiException::tooLarge('The backup may be at most 200 MB.');
		}
		$zip = new \ZipArchive();
		if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
			throw ApiException::invalid('The file is not a ZIP archive.');
		}
		return new self($zip);
	}

	/** Creates (or truncates) for writing. */
	public static function create(string $path): self {
		$zip = new \ZipArchive();
		if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			throw new \RuntimeException('The ZIP archive cannot be created.');
		}
		return new self($zip);
	}

	public function add(string $path, string $content): void {
		if (!ZipRules::isSafePath($path)) {
			throw new \InvalidArgumentException('Unsafe path in the archive.');
		}
		if (!$this->zip->addFromString($path, $content)) {
			throw new \RuntimeException('An entry cannot be written to the ZIP archive.');
		}
	}

	/**
	 * All files (no folders) with their unpacked size. Unsafe paths, too
	 * many or too large entries are refused as a whole.
	 *
	 * @return array<string,int> path → size
	 */
	public function entries(): array {
		$n = $this->zip->numFiles;
		if ($n > ZipRules::MAX_ENTRIES) {
			throw ApiException::tooLarge('The backup has too many entries.');
		}
		$out = [];
		$total = 0;
		for ($i = 0; $i < $n; $i++) {
			$st = $this->zip->statIndex($i);
			if ($st === false) {
				throw ApiException::invalid('The ZIP archive is damaged.');
			}
			$name = (string)$st['name'];
			if (str_ends_with($name, '/')) {
				continue;
			}
			if (!ZipRules::isSafePath($name)) {
				throw ApiException::invalid('The ZIP archive contains an invalid path.');
			}
			$size = (int)$st['size'];
			if ($size > ZipRules::MAX_ENTRY) {
				throw ApiException::tooLarge(Message::of('“{file}” in the backup is larger than 50 MB.', ['file' => $name]));
			}
			$total += max(0, $size);
			/** @psalm-suppress TypeDoesNotContainType Psalm sees one round of the loop only. */
			if ($total > ZipRules::MAX_TOTAL) {
				throw ApiException::tooLarge('The backup unpacks to more than 600 MB.');
			}
			$out[$name] = $size;
		}
		return $out;
	}

	/** Reads one entry; the size is checked again against the directory entry. */
	public function read(string $path): string {
		if (!ZipRules::isSafePath($path)) {
			throw ApiException::invalid('The ZIP archive contains an invalid path.');
		}
		$st = $this->zip->statName($path);
		if ($st === false || (int)$st['size'] > ZipRules::MAX_ENTRY) {
			throw ApiException::invalid(Message::of('The backup is incomplete: “{file}” is missing or has the wrong size.', ['file' => $path]));
		}
		$content = $this->zip->getFromName($path, ZipRules::MAX_ENTRY + 1);
		if ($content === false || strlen($content) !== (int)$st['size']) {
			throw ApiException::invalid(Message::of('The backup is incomplete: “{file}” is missing or has the wrong size.', ['file' => $path]));
		}
		return $content;
	}

	/** JSON entry as the client would send it (objects stay objects). */
	public function readJson(string $path): mixed {
		try {
			return Json::decode($this->read($path));
		} catch (\JsonException) {
			throw ApiException::invalid(Message::of('“{file}” in the backup is not valid JSON.', ['file' => $path]));
		}
	}

	public function close(): void {
		$this->zip->close();
	}
}
