<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The expense table (0.30.0, PLAN-SPESEN 9 D/E), pure: a receipt's frozen
 * line in the team's currency and the sums of an expense report. Computed
 * exactly as the Mac app does (`core/src/spesen/tabelle.rs`:
 * `in_teamwaehrung`, `zeile`, `summen`, `auf_005`), with its rounding –
 * half away from zero on the double itself, never PHP's round(), whose
 * pre-rounding can differ by a cent.
 */
final class ExpenseTable {
	/** Sources of a line's rate. */
	public const TEAM = 'team';
	public const BOOKED = 'booked';
	public const ECB = 'ecb';
	public const MANUAL = 'manual';

	// ------------------------------------------------------------ rounding

	/** On cents, as `zahl::rund2`. */
	public static function round2(float $x): float {
		return self::half($x * 100.0) / 100.0 + 0.0;
	}

	/** On 0.05, the payout (`auf_005`). */
	public static function to005(float $x): float {
		return self::half($x * 20.0) / 20.0 + 0.0;
	}

	/** On six places, a rate. */
	public static function round6(float $x): float {
		return self::half($x * 1000000.0) / 1000000.0 + 0.0;
	}

	/** The nearest integer, halves away from zero – Rust's `f64::round`. */
	private static function half(float $y): float {
		$a = abs($y);
		$f = floor($a);
		// Exact: a double and its floor are close enough for the difference to have no error.
		$r = $a - $f >= 0.5 ? $f + 1.0 : $f;
		return $y < 0.0 ? -$r : $r;
	}

	// ---------------------------------------------------------------- line

	/** The gross amount (`Beleg::betrag`): with `vat_lines` their sum on cents, else `gross`. */
	public static function gross(\stdClass $r): float {
		if (!ReceiptRecognition::hasLines($r)) {
			return ReceiptRules::isNumber($r->gross ?? null) ? (float)$r->gross : 0.0;
		}
		$sum = 0.0;
		foreach (ReceiptRecognition::rates($r) as $l) {
			$sum += $l['net'] + $l['vat'];
		}
		return self::round2($sum);
	}

	/**
	 * The amount in the team's currency before the factor, with the rate and
	 * its source and day: the team's currency 1:1, else `booked` in it, else
	 * the receipt's `rate`; null without any of them. A booked amount over a
	 * gross of zero has no rate (null).
	 *
	 * @return ?array{amount:float,rate:?float,source:string,date:?string}
	 */
	public static function inTeam(\stdClass $r, string $team): ?array {
		$gross = self::gross($r);
		if (strcasecmp(self::currency($r, $team), $team) === 0) {
			return ['amount' => $gross, 'rate' => 1.0, 'source' => self::TEAM, 'date' => null];
		}
		$b = $r->booked ?? null;
		if ($b instanceof \stdClass && is_string($b->currency ?? null) && strcasecmp($b->currency, $team) === 0
			&& ReceiptRules::isNumber($b->gross ?? null) && (float)$b->gross > 0.0) {
			$rate = abs($gross) > 0.004 ? self::half((float)$b->gross / $gross * 1000000.0) / 1000000.0 : null;
			return ['amount' => (float)$b->gross, 'rate' => $rate, 'source' => self::BOOKED, 'date' => null];
		}
		$k = $r->rate ?? null;
		if ($k instanceof \stdClass && ReceiptRules::isNumber($k->value ?? null) && (float)$k->value > 0.0) {
			$src = $k->source ?? null;
			$source = is_string($src) && trim($src) !== '' ? $src : self::MANUAL;
			$day = is_string($k->date ?? null) && $k->date !== '' ? $k->date : null;
			return ['amount' => self::round2($gross * (float)$k->value), 'rate' => (float)$k->value, 'source' => $source, 'date' => $day];
		}
		return null;
	}

	/** The receipt's currency; none is the team's. */
	public static function currency(\stdClass $r, string $team): string {
		$c = $r->currency ?? null;
		return is_string($c) && trim($c) !== '' ? $c : $team;
	}

	/** The factor of the line: the receipt's, else 1. */
	public static function factor(\stdClass $r): float {
		$f = $r->factor ?? null;
		return ReceiptRules::isNumber($f) && (float)$f > 0.0 ? (float)$f : 1.0;
	}

	/** What a receipt lacks to be settled (9 C): `category`, `rate` or nothing. */
	public static function lacks(\stdClass $r, string $team): ?string {
		$c = $r->category ?? null;
		if (!is_string($c) || trim($c) === '') {
			return 'category';
		}
		return self::inTeam($r, $team) === null ? 'rate' : null;
	}

	/**
	 * The frozen line of a receipt (9 D). `amount` and `rate` are null only
	 * when the receipt has no amount in the team's currency – never in a
	 * fixed report –, `rate` also for a booked amount over a gross of zero.
	 *
	 * @return array{receipt:string,date:string,supplier:string,text:string,project:?string,charged:bool,gross:float,currency:string,
	 *   vat_lines:list<array{rate:float,net:float,vat:float}>,category:?string,factor:float,rate:?array{value:float,source:string,date:?string},
	 *   amount:?float,vat_amounts:list<array{rate:float,vat:float}>,without_file:bool}
	 */
	public static function line(string $key, \stdClass $r, string $team): array {
		$factor = self::factor($r);
		$t = self::inTeam($r, $team);
		// VAT counts only in the team's currency: foreign VAT is no input tax.
		$vat = [];
		if ($t !== null && $t['source'] === self::TEAM) {
			foreach (self::ownVat($r) as [$rate, $v]) {
				$vat[] = ['rate' => $rate, 'vat' => $v * $factor];
			}
		}
		$p = $r->project ?? null;
		$project = is_string($p) && trim($p) !== '' ? $p : null;
		$c = $r->category ?? null;
		return [
			'receipt' => $key,
			'date' => (string)($r->date ?? ''),
			'supplier' => is_string($r->supplier ?? null) ? $r->supplier : '',
			'text' => is_string($r->text ?? null) ? $r->text : '',
			'project' => $project,
			'charged' => $project !== null && self::effect($r) === ReceiptRules::CHARGE,
			'gross' => self::gross($r),
			'currency' => self::currency($r, $team),
			'vat_lines' => self::vatLines($r),
			'category' => is_string($c) && trim($c) !== '' ? $c : null,
			'factor' => $factor,
			'rate' => $t === null || $t['rate'] === null ? null : ['value' => $t['rate'], 'source' => $t['source'], 'date' => $t['date']],
			'amount' => $t === null ? null : self::round2($t['amount'] * $factor),
			'vat_amounts' => $vat,
			'without_file' => ExpenseReportRules::withoutReceipt($r),
		];
	}

	/**
	 * The receipt's own VAT per rate with an amount: its `vat_lines`, else
	 * its `vat_rate` and `vat`; none without a rate.
	 *
	 * @return list<array{0:float,1:float}>
	 */
	private static function ownVat(\stdClass $r): array {
		$out = [];
		if (ReceiptRecognition::hasLines($r)) {
			foreach (ReceiptRecognition::rates($r) as $l) {
				if (abs($l['vat']) > 0.004) {
					$out[] = [$l['rate'], $l['vat']];
				}
			}
			return $out;
		}
		$rate = $r->vat_rate ?? null;
		$vat = ReceiptRules::isNumber($r->vat ?? null) ? (float)$r->vat : 0.0;
		return ReceiptRules::isNumber($rate) && abs($vat) > 0.004 ? [[(float)$rate, $vat]] : [];
	}

	/**
	 * The receipt's VAT lines in its own currency, as before 0.30.0: its
	 * `vat_lines`, else one line of its rate, else none.
	 *
	 * @return list<array{rate:float,net:float,vat:float}>
	 */
	private static function vatLines(\stdClass $r): array {
		if (ReceiptRecognition::hasLines($r)) {
			return ReceiptRecognition::rates($r);
		}
		$rate = $r->vat_rate ?? null;
		return ReceiptRules::isNumber($rate) ? [['rate' => (float)$rate, 'net' => (float)($r->net ?? 0), 'vat' => (float)($r->vat ?? 0)]] : [];
	}

	/** The effect on the invoice as it counts: the stored one, else the default. */
	private static function effect(\stdClass $r): string {
		$e = $r->invoice_effect ?? null;
		return is_string($e) && in_array($e, ReceiptRules::EFFECTS, true) ? $e : ReceiptRules::defaultEffect($r);
	}

	// ---------------------------------------------------------------- sums

	/**
	 * The sums of the lines (9 E): per category in the order of first
	 * mention, VAT per rate (summed, then rounded, highest rate first), the
	 * total and the payout on 0.05 – all in the team's currency.
	 *
	 * @param list<array<string,mixed>> $lines
	 * @return array{currency:string,categories:list<array{id:string,amount:float}>,vat:list<array{rate:float,vat:float}>,total:float,payout:float}
	 */
	public static function sums(array $lines, string $team): array {
		// Ids in the order of first mention beside their sums: an id of digits would become an integer key.
		$ids = [];
		$byId = [];
		$total = 0.0;
		$rates = [];
		$vats = [];
		foreach ($lines as $l) {
			$amount = $l['amount'] ?? null;
			$id = $l['category'] ?? null;
			if (is_string($id) && !in_array($id, $ids, true)) {
				$ids[] = $id;
				$byId[] = 0.0;
			}
			if (is_float($amount)) {
				$total += $amount;
				$at = is_string($id) ? array_search($id, $ids, true) : false;
				if (is_int($at)) {
					$byId[$at] += $amount;
				}
			}
			foreach (is_array($l['vat_amounts'] ?? null) ? $l['vat_amounts'] : [] as $x) {
				$rate = is_array($x) ? (float)($x['rate'] ?? 0) : 0.0;
				$v = is_array($x) ? (float)($x['vat'] ?? 0) : 0.0;
				foreach ($rates as $i => $r) {
					if (abs($r - $rate) < 0.001) {
						$vats[$i] += $v;
						continue 2;
					}
				}
				$rates[] = $rate;
				$vats[] = $v;
			}
		}
		$categories = [];
		foreach ($ids as $i => $id) {
			$categories[] = ['id' => $id, 'amount' => self::round2($byId[$i])];
		}
		$vat = [];
		foreach ($rates as $i => $r) {
			$vat[] = ['rate' => $r, 'vat' => self::round2($vats[$i])];
		}
		// Stable, as Rust's sort_by: equal rates keep their order.
		usort($vat, static fn (array $a, array $b): int => $b['rate'] <=> $a['rate']);
		$total = self::round2($total);
		return ['currency' => $team, 'categories' => $categories, 'vat' => $vat, 'total' => $total, 'payout' => self::to005($total)];
	}
}
