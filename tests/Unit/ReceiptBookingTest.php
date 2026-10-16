<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\ExpenseExportRules;
use OCA\TimeSister\Service\ExpenseReportRules;
use OCA\TimeSister\Service\ReceiptBooking;
use OCA\TimeSister\Service\ReceiptRules;
use PHPUnit\Framework\TestCase;

/**
 * A receipt in a foreign currency (0.22.0): the amount paid in the team's
 * currency, needed for approval, counted in reports and exports.
 */
class ReceiptBookingTest extends TestCase {
	private static function receipt(array $over = []): \stdClass {
		return (object)array_merge([
			'schema' => 1, 'kind' => 'receipt', 'date' => '2023-12-29', 'supplier' => 'Jotform', 'text' => 'Subscription',
			'net' => 234.01, 'vat_rate' => 7.7, 'vat' => 18.02, 'gross' => 252.03, 'currency' => 'usd',
			'project' => null, 'budget' => null, 'cost' => null, 'paid_by' => 'person', 'person' => 'alice', 'reimburse' => true, 'note' => null,
			'booked' => (object)['gross' => 228.4, 'currency' => 'chf', 'rate' => 0.9062],
		], $over);
	}

	private static function outcome(callable $fn): string {
		try {
			$fn();
			return 'ok';
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
	}

	public function testContent(): void {
		$d = self::receipt();
		ReceiptRules::validate('rc-1', $d);
		$this->assertEquals((object)['gross' => 228.4, 'currency' => 'CHF'], $d->booked, 'the rate is not stored');
		$same = self::receipt(['currency' => 'CHF']);
		ReceiptRules::validate('rc-1', $same);
		$this->assertNull($same->booked, 'in its own currency: none');
		$none = self::receipt(['booked' => null]);
		ReceiptRules::validate('rc-1', $none);
		$this->assertNull($none->booked);
		$this->assertStringStartsWith('422 “booked” is null or the amount paid', self::outcome(fn () => ReceiptRules::validate('rc-1', self::receipt(['booked' => (object)['gross' => '228']]))));
		$this->assertSame('422 “booked” is the amount paid in the team’s currency EUR.', self::outcome(fn () => ReceiptBooking::requireTeamCurrency($d, 'EUR')));
	}

	public function testTeamCurrencyAndApproval(): void {
		$this->assertSame('CHF', ReceiptBooking::teamCurrency(null));
		$this->assertSame('CHF', ReceiptBooking::teamCurrency((object)['issuer' => (object)['accounts' => [(object)['currency' => 'eur']]]]), 'no fallback to the first account');
		$this->assertSame('GBP', ReceiptBooking::teamCurrency((object)['currency' => 'GBP', 'issuer' => (object)['accounts' => [(object)['currency' => 'EUR']]]]));
		$this->assertSame('ok', self::outcome(fn () => ReceiptBooking::validateTeamCurrency(null)));
		$this->assertSame('ok', self::outcome(fn () => ReceiptBooking::validateTeamCurrency('EUR')));
		$this->assertSame('422 The team’s “currency” is null or three capital letters, for example CHF.', self::outcome(fn () => ReceiptBooking::validateTeamCurrency('eur')));
		$this->assertSame('422 The team’s “currency” is null or three capital letters, for example CHF.', self::outcome(fn () => ReceiptBooking::validateTeamCurrency(12)));
		$this->assertSame('422 Enter the amount in CHF that was paid.', self::outcome(fn () => ReceiptBooking::requireApprovable(self::receipt(['booked' => null, 'currency' => 'USD']), 'CHF')));
		$this->assertSame('ok', self::outcome(fn () => ReceiptBooking::requireApprovable(self::receipt(), 'CHF')));
		$this->assertSame('ok', self::outcome(fn () => ReceiptBooking::requireApprovable(self::receipt(['booked' => null, 'currency' => 'CHF']), 'CHF')));
	}

	public function testCounted(): void {
		$d = self::receipt();
		ReceiptRules::validate('rc-1', $d);
		// 228.40 gross at 7.7 %: net 212.07, VAT 16.33.
		$e = ReceiptBooking::forExpenses($d, 'CHF');
		$this->assertSame([['rate' => 7.7, 'net' => 212.07, 'vat' => 16.33]], $e['rates']);
		$this->assertSame(['CHF', 228.4, true], [$e['currency'], $e['gross'], $e['converted']]);
		$s = ExpenseReportRules::sums([$d], 'CHF');
		$this->assertSame(['CHF', 212.07, 228.4], [$s['currency'], $s['net'], $s['gross']]);
		$row = ExpenseExportRules::csvRow('Alice', 'sb-1', (object)['person' => 'alice', 'status' => 'approved'], $d);
		$this->assertSame(['CHF', '212.07', '16.33', '228.40'], [$row[8], $row[9], $row[11], $row[12]]);
		// Two rates: the booked gross split by their share.
		$two = self::receipt(['net' => 100.0, 'vat_rate' => null, 'vat' => 5.9, 'gross' => 105.9, 'currency' => 'EUR',
			'vat_lines' => [(object)['rate' => 2.6, 'net' => 40.0, 'vat' => 1.04], (object)['rate' => 8.1, 'net' => 60.0, 'vat' => 4.86]],
			'booked' => (object)['gross' => 100.0, 'currency' => 'CHF']]);
		ReceiptRules::validate('rc-2', $two);
		$r = ReceiptBooking::forExpenses($two, 'CHF')['rates'];
		$this->assertSame([2.6, 8.1], array_column($r, 'rate'));
		$this->assertEqualsWithDelta(100.0, array_sum(array_column($r, 'net')) + array_sum(array_column($r, 'vat')), 0.001);
		$this->assertSame(['net' => 37.77, 'vat' => 0.98], ['net' => $r[0]['net'], 'vat' => $r[0]['vat']]);
	}

	/** T = team, R = receipt, B = budget: which amount counts for the budget. */
	public function testForBudget(): void {
		$eur = self::receipt(['currency' => 'EUR', 'net' => 100.0, 'vat_rate' => 19, 'vat' => 19.0, 'gross' => 119.0,
			'booked' => (object)['gross' => 112.0, 'currency' => 'CHF'], 'project' => 'p1', 'budget' => 'b1', 'cost' => 1]);
		ReceiptRules::validate('rc-3', $eur);
		// R = B: the original amount for the budget, the paid one for expenses.
		$b = ReceiptBooking::forBudget($eur, 'CHF', 'EUR');
		$this->assertSame(['EUR', 119.0, false, 100.0], [$b['currency'], $b['gross'], $b['converted'], $b['rates'][0]['net']]);
		$this->assertSame(['CHF', 112.0], [ReceiptBooking::forExpenses($eur, 'CHF')['currency'], ReceiptBooking::forExpenses($eur, 'CHF')['gross']]);
		// T = B: the booked amount as before.
		$b = ReceiptBooking::forBudget($eur, 'CHF', 'CHF');
		$this->assertSame(['CHF', 112.0, true, 94.12, 17.88], [$b['currency'], $b['gross'], $b['converted'], $b['rates'][0]['net'], $b['rates'][0]['vat']]);
		// R ≠ B and T ≠ B: billed, split like booked.
		$usd = self::receipt(['currency' => 'USD', 'project' => 'p1', 'budget' => 'b1', 'cost' => 1, 'billed' => (object)['gross' => 210.0, 'currency' => 'eur', 'rate' => 0.83]]);
		ReceiptRules::validate('rc-4', $usd);
		$this->assertEquals((object)['gross' => 210.0, 'currency' => 'EUR'], $usd->billed, 'the rate is not stored');
		$this->assertTrue(ReceiptBooking::needsBilled($usd, 'CHF', 'EUR'));
		$b = ReceiptBooking::forBudget($usd, 'CHF', 'EUR');
		$this->assertSame(['EUR', 210.0, 194.99, 15.01], [$b['currency'], $b['gross'], $b['rates'][0]['net'], $b['rates'][0]['vat']]);
		// Also R = T ≠ B needs it.
		$chf = self::receipt(['currency' => 'CHF', 'booked' => null, 'project' => 'p1', 'budget' => 'b1', 'cost' => 1]);
		$this->assertTrue(ReceiptBooking::needsBilled($chf, 'CHF', 'EUR'));
		$this->assertFalse(ReceiptBooking::needsBilled($chf, 'CHF', 'CHF'));
		$this->assertFalse(ReceiptBooking::needsBilled(self::receipt(['currency' => 'CHF']), 'CHF', null), 'no budget');
	}

	public function testBilledRequired(): void {
		$usd = self::receipt(['currency' => 'USD', 'project' => 'p1', 'budget' => 'b1', 'cost' => 1, 'billed' => null]);
		ReceiptRules::validate('rc-5', $usd);
		$this->assertSame('ok', self::outcome(fn () => ReceiptBooking::requireBudgetCurrency($usd, 'CHF', 'EUR', false)), 'open: not yet');
		$this->assertSame('422 Enter the amount in EUR for the budget.', self::outcome(fn () => ReceiptBooking::requireApprovable($usd, 'CHF', 'EUR')));
		$this->assertSame('422 Enter the amount in EUR for the budget.', self::outcome(fn () => ReceiptBooking::requireBudgetCurrency($usd, 'CHF', 'EUR', true)), 'a correction after approval');
		$this->assertSame('ok', self::outcome(fn () => ReceiptBooking::requireApprovable($usd, 'CHF', 'USD')), 'R = B');
		$this->assertSame('ok', self::outcome(fn () => ReceiptBooking::requireApprovable($usd, 'CHF', 'CHF')), 'T = B');
		$usd->billed = (object)['gross' => 210.0, 'currency' => 'GBP'];
		$this->assertSame('422 “billed” is the amount for the budget in its currency EUR.', self::outcome(fn () => ReceiptBooking::requireBudgetCurrency($usd, 'CHF', 'EUR', false)));
		// Not needed: stored as null.
		ReceiptBooking::requireBudgetCurrency($usd, 'CHF', 'CHF', false);
		$this->assertNull($usd->billed);
		$this->assertStringStartsWith('422 “billed” is null or the amount for the budget', self::outcome(fn () => ReceiptRules::validate('rc-5', self::receipt(['billed' => (object)['gross' => 1, 'currency' => 'EURO']]))));
		// The budget's currency, else the team's.
		$project = (object)['budgets' => [(object)['id' => 'b1', 'currency' => 'eur'], (object)['id' => 'b2']]];
		$this->assertSame('EUR', ReceiptBooking::budgetCurrencyOf($project, (object)['budget' => 'b1'], 'CHF'));
		$this->assertSame('CHF', ReceiptBooking::budgetCurrencyOf($project, (object)['budget' => 'b2'], 'CHF'));
		$this->assertNull(ReceiptBooking::budgetCurrencyOf($project, (object)['budget' => null], 'CHF'));
	}
}
