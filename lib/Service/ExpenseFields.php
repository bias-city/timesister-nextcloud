<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The expense fields of a receipt (0.30.0, PLAN-SPESEN 9 A/B), pure:
 * `category`, `factor`, `rate`, `template`, `quantity` checked for form
 * only; who changes category, factor and rate (the person and Team Admins,
 * in any status, until settled); and what a settled receipt – one on a
 * fixed or paid expense report – still lets change.
 */
final class ExpenseFields {
	/** Open on a reimbursed receipt in any status until it is settled. */
	public const OPEN = ['category', 'factor', 'rate'];
	/** A category's or template's key, like a customer type's. */
	public const KEY_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';
	public const FACTOR_MAX = 100;
	public const QUANTITY_MAX = 1000000;
	/** A rate is above 0 and below this. */
	public const RATE_MAX = 1000000;
	public const SOURCES = [ExpenseTable::ECB, ExpenseTable::MANUAL];

	/** The form of the five fields (each may be missing or null); 422 naming the field. */
	public static function validate(\stdClass $d): void {
		foreach (['category', 'template'] as $f) {
			$v = $d->{$f} ?? null;
			if ($v !== null && (!is_string($v) || preg_match(self::KEY_PATTERN, $v) !== 1)) {
				throw ApiException::invalid(Message::of('“{field}” must be null or a key of 1 to 40 lower-case letters, digits or dashes.', ['field' => $f]));
			}
		}
		foreach (['factor' => self::FACTOR_MAX, 'quantity' => self::QUANTITY_MAX] as $f => $max) {
			$v = $d->{$f} ?? null;
			if ($v !== null && !(ReceiptRules::isNumber($v) && $v > 0 && $v <= $max)) {
				throw ApiException::invalid(Message::of('“{field}” must be null or a number above 0 and at most {max}.', ['field' => $f, 'max' => $max]));
			}
		}
		$rate = $d->rate ?? null;
		if ($rate !== null && !self::isRate($rate)) {
			throw ApiException::invalid('“rate” must be null or { “value” above 0 and below 1000000, “date” as YYYY-MM-DD, “source” “ecb” or “manual” }.');
		}
	}

	/** `{ value, date, source }` and nothing else. */
	private static function isRate(mixed $r): bool {
		if (!$r instanceof \stdClass || array_diff(array_keys(get_object_vars($r)), ['value', 'date', 'source']) !== []) {
			return false;
		}
		$v = $r->value ?? null;
		$day = $r->date ?? null;
		return ReceiptRules::isNumber($v) && $v > 0 && $v < self::RATE_MAX
			&& is_string($day) && Time::isDay($day) && in_array($r->source ?? null, self::SOURCES, true);
	}

	/** The fields of a reimbursed receipt that stay open after approval. @return list<string> */
	public static function openOn(\stdClass $receipt): array {
		return ($receipt->reimburse ?? false) === true ? self::OPEN : [];
	}

	/**
	 * Category, factor and rate of a reimbursed receipt change only by its
	 * person and Team Admins, in any status; a Lead only on their own
	 * expense (9 B 1). 403 otherwise.
	 *
	 * @param list<string> $changed the fields the write changes
	 */
	public static function requireOwner(bool $manages, string $uid, \stdClass $before, \stdClass $after, array $changed): void {
		if ($manages || array_intersect($changed, self::OPEN) === []) {
			return;
		}
		if (($before->reimburse ?? false) !== true && ($after->reimburse ?? false) !== true) {
			return;
		}
		if (($before->person ?? null) !== $uid) {
			throw ApiException::forbidden('Only the person and Team Admins set category, factor and rate of an expense.');
		}
	}

	/**
	 * A receipt on a fixed or paid expense report (9 B 2): not deleted; only
	 * project, cost kind, budget, cost, amount for the budget, invoice effect
	 * and note change – by Team Admins and the project's Leads, in any
	 * status, billed ones only in the note. 409 for anything else.
	 *
	 * @param array<string,true> $led
	 */
	public static function requireSettled(bool $manages, array $led, \stdClass $before, ?\stdClass $after): void {
		if ($after === null) {
			throw ApiException::conflict('This expense is settled; reverse the expense report first.');
		}
		$changed = ReceiptRules::changed($before, $after);
		foreach ($changed as $f) {
			if (!in_array($f, ReceiptRules::CORRECTABLE, true)) {
				throw self::stays($f);
			}
		}
		if ($changed === []) {
			return;
		}
		if (!$manages && !(ReceiptRules::ofLed($before, $led) && ReceiptRules::ofLed($after, $led))) {
			throw ApiException::forbidden('Only Team Admins and the project’s Leads change a settled expense.');
		}
		if (($before->status ?? null) === ReceiptRules::BILLED) {
			foreach ($changed as $f) {
				if ($f !== 'note') {
					throw ApiException::conflict(Message::of('A billed receipt changes only in “note”; “{field}” stays.', ['field' => $f]));
				}
			}
		}
		if (ReceiptRules::isApproved($before) && ($after->project ?? null) !== null && (!is_string($after->budget ?? null) || !is_int($after->cost ?? null))) {
			throw ApiException::invalid('An approved receipt of a project keeps a budget and a cost position.');
		}
	}

	/** 409: the field of a settled receipt stays (also `file`). */
	public static function stays(string $field): ApiException {
		return ApiException::conflict(Message::of('This expense is settled; reverse the expense report first. “{field}” stays.', ['field' => $field]));
	}
}
