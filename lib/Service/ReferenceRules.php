<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The customer's references (0.47.0, PLAN-REFERENZEN), pure. A budget holds
 * `reference_blocks` – named sets of reference lines `{kind, title, value}`,
 * exactly one of them the default; work packages, cost positions and
 * addenda name theirs with `reference_block`. An invoice carries the block
 * it came from and its copy of the lines (`references`), frozen on issue.
 */
final class ReferenceRules {
	public const BLOCKS = 'reference_blocks';
	public const BLOCK = 'reference_block';
	public const LINES = 'references';
	public const KINDS = ['cost_center', 'contract', 'order', 'leitweg', 'tender', 'project_ref', 'other'];
	/** A line without a kind is a free one. */
	public const KIND_FREE = 'other';
	public const BLOCKS_MAX = 10;
	public const LINES_MAX = 6;
	public const ID_MAX = 40;
	public const NAME_MAX = 80;
	public const TITLE_MAX = 60;
	public const VALUE_MAX = 120;
	private const CONTROL = '/[\x00-\x1F\x7F]/';

	/**
	 * A budget's blocks and every `reference_block` in it – work packages,
	 * `costs[]`, `addenda[]`: each null or the id of one of its blocks.
	 *
	 * @throws ApiException 422 with the field
	 */
	public static function checkBudget(\stdClass $b): void {
		$ids = self::checkBlocks($b->{self::BLOCKS} ?? null);
		$wps = $b->work_packages ?? null;
		foreach (is_array($wps) ? $wps : [] as $wp) {
			if ($wp instanceof \stdClass && !self::names($wp->{self::BLOCK} ?? null, $ids)) {
				$no = is_string($wp->no ?? null) || is_int($wp->no ?? null) ? (string)$wp->no : '?';
				throw ApiException::invalid(Message::of('Work package {no}: “reference_block” must be null or the “id” of a reference block of this budget.', ['no' => $no]))->field(self::BLOCK);
			}
		}
		$costs = $b->{CostRules::FIELD} ?? null;
		foreach (is_array($costs) ? $costs : [] as $c) {
			if ($c instanceof \stdClass && !self::names($c->{self::BLOCK} ?? null, $ids)) {
				throw ApiException::invalid(Message::of('Cost position {no}: “reference_block” must be null or the “id” of a reference block of this budget.', ['no' => is_int($c->no ?? null) ? $c->no : '?']))->field(self::BLOCK);
			}
		}
		$addenda = $b->addenda ?? null;
		foreach (is_array($addenda) ? $addenda : [] as $a) {
			if ($a instanceof \stdClass && !self::names($a->{self::BLOCK} ?? null, $ids)) {
				throw ApiException::invalid(Message::of('Addendum {no}: “reference_block” must be null or the “id” of a reference block of this budget.', ['no' => is_int($a->no ?? null) ? $a->no : '?']))->field(self::BLOCK);
			}
		}
	}

	/**
	 * `reference_blocks`: null or at most 10 blocks `{id, name, default, lines}`,
	 * ids unique, exactly one default once there are blocks. The lines are
	 * stored trimmed, a missing kind as `other`.
	 *
	 * @return list<string> the ids
	 * @throws ApiException 422 (`field: reference_blocks`)
	 */
	public static function checkBlocks(mixed $list): array {
		if ($list === null) {
			return [];
		}
		if (!is_array($list) || !array_is_list($list) || count($list) > self::BLOCKS_MAX) {
			throw ApiException::invalid(Message::of('“{field}” must be null or a list of at most {max} reference blocks.', ['field' => self::BLOCKS, 'max' => self::BLOCKS_MAX]))->field(self::BLOCKS);
		}
		$ids = [];
		$defaults = 0;
		foreach ($list as $i => $block) {
			$n = $i + 1;
			if (!($block instanceof \stdClass)) {
				throw ApiException::invalid(Message::of('Reference block {n}: must be a JSON object.', ['n' => $n]))->field(self::BLOCKS);
			}
			$id = $block->id ?? null;
			if (!self::isId($id) || in_array($id, $ids, true)) {
				throw ApiException::invalid(Message::of('Reference block {n}: “id” must be text of 1 to {max} characters, unique within the budget.', ['n' => $n, 'max' => self::ID_MAX]))->field(self::BLOCKS);
			}
			$ids[] = $id;
			$name = $block->name ?? null;
			if ($name !== null && (!is_string($name) || mb_strlen($name) > self::NAME_MAX || preg_match(self::CONTROL, $name) === 1 || !mb_check_encoding($name, 'UTF-8'))) {
				throw ApiException::invalid(Message::of('Reference block {n}: “name” must be empty or text of at most {max} characters.', ['n' => $n, 'max' => self::NAME_MAX]))->field(self::BLOCKS);
			}
			$default = $block->default ?? false;
			if (!is_bool($default)) {
				throw ApiException::invalid(Message::of('Reference block {n}: “default” must be true or false.', ['n' => $n]))->field(self::BLOCKS);
			}
			$defaults += $default ? 1 : 0;
			$lines = $block->lines ?? null;
			if (!is_array($lines) || !array_is_list($lines) || $lines === [] || count($lines) > self::LINES_MAX) {
				throw ApiException::invalid(Message::of('Reference block {n}: “lines” must be a list of 1 to {max} reference lines.', ['n' => $n, 'max' => self::LINES_MAX]))->field(self::BLOCKS);
			}
			try {
				$block->lines = self::checkLines($lines);
			} catch (ApiException $e) {
				throw ApiException::invalid(Message::of('Reference block {n}: {message}', ['n' => $n, 'message' => $e->getText()]))->field(self::BLOCKS);
			}
		}
		if ($ids !== [] && $defaults !== 1) {
			throw ApiException::invalid('Exactly one reference block of a budget is the default.')->field(self::BLOCKS);
		}
		return $ids;
	}

	/** An invoice's `reference_block`: null, empty or text of 1–40 characters (not checked against the budget). */
	public static function checkBlockId(mixed $v): void {
		if ($v !== null && $v !== '' && !self::isId($v)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or text of 1 to {max} characters.', ['field' => self::BLOCK, 'max' => self::ID_MAX]))->field(self::BLOCK);
		}
	}

	/**
	 * `references` of an invoice or an issued quote: null or at most six lines.
	 *
	 * @return list<\stdClass>|null the lines, trimmed
	 * @throws ApiException 422 (`field: references`)
	 */
	public static function references(mixed $v): ?array {
		if ($v === null) {
			return null;
		}
		if (!is_array($v) || !array_is_list($v) || count($v) > self::LINES_MAX) {
			throw ApiException::invalid(Message::of('“{field}” must be null or a list of at most {max} reference lines.', ['field' => self::LINES, 'max' => self::LINES_MAX]))->field(self::LINES);
		}
		try {
			return self::checkLines($v);
		} catch (ApiException $e) {
			throw $e->field(self::LINES);
		}
	}

	/**
	 * The lines as frozen – only `kind`, `title` and `value` – or null when there are none.
	 *
	 * @return list<array{kind:string,title:string,value:string}>|null
	 */
	public static function frozen(mixed $v): ?array {
		$out = [];
		foreach (is_array($v) ? $v : [] as $line) {
			if ($line instanceof \stdClass) {
				$line = get_object_vars($line);
			}
			if (is_array($line) && is_string($line['kind'] ?? null) && is_string($line['title'] ?? null) && is_string($line['value'] ?? null)) {
				$out[] = ['kind' => $line['kind'], 'title' => $line['title'], 'value' => $line['value']];
			}
		}
		return $out === [] ? null : $out;
	}

	/**
	 * @param list<mixed> $lines
	 * @return list<\stdClass>
	 * @throws ApiException 422 naming the line
	 */
	private static function checkLines(array $lines): array {
		$out = [];
		foreach ($lines as $i => $line) {
			$n = $i + 1;
			if (!($line instanceof \stdClass)) {
				throw ApiException::invalid(Message::of('Reference line {n}: must be a JSON object.', ['n' => $n]));
			}
			$kind = $line->kind ?? null;
			if ($kind === null) {
				$line->kind = self::KIND_FREE;
			} elseif (!in_array($kind, self::KINDS, true)) {
				throw ApiException::invalid(Message::of('Reference line {n}: {message}', ['n' => $n, 'message' => Message::of('“{field}” must be one of {values}.', ['field' => 'kind', 'values' => implode(', ', self::KINDS)])]));
			}
			foreach ([['title', self::TITLE_MAX], ['value', self::VALUE_MAX]] as [$f, $max]) {
				$t = self::text($line->{$f} ?? null, $max);
				if ($t === null) {
					throw ApiException::invalid(Message::of('Reference line {n}: {message}', ['n' => $n, 'message' => Message::of('“{field}” must be text of 1 to {max} characters.', ['field' => $f, 'max' => $max])]));
				}
				$line->{$f} = $t;
			}
			$out[] = $line;
		}
		return $out;
	}

	/** Text of 1 to $max characters without control characters, trimmed; else null. */
	private static function text(mixed $v, int $max): ?string {
		if (!is_string($v) || !mb_check_encoding($v, 'UTF-8') || preg_match(self::CONTROL, $v) === 1) {
			return null;
		}
		$t = trim($v);
		return $t === '' || mb_strlen($t) > $max ? null : $t;
	}

	/** @psalm-assert-if-true string $v */
	private static function isId(mixed $v): bool {
		return is_string($v) && trim($v) !== '' && mb_strlen($v) <= self::ID_MAX && preg_match(self::CONTROL, $v) !== 1 && mb_check_encoding($v, 'UTF-8');
	}

	/** Null or empty (the default block, or the parent's), or the id of one of the blocks. @param list<string> $ids */
	private static function names(mixed $v, array $ids): bool {
		return $v === null || $v === '' || (is_string($v) && in_array($v, $ids, true));
	}
}
