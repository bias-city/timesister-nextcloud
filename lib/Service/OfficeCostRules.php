<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The record `setting/office_costs` (0.27.0, controlling C5): the team's
 * fixed costs, account balances and minimum reserve for the cashflow of
 * the Mac app. Team Admins write and read it; Leads and members never
 * read it (see RecordRights). Pure.
 *
 * `costs[]` {id, name, amount, due_day 1–31, rhythm, from, to?, kind,
 * note?, month13? 1–12, percent? 0–100}, `balances[]` {date, amount,
 * note?}, `reserve` (number ≥ 0 or null), `schema` (integer).
 */
final class OfficeCostRules {
	public const KEY = 'office_costs';
	public const RHYTHMS = ['monthly', 'quarterly', 'half_yearly', 'yearly', 'once'];
	public const KINDS = ['rent', 'insurance', 'licenses', 'salaries', 'social', 'vat', 'other'];
	public const COSTS_MAX = 200;
	public const BALANCES_MAX = 500;
	public const NAME_MAX = 120;
	public const NOTE_MAX = 500;
	private const COST_FIELDS = ['id', 'name', 'amount', 'due_day', 'rhythm', 'from', 'to', 'kind', 'note', 'month13', 'percent'];
	private const BALANCE_FIELDS = ['date', 'amount', 'note'];

	/** @throws ApiException 422 */
	public static function validate(\stdClass $data): void {
		foreach (get_object_vars($data) as $key => $value) {
			switch ($key) {
				case 'schema':
					if (!is_int($value)) {
						throw ApiException::invalid('“schema” must be an integer.');
					}
					break;
				case 'reserve':
					if ($value !== null && (!self::isNumber($value) || $value < 0)) {
						throw ApiException::invalid(Message::of('“{field}” must be null or a number from 0.', ['field' => 'reserve']));
					}
					break;
				case 'costs':
					self::checkList($key, $value, self::COSTS_MAX);
					foreach ($value as $i => $cost) {
						self::validateCost($cost, $i);
					}
					break;
				case 'balances':
					self::checkList($key, $value, self::BALANCES_MAX);
					foreach ($value as $i => $balance) {
						self::validateBalance($balance, $i);
					}
					break;
				default:
					throw ApiException::invalid(Message::of('Unknown office costs field “{field}”.', ['field' => $key]));
			}
		}
	}

	private static function isNumber(mixed $v): bool {
		return (is_int($v) || is_float($v)) && is_finite((float)$v);
	}

	private static function checkList(string $field, mixed $value, int $max): void {
		if (!is_array($value) || !array_is_list($value) || count($value) > $max) {
			throw ApiException::invalid(Message::of('“{field}” must be a list of at most {limit} entries.', ['field' => $field, 'limit' => $max]));
		}
	}

	private static function checkText(string $field, mixed $v, int $max, bool $required): void {
		if ($v === null && !$required) {
			return;
		}
		if (!is_string($v) || ($required && trim($v) === '') || mb_strlen($v) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v)) {
			throw ApiException::invalid(Message::of('“{field}” must be text of at most {max} characters.', ['field' => $field, 'max' => $max]));
		}
	}

	private static function checkDay(string $field, mixed $v, bool $required): void {
		if ($v === null && !$required) {
			return;
		}
		if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) {
			throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => $field]));
		}
	}

	private static function validateCost(mixed $c, int $i): void {
		$p = "costs[$i]";
		if (!($c instanceof \stdClass)) {
			throw ApiException::invalid(Message::of('“{field}” must be an object.', ['field' => $p]));
		}
		foreach (array_keys(get_object_vars($c)) as $k) {
			if (!in_array($k, self::COST_FIELDS, true)) {
				throw ApiException::invalid(Message::of('Unknown office costs field “{field}”.', ['field' => "$p.$k"]));
			}
		}
		self::checkText("$p.id", $c->id ?? null, 64, false);
		self::checkText("$p.name", $c->name ?? null, self::NAME_MAX, true);
		if (!self::isNumber($c->amount ?? null) || $c->amount < 0) {
			throw ApiException::invalid(Message::of('“{field}” must be null or a number from 0.', ['field' => "$p.amount"]));
		}
		$d = $c->due_day ?? 1;
		if (!is_int($d) || $d < 1 || $d > 31) {
			throw ApiException::invalid(Message::of('“{field}” must be an integer from {min} to {max}.', ['field' => "$p.due_day", 'min' => 1, 'max' => 31]));
		}
		if (!in_array($c->rhythm ?? null, self::RHYTHMS, true)) {
			throw ApiException::invalid(Message::of('“rhythm” of a cost line is one of {values}.', ['values' => implode(', ', self::RHYTHMS)]));
		}
		if (!in_array($c->kind ?? null, self::KINDS, true)) {
			throw ApiException::invalid(Message::of('“kind” of a cost line is one of {values}.', ['values' => implode(', ', self::KINDS)]));
		}
		self::checkDay("$p.from", $c->from ?? null, true);
		self::checkDay("$p.to", $c->to ?? null, false);
		if (isset($c->to) && $c->to < $c->from) {
			throw ApiException::invalid(Message::of('“{field}” must be a day on or after “from”.', ['field' => "$p.to"]));
		}
		self::checkText("$p.note", $c->note ?? null, self::NOTE_MAX, false);
		$m = $c->month13 ?? null;
		if ($m !== null && (!is_int($m) || $m < 1 || $m > 12)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or an integer from {min} to {max}.', ['field' => "$p.month13", 'min' => 1, 'max' => 12]));
		}
		$pc = $c->percent ?? null;
		if ($pc !== null && (!self::isNumber($pc) || $pc < 0 || $pc > 100)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or a number from 0 to 100.', ['field' => "$p.percent"]));
		}
	}

	private static function validateBalance(mixed $b, int $i): void {
		$p = "balances[$i]";
		if (!($b instanceof \stdClass)) {
			throw ApiException::invalid(Message::of('“{field}” must be an object.', ['field' => $p]));
		}
		foreach (array_keys(get_object_vars($b)) as $k) {
			if (!in_array($k, self::BALANCE_FIELDS, true)) {
				throw ApiException::invalid(Message::of('Unknown office costs field “{field}”.', ['field' => "$p.$k"]));
			}
		}
		self::checkDay("$p.date", $b->date ?? null, true);
		if (!self::isNumber($b->amount ?? null)) {
			throw ApiException::invalid(Message::of('“{field}” must be a number.', ['field' => "$p.amount"]));
		}
		self::checkText("$p.note", $b->note ?? null, self::NOTE_MAX, false);
	}
}
