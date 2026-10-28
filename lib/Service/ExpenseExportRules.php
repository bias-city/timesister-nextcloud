<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The export of expenses (0.22.0), pure: one row per frozen line of a
 * fixed or paid expense report (0.23.0), as CSV for payroll or as a DATEV
 * Buchungsstapel (debit the expense account, credit the person's
 * creditor account). A line of 0.30.0 counts with its `amount` and
 * `vat_amounts` in the team's currency, an older one with the receipt's
 * amounts (booked in the team's currency).
 */
final class ExpenseExportRules {
	public const CSV = 'csv';
	public const DATEV = 'datev';
	public const FORMATS = [self::CSV, self::DATEV];
	public const CSV_HEAD = ['person', 'person_name', 'report', 'report_status', 'date', 'supplier', 'text', 'project', 'currency', 'net', 'vat_rate', 'vat', 'gross', 'without_receipt'];

	/** The `kind` parameter (csv default, datev); not `format`, which OCS keeps for json/xml. */
	public static function kind(mixed $v): string {
		if ($v === null || $v === '') {
			return self::CSV;
		}
		if (!in_array($v, self::FORMATS, true)) {
			throw ReceiptRules::oneOf('kind', self::FORMATS);
		}
		return $v;
	}

	/** Does the export take this report? Fixed or paid (0.23.0). */
	public static function takes(?\stdClass $report): bool {
		return ExpenseReportRules::holds($report);
	}

	/**
	 * How a frozen line counts: currency, net, the single VAT rate (null for
	 * none or several), VAT and gross.
	 *
	 * @return array{currency:string,net:float,rate:?float,vat:float,gross:float}
	 */
	public static function counted(\stdClass $report, \stdClass $line): array {
		if (ExpenseReportRules::isTableLine($line)) {
			// 0.30.0: in the team's currency after the factor; VAT only of receipts in it.
			$vat = 0.0;
			$rates = [];
			foreach (Fields::list($line, 'vat_amounts') as $x) {
				if ($x instanceof \stdClass) {
					$vat += (float)($x->vat ?? 0);
					$rates[] = (float)($x->rate ?? 0);
				}
			}
			$gross = (float)$line->amount;
			$vat = ExpenseTable::round2($vat);
			$currency = Fields::str(Fields::obj($report, 'sums'), 'currency') ?? (string)($line->currency ?? '');
			return ['currency' => $currency, 'net' => ExpenseTable::round2($gross - $vat), 'rate' => count($rates) === 1 ? $rates[0] : null, 'vat' => $vat, 'gross' => $gross];
		}
		$r = ExpenseReportRules::asReceipt($line);
		$rates = ExpenseReportRules::ratesOf($r);
		$e = ReceiptBooking::forExpenses($r, '');
		return ['currency' => $e['currency'], 'net' => array_sum(array_column($e['rates'], 'net')), 'rate' => count($rates) === 1 ? $rates[0] : null,
			'vat' => array_sum(array_column($e['rates'], 'vat')), 'gross' => $e['gross']];
	}

	/** One row of a frozen line; `report` is the report's number. @return list<string> */
	public static function csvRow(string $personName, string $reportNumber, \stdClass $report, \stdClass $r): array {
		$c = self::counted($report, $r);
		return [
			(string)$report->person, $personName, $reportNumber, (string)($report->status ?? ''), (string)($r->date ?? ''),
			(string)($r->supplier ?? ''), (string)($r->text ?? ''), is_string($r->project ?? null) ? $r->project : '', $c['currency'],
			ExportRules::amount($c['net']), $c['rate'] === null ? '' : (string)$c['rate'],
			ExportRules::amount($c['vat']), ExportRules::amount($c['gross']),
			($r->without_file ?? false) === true ? '1' : '0',
		];
	}

	/** The DATEV export needs the expense account: 422 otherwise. @param array<string,mixed> $datev */
	public static function requireExpenseAccount(array $datev): string {
		$a = $datev['expense_account'] ?? null;
		if (!is_string($a) || $a === '') {
			throw ApiException::invalid(Message::of('The DATEV export needs the accounts in the team setting “datev”: “{field}” is missing.', ['field' => 'expense_account']));
		}
		return $a;
	}

	/** The person's creditor account (1–9 digits): 422 naming the person when missing. */
	public static function creditorOf(?\stdClass $person, string $name): string {
		$a = $person?->{PersonRules::CREDITOR} ?? null;
		if (!is_string($a) || preg_match('/^\d{1,9}$/', $a) !== 1) {
			throw ApiException::invalid(Message::of('The DATEV export needs the creditor account of {person} (“creditor_account” in the person record).', ['person' => $name]));
		}
		return $a;
	}

	/**
	 * One booking: Umsatz = gross, S (H when negative), Konto = expense
	 * account, Gegenkonto = the person's creditor account, no BU key,
	 * Belegdatum DDMM, Belegfeld 1 = the report's number, text = supplier and text.
	 *
	 * @return list<string>
	 */
	public static function datevRow(\stdClass $report, \stdClass $r, string $reportNumber, string $expenseAccount, string $creditor): array {
		$c = self::counted($report, $r);
		$gross = $c['gross'];
		$date = (string)($r->date ?? '');
		$text = mb_substr(trim((string)($r->supplier ?? '') . ' ' . (string)($r->text ?? '')), 0, 60);
		return [
			number_format(abs($gross), 2, ',', ''), $gross < 0 ? 'H' : 'S', $c['currency'], '', '', '',
			$expenseAccount, $creditor, '', substr($date, 8, 2) . substr($date, 5, 2), ExportRules::belegfeld($reportNumber), '', '', $text,
		];
	}

	/** `expenses-<from>-<to>.csv` or `EXTF_expenses-….csv`. */
	public static function fileName(string $format, ?string $from, ?string $to): string {
		$span = ($from ?? 'start') . '_' . ($to ?? 'today');
		return ($format === self::DATEV ? 'EXTF_expenses_' : 'expenses_') . $span . '.csv';
	}
}
