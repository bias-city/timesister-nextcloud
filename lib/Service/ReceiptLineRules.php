<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Invoicing from receipts (0.22.0, B4), pure: the `costs` lines naming
 * receipts, the markup on them and the expense percentage of the hours,
 * checked when a draft is issued or previewed.
 */
final class ReceiptLineRules {
	public const COSTS = 'costs';
	public const MARKUP = 'markup';
	public const EXPENSE_PERCENT = 'expense_percent';
	public const MAX_RECEIPTS = 500;
	private const CENT = 0.0101;

	/** `receipts` of a position as the draft check sees it: null or a list of receipt keys. */
	public static function checkList(\stdClass $p): void {
		$list = $p->receipts ?? null;
		if ($list === null) {
			return;
		}
		if (($p->kind ?? null) !== self::COSTS) {
			throw ApiException::invalid('Only a “costs” line names receipts.');
		}
		if (!is_array($list) || !array_is_list($list) || $list === [] || count($list) > self::MAX_RECEIPTS) {
			throw ApiException::invalid(Message::of('“receipts” must be a list of 1 to {max} receipt keys.', ['max' => self::MAX_RECEIPTS]));
		}
		foreach ($list as $k) {
			if (!is_string($k) || preg_match(ReceiptRules::KEY_PATTERN, $k) !== 1) {
				throw ApiException::invalid(Message::of('“receipts” must be a list of 1 to {max} receipt keys.', ['max' => self::MAX_RECEIPTS]));
			}
		}
	}

	/**
	 * The receipt lines, the markup and the expense percentage of a draft.
	 *
	 * @param list<\stdClass> $positions
	 * @param array<string,\stdClass> $receipts the receipts named, by key (missing: not found)
	 * @param callable(string):string $numberOf the number of an invoice by key
	 */
	public static function check(array $positions, array $receipts, string $project, string $budget, string $invoiceKey, callable $numberOf): void {
		$seen = [];
		$charged = [];
		$hours = 0.0;
		foreach ($positions as $p) {
			if (($p->kind ?? null) === 'hours') {
				$hours += self::num($p->amount ?? 0);
			}
		}
		foreach ($positions as $i => $p) {
			$n = $i + 1;
			$list = is_array($p->receipts ?? null) ? $p->receipts : null;
			if ($list !== null) {
				$cost = $p->cost ?? null;
				if (!is_int($cost)) {
					throw self::at($n, Message::of('A line with receipts needs “cost”.'));
				}
				$show = 0;
				foreach ($list as $k) {
					$k = (string)$k;
					if (isset($seen[$k])) {
						throw self::at($n, Message::of('The receipt {key} is on the invoice twice.', ['key' => $k]));
					}
					$seen[$k] = true;
					$r = $receipts[$k] ?? null;
					self::requireReceipt($n, $k, $r, $project, $budget, $cost, $invoiceKey, $numberOf);
					$show += ($r?->invoice_effect ?? null) === ReceiptRules::SHOW_ONLY ? 1 : 0;
				}
				if ($show > 0 && $show < count($list)) {
					throw self::at($n, Message::of('Receipts the customer paid stand on a line of their own.'));
				}
				if ($show > 0 && abs(self::num($p->amount ?? 0)) > 0.0) {
					throw self::at($n, Message::of('A line of receipts the customer paid has the amount 0.'));
				}
				if ($show === 0) {
					$charged[$cost] = ($charged[$cost] ?? 0.0) + self::num($p->amount ?? 0);
				}
			}
		}
		foreach ($positions as $i => $p) {
			$kind = $p->kind ?? null;
			if ($kind === self::MARKUP) {
				$cost = $p->cost ?? null;
				if (!is_int($cost)) {
					throw self::at($i + 1, Message::of('A markup needs “cost”.'));
				}
				self::requireAmount($i + 1, $p, $charged[$cost] ?? 0.0);
			} elseif ($kind === self::EXPENSE_PERCENT) {
				self::requireAmount($i + 1, $p, $hours);
			}
		}
	}

	/** @param callable(string):string $numberOf */
	private static function requireReceipt(int $n, string $k, ?\stdClass $r, string $project, string $budget, int $cost, string $invoiceKey, callable $numberOf): void {
		$p = ['key' => $k];
		if ($r === null) {
			throw self::at($n, Message::of('The receipt {key} does not exist.', $p));
		}
		$billedBy = $r->invoice ?? null;
		if (($r->status ?? null) === ReceiptRules::BILLED && is_string($billedBy) && $billedBy !== $invoiceKey) {
			throw ApiException::conflict(Message::of('Position {n}: {message}', ['n' => $n, 'message' => Message::of('The receipt {key} is billed by invoice {number}.', ['key' => $k, 'number' => $numberOf($billedBy)])]));
		}
		if (($r->status ?? null) !== ReceiptRules::APPROVED) {
			throw self::at($n, Message::of('The receipt {key} is not approved.', $p));
		}
		if (($r->project ?? null) !== $project || ($r->budget ?? null) !== $budget || ($r->cost ?? null) !== $cost) {
			throw self::at($n, Message::of('The receipt {key} belongs to another project, budget or cost position.', $p));
		}
		if (($r->invoice_effect ?? null) === ReceiptRules::NONE) {
			throw self::at($n, Message::of('The receipt {key} is not for the invoice.', $p));
		}
	}

	/** `amount` = round(base × rate / 100, 2) within a cent. */
	private static function requireAmount(int $n, \stdClass $p, float $base): void {
		$rate = $p->rate ?? null;
		if (!(is_int($rate) || is_float($rate)) || $rate < 0 || $rate > 100) {
			throw self::at($n, Message::of('“{field}” must be a percentage from 0 to 100.', ['field' => 'rate']));
		}
		$want = round($base * (float)$rate / 100.0, 2);
		if (abs(self::num($p->amount ?? 0) - $want) > self::CENT) {
			throw self::at($n, Message::of('The amount must be {amount}.', ['amount' => number_format($want, 2, '.', '')]));
		}
	}

	/**
	 * The lines as they are frozen: a line of receipts the customer paid
	 * carries VAT 0 and, without a text of its own, the receipt's note.
	 *
	 * @param list<\stdClass> $positions
	 * @param array<string,\stdClass> $receipts
	 * @return list<\stdClass>
	 */
	public static function prepare(array $positions, array $receipts): array {
		$out = [];
		foreach ($positions as $p) {
			$list = array_values(Fields::list($p, 'receipts'));
			$first = is_string($list[0] ?? null) ? ($receipts[$list[0]] ?? null) : null;
			if ($list !== [] && ($first?->invoice_effect ?? null) === ReceiptRules::SHOW_ONLY) {
				$p = clone $p;
				$p->vat = 0;
				$p->amount = 0;
				$note = $first->note ?? null;
				if (is_string($note) && $note !== '' && !str_contains((string)($p->text ?? ''), $note)) {
					$p->text = mb_substr((string)$p->text . ' – ' . $note, 0, InvoiceRules::POSITION_TEXT_MAX);
				}
			}
			$out[] = $p;
		}
		return $out;
	}

	private static function at(int $n, Message $m): ApiException {
		return ApiException::invalid(Message::of('Position {n}: {message}', ['n' => $n, 'message' => $m]));
	}

	private static function num(mixed $v): float {
		return is_int($v) || is_float($v) ? (float)$v : 0.0;
	}
}
