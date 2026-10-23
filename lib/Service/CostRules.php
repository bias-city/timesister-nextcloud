<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The cost positions of a budget (0.21.0, module «costs»), pure:
 * `budgets[].costs[]` beside the work packages – expenses by percentage,
 * flat or receipts, third-party costs by receipts, each re-billed or
 * passed through. The server stores and checks; the consumption is the
 * client's. Called from {@see ProjectRules::checkBudget()}, so a quote's
 * budget is checked alike.
 */
final class CostRules {
	public const FIELD = 'costs';
	public const MAX = 200;
	public const NAME_MAX = 120;
	public const DESCRIPTION_MAX = 300;
	public const EXPENSE = 'expense';
	public const THIRD_PARTY = 'third_party';
	public const KINDS = [self::EXPENSE, self::THIRD_PARTY];
	public const PERCENT = 'percent';
	public const FLAT = 'flat';
	public const RECEIPTS = 'receipts';
	public const RULES = [self::PERCENT, self::FLAT, self::RECEIPTS];
	public const PER = ['invoice', 'budget'];
	public const REBILL = 'rebill';
	public const PASS_THROUGH = 'pass_through';
	/** 0.26.3: the longest code of a cost position. */
	public const CODE_MAX = 60;
	/** 0.26.0: at most this many amounts in a forecast (one per milestone). */
	public const FORECAST_MAX = 200;
	public const MODES = [self::REBILL, self::PASS_THROUGH];

	private const CONTROL = '/[\x00-\x1F\x7F]/';
	private const CONTROL_TEXT = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

	/** `costs` of a budget: null or a list of cost positions; 422 naming the position and the field. */
	public static function check(mixed $list): void {
		if ($list === null) {
			return;
		}
		if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX) {
			throw ApiException::invalid(Message::of('“{field}” must be a list of at most {limit} entries.', ['field' => self::FIELD, 'limit' => self::MAX]));
		}
		$seen = [];
		foreach ($list as $i => $c) {
			try {
				if (!($c instanceof \stdClass)) {
					throw ApiException::invalid('Each cost position must be a JSON object.');
				}
				self::checkOne($c);
				if (isset($seen[$c->no])) {
					throw ApiException::invalid(Message::of('“{field}” must be unique within the budget.', ['field' => 'no']));
				}
				$seen[$c->no] = true;
			} catch (ApiException $e) {
				throw ApiException::invalid(Message::of('Cost position {n}: {message}', ['n' => $i + 1, 'message' => $e->getText()]));
			}
		}
	}

	/** One cost position; a missing `rule` of third-party costs and a missing `mode` are set (receipts, rebill). */
	private static function checkOne(\stdClass $c): void {
		$no = $c->no ?? null;
		if (!is_int($no) || $no < 1) {
			throw ApiException::invalid(Message::of('The field “{field}” must be an integer ≥ 1.', ['field' => 'no']));
		}
		$name = $c->name ?? null;
		if (!is_string($name) || trim($name) === '' || mb_strlen($name) > self::NAME_MAX || preg_match(self::CONTROL, $name) || !mb_check_encoding($name, 'UTF-8')) {
			throw ApiException::invalid(Message::of('“{field}” must be text of 1 to {max} characters.', ['field' => 'name', 'max' => self::NAME_MAX]));
		}
		$kind = $c->kind ?? null;
		if (!in_array($kind, self::KINDS, true)) {
			throw self::oneOf('kind', self::KINDS);
		}
		$rule = $c->rule ?? null;
		if ($kind === self::THIRD_PARTY) {
			if ($rule !== null && $rule !== self::RECEIPTS) {
				throw self::oneOf('rule', [self::RECEIPTS]);
			}
			$c->rule = $rule = self::RECEIPTS;
		} elseif (!in_array($rule, self::RULES, true)) {
			throw self::oneOf('rule', self::RULES);
		}
		self::percent($c, 'percent', $rule === self::PERCENT);
		$amount = $c->amount ?? null;
		if (($amount !== null && !ProjectRules::isRate($amount)) || ($rule === self::FLAT && $amount === null)) {
			throw ApiException::invalid(Message::of('“{field}” must be a number ≥ 0.', ['field' => 'amount']));
		}
		$per = $c->per ?? null;
		if (($per !== null && !in_array($per, self::PER, true)) || ($rule === self::FLAT && $per === null)) {
			throw self::oneOf('per', self::PER);
		}
		$cap = $c->cap ?? null;
		if (($cap !== null && !ProjectRules::isRate($cap)) || ($rule === self::RECEIPTS && $cap === null)) {
			throw ApiException::invalid(Message::of('“{field}” must be a number ≥ 0.', ['field' => 'cap']));
		}
		$mode = $c->mode ?? null;
		if ($mode !== null && !in_array($mode, self::MODES, true)) {
			throw self::oneOf('mode', self::MODES);
		}
		$c->mode = $mode ?? self::REBILL;
		// Markup and a rate of its own only for re-billed costs; a pass-through item is billed as it is.
		foreach (['markup', 'vat'] as $f) {
			if ($c->mode === self::PASS_THROUGH && ($c->{$f} ?? null) !== null) {
				throw ApiException::invalid(Message::of('“{field}” applies only when “{other}” is “{value}”.', ['field' => $f, 'other' => 'mode', 'value' => self::REBILL]));
			}
			self::percent($c, $f, false);
		}
		// 0.26.3: the code of the work package the position belongs to (the client's code logic); null or short text.
		$code = $c->code ?? null;
		if ($code !== null && (!is_string($code) || trim($code) === '' || mb_strlen($code) > self::CODE_MAX || preg_match(self::CONTROL, $code) || !mb_check_encoding($code, 'UTF-8'))) {
			throw ApiException::invalid(Message::of('“{field}” must be null or text of 1 to {max} characters.', ['field' => 'code', 'max' => self::CODE_MAX]));
		}
		// 0.26.0: the forecast per milestone, at most 200 amounts ≥ 0 (its sum is the cap; the client sets both).
		$f = $c->forecast ?? null;
		if ($f !== null) {
			if (!is_array($f) || !array_is_list($f) || count($f) > self::FORECAST_MAX) {
				throw ApiException::invalid(Message::of('“{field}” must be a list of at most {limit} entries.', ['field' => 'forecast', 'limit' => self::FORECAST_MAX]));
			}
			foreach ($f as $x) {
				if (!ProjectRules::isRate($x)) {
					throw ApiException::invalid(Message::of('“{field}” must be a list of numbers ≥ 0.', ['field' => 'forecast']));
				}
			}
		}
		$d = $c->description ?? null;
		if ($d !== null && (!is_string($d) || mb_strlen($d) > self::DESCRIPTION_MAX || preg_match(self::CONTROL_TEXT, $d) || !mb_check_encoding($d, 'UTF-8'))) {
			throw ApiException::invalid(Message::of('“{field}” must be text of at most {max} characters.', ['field' => 'description', 'max' => self::DESCRIPTION_MAX]));
		}
	}

	/** A percentage 0–100 (null allowed unless required). */
	private static function percent(\stdClass $c, string $field, bool $required): void {
		$v = $c->{$field} ?? null;
		if ($v === null && !$required) {
			return;
		}
		if (!ProjectRules::isRate($v) || $v > 100) {
			throw ApiException::invalid(Message::of('“{field}” must be a percentage from 0 to 100.', ['field' => $field]));
		}
	}

	/** @param list<string> $values */
	private static function oneOf(string $field, array $values): ApiException {
		return ApiException::invalid(Message::of('“{field}” must be one of {values}.', ['field' => $field, 'values' => implode(', ', $values)]));
	}
}
