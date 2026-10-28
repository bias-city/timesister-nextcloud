<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\DossierRules;
use OCA\TimeSister\Service\ExpenseExportRules;
use OCA\TimeSister\Service\ExpenseReportRules;
use OCA\TimeSister\Service\ExpenseTable;
use OCA\TimeSister\Service\ApiException;
use PHPUnit\Framework\TestCase;

/** The project dossier and the expense export (0.22.0), the pure parts. */
class DossierRulesTest extends TestCase {
	public function testEntriesOfTheProject(): void {
		$this->assertTrue(DossierRules::matches(['D196'], ['D196']));
		$this->assertTrue(DossierRules::matches(['d196.4.1'], ['D196']), 'a work package below the project');
		$this->assertFalse(DossierRules::matches(['D1961'], ['D196']));
		$this->assertFalse(DossierRules::matches([], ['D196']));
		$this->assertSame(['D196'], DossierRules::codesOf((object)['codes' => ['D196', '', 3]]));
		$ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:x\r\nDTSTART:20261003T083000Z\r\nDTEND:20261003T120000Z\r\nSUMMARY:Plan #D196\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$this->assertSame(['start' => '2026-10-03T08:30:00', 'end' => '2026-10-03T12:00:00', 'hours' => 3.5, 'series' => false], DossierRules::occurrence($ics));
		$series = str_replace('SUMMARY', "RRULE:FREQ=WEEKLY\r\nSUMMARY", $ics);
		$this->assertTrue(DossierRules::occurrence($series)['series']);
		$day = "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20261003\r\nDTEND;VALUE=DATE:20261004\r\nEND:VEVENT\r\n";
		$this->assertSame(['start' => '2026-10-03', 'end' => '2026-10-04', 'hours' => null, 'series' => false], DossierRules::occurrence($day));
		$csv = DossierRules::hoursCsv([['person' => 'alice', 'event_uid' => 'x', 'start' => '2026-10-03T08:30:00', 'end' => '2026-10-03T12:00:00', 'hours' => 3.5, 'series' => false, 'codes' => ['D196'], 'title' => 'Plan; site']]);
		$this->assertStringContainsString("person;event_uid;start;end;hours;series;codes;title\r\n", $csv);
		$this->assertStringContainsString("alice;x;2026-10-03T08:30:00;2026-10-03T12:00:00;3.50;0;D196;\"Plan; site\"\r\n", $csv);
		$this->assertSame("4 2026-10-16T10:00:00Z pbadmin issued RE-1 payload ab prev cd hash ef\n", DossierRules::ledgerText([['seq' => 4, 'at' => '2026-10-16T10:00:00Z', 'actor' => 'pbadmin', 'action' => 'issued', 'number' => 'RE-1', 'payload_hash' => 'ab', 'prev_hash' => 'cd', 'hash' => 'ef']]));
	}

	public function testExpenseExport(): void {
		// 0.23.0: rows are the frozen lines of fixed and paid reports; `report` is the number.
		$report = (object)['schema' => 2, 'person' => 'alice', 'status' => 'paid', 'number' => 'SP-2026-0001'];
		// A line of 0.23.0 as stored: the receipt's own amounts.
		$r = (object)['receipt' => 'rc-1', 'date' => '2026-10-02', 'supplier' => 'Parking', 'text' => 'Meter', 'project' => null, 'cost' => null,
			'vat_lines' => [(object)['rate' => 8.1, 'net' => 4.63, 'vat' => 0.37]], 'net' => 4.63, 'vat' => 0.37, 'gross' => 5.0, 'currency' => 'CHF', 'booked' => null, 'without_file' => true];
		$this->assertSame(['alice', 'Alice Example', 'SP-2026-0001', 'paid', '2026-10-02', 'Parking', 'Meter', '', 'CHF', '4.63', '8.1', '0.37', '5.00', '1'], ExpenseExportRules::csvRow('Alice Example', 'SP-2026-0001', $report, $r));
		$this->assertSame(['5,00', 'S', 'CHF', '', '', '', '6300', '70001', '', '0210', 'SP-2026-0001', '', '', 'Parking Meter'], ExpenseExportRules::datevRow($report, $r, 'SP-2026-0001', '6300', '70001'));
		$none = (object)['receipt' => 'rc-2', 'date' => '2026-10-02', 'vat_lines' => [], 'net' => 5, 'vat' => 0, 'gross' => 5, 'currency' => 'CHF', 'booked' => null];
		$this->assertSame('', ExpenseExportRules::csvRow('A', 'SP-1', $report, $none)[10], 'no rate: empty');
		// 0.30.0: a line of the expense table counts with its amount and VAT in the team's currency.
		$fixed = (object)['schema' => 2, 'person' => 'alice', 'status' => 'fixed', 'number' => 'SP-2026-0002', 'sums' => (object)['currency' => 'CHF']];
		$half = ExpenseTable::line('rc-3', (object)['kind' => 'receipt', 'date' => '2026-10-05', 'supplier' => 'Telefon Muster', 'text' => 'Mobile', 'net' => 92.51, 'vat_rate' => 8.1,
			'vat' => 7.49, 'gross' => 100.0, 'currency' => 'CHF', 'project' => null, 'invoice_effect' => 'none', 'category' => 'office', 'factor' => 0.5], 'CHF');
		$line = json_decode(json_encode($half, JSON_PRESERVE_ZERO_FRACTION));
		$this->assertTrue(ExpenseReportRules::isTableLine($line));
		$this->assertSame(['CHF', '46.25', '8.1', '3.75', '50.00', '0'], array_slice(ExpenseExportRules::csvRow('Alice', 'SP-2026-0002', $fixed, $line), 8));
		$this->assertSame(['50,00', 'S', 'CHF'], array_slice(ExpenseExportRules::datevRow($fixed, $line, 'SP-2026-0002', '6300', '70001'), 0, 3));
		$usd = json_decode(json_encode(ExpenseTable::line('rc-4', (object)['kind' => 'trust', 'date' => '2026-10-06', 'supplier' => 'Cab', 'text' => '', 'net' => 20.0, 'vat_rate' => 10,
			'vat' => 2.0, 'gross' => 22.0, 'currency' => 'USD', 'category' => 'travel', 'rate' => (object)['value' => 0.8, 'date' => '2026-10-05', 'source' => 'ecb']], 'CHF'), JSON_PRESERVE_ZERO_FRACTION));
		$this->assertSame(['CHF', '17.60', '', '0.00', '17.60', '1'], array_slice(ExpenseExportRules::csvRow('Alice', 'SP-2026-0002', $fixed, $usd), 8), 'foreign VAT is no input tax');
		$this->assertTrue(ExpenseExportRules::takes($report));
		$this->assertTrue(ExpenseExportRules::takes((object)['schema' => 2, 'status' => 'fixed']));
		$this->assertFalse(ExpenseExportRules::takes((object)['schema' => 2, 'status' => 'reversed']));
		$this->assertFalse(ExpenseExportRules::takes((object)['schema' => 1, 'status' => 'paid']), 'a report of 0.22.0 is ignored');
		$this->assertSame('csv', ExpenseExportRules::kind(null));
		$fail = static function (callable $fn): string {
			try {
				$fn();
				return 'ok';
			} catch (ApiException $e) {
				return $e->getStatus() . ' ' . $e->getMessage();
			}
		};
		$this->assertSame('422 “kind” must be one of csv, datev.', $fail(fn () => ExpenseExportRules::kind('xls')));
		$this->assertSame('422 The DATEV export needs the accounts in the team setting “datev”: “expense_account” is missing.', $fail(fn () => ExpenseExportRules::requireExpenseAccount(['expense_account' => null])));
		$this->assertSame('422 The DATEV export needs the creditor account of Alice Example (“creditor_account” in the person record).', $fail(fn () => ExpenseExportRules::creditorOf((object)[], 'Alice Example')));
		$this->assertSame('70001', ExpenseExportRules::creditorOf((object)['creditor_account' => '70001'], 'x'));
	}
}
