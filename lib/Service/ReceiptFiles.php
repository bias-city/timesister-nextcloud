<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\RecordMapper;

/**
 * The receipts an invoice names (0.22.0): their keys in the positions and
 * their records. The copies for a document went with the server's renderer
 * (0.32.0, the app renders).
 */
final class ReceiptFiles {
	public function __construct(
		private RecordMapper $records,
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
