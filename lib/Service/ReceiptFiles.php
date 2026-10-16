<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\RecordMapper;

/**
 * The receipts' files as copies for a document (0.22.0): the invoice and
 * the expense report append them. Reads the stored files from the backup
 * owner's files; a missing file becomes a page that says so.
 */
final class ReceiptFiles {
	public function __construct(
		private RecordMapper $records,
		private TeamTree $tree,
		private DocumentFolder $folders,
	) {
	}

	/**
	 * The receipt records by key, also deleted ones (their last data).
	 *
	 * @param list<string> $keys
	 * @return array<string,\stdClass>
	 */
	public function receipts(int $tenantId, array $keys): array {
		$out = [];
		foreach (array_values(array_unique($keys)) as $k) {
			$r = $this->records->findOne($tenantId, ReceiptRules::KIND, $k);
			$d = InvoiceRules::decode($r?->getData());
			if ($d !== null) {
				$out[$k] = $d;
			}
		}
		return $out;
	}

	/**
	 * The copies of the receipts that carry a file, in the order given.
	 *
	 * @param array<string,\stdClass> $receipts
	 * @param array<string,string> $t the document's labels
	 * @return list<array{head:string,content:?string,mime:string,path:string,sha256:string}>
	 */
	public function copies(int $tenantId, array $receipts, string $lang, array $t): array {
		$owner = $this->tree->ownerOf($tenantId);
		$out = [];
		foreach ($receipts as $d) {
			$file = $d->file ?? null;
			$path = $file instanceof \stdClass && is_string($file->path ?? null) ? $file->path : null;
			if ($path === null) {
				continue;
			}
			$node = $owner === null ? null : $this->folders->read($owner, $path);
			$out[] = [
				'head' => self::head($d, $lang, $t),
				'content' => $node?->getContent(),
				'mime' => is_string($file->mime ?? null) ? $file->mime : (ReceiptRules::sniff((string)$node?->getContent()) ?? 'application/pdf'),
				'path' => $path,
				'sha256' => is_string($file->sha256 ?? null) ? $file->sha256 : '',
			];
		}
		return $out;
	}

	/** «Receipt 03.10.2026 Supplier». @param array<string,string> $t */
	public static function head(\stdClass $d, string $lang, array $t): string {
		return trim($t['receipt'] . ' ' . DocumentRules::day((string)($d->date ?? ''), $lang) . ' ' . (is_string($d->supplier ?? null) ? $d->supplier : ''));
	}

	/**
	 * The receipt keys of invoice positions, in order of appearance.
	 *
	 * @param list<\stdClass|array<string,mixed>> $positions
	 * @return list<string>
	 */
	public static function keysOf(array $positions): array {
		$out = [];
		foreach ($positions as $p) {
			$list = is_array($p) ? ($p['receipts'] ?? null) : ($p->receipts ?? null);
			foreach (is_array($list) ? $list : [] as $k) {
				if (is_string($k)) {
					$out[$k] = true;
				}
			}
		}
		return array_keys($out);
	}
}
