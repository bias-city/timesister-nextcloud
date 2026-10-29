<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * A receipt in a foreign currency (0.22.0), pure. `booked` is the amount
 * paid in the team's currency (from the card statement); without it a
 * foreign receipt is not approved. `billed` is the amount for a budget in
 * a third currency (neither the receipt's nor the team's). The one place
 * that says which amount counts: {@see forExpenses()} for expense reports
 * and exports, {@see forBudget()} for invoice lines and cost consumption;
 * net and VAT are recomputed from that gross (half away from zero).
 */
final class ReceiptBooking {
	/** The team's currency: `settings.currency`, else CHF. */
	public static function teamCurrency(?\stdClass $settings): string {
		$c = $settings?->currency ?? null;
		return is_string($c) && preg_match(ProjectRules::CURRENCY_PATTERN, $c) === 1 ? strtoupper($c) : 'CHF';
	}

	/** `settings.currency`: null or three capital letters. */
	public static function validateTeamCurrency(mixed $c): void {
		if ($c !== null && (!is_string($c) || preg_match('/^[A-Z]{3}$/', $c) !== 1)) {
			throw ApiException::invalid('The team’s “currency” is null or three capital letters, for example CHF.');
		}
	}

	/** The budget's currency: its own `currency`, else the team's. */
	public static function budgetCurrency(?\stdClass $budget, string $team): string {
		$c = $budget?->currency ?? null;
		return is_string($c) && preg_match(ProjectRules::CURRENCY_PATTERN, $c) === 1 ? strtoupper($c) : $team;
	}

	/** The currency of the receipt's budget in its project; null without a budget. */
	public static function budgetCurrencyOf(?\stdClass $project, \stdClass $d, string $team): ?string {
		$id = $d->budget ?? null;
		return $project === null || !is_string($id) ? null : self::budgetCurrency(IssueRules::budgetOf($project, $id), $team);
	}

	/** Content of `booked` and `billed`: null or `{ gross, currency }` (a `rate` sent along is dropped); `booked` is null in the receipt's own currency. */
	public static function validate(\stdClass $d): void {
		$o = $d->currency ?? null;
		$own = is_string($o) ? strtoupper($o) : null;
		$booked = self::amount($d->booked ?? null);
		if ($booked === false) {
			throw ApiException::invalid('“booked” is null or the amount paid: { “gross”, “currency” }.');
		}
		$d->booked = $booked !== null && $booked->currency === $own ? null : $booked;
		$billed = self::amount($d->billed ?? null);
		if ($billed === false) {
			throw ApiException::invalid('“billed” is null or the amount for the budget: { “gross”, “currency” }.');
		}
		$d->billed = $billed;
	}

	/** `{ gross, currency }` rounded and upper-cased; false when not such an object. */
	private static function amount(mixed $b): \stdClass|null|false {
		if ($b === null) {
			return null;
		}
		if (!$b instanceof \stdClass) {
			return false;
		}
		$c = $b->currency ?? null;
		if (!ReceiptRules::isNumber($b->gross ?? null) || !is_string($c)
			|| preg_match(ProjectRules::CURRENCY_PATTERN, $c) !== 1 || array_diff(array_keys(get_object_vars($b)), ['gross', 'currency', 'rate']) !== []) {
			return false;
		}
		$o = new \stdClass();
		$o->gross = round((float)$b->gross, 2);
		$o->currency = strtoupper($c);
		return $o;
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

	/**
	 * Does the budget need its own amount? Only a receipt of a budget whose
	 * currency is neither the receipt's nor the team's. `$budget` null:
	 * the receipt has no budget.
	 */
	public static function needsBilled(\stdClass $d, string $team, ?string $budget): bool {
		$own = strtoupper((string)($d->currency ?? $team));
		return $budget !== null && is_string($d->budget ?? null) && $own !== $budget && $team !== $budget;
	}

	/**
	 * `billed` against the budget: null when not needed, else in the
	 * budget's currency; required once the receipt is approved
	 * (`$approved`, a correction after approval).
	 */
	public static function requireBudgetCurrency(\stdClass $d, string $team, ?string $budget, bool $approved): void {
		$b = $d->billed ?? null;
		if (!self::needsBilled($d, $team, $budget)) {
			$d->billed = null;
			return;
		}
		if ($b instanceof \stdClass && ($b->currency ?? null) !== $budget) {
			throw ApiException::invalid(Message::of('“billed” is the amount for the budget in its currency {currency}.', ['currency' => (string)$budget]));
		}
		if ($approved && !$b instanceof \stdClass) {
			throw ApiException::invalid(Message::of('Enter the amount in {currency} for the budget.', ['currency' => (string)$budget]))->code(ErrorCode::BUDGET_CURRENCY_AMOUNT)->field('billed');
		}
	}

	/** Before approval: a receipt in another currency names the amount paid in the team's, and the amount for a budget in a third currency. */
	public static function requireApprovable(\stdClass $d, string $team, ?string $budget = null): void {
		$own = strtoupper((string)($d->currency ?? $team));
		if ($own !== $team && !(($d->booked ?? null) instanceof \stdClass)) {
			throw ApiException::invalid(Message::of('Enter the amount in {currency} that was paid.', ['currency' => $team]))->code(ErrorCode::BOOKED_CURRENCY_AMOUNT)->field('booked');
		}
		self::requireBudgetCurrency($d, $team, $budget, true);
	}

	/**
	 * As it counts for expenses (reports, exports): the receipt's own
	 * amounts, or the booked ones in the team's currency.
	 *
	 * @return array{currency:string,gross:float,rates:list<array{rate:float,net:float,vat:float}>,converted:bool}
	 */
	public static function forExpenses(\stdClass $d, string $fallback): array {
		return self::counted($d, $d->booked ?? null, $fallback);
	}

	/**
	 * As it counts for the budget (invoice lines, cost consumption): the
	 * receipt's own amounts in the budget's currency, else the booked ones
	 * when the budget is in the team's currency, else `billed`.
	 *
	 * @return array{currency:string,gross:float,rates:list<array{rate:float,net:float,vat:float}>,converted:bool}
	 */
	public static function forBudget(\stdClass $d, string $team, string $budget): array {
		$own = strtoupper((string)($d->currency ?? $team));
		$amount = $own === $budget ? null : ($team === $budget ? ($d->booked ?? null) : ($d->billed ?? null));
		return self::counted($d, $amount, $team);
	}

	/** @return array{currency:string,gross:float,rates:list<array{rate:float,net:float,vat:float}>,converted:bool} */
	private static function counted(\stdClass $d, mixed $amount, string $fallback): array {
		$own = ReceiptRecognition::rates($d);
		if (!$amount instanceof \stdClass) {
			$c = $d->currency ?? null;
			return ['currency' => is_string($c) ? $c : $fallback, 'gross' => (float)($d->gross ?? 0), 'rates' => $own, 'converted' => false];
		}
		return ['currency' => (string)$amount->currency, 'gross' => (float)$amount->gross, 'rates' => self::split($own, (float)$amount->gross), 'converted' => true];
	}

	/**
	 * A gross in another currency over the receipt's rates by their share
	 * of its gross (the last takes the rest), each net = gross / (1 + rate),
	 * VAT the rest.
	 *
	 * @param list<array{rate:float,net:float,vat:float}> $own
	 * @return list<array{rate:float,net:float,vat:float}>
	 */
	private static function split(array $own, float $total): array {
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
