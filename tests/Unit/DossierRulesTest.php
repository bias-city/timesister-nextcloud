<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\DossierRules;
use OCA\TimeSister\Service\ExpenseExportRules;
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
		$report = (object)['person' => 'alice', 'status' => 'paid'];
		$r = (object)['kind' => 'trust', 'date' => '2026-10-02', 'supplier' => 'Parking', 'text' => 'Meter', 'project' => null, 'currency' => 'CHF', 'net' => 4.63, 'vat_rate' => 8.1, 'vat' => 0.37, 'gross' => 5.0];
		$this->assertSame(['alice', 'Alice Example', 'sb-1', 'paid', '2026-10-02', 'Parking', 'Meter', '', 'CHF', '4.63', '8.1', '0.37', '5.00', '1'], ExpenseExportRules::csvRow('Alice Example', 'sb-1', $report, $r));
		$this->assertSame(['5,00', 'S', 'CHF', '', '', '', '6300', '70001', '', '0210', 'sb-1', '', '', 'Parking Meter'], ExpenseExportRules::datevRow($r, 'sb-1', '6300', '70001'));
		$this->assertTrue(ExpenseExportRules::takes($report));
		$this->assertFalse(ExpenseExportRules::takes((object)['status' => 'submitted']));
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
