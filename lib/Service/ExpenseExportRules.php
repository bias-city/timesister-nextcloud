<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The export of expenses (0.22.0), pure: one row per receipt of an
 * approved or paid expense report, as CSV for payroll or as a DATEV
 * Buchungsstapel (debit the expense account, credit the person's
 * creditor account).
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

	/** Does the export take this report? Approved or paid. */
	public static function takes(?\stdClass $report): bool {
		return in_array($report?->status ?? null, [ExpenseReportRules::APPROVED, ExpenseReportRules::PAID], true);
	}

	/** @return list<string> */
	public static function csvRow(string $personName, string $reportKey, \stdClass $report, \stdClass $r): array {
		$rate = $r->vat_rate ?? null;
		return [
			(string)$report->person, $personName, $reportKey, (string)($report->status ?? ''), (string)($r->date ?? ''),
			(string)($r->supplier ?? ''), (string)($r->text ?? ''), is_string($r->project ?? null) ? $r->project : '', (string)($r->currency ?? ''),
			ExportRules::amount((float)($r->net ?? 0)), is_int($rate) || is_float($rate) ? (string)$rate : '',
			ExportRules::amount((float)($r->vat ?? 0)), ExportRules::amount((float)($r->gross ?? 0)),
			ExpenseReportRules::withoutReceipt($r) ? '1' : '0',
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
	 * Belegdatum DDMM, Belegfeld 1 = the report's key, text = supplier and text.
	 *
	 * @return list<string>
	 */
	public static function datevRow(\stdClass $r, string $reportKey, string $expenseAccount, string $creditor): array {
		$gross = (float)($r->gross ?? 0);
		$date = (string)($r->date ?? '');
		$text = mb_substr(trim((string)($r->supplier ?? '') . ' ' . (string)($r->text ?? '')), 0, 60);
		return [
			number_format(abs($gross), 2, ',', ''), $gross < 0 ? 'H' : 'S', (string)($r->currency ?? ''), '', '', '',
			$expenseAccount, $creditor, '', substr($date, 8, 2) . substr($date, 5, 2), ExportRules::belegfeld($reportKey), '', '', $text,
		];
	}

	/** `expenses-<from>-<to>.csv` or `EXTF_expenses-….csv`. */
	public static function fileName(string $format, ?string $from, ?string $to): string {
		$span = ($from ?? 'start') . '_' . ($to ?? 'today');
		return ($format === self::DATEV ? 'EXTF_expenses_' : 'expenses_') . $span . '.csv';
	}
}
