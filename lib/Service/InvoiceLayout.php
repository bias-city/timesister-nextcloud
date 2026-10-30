<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The layout of an invoice document (0.26.0), pure: the positions flat or
 * grouped by the level of their work packages (`path` of each hours
 * position), and appended tables. Since 0.32.0 (`invoice_layout: 2`) six
 * appendices – `levels`, `tags`, `budget`, `aps`, `companies`, `rates` –,
 * since 0.33.0 also `by_level` (hours and amount per level of the team's
 * list, beside `levels`, the table per level of the package tree) –
 * and every table comes from the client: the server checks the form only
 * (lists of at most 500 objects, strings of at most 500 characters, finite
 * numbers), freezes the layout with the invoice and renders nothing.
 */
final class InvoiceLayout {
	public const FIELD = 'layout';
	public const GROUPINGS = ['flat', 'level'];
	public const APPENDIX = ['levels', 'tags', 'budget', 'aps', 'companies', 'rates', 'by_level'];
	/** The tables the client sends as lists of objects (the budget is one object). */
	public const TABLES = ['tags', 'aps', 'companies', 'rates', 'by_level'];
	public const ROWS_MAX = 500;
	public const PATH_MAX = 10;
	public const TEXT_MAX = 500;
	public const TAGS_MAX = 20;
	public const TAG_MAX = 60;
	private const CONTROL = '/[\x00-\x1F\x7F]/';

	/** The layout of a draft: null or `{grouping, appendix[], levels[]?, budget?}`, normalised; 422 naming the field. */
	public static function check(mixed $l): ?\stdClass {
		if ($l === null) {
			return null;
		}
		if (!($l instanceof \stdClass)) {
			throw ApiException::invalid('“layout” must be null or an object.');
		}
		$g = $l->grouping ?? 'flat';
		if (!in_array($g, self::GROUPINGS, true)) {
			throw ApiException::invalid(Message::of('“{field}” must be one of {values}.', ['field' => 'layout.grouping', 'values' => implode(', ', self::GROUPINGS)]));
		}
		$a = $l->appendix ?? [];
		if (!is_array($a) || !array_is_list($a) || array_diff($a, self::APPENDIX) !== []) {
			throw ApiException::invalid(Message::of('“{field}” must be a list of {values}.', ['field' => 'layout.appendix', 'values' => implode(', ', self::APPENDIX)]));
		}
		$out = new \stdClass();
		$out->grouping = $g;
		$out->appendix = array_values(array_unique($a));
		$rows = $l->levels ?? null;
		if ($rows !== null) {
			if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::ROWS_MAX) {
				throw ApiException::invalid(Message::of('“{field}” must be a list of at most {limit} entries.', ['field' => 'layout.levels', 'limit' => self::ROWS_MAX]));
			}
			$out->levels = array_map(static fn (mixed $r): \stdClass => self::row($r, 'layout.levels'), $rows);
		}
		$b = $l->budget ?? null;
		if ($b !== null) {
			$out->budget = self::figures($b, 'layout.budget');
		}
		// 0.32.0: the other tables as the client computed them, form only.
		foreach (self::TABLES as $t) {
			$rows = $l->{$t} ?? null;
			if ($rows !== null) {
				$out->{$t} = self::table($rows, 'layout.' . $t);
			}
		}
		return $out;
	}

	/** A list of at most 500 flat objects whose strings are short and whose numbers are finite. @return list<\stdClass> */
	private static function table(mixed $rows, string $field): array {
		if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::ROWS_MAX) {
			throw ApiException::invalid(Message::of('“{field}” must be a list of at most {limit} entries.', ['field' => $field, 'limit' => self::ROWS_MAX]));
		}
		$out = [];
		foreach ($rows as $r) {
			if (!($r instanceof \stdClass)) {
				throw ApiException::invalid(Message::of('Each entry of “{field}” must be an object.', ['field' => $field]));
			}
			$row = new \stdClass();
			foreach (get_object_vars($r) as $k => $v) {
				$ok = $v === null || is_bool($v) || is_int($v) || (is_float($v) && is_finite($v))
					|| (is_string($v) && mb_strlen($v) <= self::TEXT_MAX && preg_match(self::CONTROL, $v) !== 1 && mb_check_encoding($v, 'UTF-8'));
				if (!$ok || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $k) !== 1) {
					throw ApiException::invalid(Message::of('“{field}” takes flat objects with texts of at most {max} characters and finite numbers.', ['field' => $field, 'max' => self::TEXT_MAX]));
				}
				$row->{$k} = is_float($v) ? round($v, 4) : $v;
			}
			$out[] = $row;
		}
		return $out;
	}

	private static function row(mixed $r, string $field): \stdClass {
		if (!($r instanceof \stdClass)) {
			throw ApiException::invalid(Message::of('Each entry of “{field}” must be an object.', ['field' => $field]));
		}
		$title = $r->title ?? null;
		if (!is_string($title) || trim($title) === '' || mb_strlen($title) > self::TEXT_MAX || preg_match(self::CONTROL, $title) === 1) {
			throw ApiException::invalid(Message::of('“{field}” must be text of 1 to {max} characters.', ['field' => $field . '.title', 'max' => self::TEXT_MAX]));
		}
		$f = self::figures($r, $field);
		$f->title = trim($title);
		return $f;
	}

	/** `{target, before, this}`: numbers (target may be null). */
	private static function figures(mixed $r, string $field): \stdClass {
		if (!($r instanceof \stdClass)) {
			throw ApiException::invalid(Message::of('“{field}” must be an object.', ['field' => $field]));
		}
		$out = new \stdClass();
		foreach (['target', 'before', 'this'] as $k) {
			$v = $r->{$k} ?? null;
			if ($v !== null && !(is_int($v) || (is_float($v) && is_finite($v)))) {
				throw ApiException::invalid(Message::of('“{field}” must be null or a number.', ['field' => $field . '.' . $k]));
			}
			$out->{$k} = $v === null ? null : round((float)$v, 2);
		}
		return $out;
	}

	/** A position's `path` (the titles of the levels above, at most 10) and `tags` (at most 20), stored trimmed. */
	public static function checkPosition(\stdClass $p): void {
		foreach (['path' => [self::PATH_MAX, self::TEXT_MAX], 'tags' => [self::TAGS_MAX, self::TAG_MAX]] as $f => [$n, $max]) {
			$v = $p->{$f} ?? null;
			if ($v === null) {
				continue;
			}
			$ok = is_array($v) && array_is_list($v) && count($v) <= $n;
			$out = [];
			foreach ($ok ? $v : [] as $x) {
				$t = is_string($x) ? trim($x) : '';
				if ($t === '' || mb_strlen($t) > $max || preg_match(self::CONTROL, $t) === 1) {
					$ok = false;
					break;
				}
				$out[] = $t;
			}
			if (!$ok) {
				throw ApiException::invalid(Message::of('“{field}” must be null or a list of at most {n} texts of 1 to {max} characters.', ['field' => $f, 'n' => $n, 'max' => $max]));
			}
			$p->{$f} = $out;
		}
	}
}
