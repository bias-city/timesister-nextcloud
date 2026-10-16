<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\IssueRules;
use OCA\TimeSister\Service\TaxRules;
use OCA\TimeSister\Service\VatLines;
use PHPUnit\Framework\TestCase;

/**
 * The sums of an invoice (0.21.0): net and VAT per rate, pass-through
 * items, total; the invoice's single rate; the frozen lines; abroad;
 * rounding in cents; reversal; invoices from before 0.21.0; the tax
 * groups of the e-invoice.
 */
class VatLinesTest extends TestCase {
	private static function p(int|float $amount, ?float $vat = null, string $mode = 'taxable', string $kind = 'flat'): \stdClass {
		$p = (object)['kind' => $kind, 'text' => 'x', 'amount' => $amount];
		if ($vat !== null) {
			$p->vat = $vat;
		}
		if ($mode !== 'taxable') {
			$p->vat_mode = $mode;
		}
		return $p;
	}

	public function testSeveralRatesAndPassThrough(): void {
		$s = VatLines::of([
			self::p(1000, 8.1), self::p(200, 2.6), self::p(100, null, 'pass_through'), self::p(0, 3.8, 'taxable', 'text'), self::p(50),
		], 8.1, TaxRules::DOMESTIC);
		$this->assertSame([['rate' => 2.6, 'net' => 200.0, 'vat' => 5.2], ['rate' => 8.1, 'net' => 1050.0, 'vat' => 85.05]], $s->lines, 'sorted by rate; a line without rate takes the invoice’s; a text line opens no rate');
		$this->assertSame(['net' => 1250.0, 'vat_amount' => 90.25, 'gross' => 1340.25], $s->taxable());
		$this->assertSame([100.0, 1440.25], [$s->passThrough, $s->total]);
		$this->assertSame(8.1, $s->rate, 'the rate of the largest net');
		$this->assertSame(['vat_lines' => $s->lines, 'pass_through' => 100.0, 'total' => 1440.25], $s->frozen());
		$this->assertSame([2.6, 8.1], $s->rates());
		$tie = VatLines::of([self::p(100, 2.6), self::p(100, 8.1)], 0.0, TaxRules::DOMESTIC);
		$this->assertSame(8.1, $tie->rate, 'a tie: the higher rate');
		$only = VatLines::of([self::p(80, null, 'pass_through')], 8.1, TaxRules::DOMESTIC);
		$this->assertSame([[], 0.0, 80.0, 80.0, 8.1], [$only->lines, $only->gross, $only->passThrough, $only->total, $only->rate], 'only pass-through items: no rate line, the invoice’s rate stays');
		$zero = VatLines::of([self::p(100, 0.0), self::p(100, 8.1)], 8.1, TaxRules::DOMESTIC);
		$this->assertSame([0.0, 8.1], $zero->rates(), 'a rate of 0 at home is a line of its own');
	}

	public function testAbroadAllRatesZero(): void {
		foreach ([TaxRules::EU_REVERSE, TaxRules::EXPORT] as $case) {
			$s = VatLines::of([self::p(1000, 8.1), self::p(200, 2.6), self::p(30, 8.1, 'pass_through')], 8.1, $case);
			$this->assertSame([['rate' => 0.0, 'net' => 1200.0, 'vat' => 0.0]], $s->lines, $case);
			$this->assertSame([1200.0, 30.0, 1230.0, 0.0], [$s->gross, $s->passThrough, $s->total, $s->rate], $case);
		}
	}

	public function testRoundingInCents(): void {
		$this->assertSame(0.33, VatLines::of([self::p(12.5)], 2.6, TaxRules::DOMESTIC)->vatAmount, '0.325 rounds up');
		$this->assertSame(-0.33, VatLines::of([self::p(-12.5)], 2.6, TaxRules::DOMESTIC)->vatAmount, 'and away from zero below');
		$this->assertSame(7.74, VatLines::of([self::p(100.5)], 7.7, TaxRules::DOMESTIC)->vatAmount);
		$this->assertSame(147.83, VatLines::of([self::p(1825)], 8.1, TaxRules::DOMESTIC)->vatAmount, '147.825');
		// The rate per net is rounded once per rate, not per line: 3 × 0.10 at 8.1 = 0.30 → 0.02 (not 3 × 0.01).
		$s = VatLines::of([self::p(0.1), self::p(0.1), self::p(0.1)], 8.1, TaxRules::DOMESTIC);
		$this->assertSame([0.3, 0.02], [$s->net, $s->vatAmount]);
		$this->assertSame(36.03, VatLines::of([self::p(11.11), self::p(22.22)], 8.1, TaxRules::DOMESTIC)->gross);
		$this->assertSame(1, VatLines::share(5, 2000), '5 cents × 20 % = 1 cent');
		$this->assertSame(-1, VatLines::share(-5, 2000));
		$this->assertSame(33, VatLines::share(1250, 260));
		$this->assertSame(0.0, VatLines::of([self::p(0.04)], 8.1, TaxRules::DOMESTIC)->vatAmount);
	}

	public function testFreezeAndReversal(): void {
		$frozen = VatLines::freeze([self::p(1000), self::p(200, 2.6), self::p(100, 8.1, 'pass_through')], 8.1, TaxRules::DOMESTIC);
		$this->assertSame([[8.1, 'taxable'], [2.6, 'taxable'], [null, 'pass_through']], array_map(static fn (\stdClass $p): array => [$p->vat, $p->vat_mode], $frozen), 'each line carries its rate and mode');
		$abroad = VatLines::freeze([self::p(1000, 8.1)], 0.0, TaxRules::EXPORT);
		$this->assertSame(0.0, $abroad[0]->vat, 'abroad the frozen rate is 0');
		$orig = VatLines::of($frozen, 8.1, TaxRules::DOMESTIC);
		// A reversal negates the frozen lines; the invoice's rate no longer matters for them.
		$neg = VatLines::freeze(IssueRules::negate($frozen), 0.0, TaxRules::DOMESTIC);
		$this->assertSame('pass_through', $neg[2]->vat_mode);
		$rev = VatLines::of($neg, 0.0, TaxRules::DOMESTIC);
		$this->assertSame([-$orig->net, -$orig->vatAmount, -$orig->gross, -$orig->passThrough, -$orig->total], [$rev->net, $rev->vatAmount, $rev->gross, $rev->passThrough, $rev->total]);
		$this->assertSame([['rate' => 2.6, 'net' => -200.0, 'vat' => -5.2], ['rate' => 8.1, 'net' => -1000.0, 'vat' => -81.0]], $rev->lines);
		$this->assertSame(8.1, $rev->rate, 'by amount');
	}

	public function testRestore(): void {
		$legacy = VatLines::restore(null, 0.0, null, 8.1, 1825.0, 147.83, 1972.83);
		$this->assertSame([[['rate' => 8.1, 'net' => 1825.0, 'vat' => 147.83]], 0.0, 1972.83], [$legacy->lines, $legacy->passThrough, $legacy->total], 'before 0.21.0: one line, total = gross');
		$this->assertSame([], VatLines::restore(null, 0.0, null, 8.1, 0.0, 0.0, 0.0)->lines);
		$s = VatLines::restore('[{"rate":2.6,"net":200,"vat":5.2},{"rate":8.1,"net":1000.0,"vat":81.0}]', '100.00', '1386.20', 8.1, 1200.0, 86.2, 1286.2);
		$this->assertSame([['rate' => 2.6, 'net' => 200.0, 'vat' => 5.2], ['rate' => 8.1, 'net' => 1000.0, 'vat' => 81.0]], $s->lines);
		$this->assertSame([100.0, 1386.2], [$s->passThrough, $s->total]);
		$f = VatLines::fromFixed(['vat' => 8.1, 'net' => 1200.0, 'vat_amount' => 86.2, 'gross' => 1286.2, 'vat_lines' => [['rate' => 8.1, 'net' => 1200.0, 'vat' => 86.2]], 'pass_through' => 0.0, 'total' => 1286.2]);
		$this->assertSame(1286.2, $f->total);
	}

	public function testBreakdownOfTheEInvoice(): void {
		$s = VatLines::of([self::p(1000, 8.1), self::p(200, 2.6), self::p(100, null, 'pass_through')], 8.1, TaxRules::DOMESTIC);
		$g = TaxRules::breakdown($s, TaxRules::DOMESTIC, 'CH', true);
		$this->assertSame([['S', 2.6, 200.0, 5.2, null], ['S', 8.1, 1000.0, 81.0, null], ['E', 0.0, 100.0, 0.0, 'VATEX-EU-79-C']],
			array_map(static fn (array $x): array => [$x['code'], $x['rate'], $x['basis'], $x['tax'], $x['reason_code']], $g));
		$this->assertSame(TaxRules::PASS_THROUGH_REASON, $g[2]['reason']);
		$this->assertSame([TaxRules::CODE_EXEMPT, 0.0], TaxRules::lineCategory(self::p(100, 8.1, 'pass_through'), 8.1, TaxRules::DOMESTIC, 'CH'));
		$this->assertSame([TaxRules::CODE_STANDARD, 2.6], TaxRules::lineCategory(self::p(100, 2.6), 8.1, TaxRules::DOMESTIC, 'CH'));
		$this->assertSame([TaxRules::CODE_ZERO, 0.0], TaxRules::lineCategory(self::p(100, 0.0), 8.1, TaxRules::DOMESTIC, 'CH'));
		// Export to an EU customer is E as well: one E group with both reasons (BR-E-01).
		$e = VatLines::of([self::p(1000), self::p(100, null, 'pass_through')], 0.0, TaxRules::EXPORT);
		$g = TaxRules::breakdown($e, TaxRules::EXPORT, 'DE', true);
		$this->assertCount(1, $g);
		$this->assertSame(['E', 1100.0, null], [$g[0]['code'], $g[0]['basis'], $g[0]['reason_code']]);
		$this->assertStringContainsString('Durchlaufender Posten', (string)$g[0]['reason']);
		$g = TaxRules::breakdown(VatLines::of([self::p(1000), self::p(100, null, 'pass_through')], 0.0, TaxRules::EU_REVERSE), TaxRules::EU_REVERSE, 'FR', true);
		$this->assertSame(['AE', 'E'], array_column($g, 'code'), 'reverse charge and pass-through items side by side');
	}
}
