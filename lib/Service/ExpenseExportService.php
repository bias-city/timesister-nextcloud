<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\RecordMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * `GET /exports/expenses?from&to&kind=csv|datev` (0.22.0, Team Admins):
 * the receipts of approved and paid expense reports dated in the period.
 */
final class ExpenseExportService {
	public function __construct(
		private RecordMapper $records,
		private TenantService $team,
		private ITimeFactory $time,
	) {
	}

	/** @return array{content:string,name:string} */
	public function export(Membership $m, mixed $from, mixed $to, mixed $format): array {
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins export expenses.');
		}
		$format = ExpenseExportRules::kind($format);
		$from = ExportRules::day($from, 'from');
		$to = ExportRules::day($to, 'to');
		$tid = $m->tenantId;
		$receipts = [];
		foreach ($this->records->findLiveByKind($tid, ReceiptRules::KIND) as $r) {
			$d = InvoiceRules::decode($r->getData());
			if ($d !== null) {
				$receipts[$r->getRkey()] = $d;
			}
		}
		$rows = [];
		foreach ($this->records->findLiveByKind($tid, ExpenseReportRules::KIND) as $rep) {
			$report = InvoiceRules::decode($rep->getData());
			if ($report === null || !ExpenseExportRules::takes($report)) {
				continue;
			}
			foreach (ExpenseReportRules::receiptsOf($report) as $k) {
				$d = $receipts[$k] ?? null;
				if ($d !== null && ExportRules::inPeriod((string)($d->date ?? ''), $from, $to)) {
					$rows[] = [$rep->getRkey(), $report, $d];
				}
			}
		}
		usort($rows, static fn (array $a, array $b): int => [(string)$a[2]->date, $a[0]] <=> [(string)$b[2]->date, $b[0]]);
		$content = $format === ExpenseExportRules::DATEV ? $this->datev($m, $rows, $from, $to) : $this->csv($rows);
		return ['content' => ExportRules::BOM . $content, 'name' => ExpenseExportRules::fileName($format, $from, $to)];
	}

	/** @param list<array{0:string,1:\stdClass,2:\stdClass}> $rows */
	private function csv(array $rows): string {
		$out = ExportRules::line(ExpenseExportRules::CSV_HEAD);
		foreach ($rows as [$key, $report, $r]) {
			$out .= ExportRules::line(ExpenseExportRules::csvRow($this->team->displayName((string)$report->person), $key, $report, $r));
		}
		return $out;
	}

	/** @param list<array{0:string,1:\stdClass,2:\stdClass}> $rows */
	private function datev(Membership $m, array $rows, ?string $from, ?string $to): string {
		$datev = ExportRules::datev($this->team->tenant($m->tenantId)->getDatevJson());
		$expense = ExpenseExportRules::requireExpenseAccount($datev);
		$creditors = [];
		foreach ($rows as [, $report]) {
			$uid = (string)$report->person;
			if (!isset($creditors[$uid])) {
				// The person record keyed by the account, else one naming it in `accounts`.
				$p = $this->records->findOne($m->tenantId, 'person', $uid);
				if ($p === null || $p->isTombstone()) {
					$p = $this->records->findPersonsOf($uid, $m->tenantId, true)[0] ?? null;
				}
				$creditors[$uid] = ExpenseExportRules::creditorOf(InvoiceRules::decode($p?->getData()), $this->team->displayName($uid));
			}
		}
		$today = gmdate('Y-m-d', $this->time->getTime());
		$first = $rows[0][2]->date ?? $today;
		$last = $rows === [] ? $today : ($rows[count($rows) - 1][2]->date ?? $today);
		$head = ExportRules::datevHeader($datev, $from ?? (string)$first, $to ?? (string)$last, $this->time->getTime(), $m->uid, (string)($rows[0][2]->currency ?? 'EUR'));
		$head[16] = 'TimeSister Spesen';
		$out = ExportRules::line($head) . ExportRules::line(ExportRules::DATEV_HEAD);
		foreach ($rows as [$key, $report, $r]) {
			$out .= ExportRules::line(ExpenseExportRules::datevRow($r, $key, $expense, $creditors[(string)$report->person]));
		}
		return $out;
	}
}
