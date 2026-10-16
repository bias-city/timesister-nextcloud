<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The sums of an invoice (0.21.0), pure and in one place: taxable lines
 * grouped by their rate (net and VAT per rate), the pass-through items
 * outside the taxable amount, and the total to pay. Issuing, the preview,
 * reversal and credit note, `fixed`, the PDF, the e-invoice and the
 * exports all read their sums from here.
 *
 * Amounts are counted in cents: VAT per rate = net × rate rounded half
 * away from zero, the rate taken with two decimals (as DECIMAL(5,2)).
 */
final class VatLines {
	public const TAXABLE = 'taxable';
	public const PASS_THROUGH = 'pass_through';
	public const MODES = [self::TAXABLE, self::PASS_THROUGH];

	/**
	 * @param list<array{rate:float,net:float,vat:float}> $lines sorted by rate
	 */
	private function __construct(
		public readonly array $lines,
		public readonly float $net,
		public readonly float $vatAmount,
		public readonly float $gross,
		public readonly float $passThrough,
		public readonly float $total,
		/** The invoice's single rate for old readers: the rate of the largest net (by amount). */
		public readonly float $rate,
	) {
	}

	// ------------------------------------------------------------ a line

	/** `vat_mode` of a position: pass_through, else taxable. @param \stdClass|array<string,mixed> $p */
	public static function modeOf(\stdClass|array $p): string {
		$m = is_array($p) ? ($p['vat_mode'] ?? null) : ($p->vat_mode ?? null);
		return $m === self::PASS_THROUGH ? self::PASS_THROUGH : self::TAXABLE;
	}

	/** @param \stdClass|array<string,mixed> $p */
	public static function isPassThrough(\stdClass|array $p): bool {
		return self::modeOf($p) === self::PASS_THROUGH;
	}

	/**
	 * The effective rate of a taxable line: 0 abroad (reverse charge,
	 * export), else its own `vat`, else the invoice's.
	 *
	 * @param \stdClass|array<string,mixed> $p
	 */
	public static function rateOf(\stdClass|array $p, float $default, string $taxCase): float {
		if ($taxCase !== TaxRules::DOMESTIC) {
			return 0.0;
		}
		$v = is_array($p) ? ($p['vat'] ?? null) : ($p->vat ?? null);
		return (is_int($v) || is_float($v)) && $v >= 0 && $v <= 100 ? self::twoDecimals((float)$v) : self::twoDecimals($default);
	}

	/**
	 * The positions as they are frozen: each carries `vat_mode`, a taxable
	 * line its effective `vat` (0 abroad), a pass-through line `vat` null.
	 * A reversal or credit note of them keeps every line's rate and mode.
	 *
	 * @param list<\stdClass> $positions
	 * @return list<\stdClass>
	 */
	public static function freeze(array $positions, float $default, string $taxCase): array {
		$out = [];
		foreach ($positions as $p) {
			$c = clone $p;
			$c->vat_mode = self::modeOf($p);
			$c->vat = $c->vat_mode === self::PASS_THROUGH ? null : self::rateOf($p, $default, $taxCase);
			$out[] = $c;
		}
		return $out;
	}

	// ------------------------------------------------------------- sums

	/**
	 * The sums of the positions. Text lines and lines of amount 0 open no
	 * rate of their own; the client's amounts are taken as sent.
	 *
	 * @param list<\stdClass|array<string,mixed>> $positions
	 */
	public static function of(array $positions, float $default, string $taxCase): self {
		/** @var array<int,float> $byRate rate in hundredths → raw sum */
		$byRate = [];
		$pass = 0.0;
		foreach ($positions as $p) {
			$a = is_array($p) ? ($p['amount'] ?? 0) : ($p->amount ?? 0);
			$amount = is_int($a) || (is_float($a) && is_finite($a)) ? (float)$a : 0.0;
			$kind = is_array($p) ? ($p['kind'] ?? null) : ($p->kind ?? null);
			if ($kind === 'text' || $amount === 0.0) {
				continue;
			}
			if (self::isPassThrough($p)) {
				$pass += $amount;
				continue;
			}
			$r = (int)round(self::rateOf($p, $default, $taxCase) * 100.0);
			$byRate[$r] = ($byRate[$r] ?? 0.0) + $amount;
		}
		ksort($byRate);
		$lines = [];
		$netCents = 0;
		$vatCents = 0;
		foreach ($byRate as $r => $sum) {
			$n = self::cents($sum);
			$v = self::share($n, $r);
			$netCents += $n;
			$vatCents += $v;
			$lines[] = ['rate' => self::unit($r), 'net' => self::unit($n), 'vat' => self::unit($v)];
		}
		$passCents = self::cents($pass);
		return new self($lines, self::unit($netCents), self::unit($vatCents), self::unit($netCents + $vatCents), self::unit($passCents),
			self::unit($netCents + $vatCents + $passCents), self::mainRate($lines, $taxCase === TaxRules::DOMESTIC ? self::twoDecimals($default) : 0.0));
	}

	/**
	 * The frozen sums of an invoice: its `vat_lines`, `pass_through` and
	 * `total`; an invoice from before 0.21.0 has none and gets one line of
	 * its single rate, no pass-through items and gross as the total.
	 */
	public static function restore(mixed $lines, mixed $passThrough, mixed $total, float $rate, float $net, float $vatAmount, float $gross): self {
		if (is_string($lines)) {
			$lines = json_decode($lines, true);
		}
		if (!is_array($lines)) {
			$legacy = $net === 0.0 && $vatAmount === 0.0 ? [] : [['rate' => $rate, 'net' => $net, 'vat' => $vatAmount]];
			return new self($legacy, $net, $vatAmount, $gross, 0.0, $gross, $rate);
		}
		$out = [];
		foreach ($lines as $l) {
			if ($l instanceof \stdClass) {
				$l = get_object_vars($l);
			}
			if (is_array($l)) {
				$out[] = ['rate' => self::num($l['rate'] ?? 0), 'net' => self::num($l['net'] ?? 0), 'vat' => self::num($l['vat'] ?? 0)];
			}
		}
		return new self($out, $net, $vatAmount, $gross, self::num($passThrough), self::num($total ?? $gross), $rate);
	}

	/** The frozen sums of a `fixed`-like array (also a preview's). @param array<string,mixed> $f */
	public static function fromFixed(array $f): self {
		return self::restore($f['vat_lines'] ?? null, $f['pass_through'] ?? 0, $f['total'] ?? null, self::num($f['vat'] ?? 0),
			self::num($f['net'] ?? 0), self::num($f['vat_amount'] ?? 0), self::num($f['gross'] ?? 0));
	}

	/**
	 * What `fixed`, the `issue` answer and the ledger carry besides net,
	 * VAT and gross.
	 *
	 * @return array{vat_lines:list<array{rate:float,net:float,vat:float}>,pass_through:float,total:float}
	 */
	public function frozen(): array {
		return ['vat_lines' => $this->lines, 'pass_through' => $this->passThrough, 'total' => $this->total];
	}

	/** The three sums of 0.18.0 (taxable part only). @return array{net:float,vat_amount:float,gross:float} */
	public function taxable(): array {
		return ['net' => $this->net, 'vat_amount' => $this->vatAmount, 'gross' => $this->gross];
	}

	/** The rates in use. @return list<float> */
	public function rates(): array {
		return array_map(static fn (array $l): float => $l['rate'], $this->lines);
	}

	/** The rate of the largest net; on a tie the higher rate; without lines the default. @param list<array{rate:float,net:float,vat:float}> $lines */
	private static function mainRate(array $lines, float $default): float {
		$best = null;
		foreach ($lines as $l) {
			if ($best === null || abs($l['net']) >= abs($best['net'])) {
				$best = $l;
			}
		}
		return $best === null ? $default : $best['rate'];
	}

	// ----------------------------------------------------------- cents

	public static function cents(float $v): int {
		return (int)round($v * 100.0);
	}

	/** Cents (or hundredths of a percent) back to a float. */
	private static function unit(int $hundredths): float {
		return (float)$hundredths / 100.0;
	}

	/** VAT in cents of a net in cents at a rate in hundredths of a percent: half away from zero, exact. */
	public static function share(int $netCents, int $rateHundredths): int {
		$x = $netCents * $rateHundredths;
		$q = intdiv(abs($x) + 5000, 10000);
		return $x < 0 ? -$q : $q;
	}

	private static function twoDecimals(float $v): float {
		return round($v, 2);
	}

	private static function num(mixed $v): float {
		return is_int($v) || is_float($v) ? (float)$v : (is_numeric($v) ? (float)$v : 0.0);
	}
}
