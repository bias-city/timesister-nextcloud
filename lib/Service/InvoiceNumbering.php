<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The invoice number (0.16.0): the pattern in `setting/settings`
 * (`invoice_number`) and the team's counter. Pure. The server hands out
 * only the running number; the client fills the pattern.
 */
final class InvoiceNumbering {
	public const FIELD = 'invoice_number';
	public const PATTERN_MAX = 64;
	public const START_MAX = 999999999;
	public const YEAR_MIN = 1900;
	public const YEAR_MAX = 9999;
	/** Placeholders besides the number, English and German. */
	public const PLACEHOLDERS = ['year', 'jahr', 'yy', 'jj', 'project', 'projekt', 'customer', 'kunde'];
	/** The running number, with an optional width 1–8. */
	private const NUMBER = '/^(no|nr)(:[1-8])?$/';
	private const COUNTER = 'invoice';
	/** The number when the team set no pattern (0.18.0). */
	public const DEFAULT_PATTERN = '{year}-{no:4}';

	/** `invoice_number` of the settings record when it is written; null or missing passes. */
	public static function validate(mixed $v): void {
		if ($v === null) {
			return;
		}
		if (!($v instanceof \stdClass)) {
			throw ApiException::invalid(Message::of('“{field}” must be an object.', ['field' => self::FIELD]));
		}
		self::checkPattern($v->pattern ?? null);
		$perYear = $v->per_year ?? null;
		if ($perYear !== null && !is_bool($perYear)) {
			throw ApiException::notBool(self::FIELD . '.per_year');
		}
		$start = $v->start ?? null;
		if ($start !== null && (!is_int($start) || $start < 1 || $start > self::START_MAX)) {
			throw ApiException::invalid(Message::of('“{field}” must be an integer from 1 to {max}.', ['field' => self::FIELD . '.start', 'max' => self::START_MAX]));
		}
	}

	/** Exactly one number placeholder, only known placeholders, no stray braces. */
	public static function checkPattern(mixed $p): void {
		$field = self::FIELD . '.pattern';
		if (!is_string($p) || $p === '' || mb_strlen($p) > self::PATTERN_MAX || !mb_check_encoding($p, 'UTF-8')) {
			throw ApiException::invalid(Message::of('“{field}” must be text of 1 to {max} characters.', ['field' => $field, 'max' => self::PATTERN_MAX]));
		}
		if (preg_match('/[\x00-\x1F\x7F]/', $p)) {
			throw ApiException::invalidField($field);
		}
		preg_match_all('/\{([^{}]*)\}/', $p, $m);
		$numbers = 0;
		foreach ($m[1] as $name) {
			if (preg_match(self::NUMBER, $name)) {
				$numbers++;
			} elseif (!in_array($name, self::PLACEHOLDERS, true)) {
				throw ApiException::invalid(Message::of('Unknown placeholder {placeholder} in the invoice number pattern.', ['placeholder' => '{' . $name . '}']));
			}
		}
		$rest = (string)preg_replace('/\{[^{}]*\}/', '', $p);
		if (str_contains($rest, '{') || str_contains($rest, '}')) {
			throw ApiException::invalid('A brace in the invoice number pattern belongs to no placeholder.');
		}
		if ($numbers !== 1) {
			throw ApiException::invalid(Message::of('The invoice number pattern needs exactly one number placeholder: {placeholders}.', ['placeholders' => '{no}, {no:N}, {nr}, {nr:N} (N 1–8)']));
		}
	}

	/**
	 * The team's numbering from the settings record, lenient: what is
	 * missing or malformed takes the default (per year, from 1).
	 *
	 * @return array{per_year:bool,start:int}
	 */
	public static function of(mixed $settings): array {
		$v = $settings instanceof \stdClass ? ($settings->{self::FIELD} ?? null) : null;
		$perYear = $v instanceof \stdClass ? ($v->per_year ?? null) : null;
		$start = $v instanceof \stdClass ? ($v->start ?? null) : null;
		return [
			'per_year' => is_bool($perYear) ? $perYear : true,
			'start' => is_int($start) && $start >= 1 ? min($start, self::START_MAX) : 1,
		];
	}

	/** The pattern of the settings record, or null when none is set (lenient like {@see of()}). */
	public static function patternOf(mixed $settings): ?string {
		$v = $settings instanceof \stdClass ? ($settings->{self::FIELD} ?? null) : null;
		$p = $v instanceof \stdClass ? ($v->pattern ?? null) : null;
		return is_string($p) && $p !== '' ? $p : null;
	}

	/**
	 * Fills the pattern with the running number (0.18.0, issuing on the
	 * server): `{no}`/`{nr}` with an optional width, year, two-digit year,
	 * project and customer keys. Without a pattern: `{year}-{no:4}`. The
	 * result must fit the invoice number (64 characters).
	 */
	public static function fill(?string $pattern, int $no, int $year, string $project, ?string $customer): string {
		$out = (string)preg_replace_callback('/\{([^{}]*)\}/', static function (array $m) use ($no, $year, $project, $customer): string {
			$name = $m[1];
			if (preg_match(self::NUMBER, $name, $w)) {
				return isset($w[2]) ? str_pad((string)$no, (int)substr($w[2], 1), '0', STR_PAD_LEFT) : (string)$no;
			}
			return match ($name) {
				'year', 'jahr' => (string)$year,
				'yy', 'jj' => substr(str_pad((string)$year, 4, '0', STR_PAD_LEFT), -2),
				'project', 'projekt' => $project,
				'customer', 'kunde' => $customer ?? '',
				default => $m[0],
			};
		}, $pattern ?? self::DEFAULT_PATTERN);
		if ($out === '' || mb_strlen($out) > InvoiceRules::NUMBER_MAX) {
			throw ApiException::invalid(Message::of('The invoice number {number} is empty or longer than {max} characters; change the pattern.', ['number' => $out, 'max' => InvoiceRules::NUMBER_MAX]));
		}
		return $out;
	}

	/** The counter's name: one per year, or one for all years. */
	public static function counter(bool $perYear, int $year): string {
		return $perYear ? self::COUNTER . ':' . $year : self::COUNTER;
	}

	/** The next number after `$last` (null: none given yet) – never below `$start`. */
	public static function next(?int $last, int $start): int {
		return max(($last ?? 0) + 1, $start, 1);
	}

	/** The year of a request: an integer (or digits); missing: `$default`. */
	public static function year(mixed $v, int $default): int {
		if ($v === null || $v === '') {
			return $default;
		}
		if (is_string($v) && preg_match('/^\d{1,9}$/', $v)) {
			$v = (int)$v;
		}
		if (!is_int($v) || $v < self::YEAR_MIN || $v > self::YEAR_MAX) {
			throw ApiException::invalid(Message::of('“year” must be a year from {min} to {max}.', ['min' => self::YEAR_MIN, 'max' => self::YEAR_MAX]));
		}
		return $v;
	}
}
