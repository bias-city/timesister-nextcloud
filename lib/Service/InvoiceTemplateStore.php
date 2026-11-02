<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * The team's Word templates in the app's IAppData: the invoice template
 * (0.16.0) as `templates/t<team>/invoice.docx` with its meta in
 * `invoice.json`, the quote template (0.34.0) as `quote.docx` and
 * `quote.json` next to it. No rights here; the names are the app's own.
 */
final class InvoiceTemplateStore {
	public const INVOICE = 'invoice';
	public const QUOTE = 'quote';
	public const KINDS = [self::INVOICE, self::QUOTE];
	private const ROOT = 'templates';

	public function __construct(
		private IAppData $appData,
	) {
	}

	/**
	 * The meta, or null when none is stored (or only half of it).
	 *
	 * @return ?array{name:string,size:int,sha256:string,modified_at:string,modified_by:string}
	 */
	public function info(int $tenantId, string $kind = self::INVOICE): ?array {
		[$file, $meta] = self::names($kind);
		$folder = $this->folder($tenantId, false);
		if ($folder === null || !$folder->fileExists($file) || !$folder->fileExists($meta)) {
			return null;
		}
		try {
			return InvoiceTemplateRules::readMeta(Json::decode($folder->getFile($meta)->getContent()));
		} catch (\JsonException|NotFoundException) {
			return null;
		}
	}

	/**
	 * Meta and file, or null when none is stored.
	 *
	 * @return ?array{meta:array{name:string,size:int,sha256:string,modified_at:string,modified_by:string},content:string}
	 */
	public function get(int $tenantId, string $kind = self::INVOICE): ?array {
		$meta = $this->info($tenantId, $kind);
		$folder = $this->folder($tenantId, false);
		if ($meta === null || $folder === null) {
			return null;
		}
		try {
			return ['meta' => $meta, 'content' => $folder->getFile(self::names($kind)[0])->getContent()];
		} catch (NotFoundException) {
			return null;
		}
	}

	/**
	 * Stores (or replaces) the file, then the meta: the meta written last
	 * marks a complete template.
	 *
	 * @param array{name:string,size:int,sha256:string,modified_at:string,modified_by:string} $meta
	 */
	public function put(int $tenantId, array $meta, string $content, string $kind = self::INVOICE): void {
		[$file, $metaName] = self::names($kind);
		$folder = $this->folder($tenantId, true);
		if ($folder === null) {
			throw new \RuntimeException('The template folder cannot be created.');
		}
		if ($folder->fileExists($metaName)) {
			// A half-written replacement is never taken for a complete one.
			$folder->getFile($metaName)->delete();
		}
		self::write($folder, $file, $content);
		self::write($folder, $metaName, Json::encode(TeamExportService::toObject($meta)));
	}

	/** Removes the team's template of this kind; true when there was one. The other kind stays. */
	public function delete(int $tenantId, string $kind = self::INVOICE): bool {
		[$file, $meta] = self::names($kind);
		$folder = $this->folder($tenantId, false);
		if ($folder === null) {
			return false;
		}
		$had = $folder->fileExists($file) && $folder->fileExists($meta);
		foreach ([$meta, $file] as $name) {
			if ($folder->fileExists($name)) {
				$folder->getFile($name)->delete();
			}
		}
		if ($folder->getDirectoryListing() === []) {
			$folder->delete();
		}
		return $had;
	}

	/** Removes every template of the team (deleting the team). */
	public function deleteAll(int $tenantId): void {
		$this->folder($tenantId, false)?->delete();
	}

	/** How many templates the team has stored (0–2). */
	public function count(int $tenantId): int {
		return count(array_filter(self::KINDS, fn (string $k): bool => $this->info($tenantId, $k) !== null));
	}

	/** @return array{0:string,1:string} file and meta of a kind */
	private static function names(string $kind): array {
		if (!in_array($kind, self::KINDS, true)) {
			throw new \InvalidArgumentException('Unknown template kind ' . $kind);
		}
		return [$kind . '.docx', $kind . '.json'];
	}

	private static function write(ISimpleFolder $folder, string $name, string $content): void {
		if ($folder->fileExists($name)) {
			$folder->getFile($name)->putContent($content);
		} else {
			$folder->newFile($name, $content);
		}
	}

	private function folder(int $tenantId, bool $create): ?ISimpleFolder {
		$name = 't' . $tenantId;
		try {
			$root = $this->appData->getFolder(self::ROOT);
		} catch (NotFoundException) {
			if (!$create) {
				return null;
			}
			$root = $this->appData->newFolder(self::ROOT);
		}
		try {
			return $root->getFolder($name);
		} catch (NotFoundException) {
			return $create ? $root->newFolder($name) : null;
		}
	}
}
