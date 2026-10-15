<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\CounterMapper;
use OCA\TimeSister\Db\RecordMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The invoice number (0.16.0): the server keeps the team's counter, so two
 * Macs never give the same number. Team Admins only.
 */
final class InvoiceService {
	public function __construct(
		private CounterMapper $counters,
		private RecordMapper $records,
		private ITimeFactory $time,
	) {
	}

	/**
	 * The next number of the team's sequence: taken (`$take`) or only
	 * shown (preview, nothing changes).
	 *
	 * @return array{number:int,year:int}
	 */
	public function nextNumber(Membership $m, mixed $year, bool $take): array {
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins assign invoice numbers.');
		}
		$now = $this->time->getTime();
		$year = InvoiceNumbering::year($year, (int)gmdate('Y', $now));
		$n = InvoiceNumbering::of($this->settings($m->tenantId));
		$name = InvoiceNumbering::counter($n['per_year'], $year);
		$number = $take
			? $this->counters->take($m->tenantId, $name, $n['start'], $now)
			: InvoiceNumbering::next($this->counters->last($m->tenantId, $name), $n['start']);
		return ['number' => $number, 'year' => $year];
	}

	private function settings(int $tenantId): ?\stdClass {
		$r = $this->records->findOne($tenantId, 'setting', 'settings');
		$raw = $r?->getData();
		if ($r === null || $r->isTombstone() || $raw === null) {
			return null;
		}
		$d = Json::decode($raw);
		return $d instanceof \stdClass ? $d : null;
	}
}
