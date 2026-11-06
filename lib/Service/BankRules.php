<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The record kind `bank` (0.40.0, Team Admins only), pure. Three kinds of
 * key: `<account>.<year>` holds the entries of a bank account in a year
 * (written only through `POST /bank/entries`, an upsert per entry key),
 * `rules` the team salt and the rules per counterparty, `plan.<YYYY-MM>`
 * the planned figures of a month (the first writer wins: version 0).
 *
 * An entry is `{key, account, source, date, amount, currency?, orig_amount?,
 * orig_currency?, charges?, reversal?, code?, category?, ignored?, party?,
 * party_fp?, text?, links[]?, cost_line?, rule?, parts?, note?, confirmed?,
 * by?, at?}` – no addresses, no IBAN of the counterparty, no file.
 * `diff` and `state` are computed by the app, never stored.
 */
final class BankRules {
	public const KIND = 'bank';
	public const RULES = 'rules';
	public const PLAN = 'plan.';
	public const CATEGORIES = ['receivables', 'other_in', 'salaries', 'social', 'rent', 'insurance', 'licenses', 'vat', 'costs', 'expenses', 'bank', 'other_out', 'internal'];
	public const SOURCES = ['camt', 'csv', 'manual'];
	public const LINK_KINDS = ['invoice', 'receipt', 'expense_report'];
	public const DIRECTIONS = ['debit', 'credit'];
	public const ACCOUNT_PATTERN = '/^(?!rules$|plan$)[a-z0-9][a-z0-9-]{0,31}$/';
	public const ENTRIES_MAX = 2000;
	public const RULES_MAX = 500;
	public const LINKS_MAX = 50;
	public const MAX_BYTES = 786432;
	private const FIELDS = ['key', 'account', 'source', 'date', 'amount', 'currency', 'orig_amount', 'orig_currency', 'charges', 'reversal',
		'code', 'category', 'ignored', 'party', 'party_fp', 'text', 'links', 'cost_line', 'rule', 'parts', 'note', 'confirmed', 'by', 'at'];
	private const TEXTS = ['party' => 120, 'text' => 140, 'note' => 200, 'cost_line' => 64, 'rule' => 64, 'by' => 64, 'at' => 64];
	private const CONTROL = '/[\x00-\x1F\x7F]/';

	/** `<account>.<year>`: the account and the year, else null. @return ?array{0:string,1:int} */
	public static function entriesKey(string $key): ?array {
		if (preg_match('/^([a-z0-9][a-z0-9-]{0,31})\.(\d{4})$/', $key, $m) !== 1 || preg_match(self::ACCOUNT_PATTERN, $m[1]) !== 1) {
			return null;
		}
		return [$m[1], (int)$m[2]];
	}

	/** Bank entries change only through `POST /bank/entries` (409 bank_entries_only). */
	public static function requireClientWrite(string $kind, string $key): void {
		if ($kind === self::KIND && self::entriesKey($key) !== null) {
			throw ApiException::conflict('Bank entries change only through POST /bank/entries.')->code(ErrorCode::BANK_ENTRIES_ONLY);
		}
	}

	/** Team Admins only (403 bank_admin_only). */
	public static function requireAdmin(Membership $m): void {
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins read and write bank entries.')->code(ErrorCode::BANK_ADMIN_ONLY);
		}
	}

	/** A record `bank/<key>`: entries of an account and year, the rules or a month's plan. @throws ApiException 422 */
	public static function validate(string $key, \stdClass $d): void {
		$ek = self::entriesKey($key);
		if ($ek !== null) {
			self::validateYear($ek[0], $ek[1], $d);
		} elseif ($key === self::RULES) {
			self::validateRules($d);
		} elseif (str_starts_with($key, self::PLAN)) {
			self::validatePlan(substr($key, strlen(self::PLAN)), $d);
		} else {
			throw ApiException::invalid('A bank record is “rules”, “plan.<YYYY-MM>” or “<account>.<year>”.');
		}
	}

	/** `{schema, account, year, entries[]}`: every entry of this account and year, each key once. */
	public static function validateYear(string $account, int $year, \stdClass $d): void {
		if (($d->account ?? null) !== $account || ($d->year ?? null) !== $year) {
			throw ApiException::invalid('“account” and “year” must match the key.');
		}
		$list = $d->entries ?? null;
		if (!is_array($list) || !array_is_list($list) || count($list) > self::ENTRIES_MAX) {
			throw ApiException::invalid(Message::of('“entries” must be a list of at most {max} bank entries.', ['max' => self::ENTRIES_MAX]))->field('entries');
		}
		$seen = [];
		foreach ($list as $e) {
			self::validateEntry($e, $account);
			if ((int)substr((string)$e->date, 0, 4) !== $year) {
				throw ApiException::invalid('An entry belongs to the year of its date.')->field('date');
			}
			if (isset($seen[$e->key])) {
				throw ApiException::invalid('Every entry key once.')->field('key');
			}
			$seen[$e->key] = true;
		}
	}

	/** One entry of `account` (the field that fails is named). */
	public static function validateEntry(mixed $e, string $account): void {
		if (!($e instanceof \stdClass)) {
			throw ApiException::invalid('An entry must be an object.')->field('entries');
		}
		foreach (array_keys(get_object_vars($e)) as $k) {
			if (!in_array($k, self::FIELDS, true)) {
				throw ApiException::invalid(Message::of('Unknown field of a bank entry “{field}”.', ['field' => $k]))->field($k);
			}
		}
		$key = $e->key ?? null;
		if (!is_string($key)) {
			throw ApiException::invalidField('key');
		}
		if (!str_starts_with($key, $account . '/') || strlen($key) <= strlen($account) + 1 || mb_strlen($key) > 160 || preg_match(self::CONTROL, $key) === 1) {
			throw ApiException::invalidField('key');
		}
		if (($e->account ?? null) !== $account) {
			throw ApiException::invalidField('account');
		}
		if (!in_array($e->source ?? 'manual', self::SOURCES, true)) {
			throw ApiException::invalidField('source');
		}
		$date = $e->date ?? null;
		if (!is_string($date) || !Time::isDay($date)) {
			throw ApiException::invalidField('date');
		}
		foreach (['amount' => true, 'orig_amount' => false, 'charges' => false] as $f => $required) {
			$v = $e->$f ?? null;
			if (($v === null && $required) || ($v !== null && (!self::isNumber($v) || abs((float)$v) >= 1e12 || ($f === 'charges' && $v < 0)))) {
				throw ApiException::invalidField($f);
			}
		}
		foreach (['currency', 'orig_currency'] as $f) {
			$v = $e->$f ?? null;
			if ($v !== null && $v !== '' && (!is_string($v) || preg_match('/^[A-Z]{3}$/', $v) !== 1)) {
				throw ApiException::invalidField($f);
			}
		}
		if ((($e->orig_amount ?? null) === null) !== (($e->orig_currency ?? null) === null)) {
			throw ApiException::invalidField('orig_amount');
		}
		$code = $e->code ?? null;
		if ($code !== null && (!is_string($code) || preg_match('#^[A-Za-z0-9/]{1,40}$#', $code) !== 1)) {
			throw ApiException::invalidField('code');
		}
		$cat = $e->category ?? null;
		if ($cat !== null && !in_array($cat, self::CATEGORIES, true)) {
			throw ApiException::invalidField('category');
		}
		foreach (['reversal', 'ignored', 'confirmed'] as $f) {
			if (($e->$f ?? null) !== null && !is_bool($e->$f)) {
				throw ApiException::invalidField($f);
			}
		}
		$links = $e->links ?? [];
		if (($e->ignored ?? false) === true && ($cat !== null || $links !== [])) {
			throw ApiException::invalid('An ignored entry has no category and no links.')->field('ignored');
		}
		foreach (self::TEXTS as $f => $max) {
			$v = $e->$f ?? null;
			if ($v !== null && (!is_string($v) || mb_strlen($v) > $max || preg_match(self::CONTROL, $v) || !mb_check_encoding($v, 'UTF-8'))) {
				throw ApiException::invalidField($f);
			}
		}
		$fp = $e->party_fp ?? null;
		if ($fp !== null && (!is_string($fp) || preg_match('/^[0-9a-f]{64}$/', $fp) !== 1)) {
			throw ApiException::invalidField('party_fp');
		}
		$parts = $e->parts ?? null;
		if ($parts !== null && (!is_int($parts) || $parts < 1)) {
			throw ApiException::invalidField('parts');
		}
		if (!is_array($links) || !array_is_list($links) || count($links) > self::LINKS_MAX) {
			throw ApiException::invalidField('links');
		}
		foreach ($links as $l) {
			if (!($l instanceof \stdClass) || !in_array($l->kind ?? null, self::LINK_KINDS, true) || !is_string($l->id ?? null)
				|| !RecordValidator::isKey($l->id) || !self::isNumber($l->amount ?? null) || $l->amount <= 0) {
				throw ApiException::invalidField('links');
			}
		}
	}

	/** `{schema, salt, rules[]}`: the team salt (≥ 32 hex) and per counterparty and direction one target. */
	public static function validateRules(\stdClass $d): void {
		$salt = $d->salt ?? null;
		if (!is_string($salt) || preg_match('/^[0-9a-f]{32,128}$/', $salt) !== 1) {
			throw ApiException::invalidField('salt');
		}
		$rules = $d->rules ?? [];
		if (!is_array($rules) || !array_is_list($rules) || count($rules) > self::RULES_MAX) {
			throw ApiException::invalidField('rules');
		}
		$seen = [];
		foreach ($rules as $r) {
			if (!($r instanceof \stdClass) || !is_string($r->fp ?? null) || preg_match('/^[0-9a-f]{64}$/', $r->fp) !== 1
				|| !in_array($r->direction ?? null, self::DIRECTIONS, true)) {
				throw ApiException::invalidField('rules');
			}
			$cat = $r->category ?? null;
			$ignored = ($r->ignored ?? false) === true;
			$line = $r->cost_line ?? null;
			$ok = $ignored ? ($cat === null && $line === null) : in_array($cat, self::CATEGORIES, true);
			if (!$ok || ($line !== null && (!is_string($line) || mb_strlen($line) > 64))) {
				throw ApiException::invalid('A bank rule names a category (with a cost line) or is ignored.')->field('rules');
			}
			$id = $r->fp . '/' . $r->direction;
			if (isset($seen[$id])) {
				throw ApiException::invalid('One rule per counterparty and direction.')->field('rules');
			}
			$seen[$id] = true;
		}
	}

	/** `{schema, month, made_on, currencies: {CHF: {category: amount}}}`. */
	public static function validatePlan(string $month, \stdClass $d): void {
		if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1 || ($d->month ?? null) !== $month) {
			throw ApiException::invalidField('month');
		}
		$made = $d->made_on ?? null;
		if (!is_string($made) || !Time::isDay($made)) {
			throw ApiException::invalidField('made_on');
		}
		$cur = $d->currencies ?? null;
		if (!($cur instanceof \stdClass) || count(get_object_vars($cur)) > 10) {
			throw ApiException::invalidField('currencies');
		}
		foreach (get_object_vars($cur) as $c => $values) {
			if (preg_match('/^[A-Z]{3}$/', $c) !== 1 || !($values instanceof \stdClass)) {
				throw ApiException::invalidField('currencies');
			}
			foreach (get_object_vars($values) as $k => $v) {
				if (!in_array($k, self::CATEGORIES, true) || !self::isNumber($v)) {
					throw ApiException::invalidField('currencies');
				}
			}
		}
	}

	/**
	 * **Upsert per key**: the incoming entry replaces the stored one – unless
	 * the stored one is confirmed and the incoming not. Returns the entry to
	 * keep and what happened: `new`, `changed`, `same`, `kept`.
	 *
	 * @return array{0:\stdClass,1:string}
	 */
	public static function merge(?\stdClass $stored, \stdClass $incoming): array {
		if ($stored === null) {
			return [$incoming, 'new'];
		}
		if (($stored->confirmed ?? false) === true && ($incoming->confirmed ?? false) !== true) {
			return [$stored, 'kept'];
		}
		return [$incoming, Json::encode($stored) === Json::encode($incoming) ? 'same' : 'changed'];
	}

	/**
	 * Merge a list of incoming entries into the stored year record.
	 *
	 * @param list<\stdClass> $incoming
	 * @return array{record:\stdClass,saved:int,unchanged:int,kept:list<string>}
	 */
	public static function mergeYear(?\stdClass $stored, string $account, int $year, array $incoming): array {
		$byKey = [];
		$list = $stored?->entries ?? null;
		foreach (is_array($list) ? $list : [] as $e) {
			if ($e instanceof \stdClass && is_string($e->key ?? null)) {
				$byKey[(string)$e->key] = $e;
			}
		}
		$saved = 0;
		$unchanged = 0;
		$kept = [];
		foreach ($incoming as $e) {
			$k = (string)$e->key;
			[$keep, $what] = self::merge($byKey[$k] ?? null, $e);
			$byKey[$k] = $keep;
			if ($what === 'kept') {
				$kept[] = $k;
			} elseif ($what === 'same') {
				$unchanged++;
			} else {
				$saved++;
			}
		}
		$entries = array_values($byKey);
		usort($entries, static fn (\stdClass $a, \stdClass $b): int => [(string)$a->date, (string)$a->key] <=> [(string)$b->date, (string)$b->key]);
		$out = new \stdClass();
		$out->schema = 1;
		$out->account = $account;
		$out->year = $year;
		$out->entries = $entries;
		return ['record' => $out, 'saved' => $saved, 'unchanged' => $unchanged, 'kept' => $kept];
	}

	public static function isNumber(mixed $v): bool {
		return (is_int($v) || is_float($v)) && is_finite((float)$v);
	}
}
