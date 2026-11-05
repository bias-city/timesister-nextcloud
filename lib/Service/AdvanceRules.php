<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Advances, their deductions and the expenses invoice beside a main
 * invoice (0.39.0, PLAN-RECHNUNGEN R5/R6), pure: checked when a draft is
 * issued, against the team's other invoice records. Also the team rule
 * `invoicing.expenses_separate` in the settings record.
 */
final class AdvanceRules {
	/** Invoices that bill: a deduction in one of them uses the advance up. */
	public const LIVE = [InvoiceRules::DRAFT, 'issuing', 'issued', 'paid', HandoverRules::STATUS];
	/** An advance can be deducted once it is fixed. */
	public const DEDUCTIBLE = ['issued', 'paid'];
	/** The main invoice of an expenses invoice: from draft to paid. */
	public const COMPANION = [InvoiceRules::DRAFT, 'issuing', 'issued', 'paid'];
	/** The kind of an expenses invoice. */
	public const EXPENSES = 'expenses';
	/** The settings record's object of invoice rules and its switch (default true). */
	public const SETTING = 'invoicing';
	public const EXPENSES_SEPARATE = 'expenses_separate';
	private const CENT = 0.0101;

	/**
	 * `kind: advance`: exactly one `advance` line, no `hours` line, no bookings.
	 *
	 * @param list<\stdClass> $positions
	 * @throws ApiException 422 `advance_position_count`
	 */
	public static function checkKind(\stdClass $d, array $positions, int $bookings): void {
		if (($d->kind ?? null) !== InvoiceRules::ADVANCE) {
			return;
		}
		$advances = 0;
		$hours = $bookings;
		foreach ($positions as $p) {
			$k = $p->kind ?? null;
			$advances += $k === InvoiceRules::ADVANCE ? 1 : 0;
			$hours += $k === 'hours' ? 1 : 0;
		}
		if ($advances !== 1 || $hours > 0) {
			throw ApiException::invalid('An advance invoice has exactly one advance line and no hours.')->code(ErrorCode::ADVANCE_POSITION_COUNT)->field('positions');
		}
	}

	/** Whether the positions deduct an advance, so the other invoices are needed. @param list<\stdClass> $positions */
	public static function deducts(array $positions): bool {
		foreach ($positions as $p) {
			if (($p->kind ?? null) === InvoiceRules::ADVANCE_DEDUCTION) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every `advance_deduction` names an issued or paid advance invoice of
	 * the same budget (no budget: the same project; neither: the same
	 * customer), deducted nowhere else and once here, by its amount negated.
	 * Reversals and credit notes deduct nothing.
	 *
	 * @param list<\stdClass> $positions
	 * @param array<string,\stdClass> $invoices the team's live invoice records by key
	 * @throws ApiException 422 `advance_unknown`, `advance_deducted_twice`, `advance_amount`
	 */
	public static function checkDeductions(string $key, array $positions, ?string $project, ?string $budget, string $customer, array $invoices): void {
		$here = [];
		foreach ($positions as $i => $p) {
			if (($p->kind ?? null) !== InvoiceRules::ADVANCE_DEDUCTION) {
				continue;
			}
			$n = $i + 1;
			$ref = (string)($p->invoice ?? '');
			$a = $invoices[$ref] ?? null;
			if ($ref === $key || $a === null || !self::isAdvanceOf($a, $project, $budget, $customer)) {
				throw self::at($n, Message::of('The invoice {key} is no issued advance of this budget, project or customer.', ['key' => $ref]))->code(ErrorCode::ADVANCE_UNKNOWN)->field('positions');
			}
			$number = self::numberOf($a, $ref);
			if (isset($here[$ref])) {
				throw self::at($n, Message::of('The advance {number} is deducted twice.', ['number' => $number]))->code(ErrorCode::ADVANCE_DEDUCTED_TWICE)->field('positions');
			}
			$here[$ref] = true;
			foreach ($invoices as $k => $o) {
				if ($k !== $key && self::deductsLive($o, $ref)) {
					throw self::at($n, Message::of('The advance {number} is already deducted by invoice {other}.', ['number' => $number, 'other' => self::numberOf($o, $k)]))->code(ErrorCode::ADVANCE_DEDUCTED_TWICE)->field('positions');
				}
			}
			$want = -self::advanceAmount($a);
			if (abs(self::num($p->amount ?? null) - $want) > self::CENT) {
				throw self::at($n, Message::of('The deduction of the advance {number} must be {amount}.', ['number' => $number, 'amount' => number_format($want, 2, '.', '')]))->code(ErrorCode::ADVANCE_AMOUNT)->field('positions');
			}
		}
	}

	/**
	 * `kind: expenses`: `companion` names another invoice of the same
	 * customer, from draft to paid.
	 *
	 * @param array<string,\stdClass> $invoices the team's live invoice records by key
	 * @param callable(\stdClass):?string $customerOf the customer of an invoice record
	 * @throws ApiException 422 `companion_unknown`
	 */
	public static function checkCompanion(string $key, \stdClass $d, string $customer, array $invoices, callable $customerOf): void {
		if (($d->kind ?? null) !== self::EXPENSES) {
			return;
		}
		$c = InvoiceRules::keyOf($d->companion ?? null);
		$o = $c === null || $c === $key ? null : ($invoices[$c] ?? null);
		if ($o === null || !in_array($o->status ?? null, self::COMPANION, true) || $customerOf($o) !== $customer) {
			throw ApiException::invalid('An expenses invoice names its main invoice in “companion”: an invoice of the same customer, from draft to paid.')->code(ErrorCode::COMPANION_UNKNOWN)->field('companion');
		}
	}

	/** `invoicing` in the settings record: null or an object; `expenses_separate` null or a boolean. */
	public static function validateSetting(mixed $v): void {
		if ($v === null) {
			return;
		}
		if (!($v instanceof \stdClass)) {
			throw ApiException::invalid('“invoicing” must be null or an object.')->field(self::SETTING);
		}
		$s = $v->{self::EXPENSES_SEPARATE} ?? null;
		if ($s !== null && !is_bool($s)) {
			throw ApiException::notBool(self::SETTING . '.' . self::EXPENSES_SEPARATE);
		}
	}

	/** The team rule: receipts on an invoice of their own (default true). */
	public static function expensesSeparate(?\stdClass $settings): bool {
		$v = $settings?->{self::SETTING} ?? null;
		$s = $v instanceof \stdClass ? ($v->{self::EXPENSES_SEPARATE} ?? null) : null;
		return $s !== false;
	}

	/** An issued or paid advance invoice in the scope, not a reversal or credit note. */
	private static function isAdvanceOf(\stdClass $a, ?string $project, ?string $budget, string $customer): bool {
		if (!in_array($a->status ?? null, self::DEDUCTIBLE, true) || self::isCounter($a) || !InvoiceRules::isAdvance($a->positions ?? null)) {
			return false;
		}
		$p = InvoiceRules::keyOf($a->project ?? null);
		if ($budget !== null) {
			return $p === $project && InvoiceRules::keyOf($a->budget ?? null) === $budget;
		}
		if ($project !== null) {
			return $p === $project;
		}
		return InvoiceRules::keyOf($a->customer ?? null) === $customer;
	}

	/** A live invoice (not a reversal or credit note) with a deduction of the advance `$ref`. */
	private static function deductsLive(\stdClass $o, string $ref): bool {
		if (!in_array($o->status ?? null, self::LIVE, true) || self::isCounter($o)) {
			return false;
		}
		$list = $o->positions ?? null;
		foreach (is_array($list) ? $list : [] as $p) {
			if ($p instanceof \stdClass && ($p->kind ?? null) === InvoiceRules::ADVANCE_DEDUCTION && ($p->invoice ?? null) === $ref) {
				return true;
			}
		}
		return false;
	}

	private static function isCounter(\stdClass $o): bool {
		return InvoiceRules::keyOf($o->reverses ?? null) !== null || InvoiceRules::keyOf($o->credits ?? null) !== null;
	}

	/** The sum of the advance lines of an advance invoice. */
	private static function advanceAmount(\stdClass $a): float {
		$sum = 0.0;
		$list = $a->positions ?? null;
		foreach (is_array($list) ? $list : [] as $p) {
			if ($p instanceof \stdClass && ($p->kind ?? null) === InvoiceRules::ADVANCE) {
				$sum += self::num($p->amount ?? null);
			}
		}
		return round($sum, 2);
	}

	private static function numberOf(\stdClass $o, string $key): string {
		$n = $o->number ?? null;
		return is_string($n) && $n !== '' ? $n : $key;
	}

	private static function at(int $n, Message $m): ApiException {
		return ApiException::invalid(Message::of('Position {n}: {message}', ['n' => $n, 'message' => $m]));
	}

	private static function num(mixed $v): float {
		return is_int($v) || is_float($v) ? (float)$v : 0.0;
	}
}
