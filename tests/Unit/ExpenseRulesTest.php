<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\ExpenseReportRules;
use OCA\TimeSister\Service\ExpenseRuleRules;
use PHPUnit\Framework\TestCase;

/**
 * Expense rules and reports (0.22.0): the rule's content, periods and
 * approval; the report's receipts, sums per rate and rights.
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

	public function testReport(): void {
		$report = (object)['schema' => 1, 'person' => 'alice', 'from' => '2026-10-01', 'to' => '2026-10-31', 'receipts' => ['rc-1', 'rc-2']];
		$this->assertSame('ok', self::outcome(fn () => ExpenseReportRules::validate($report)));
		$this->assertSame('422 The receipt rc-1 is in the list twice.', self::outcome(fn () => ExpenseReportRules::validate((object)['person' => 'alice', 'from' => '2026-10-01', 'to' => '2026-10-31', 'receipts' => ['rc-1', 'rc-1']])));
		$this->assertSame('422 “receipts” must be a list of 1 to 500 receipt keys.', self::outcome(fn () => ExpenseReportRules::validate((object)['person' => 'alice', 'from' => '2026-10-01', 'to' => '2026-10-31', 'receipts' => []])));
		$ok = fn (?\stdClass $r): string => self::outcome(fn () => ExpenseReportRules::requireEligible($report, 'sb-1', 'rc-1', $r));
		$this->assertSame('ok', $ok(self::receipt()));
		$this->assertSame('ok', $ok(self::receipt(['status' => 'internal', 'project' => null])), 'a general receipt, approved to internal');
		$this->assertSame('ok', $ok(self::receipt(['report' => 'sb-1'])), 'already in this report');
		$this->assertSame('422 The receipt rc-1 does not exist.', $ok(null));
		$this->assertSame('422 The receipt rc-1 is not reimbursed to this person.', $ok(self::receipt(['person' => 'bob'])));
		$this->assertSame('422 The receipt rc-1 is not reimbursed to this person.', $ok(self::receipt(['reimburse' => false])));
		$this->assertSame('422 The receipt rc-1 is not approved.', $ok(self::receipt(['status' => 'open'])));
		$this->assertSame('422 The receipt rc-1 is not dated within the report’s period.', $ok(self::receipt(['date' => '2026-11-01'])));
		$this->assertSame('409 The receipt rc-1 is in the expense report sb-0.', $ok(self::receipt(['report' => 'sb-0'])));
		$s = ExpenseReportRules::sums([self::receipt(), self::receipt(['vat_rate' => null, 'vat' => 0, 'net' => 50.0, 'gross' => 50.0]), self::receipt(['net' => 12.5, 'vat_rate' => 2.6, 'vat' => 0.33])], 'CHF');
		$this->assertSame(['currency' => 'CHF', 'net' => 162.5, 'vat_lines' => [['rate' => 0.0, 'net' => 50.0, 'vat' => 0.0], ['rate' => 2.6, 'net' => 12.5, 'vat' => 0.33], ['rate' => 8.1, 'net' => 100.0, 'vat' => 8.1]], 'gross' => 170.93], $s);
		$this->assertSame('422 All receipts of an expense report are in one currency.', self::outcome(fn () => ExpenseReportRules::sums([self::receipt(), self::receipt(['currency' => 'EUR'])], 'CHF')));
		$this->assertTrue(ExpenseReportRules::leadApproves(['d1' => true], [self::receipt()]));
		$this->assertFalse(ExpenseReportRules::leadApproves(['d1' => true], [self::receipt(), self::receipt(['project' => null])]));
		$this->assertTrue(ExpenseReportRules::canRead(false, 'alice', [], $report, []));
		$this->assertFalse(ExpenseReportRules::canRead(false, 'bob', [], $report, [self::receipt()]));
		$this->assertSame('409 An approved expense report does not change.', self::outcome(fn () => ExpenseReportRules::requireWrite(true, 'admin', (object)['status' => 'approved', 'person' => 'alice'], $report)));
		$this->assertStringStartsWith('403', self::outcome(fn () => ExpenseReportRules::requireWrite(false, 'bob', null, $report)));
		$new = clone $report;
		$new->status = 'paid';
		ExpenseReportRules::mergeServer(null, $new, $s, '2026-11-02');
		$this->assertSame(['submitted', '2026-11-02', null], [$new->status, $new->submitted_on, $new->document]);
		$this->assertTrue(ExpenseReportRules::withoutReceipt(self::receipt(['kind' => 'trust'])));
		$this->assertFalse(ExpenseReportRules::withoutReceipt(self::receipt()));
	}
}
