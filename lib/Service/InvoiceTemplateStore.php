<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * The team's invoice template in the app's IAppData (0.16.0):
 * `templates/t<team>/invoice.docx` and its meta in `invoice.json`. No
 * rights here; the names are the app's own.
 */
final class InvoiceTemplateStore {
	private const ROOT = 'templates';
	private const FILE = 'invoice.docx';
	private const META = 'invoice.json';

	public function __construct(
		private IAppData $appData,
	) {
	}

	/**
	 * The meta, or null when none is stored (or only half of it).
	 *
	 * @return ?array{name:string,size:int,sha256:string,modified_at:string,modified_by:string}
	 */
	public function info(int $tenantId): ?array {
		$folder = $this->folder($tenantId, false);
		if ($folder === null || !$folder->fileExists(self::FILE) || !$folder->fileExists(self::META)) {
			return null;
		}
		try {
			return InvoiceTemplateRules::readMeta(Json::decode($folder->getFile(self::META)->getContent()));
		} catch (\JsonException|NotFoundException) {
			return null;
		}
	}

	/**
	 * Meta and file, or null when none is stored.
	 *
	 * @return ?array{meta:array{name:string,size:int,sha256:string,modified_at:string,modified_by:string},content:string}
	 */
	public function get(int $tenantId): ?array {
		$meta = $this->info($tenantId);
		$folder = $this->folder($tenantId, false);
		if ($meta === null || $folder === null) {
			return null;
		}
		try {
			return ['meta' => $meta, 'content' => $folder->getFile(self::FILE)->getContent()];
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
	public function put(int $tenantId, array $meta, string $content): void {
		$folder = $this->folder($tenantId, true);
		if ($folder === null) {
			throw new \RuntimeException('The template folder cannot be created.');
		}
		if ($folder->fileExists(self::META)) {
			// A half-written replacement is never taken for a complete one.
			$folder->getFile(self::META)->delete();
		}
		self::write($folder, self::FILE, $content);
		self::write($folder, self::META, Json::encode(TeamExportService::toObject($meta)));
	}

	/** Removes the team's template; true when there was one. */
	public function delete(int $tenantId): bool {
		$folder = $this->folder($tenantId, false);
		if ($folder === null) {
			return false;
		}
		$had = $folder->fileExists(self::FILE) && $folder->fileExists(self::META);
		$folder->delete();
		return $had;
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
