<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The layout of an invoice document (0.26.0), pure: the positions flat or
 * grouped by the level of their work packages (`path` of each hours
 * position), and appended tables – per level against the budget, per tag,
 * the state of the budget. The client computes the figures from the
 * budget; the server freezes them with the invoice and draws them.
 */
final class InvoiceLayout {
	public const FIELD = 'layout';
	public const GROUPINGS = ['flat', 'level'];
	public const APPENDIX = ['levels', 'tags', 'budget'];
	public const ROWS_MAX = 200;
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

	/**
	 * The positions as drawn grouped by level: a heading where a level
	 * begins, a subtotal where a top level ends; positions without a path
	 * (flat fees, costs) follow ungrouped.
	 *
	 * @param list<array<string,mixed>> $positions
	 * @return list<array{type:string,title?:string,depth?:int,amount?:float,p?:array<string,mixed>}>
	 */
	public static function grouped(array $positions): array {
		$out = [];
		/** @var list<string> $open */
		$open = [];
		$sum = 0.0;
		$loose = [];
		foreach ($positions as $p) {
			$path = is_array($p['path'] ?? null) ? array_values(array_map('strval', $p['path'])) : [];
			if (($p['kind'] ?? '') !== 'hours' || $path === []) {
				$loose[] = $p;
				continue;
			}
			if ($open !== [] && $open[0] !== $path[0]) {
				$out[] = ['type' => 'sub', 'title' => $open[0], 'amount' => round($sum, 2)];
				$open = [];
				$sum = 0.0;
			}
			// The first level that differs from the open path begins the headings.
			$same = 0;
			while ($same < count($open) && $same < count($path) && $open[$same] === $path[$same]) {
				$same++;
			}
			for ($i = $same; $i < count($path); $i++) {
				$out[] = ['type' => 'head', 'title' => $path[$i], 'depth' => $i];
			}
			$open = $path;
			$sum += is_numeric($p['amount'] ?? null) ? (float)$p['amount'] : 0.0;
			$out[] = ['type' => 'pos', 'p' => $p, 'depth' => count($path)];
		}
		if ($open !== []) {
			$out[] = ['type' => 'sub', 'title' => $open[0], 'amount' => round($sum, 2)];
		}
		foreach ($loose as $p) {
			$out[] = ['type' => 'pos', 'p' => $p, 'depth' => 0];
		}
		return $out;
	}

	/**
	 * Hours and amount per tag of this invoice's positions; a position
	 * counts with each of its tags.
	 *
	 * @param list<array<string,mixed>> $positions
	 * @return list<array{tag:string,hours:float,amount:float}>
	 */
	public static function tagRows(array $positions): array {
		$rows = [];
		foreach ($positions as $p) {
			foreach (is_array($p['tags'] ?? null) ? $p['tags'] : [] as $tag) {
				$k = (string)$tag;
				$rows[$k] ??= ['tag' => $k, 'hours' => 0.0, 'amount' => 0.0];
				$rows[$k]['hours'] += is_numeric($p['hours'] ?? null) ? (float)$p['hours'] : 0.0;
				$rows[$k]['amount'] += is_numeric($p['amount'] ?? null) ? (float)$p['amount'] : 0.0;
			}
		}
		return array_values(array_map(static fn (array $r): array => ['tag' => $r['tag'], 'hours' => round($r['hours'], 2), 'amount' => round($r['amount'], 2)], $rows));
	}
}
