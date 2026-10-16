<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * A receipt in a foreign currency (0.22.0), pure: `booked` is the amount
 * paid in the team's currency (from the card statement); without it a
 * foreign receipt is not approved. Expense reports, exports and the cost
 * consumption count the booked amount as gross with the receipt's rate(s);
 * net and VAT are recomputed from it (half away from zero).
 */
final class ReceiptBooking {
	/** The team's currency: `settings.currency`, else the first account of the issuer, else CHF. */
	public static function teamCurrency(?\stdClass $settings): string {
		$accounts = $settings?->issuer?->accounts ?? null;
		$first = is_array($accounts) && ($accounts[0] ?? null) instanceof \stdClass ? ($accounts[0]->currency ?? null) : null;
		foreach ([$settings?->currency ?? null, $first] as $c) {
			if (is_string($c) && preg_match(ProjectRules::CURRENCY_PATTERN, $c) === 1) {
				return strtoupper($c);
			}
		}
		return 'CHF';
	}

	/** Content: null or `{ gross, currency }` (a `rate` sent along is dropped); stored as null in the receipt's own currency. */
	public static function validate(\stdClass $d): void {
		$b = $d->booked ?? null;
		if ($b === null) {
			$d->booked = null;
			return;
		}
		$c = $b instanceof \stdClass ? ($b->currency ?? null) : null;
		if (!$b instanceof \stdClass || !ReceiptRules::isNumber($b->gross ?? null) || !is_string($c)
			|| preg_match(ProjectRules::CURRENCY_PATTERN, $c) !== 1 || array_diff(array_keys(get_object_vars($b)), ['gross', 'currency', 'rate']) !== []) {
			throw ApiException::invalid('“booked” is null or the amount paid: { “gross”, “currency” }.');
		}
		$cur = strtoupper($c);
		$o = $d->currency ?? null;
		$own = is_string($o) ? strtoupper($o) : null;
		$d->booked = $own === $cur ? null : (object)['gross' => round((float)$b->gross, 2), 'currency' => $cur];
	}

	/** The booked amount is in the team's currency (checked when the receipt's currency is known). */
	public static function requireTeamCurrency(\stdClass $d, string $team): void {
		$b = $d->booked ?? null;
		if ($b instanceof \stdClass && ($b->currency ?? null) !== $team) {
			throw ApiException::invalid(Message::of('“booked” is the amount paid in the team’s currency {currency}.', ['currency' => $team]));
		}
		if ($b instanceof \stdClass && strtoupper((string)($d->currency ?? '')) === $team) {
			$d->booked = null;
		}
	}

	/** Before approval: a receipt in another currency names the amount paid in the team's. */
	public static function requireApprovable(\stdClass $d, string $team): void {
		$own = strtoupper((string)($d->currency ?? $team));
		if ($own !== $team && !(($d->booked ?? null) instanceof \stdClass)) {
			throw ApiException::invalid(Message::of('Enter the amount in {currency} that was paid.', ['currency' => $team]));
		}
	}

	public static function isForeign(?\stdClass $d): bool {
		return ($d?->booked ?? null) instanceof \stdClass;
	}

	/** The currency it counts in: the booked one, else its own. */
	public static function currency(\stdClass $d, string $fallback): string {
		$own = $d->currency ?? null;
		return self::isForeign($d) ? (string)$d->booked->currency : (is_string($own) ? $own : $fallback);
	}

	/** Gross as it counts. */
	public static function gross(\stdClass $d): float {
		return self::isForeign($d) ? (float)$d->booked->gross : (float)($d->gross ?? 0);
	}

	/**
	 * The amounts by rate as they count: the receipt's own, or - booked -
	 * the booked gross split over its rates by their share of the gross
	 * (the last takes the rest), each net = gross / (1 + rate), VAT the rest.
	 *
	 * @return list<array{rate:float,net:float,vat:float}>
	 */
	public static function rates(\stdClass $d): array {
		$own = ReceiptRecognition::rates($d);
		if (!self::isForeign($d)) {
			return $own;
		}
		$total = (float)$d->booked->gross;
		$base = array_sum(array_map(static fn (array $l): float => $l['net'] + $l['vat'], $own));
		$out = [];
		$left = $total;
		foreach ($own as $i => $l) {
			$share = $i === count($own) - 1 ? round($left, 2) : ($base == 0.0 ? 0.0 : round($total * ($l['net'] + $l['vat']) / $base, 2));
			$left -= $share;
			$net = round($share / (1.0 + $l['rate'] / 100.0), 2);
			$out[] = ['rate' => $l['rate'], 'net' => $net, 'vat' => round($share - $net, 2)];
		}
		return $out;
	}
}
