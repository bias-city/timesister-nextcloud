<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\InvoiceRules;
use OCA\TimeSister\Service\ReceiptFiles;
use OCA\TimeSister\Service\ReceiptLineRules;
use PHPUnit\Framework\TestCase;

/**
 * Invoicing from receipts (0.22.0): costs lines naming receipts, the
 * markup, the expense percentage, receipts the customer paid at 0.00.
 */
class ReceiptLineRulesTest extends TestCase {
	/** @return array<string,\stdClass> */
	private static function receipts(): array {
		$r = static fn (array $over): \stdClass => (object)array_merge(['status' => 'approved', 'project' => 'd1', 'budget' => 'b1', 'cost' => 1, 'invoice_effect' => 'charge', 'invoice' => null, 'note' => null], $over);
		return [
			'rc-1' => $r([]),
			'rc-2' => $r([]),
			'rc-3' => $r(['cost' => 2, 'invoice_effect' => 'show_only', 'note' => 'paid by the customer']),
		];
	}

	/** @return list<\stdClass> */
	private static function positions(array $change = []): array {
		$p = [
			['kind' => 'hours', 'text' => 'Planung', 'amount' => 1000],
			['kind' => 'costs', 'text' => 'Spesen', 'amount' => 300, 'cost' => 1, 'receipts' => ['rc-1', 'rc-2']],
			['kind' => 'markup', 'text' => 'Zuschlag 10 %', 'amount' => 30, 'cost' => 1, 'rate' => 10],
			['kind' => 'costs', 'text' => 'Druck', 'amount' => 0, 'cost' => 2, 'receipts' => ['rc-3']],
			['kind' => 'expense_percent', 'text' => 'Nebenkosten 3 %', 'amount' => 30, 'rate' => 3],
		];
		foreach ($change as $i => $over) {
			$p[$i] = $over === null ? null : array_merge($p[$i], $over);
		}
		return array_values(array_map(static fn (array $x): \stdClass => (object)$x, array_filter($p)));
	}

	private static function check(array $positions, ?array $receipts = null, string $project = 'd1', string $budget = 'b1', ?string $customer = 'k1'): string {
		try {
			// d1 and d2 belong to the customer k1, d3 to k2.
			ReceiptLineRules::check($positions, $receipts ?? self::receipts(), $project, $budget, $customer, 'inv-1', static fn (string $k): string => 'RE-' . $k,
				static fn (string $p): ?string => ['d1' => 'k1', 'd2' => 'k1', 'd3' => 'k2'][$p] ?? null);
			return 'ok';
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
	}

	public function testValid(): void {
		$this->assertSame('ok', self::check(self::positions()));
		$this->assertSame('ok', self::check(self::positions([2 => ['amount' => 30.01]])), 'within a cent');
		$this->assertSame(['rc-1', 'rc-2', 'rc-3'], ReceiptFiles::keysOf(self::positions()));
	}

	public function testErrors(): void {
		$rc = self::receipts();
		$this->assertSame('422 Position 3: The amount must be 30.00.', self::check(self::positions([2 => ['amount' => 33]])));
		$this->assertSame('422 Position 5: The amount must be 30.00.', self::check(self::positions([4 => ['amount' => 31]])));
		$this->assertSame('422 Position 5: “rate” must be a percentage from 0 to 100.', self::check(self::positions([4 => ['rate' => null]])));
		// 0.39.0: a markup without a cost position adds to the lines without one.
		$this->assertSame('422 Position 3: The amount must be 0.00.', self::check(self::positions([2 => ['cost' => null]])));
		$this->assertSame('422 Position 2: A line with receipts of a cost position needs “cost”.', self::check(self::positions([1 => ['cost' => null]])));
		$this->assertSame('422 Position 4: The receipt rc-1 is on the invoice twice.', self::check(self::positions([3 => ['receipts' => ['rc-1']]])));
		$this->assertSame('422 Position 2: The receipt rc-9 does not exist.', self::check(self::positions([1 => ['receipts' => ['rc-9']]])));
		$rc['rc-1']->status = 'open';
		$this->assertSame('422 Position 2: The receipt rc-1 is not approved.', self::check(self::positions(), $rc));
		$rc['rc-1']->status = 'billed';
		$rc['rc-1']->invoice = 'inv-0';
		$this->assertSame('409 Position 2: The receipt rc-1 is billed by invoice RE-inv-0.', self::check(self::positions(), $rc));
		$rc = self::receipts();
		$rc['rc-2']->budget = 'b2';
		$this->assertSame('422 Position 2: The receipt rc-2 belongs to another project, budget or cost position.', self::check(self::positions(), $rc));
		$rc = self::receipts();
		$rc['rc-2']->invoice_effect = 'none';
		$this->assertSame('422 Position 2: The receipt rc-2 is not for the invoice.', self::check(self::positions(), $rc));
		$this->assertSame('422 Position 4: A line of receipts the customer paid has the amount 0.', self::check(self::positions([3 => ['amount' => 50]])));
		$rc = self::receipts();
		$rc['rc-2']->invoice_effect = 'show_only';
		$this->assertSame('422 Position 2: Receipts the customer paid stand on a line of their own.', self::check(self::positions(), $rc));
		// The draft check: receipts only on costs lines, as keys.
		$bad = static function (array $p): string {
			try {
				InvoiceRules::checkPositions([(object)$p]);
				return 'ok';
			} catch (ApiException $e) {
				return $e->getStatus() . ' ' . $e->getMessage();
			}
		};
		$this->assertSame('422 Position 1: Only a “costs” line names receipts.', $bad(['kind' => 'flat', 'text' => 'x', 'amount' => 1, 'receipts' => ['rc-1']]));
		$this->assertSame('422 Position 1: “receipts” must be a list of 1 to 500 receipt keys.', $bad(['kind' => 'costs', 'text' => 'x', 'amount' => 1, 'receipts' => ['x']]));
		$this->assertSame('ok', $bad(['kind' => 'markup', 'text' => 'x', 'amount' => 1, 'cost' => 1, 'rate' => 10]));
		$this->assertSame('422 Position 1: “rate” must be null or a percentage from 0 to 100.', $bad(['kind' => 'markup', 'text' => 'x', 'amount' => 1, 'rate' => 110]));
	}

	/** 0.39.0: receipts go by the customer; project, budget and cost position only with a budget and a cost position. */
	public function testReceiptsByCustomer(): void {
		$rc = self::receipts();
		// Without a budget on the invoice: the same project or a project of the same customer.
		$this->assertSame('ok', self::check(self::positions(), $rc, 'd1', ''));
		$this->assertSame('ok', self::check(self::positions(), $rc, '', '', 'k1'), 'no project: the customer');
		$this->assertSame('ok', self::check(self::positions([1 => ['cost' => null], 2 => ['cost' => null]]), $rc, 'd1', ''), 'no cost position needed');
		$rc['rc-2']->project = 'd2';
		$rc['rc-2']->budget = 'b9';
		$this->assertSame('ok', self::check(self::positions(), $rc, 'd1', ''), 'another project of the customer');
		$rc['rc-2']->project = 'd3';
		$this->assertSame('422 Position 2: The receipt rc-2 belongs to another customer.', self::check(self::positions(), $rc, 'd1', ''));
		$this->assertSame('422 Position 2: The receipt rc-2 belongs to another customer.', self::check(self::positions(), $rc, 'd1', '', null), 'no customer: only the same project');
		$rc['rc-2']->project = null;
		$this->assertSame('422 Position 2: The receipt rc-2 belongs to another customer.', self::check(self::positions(), $rc, 'd1', ''), 'a general cost has no customer');
		// With a budget: a receipt without a cost position goes by the customer too.
		$rc = self::receipts();
		$rc['rc-2']->project = 'd2';
		$rc['rc-2']->budget = null;
		$rc['rc-2']->cost = null;
		$this->assertSame('ok', self::check(self::positions(), $rc));
		$rc['rc-2']->cost = 1;
		$rc['rc-2']->budget = 'b1';
		$this->assertSame('422 Position 2: The receipt rc-2 belongs to another project, budget or cost position.', self::check(self::positions(), $rc));
	}

	public function testShowOnlyLinesAtZero(): void {
		$p = ReceiptLineRules::prepare(self::positions([3 => ['vat' => 8.1]]), self::receipts());
		$this->assertSame([0, 0, 'Druck – paid by the customer'], [$p[3]->vat, $p[3]->amount, $p[3]->text]);
		$this->assertSame(1000, $p[0]->amount, 'other lines unchanged');
	}
}
