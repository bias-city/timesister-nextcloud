<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The rule of a recurring expense (0.22.0, record `expense_rule`, B4a),
 * pure: content, the periods it covers and who reads and writes it. Since
 * 0.30.0 a rule applies from creation, without approval. The client
 * creates the recurring receipt of each period; the server only checks it.
 */
final class ExpenseRuleRules {
	public const KIND = 'expense_rule';
	public const MONTHLY = 'monthly';
	public const QUARTERLY = 'quarterly';
	public const YEARLY = 'yearly';
	public const PERIODS = [self::MONTHLY, self::QUARTERLY, self::YEARLY];
	/** The approval of rules before 0.30.0: kept as stored, never set any more. */
	public const SERVER = ['approved_by', 'approved_on'];
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

	/** The server's fields as stored: an old rule keeps its approval, a new one has none (0.30.0). */
	public static function mergeServer(?\stdClass $before, \stdClass $after): void {
		foreach (self::SERVER as $f) {
			$after->{$f} = Fields::str($before, $f);
		}
	}

	// ------------------------------------------------------------- rights

	/** The person, Team Admins, and the Lead of the rule's project. @param array<string,true> $led */
	public static function canRead(bool $manages, string $uid, array $led, ?\stdClass $d): bool {
		return $manages || ($d?->person ?? null) === $uid || ReceiptRules::ofLed($d, $led);
	}

	/** The person writes their own rules, Team Admins any. */
	public static function requireWrite(bool $manages, string $uid, ?\stdClass $before, ?\stdClass $after): void {
		if ($manages) {
			return;
		}
		foreach ([$before, $after] as $d) {
			if ($d !== null && ($d->person ?? null) !== $uid) {
				throw ApiException::forbidden('Expense rules are written by their person and by Team Admins.')->code(ErrorCode::RULE_OWNER_ONLY);
			}
		}
	}

	/** A rule is deleted while no live receipt names it; with receipts it is ended by `to` (0.30.0). */
	public static function requireDelete(bool $hasReceipts): void {
		if ($hasReceipts) {
			throw ApiException::conflict('An expense rule with receipts is not deleted; end it by setting “to”.')->code(ErrorCode::RULE_HAS_RECEIPTS);
		}
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
