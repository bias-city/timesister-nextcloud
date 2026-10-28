<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ExpenseTable;
use PHPUnit\Framework\TestCase;

/**
 * The expense table (0.30.0, PLAN-SPESEN 9 D/E): the same numbers as the
 * Mac app's `core/src/spesen/tabelle.rs` – its scenarios and its rounding.
 */
class ExpenseTableTest extends TestCase {
	private static function spese(string $date, float $gross, array $over = []): \stdClass {
		return (object)array_merge(['kind' => 'receipt', 'date' => $date, 'supplier' => 'Bahn Muster', 'text' => 'Billett', 'net' => $gross, 'vat_rate' => null, 'vat' => 0,
			'gross' => $gross, 'currency' => 'CHF', 'project' => null, 'invoice_effect' => 'none', 'paid_by' => 'person', 'person' => 'ann', 'reimburse' => true], $over);
	}

	/** Rust's `(x * 100.0).round() / 100.0`: the double itself, halves away from zero – not PHP's round(). */
	public function testRounding(): void {
		$this->assertSame(1.0, ExpenseTable::round2(1.005), '1.005 × 100 is 100.49999999999999; PHP’s round() says 1.01');
		$this->assertSame(0.28, ExpenseTable::round2(0.285), '0.285 × 100 is 28.499999999999996; PHP’s round() says 0.29');
		$this->assertSame(1.25, ExpenseTable::round2(1.255));
		$this->assertSame(2.68, ExpenseTable::round2(2.675), '2.675 × 100 is 267.5 exactly');
		$this->assertSame(1.01, ExpenseTable::round2(1.0050000000000001));
		$this->assertSame(-2.5, ExpenseTable::round2(-2.495), 'away from zero');
		$this->assertSame('0.0', json_encode(ExpenseTable::round2(-0.004), JSON_PRESERVE_ZERO_FRACTION), 'no negative zero');
		$this->assertSame([69.25, 69.3, 69.3], [ExpenseTable::to005(69.27), ExpenseTable::to005(69.28), ExpenseTable::to005(69.275)]);
		$this->assertSame(167.95, ExpenseTable::to005(167.93));
		$this->assertSame(0.906241, ExpenseTable::round6(228.4 / 252.03));
	}

	/** The scenario of the Mac's `spesentabelle_wie_die_vorlage` and `abrechnung_als_zip_wie_die_vorlage`. */
	public function testLinesAndSums(): void {
		$a = self::spese('2026-10-02', 108.1, ['net' => 100.0, 'vat_rate' => 8.1, 'vat' => 8.1, 'category' => 'mobilitaet']);
		$b = self::spese('2026-10-05', 100.0, ['currency' => 'EUR', 'factor' => 0.5, 'category' => 'diverses', 'vat_rate' => 19, 'vat' => 15.97,
			'rate' => (object)['value' => 0.95, 'date' => '2026-10-05', 'source' => 'ecb']]);
		$c = self::spese('2026-10-06', 40.0, ['currency' => 'EUR', 'category' => 'verpflegung']);
		$d = self::spese('2026-10-07', 12.35);
		$t = self::spese('2026-10-07', 12.33, ['kind' => 'trust', 'category' => 'verpflegung']);

		$la = ExpenseTable::line('rc-a', $a, 'CHF');
		$this->assertSame([108.1, ['value' => 1.0, 'source' => 'team', 'date' => null], [['rate' => 8.1, 'vat' => 8.1]], 1.0, 'CHF'],
			[$la['amount'], $la['rate'], $la['vat_amounts'], $la['factor'], $la['currency']]);
		$lb = ExpenseTable::line('rc-b', $b, 'CHF');
		$this->assertSame([47.5, ['value' => 0.95, 'source' => 'ecb', 'date' => '2026-10-05'], [], 0.5, 'EUR', 100.0],
			[$lb['amount'], $lb['rate'], $lb['vat_amounts'], $lb['factor'], $lb['currency'], $lb['gross']], '100 EUR × 0.95 × 0.5, foreign VAT is no input tax');
		$lc = ExpenseTable::line('rc-c', $c, 'CHF');
		$this->assertSame([null, null], [$lc['amount'], $lc['rate']], 'no rate, no amount in the team’s currency');
		$this->assertSame(['rate', 'category', null], [ExpenseTable::lacks($c, 'CHF'), ExpenseTable::lacks($d, 'CHF'), ExpenseTable::lacks($a, 'CHF')]);
		$lt = ExpenseTable::line('rc-t', $t, 'CHF');
		$this->assertSame([12.33, true, false], [$lt['amount'], $lt['without_file'], $la['without_file']]);

		$s = ExpenseTable::sums([$la, $lb, $lt], 'CHF');
		$this->assertSame(['currency' => 'CHF', 'categories' => [['id' => 'mobilitaet', 'amount' => 108.1], ['id' => 'diverses', 'amount' => 47.5], ['id' => 'verpflegung', 'amount' => 12.33]],
			'vat' => [['rate' => 8.1, 'vat' => 8.1]], 'total' => 167.93, 'payout' => 167.95], $s);
	}

	public function testOtherCurrencyAndFactor(): void {
		// Booked by card: the amount paid, the rate on six places.
		$usd = self::spese('2026-10-03', 252.03, ['currency' => 'USD', 'category' => 'it', 'booked' => (object)['gross' => 228.4, 'currency' => 'CHF']]);
		$l = ExpenseTable::line('rc-u', $usd, 'CHF');
		$this->assertSame([228.4, ['value' => 0.906241, 'source' => 'booked', 'date' => null]], [$l['amount'], $l['rate']]);
		// Booked wins over a rate; booked in another currency or of zero does not count.
		$this->assertSame('booked', ExpenseTable::inTeam(self::spese('2026-10-03', 10.0, ['currency' => 'USD', 'booked' => (object)['gross' => 9.0, 'currency' => 'CHF'],
			'rate' => (object)['value' => 2.0, 'date' => '2026-10-03', 'source' => 'manual']]), 'CHF')['source'] ?? null);
		$this->assertSame(20.0, ExpenseTable::inTeam(self::spese('2026-10-03', 10.0, ['currency' => 'USD', 'booked' => (object)['gross' => 0.0, 'currency' => 'CHF'],
			'rate' => (object)['value' => 2.0, 'date' => '2026-10-03', 'source' => 'manual']]), 'CHF')['amount'] ?? null);
		$zero = self::spese('2026-10-03', 0.0, ['currency' => 'USD', 'category' => 'x', 'booked' => (object)['gross' => 5.0, 'currency' => 'CHF']]);
		$this->assertSame([5.0, null], [ExpenseTable::line('rc-z', $zero, 'CHF')['amount'], ExpenseTable::line('rc-z', $zero, 'CHF')['rate']], 'no rate over a gross of zero, as the Mac app');
		// The team's currency in small letters is still the team's; no currency is the team's.
		$this->assertSame('team', ExpenseTable::inTeam(self::spese('2026-10-03', 10.0, ['currency' => 'chf']), 'CHF')['source'] ?? null);
		$this->assertSame('CHF', ExpenseTable::line('rc-n', self::spese('2026-10-03', 10.0, ['currency' => null, 'category' => 'x']), 'CHF')['currency']);
		// A rate: gross × value on cents, then the factor on cents.
		$eur = self::spese('2026-10-03', 33.33, ['currency' => 'EUR', 'category' => 'x', 'factor' => 0.5, 'rate' => (object)['value' => 0.9412, 'date' => '2026-10-02', 'source' => 'ecb']]);
		$this->assertSame(15.69, ExpenseTable::line('rc-e', $eur, 'CHF')['amount'], '33.33 × 0.9412 = 31.37 on cents, × 0.5 = 15.685 → 15.69');
	}

	public function testVatPerRate(): void {
		// Two rates of one receipt, half of it: VAT per line unrounded, rounded in the sums; rates merged and highest first.
		$two = self::spese('2026-10-03', 105.35, ['net' => 100.0, 'vat' => 5.35, 'category' => 'food', 'factor' => 0.5,
			'vat_lines' => [(object)['rate' => 2.6, 'net' => 50.0, 'vat' => 1.3], (object)['rate' => 8.1, 'net' => 50.0, 'vat' => 4.05]]]);
		$one = self::spese('2026-10-04', 10.81, ['net' => 10.0, 'vat_rate' => 8.1, 'vat' => 0.81, 'category' => 'food']);
		$zero = self::spese('2026-10-05', 20.0, ['vat_rate' => 0, 'category' => 'office']);
		$lines = [ExpenseTable::line('rc-1', $two, 'CHF'), ExpenseTable::line('rc-2', $one, 'CHF'), ExpenseTable::line('rc-3', $zero, 'CHF')];
		$this->assertSame([['rate' => 2.6, 'vat' => 0.65], ['rate' => 8.1, 'vat' => 2.025]], $lines[0]['vat_amounts']);
		$this->assertSame([52.68, [], [['rate' => 0.0, 'net' => 20.0, 'vat' => 0.0]]], [$lines[0]['amount'], $lines[2]['vat_amounts'], $lines[2]['vat_lines']], '105.35 × 0.5; a rate without VAT is no VAT line');
		$s = ExpenseTable::sums($lines, 'CHF');
		$this->assertSame([['rate' => 8.1, 'vat' => 2.84], ['rate' => 2.6, 'vat' => 0.65]], $s['vat'], '2.025 + 0.81 = 2.835 → 2.84');
		$this->assertSame([['id' => 'food', 'amount' => 63.49], ['id' => 'office', 'amount' => 20.0]], $s['categories']);
		$this->assertSame([83.49, 83.5], [$s['total'], $s['payout']]);
	}

	public function testChargedAndTexts(): void {
		$p = self::spese('2026-10-03', 10.0, ['project' => 'd1', 'invoice_effect' => 'charge', 'category' => 'x', 'supplier' => null, 'text' => null]);
		$l = ExpenseTable::line('rc-p', $p, 'CHF');
		$this->assertSame([true, 'd1', '', ''], [$l['charged'], $l['project'], $l['supplier'], $l['text']]);
		$this->assertFalse(ExpenseTable::line('rc-s', self::spese('2026-10-03', 10.0, ['project' => 'd1', 'invoice_effect' => 'show_only']), 'CHF')['charged']);
		$this->assertTrue(ExpenseTable::line('rc-d', self::spese('2026-10-03', 10.0, ['project' => 'd1', 'invoice_effect' => null]), 'CHF')['charged'], 'the default of a person’s receipt with a project');
		$this->assertFalse(ExpenseTable::line('rc-g', self::spese('2026-10-03', 10.0), 'CHF')['charged'], 'without project never');
	}
}
