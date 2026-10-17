<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\ExpenseReportRules;
use OCA\TimeSister\Service\ExpenseRuleRules;
use OCA\TimeSister\Service\LedgerRules;
use PHPUnit\Framework\TestCase;

/**
 * Expense rules (0.22.0): content, periods and approval. Expense reports
 * (0.23.0): the expense list, fixing (lines, sums, payload), status and
 * rights.
 */
class ExpenseRulesTest extends TestCase {
	private static function outcome(callable $fn): string {
		try {
			$fn();
			return 'ok';
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
	}

	private static function rule(array $over = []): \stdClass {
		return (object)array_merge(['schema' => 1, 'person' => 'alice', 'text' => 'Mobile', 'amount' => 50, 'vat_rate' => null, 'period' => 'monthly',
			'from' => '2026-01-15', 'to' => null, 'project' => null, 'budget' => null, 'cost' => null], $over);
	}

	public function testRuleContent(): void {
		$this->assertSame('ok', self::outcome(fn () => ExpenseRuleRules::validate(self::rule())));
		$this->assertSame('ok', self::outcome(fn () => ExpenseRuleRules::validate(self::rule(['project' => 'd1', 'budget' => 'b1', 'cost' => 1, 'to' => '2026-12-31']))));
		$this->assertSame('422 “period” must be one of monthly, quarterly, yearly.', self::outcome(fn () => ExpenseRuleRules::validate(self::rule(['period' => 'weekly']))));
		$this->assertSame('422 “amount” must be a number ≥ 0.', self::outcome(fn () => ExpenseRuleRules::validate(self::rule(['amount' => -1]))));
		$this->assertSame('422 “from” must not be after “to”.', self::outcome(fn () => ExpenseRuleRules::validate(self::rule(['to' => '2026-01-01']))));
		$this->assertSame('422 “text” must be text of 1 to 300 characters.', self::outcome(fn () => ExpenseRuleRules::validate(self::rule(['text' => ' ']))));
		$this->assertStringStartsWith('422 “project”, “budget” and “cost”', self::outcome(fn () => ExpenseRuleRules::validate(self::rule(['project' => 'd1']))));
	}

	public function testApprovalCleared(): void {
		$stored = self::rule(['approved_by' => 'admin', 'approved_on' => '2026-01-20']);
		$same = self::rule(['text' => 'Mobile phone', 'to' => '2026-06-30', 'approved_by' => 'mallory']);
		ExpenseRuleRules::mergeServer($stored, $same);
		$this->assertSame(['admin', '2026-01-20'], [$same->approved_by, $same->approved_on], 'text and end keep the approval; the client’s value is ignored');
		$more = self::rule(['amount' => 60]);
		ExpenseRuleRules::mergeServer($stored, $more);
		$this->assertSame([null, null], [$more->approved_by, $more->approved_on], 'a new amount clears it');
		$new = self::rule(['approved_by' => 'alice']);
		ExpenseRuleRules::mergeServer(null, $new);
		$this->assertNull($new->approved_by);
		$this->assertSame('409 An approved expense rule is not deleted; end it by setting “to”.', self::outcome(fn () => ExpenseRuleRules::requireWrite(true, 'admin', $stored, null)));
		$this->assertStringStartsWith('403', self::outcome(fn () => ExpenseRuleRules::requireWrite(false, 'bob', null, self::rule())));
		$this->assertSame('ok', self::outcome(fn () => ExpenseRuleRules::requireWrite(false, 'alice', null, self::rule())));
		$this->assertTrue(ExpenseRuleRules::canApprove(false, ['d1' => true], self::rule(['project' => 'd1'])));
		$this->assertFalse(ExpenseRuleRules::canApprove(false, ['d1' => true], self::rule()));
	}

	public function testPeriods(): void {
		$this->assertSame(['2026-02-01', '2026-02-28'], ExpenseRuleRules::bounds('2026-02'));
		$this->assertSame(['2026-04-01', '2026-06-30'], ExpenseRuleRules::bounds('2026-Q2'));
		$this->assertSame(['2026-01-01', '2026-12-31'], ExpenseRuleRules::bounds('2026'));
		$r = self::rule(['to' => '2026-06-10']);
		$this->assertTrue(ExpenseRuleRules::covers($r, '2026-01'), 'the month the rule begins in');
		$this->assertTrue(ExpenseRuleRules::covers($r, '2026-06'));
		$this->assertFalse(ExpenseRuleRules::covers($r, '2025-12'));
		$this->assertFalse(ExpenseRuleRules::covers($r, '2026-07'));
		$this->assertFalse(ExpenseRuleRules::covers($r, '2026-Q1'), 'not the rule’s form');
		$this->assertTrue(ExpenseRuleRules::covers(self::rule(['period' => 'quarterly']), '2026-Q1'));
		$this->assertTrue(ExpenseRuleRules::covers(self::rule(['period' => 'yearly']), '2027'));
	}

	private static function receipt(array $over = []): \stdClass {
		return (object)array_merge(['kind' => 'receipt', 'date' => '2026-10-02', 'person' => 'alice', 'reimburse' => true, 'status' => 'approved',
			'net' => 100.0, 'vat_rate' => 8.1, 'vat' => 8.1, 'gross' => 108.1, 'currency' => 'CHF', 'project' => 'd1', 'report' => null], $over);
	}

	/** Schema 2 (0.23.0): content, status, one open report per person, client writes. */
	public function testReport(): void {
		$open = (object)['schema' => 2, 'person' => 'alice', 'status' => 'open', 'ready_on' => null];
		$this->assertSame('ok', self::outcome(fn () => ExpenseReportRules::validate((object)['person' => 'alice', 'ready_on' => '2026-10-31'])));
		$this->assertSame('422 “ready_on” must be a day in the format YYYY-MM-DD.', self::outcome(fn () => ExpenseReportRules::validate((object)['person' => 'alice', 'ready_on' => 'soon'])));
		$this->assertSame('422 “person” is required: the account’s user ID.', self::outcome(fn () => ExpenseReportRules::validate((object)[])));
		$fixed = (object)['schema' => 2, 'person' => 'alice', 'status' => 'fixed', 'number' => 'SP-2026-0001'];
		$old = (object)['schema' => 1, 'person' => 'alice', 'status' => 'approved'];
		$this->assertTrue(ExpenseReportRules::holds($fixed));
		$this->assertTrue(ExpenseReportRules::holds((object)['schema' => 2, 'status' => 'paid']));
		$this->assertFalse(ExpenseReportRules::holds((object)['schema' => 2, 'status' => 'reversed']), 'a reversed report has released its receipts');
		$this->assertFalse(ExpenseReportRules::holds($old), 'a report of 0.22.0 is ignored');
		$this->assertTrue(ExpenseReportRules::is($fixed, 'fixed'));
		$this->assertFalse(ExpenseReportRules::is($old, 'approved'));
		$this->assertSame('sb-2', ExpenseReportRules::openOf(['sb-1' => $fixed, 'sb-0' => $old, 'sb-2' => $open, 'sb-3' => null], 'alice'));
		$this->assertNull(ExpenseReportRules::openOf(['sb-2' => $open], 'bob'));

		// Client writes: only the open report, by its person or a Team Admin; the server's fields are kept.
		$this->assertSame('ok', self::outcome(fn () => ExpenseReportRules::requireWrite(false, 'alice', null, $open)));
		$this->assertStringStartsWith('403', self::outcome(fn () => ExpenseReportRules::requireWrite(false, 'bob', null, $open)));
		$this->assertSame('ok', self::outcome(fn () => ExpenseReportRules::requireWrite(true, 'admin', $open, null)), 'an open report is deleted');
		$this->assertSame('409 A fixed expense report changes only through its actions (paid, reverse).', self::outcome(fn () => ExpenseReportRules::requireWrite(true, 'admin', $fixed, $fixed)));
		$this->assertSame('409 A fixed expense report changes only through its actions (paid, reverse).', self::outcome(fn () => ExpenseReportRules::requireWrite(true, 'admin', $old, null)), 'nor an old one');
		$this->assertSame('409 The person of an expense report does not change.', self::outcome(fn () => ExpenseReportRules::requireWrite(true, 'admin', $open, (object)['person' => 'bob'])));
		$new = (object)['person' => 'alice', 'status' => 'paid', 'number' => 'X', 'lines' => [1]];
		ExpenseReportRules::mergeServer(null, $new);
		$this->assertSame([2, 'open', null, null], [$new->schema, $new->status, $new->number, $new->lines]);
		$kept = (object)['person' => 'alice', 'ready_on' => '2026-10-31', 'status' => 'fixed'];
		ExpenseReportRules::mergeServer($open, $kept);
		$this->assertSame(['open', '2026-10-31'], [$kept->status, $kept->ready_on]);
		$this->assertTrue(ExpenseReportRules::canRead(false, 'alice', $open));
		$this->assertFalse(ExpenseReportRules::canRead(false, 'bob', $open), 'a Lead reads no expense report');
		$this->assertTrue(ExpenseReportRules::canRead(true, 'admin', $open));
	}

	/** The expense list, the subset to fix, the frozen lines, sums and payload. */
	public function testFixing(): void {
		$reports = ['sb-1' => (object)['schema' => 2, 'status' => 'fixed', 'number' => 'SP-2026-0001', 'person' => 'alice'], 'sb-0' => (object)['schema' => 1, 'status' => 'approved']];
		$receipts = [
			'rc-b' => self::receipt(['date' => '2026-10-05']),
			'rc-a' => self::receipt(['status' => 'internal', 'project' => null, 'kind' => 'trust', 'vat_rate' => null, 'vat' => 0, 'net' => 50.0, 'gross' => 50.0]),
			'rc-old' => self::receipt(['report' => 'sb-0', 'date' => '2026-09-30', 'vat_lines' => [(object)['rate' => 2.6, 'net' => 40.0, 'vat' => 1.04], (object)['rate' => 8.1, 'net' => 60.0, 'vat' => 4.86]], 'vat_rate' => null, 'vat' => 5.9, 'gross' => 105.9]),
			'rc-held' => self::receipt(['report' => 'sb-1']),
			'rc-open' => self::receipt(['status' => 'open']),
			'rc-bob' => self::receipt(['person' => 'bob']),
			'rc-company' => self::receipt(['reimburse' => false]),
		];
		$this->assertSame(['rc-old', 'rc-a', 'rc-b'], ExpenseReportRules::pick($receipts, $reports, 'alice', null), 'the whole list by date; a mark of 0.22.0 counts as none');
		$this->assertSame(['rc-a', 'rc-b'], ExpenseReportRules::pick($receipts, $reports, 'alice', ['rc-b', 'rc-a']));
		$pick = fn (mixed $given): string => self::outcome(fn () => ExpenseReportRules::pick($receipts, $reports, 'alice', $given));
		$this->assertSame('409 The receipt rc-held is in the expense report SP-2026-0001.', $pick(['rc-held']));
		$this->assertSame('422 The receipt rc-open is not on the expense list of this person.', $pick(['rc-open']));
		$this->assertSame('422 The receipt rc-bob is not on the expense list of this person.', $pick(['rc-bob']));
		$this->assertSame('422 The receipt rc-x is not on the expense list of this person.', $pick(['rc-x']));
		$this->assertSame('422 The receipt rc-a is in the list twice.', $pick(['rc-a', 'rc-a']));
		$this->assertSame('422 “receipts” must be a list of 1 to 500 receipt keys.', $pick([]));
		$this->assertSame('422 The expense list of this person holds no receipts.', self::outcome(fn () => ExpenseReportRules::pick($receipts, $reports, 'carol', null)));

		$line = ExpenseReportRules::line('rc-b', $receipts['rc-b']);
		$this->assertSame(['receipt' => 'rc-b', 'date' => '2026-10-05', 'supplier' => null, 'text' => null, 'project' => 'd1', 'cost' => null,
			'vat_lines' => [['rate' => 8.1, 'net' => 100.0, 'vat' => 8.1]], 'net' => 100.0, 'vat' => 8.1, 'gross' => 108.1, 'currency' => 'CHF', 'booked' => null, 'without_file' => false], $line);
		$this->assertSame([], ExpenseReportRules::line('rc-a', $receipts['rc-a'])['vat_lines'], 'no rate: no VAT line');
		$this->assertTrue(ExpenseReportRules::line('rc-a', $receipts['rc-a'])['without_file']);
		$this->assertSame([2.6, 8.1], ExpenseReportRules::ratesOf(ExpenseReportRules::line('rc-old', $receipts['rc-old'])));
		$lines = array_map(fn (string $k): array => ExpenseReportRules::line($k, $receipts[$k]), ['rc-old', 'rc-a', 'rc-b']);
		$this->assertSame(['currency' => 'CHF', 'net' => 250.0, 'vat_lines' => [['rate' => 0.0, 'net' => 50.0, 'vat' => 0.0], ['rate' => 2.6, 'net' => 40.0, 'vat' => 1.04], ['rate' => 8.1, 'net' => 160.0, 'vat' => 12.96]], 'gross' => 264.0],
			ExpenseReportRules::sums($lines, 'CHF'));
		// Another currency: the booked amount in the team's currency counts.
		$usd = ExpenseReportRules::line('rc-u', self::receipt(['currency' => 'USD', 'booked' => (object)['gross' => 97.3, 'currency' => 'CHF']]));
		$this->assertSame(['gross' => 97.3, 'currency' => 'CHF'], $usd['booked']);
		$this->assertSame(97.3, ExpenseReportRules::sums([$usd], 'CHF')['gross']);
		$this->assertSame('CHF', ExpenseReportRules::sums([$usd], 'CHF')['currency']);
		$this->assertSame('422 All receipts of an expense report are in one currency.', self::outcome(fn () => ExpenseReportRules::sums([$line, ExpenseReportRules::line('rc-e', self::receipt(['currency' => 'EUR']))], 'CHF')));

		$payload = ExpenseReportRules::payload('sb-9', 'SP-2026-0002', 'alice', '2026-11-02', 'admin', null, [$line], ExpenseReportRules::sums([$line], 'CHF'));
		$this->assertSame('{"fixed_by":"admin","fixed_on":"2026-11-02","key":"sb-9","lines":[{"booked":null,"cost":null,"currency":"CHF","date":"2026-10-05","gross":108.1,"net":100.0,"project":"d1","receipt":"rc-b","supplier":null,"text":null,"vat":8.1,"vat_lines":[{"net":100.0,"rate":8.1,"vat":8.1}],"without_file":false}],"number":"SP-2026-0002","person":"alice","ready_on":null,"sums":{"currency":"CHF","gross":108.1,"net":100.0,"vat_lines":[{"net":100.0,"rate":8.1,"vat":8.1}]}}',
			LedgerRules::canonical($payload));
		$this->assertTrue(ExpenseReportRules::withoutReceipt(self::receipt(['kind' => 'recurring'])));
		$this->assertFalse(ExpenseReportRules::withoutReceipt(self::receipt()));
	}
}
