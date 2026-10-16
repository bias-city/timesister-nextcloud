<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The rule of a recurring expense (0.22.0, record `expense_rule`, B4a),
 * pure: content, the periods it covers, its approval (cleared when amount,
 * rate or period change) and who reads and writes it. The client creates
 * the recurring receipt of each period; the server only checks it.
 */
final class ExpenseRuleRules {
	public const KIND = 'expense_rule';
	public const MONTHLY = 'monthly';
	public const QUARTERLY = 'quarterly';
	public const YEARLY = 'yearly';
	public const PERIODS = [self::MONTHLY, self::QUARTERLY, self::YEARLY];
	public const SERVER = ['approved_by', 'approved_on'];
	/** A change of these clears the approval. */
	public const APPROVAL_FIELDS = ['amount', 'vat_rate', 'period'];
	public const TEXT_MAX = 300;

	public static function validate(\stdClass $d): void {
		$s = $d->schema ?? null;
		if ($s !== null && (!is_int($s) || $s < 1 || $s > 1000)) {
			throw ApiException::invalidField('schema');
		}
		$person = $d->person ?? null;
		if (!is_string($person) || $person === '' || mb_strlen($person) > ReceiptRules::UID_MAX || preg_match('/[\x00-\x1F\x7F]/', $person)) {
			throw ApiException::invalid('“person” is required: the account’s user ID.');
		}
		$text = $d->text ?? null;
		if (!is_string($text) || trim($text) === '' || mb_strlen($text) > self::TEXT_MAX || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
			throw ApiException::invalid(Message::of('“{field}” must be text of 1 to {max} characters.', ['field' => 'text', 'max' => self::TEXT_MAX]));
		}
		$amount = $d->amount ?? null;
		if (!ProjectRules::isRate($amount)) {
			throw ApiException::invalid(Message::of('“{field}” must be a number ≥ 0.', ['field' => 'amount']));
		}
		$rate = $d->vat_rate ?? null;
		if ($rate !== null && !(ProjectRules::isRate($rate) && $rate <= 100)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or a percentage from 0 to 100.', ['field' => 'vat_rate']));
		}
		if (!in_array($d->period ?? null, self::PERIODS, true)) {
			throw ReceiptRules::oneOf('period', self::PERIODS);
		}
		$from = $d->from ?? null;
		$to = $d->to ?? null;
		if (!is_string($from) || !Time::isDay($from)) {
			throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => 'from']));
		}
		if ($to !== null && (!is_string($to) || !Time::isDay($to))) {
			throw ApiException::invalid(Message::of('“{field}” must be null or a day in the format YYYY-MM-DD.', ['field' => 'to']));
		}
		if ($to !== null && $to < $from) {
			throw ApiException::invalid('“from” must not be after “to”.');
		}
		$p = $d->project ?? null;
		$b = $d->budget ?? null;
		$c = $d->cost ?? null;
		if (($p !== null || $b !== null || $c !== null)
			&& (!is_string($p) || !RecordValidator::isKey($p) || !is_string($b) || $b === '' || mb_strlen($b) > ReceiptRules::BUDGET_MAX || !is_int($c) || $c < 1)) {
			throw ApiException::invalid('“project”, “budget” and “cost” are all null or name a project, one of its budgets and a cost position of that budget.');
		}
	}

	/** The approval is kept from the stored rule unless amount, rate or period changed. */
	public static function mergeServer(?\stdClass $before, \stdClass $after): void {
		$keep = $before !== null && ($before->approved_by ?? null) !== null;
		foreach (self::APPROVAL_FIELDS as $f) {
			if ($before !== null && Json::encode([$before->{$f} ?? null]) !== Json::encode([$after->{$f} ?? null])) {
				$keep = false;
			}
		}
		$after->approved_by = $keep ? Fields::str($before, 'approved_by') : null;
		$after->approved_on = $keep ? Fields::str($before, 'approved_on') : null;
	}

	public static function isApproved(?\stdClass $d): bool {
		return is_string($d?->approved_by ?? null);
	}

	// ------------------------------------------------------------- rights

	/** The person, Team Admins, and the Lead of the rule's project. @param array<string,true> $led */
	public static function canRead(bool $manages, string $uid, array $led, ?\stdClass $d): bool {
		return $manages || ($d?->person ?? null) === $uid || ReceiptRules::ofLed($d, $led);
	}

	/** The person writes their own rules, Team Admins any; an approved rule is ended by `to`, not deleted. */
	public static function requireWrite(bool $manages, string $uid, ?\stdClass $before, ?\stdClass $after): void {
		if ($after === null && self::isApproved($before)) {
			throw ApiException::conflict('An approved expense rule is not deleted; end it by setting “to”.');
		}
		if ($manages) {
			return;
		}
		foreach ([$before, $after] as $d) {
			if ($d !== null && ($d->person ?? null) !== $uid) {
				throw ApiException::forbidden('Expense rules are written by their person and by Team Admins.');
			}
		}
	}

	/** Team Admins; with a project also its Lead. @param array<string,true> $led */
	public static function canApprove(bool $manages, array $led, \stdClass $d): bool {
		return $manages || ReceiptRules::ofLed($d, $led);
	}

	// ------------------------------------------------------------ periods

	/** Does the period have the rule's form: monthly YYYY-MM, quarterly YYYY-Qn, yearly YYYY? */
	public static function fits(string $rulePeriod, string $period): bool {
		return match ($rulePeriod) {
			self::MONTHLY => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1,
			self::QUARTERLY => preg_match('/^\d{4}-Q[1-4]$/', $period) === 1,
			self::YEARLY => preg_match('/^\d{4}$/', $period) === 1,
			default => false,
		};
	}

	/** The first and last day of a period. @return array{0:string,1:string} */
	public static function bounds(string $period): array {
		$y = (int)substr($period, 0, 4);
		if (preg_match('/^\d{4}-(\d{2})$/', $period, $m)) {
			$first = sprintf('%04d-%02d-01', $y, (int)$m[1]);
			return [$first, gmdate('Y-m-t', (int)strtotime($first . ' 00:00:00 UTC'))];
		}
		if (preg_match('/^\d{4}-Q([1-4])$/', $period, $m)) {
			$q = (int)$m[1];
			$first = sprintf('%04d-%02d-01', $y, $q * 3 - 2);
			$last = sprintf('%04d-%02d-01', $y, $q * 3);
			return [$first, gmdate('Y-m-t', (int)strtotime($last . ' 00:00:00 UTC'))];
		}
		return [sprintf('%04d-01-01', $y), sprintf('%04d-12-31', $y)];
	}

	/** A period of the rule's form that overlaps the rule's `from`–`to`. */
	public static function covers(\stdClass $rule, string $period): bool {
		if (!self::fits((string)($rule->period ?? ''), $period)) {
			return false;
		}
		[$first, $last] = self::bounds($period);
		$from = (string)($rule->from ?? '');
		$to = $rule->to ?? null;
		return $last >= $from && (!is_string($to) || $first <= $to);
	}
}
