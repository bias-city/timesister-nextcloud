<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Levels (0.27.0): the value of the work – Lead, Specialist, Staff. The
 * team setting `levels` lists them with default rates valid from a day on;
 * a person carries one level as a series `levels: [{from, level}]`; a
 * project overrides the rate per level in `level_rates: {id: rate}`.
 * Rates are read by Team Admins and Leads only ({@see RecordRights::shape()}).
 * Since 0.33.0 the one list of every fee model: a work package presets a
 * level (`category` model), positions and bookings name one. Pure: the
 * form, the level of a package, the rate of a level on a day and the
 * catalog's `booking_levels` – the server computes no money with it.
 */
final class LevelRules {
	/** On `setting/settings`: `[{id, name, rates: [{from, rate}]}]`. */
	public const FIELD = 'levels';
	/** On the person: `[{from, level}]`. */
	public const PERSON_FIELD = 'levels';
	/** On the project: `{"<level id>": rate}`. */
	public const PROJECT_FIELD = 'level_rates';
	public const MAX = 50;
	public const ID_MAX = 40;
	public const NAME_MAX = 60;
	/** 0.33.0: what the level stands for, e.g. from a rate template. */
	public const DESCRIPTION_MAX = 300;
	/** 0.33.0: derived in the booking catalog; a client's write is dropped. */
	public const CATALOG_FIELD = 'booking_levels';
	private const ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';

	/** A level id: 1–40 lower-case letters, digits or dashes. */
	public static function isId(mixed $v): bool {
		return is_string($v) && preg_match(self::ID_PATTERN, $v) === 1;
	}

	/** `null` or a level id (a package's, position's or booking's `level`), else 422 naming the field. */
	public static function checkId(mixed $v, string $field = 'level'): void {
		if ($v !== null && !self::isId($v)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or the id of a level (1 to {max} lower-case letters, digits or dashes).', ['field' => $field, 'max' => self::ID_MAX]))->field($field);
		}
	}

	/**
	 * The team's levels: null or a list of at most 50 `{id, name, rates}` –
	 * `id` lower case letters, digits and dashes (1–40), unique; `name` text
	 * of 1–60; `rates` missing, null or a series like `cost_rates`.
	 *
	 * @throws ApiException 422
	 */
	public static function validateSettings(mixed $levels): void {
		if ($levels === null) {
			return;
		}
		if (!is_array($levels) || !array_is_list($levels) || count($levels) > self::MAX) {
			throw ApiException::invalid(Message::of('“{field}” must be null or a list of at most {n} levels { id, name, rates }.', ['field' => self::FIELD, 'n' => self::MAX]));
		}
		$ids = [];
		foreach ($levels as $l) {
			$id = $l instanceof \stdClass ? ($l->id ?? null) : null;
			$name = $l instanceof \stdClass ? ($l->name ?? null) : null;
			if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
				throw ApiException::invalid(Message::of('Each level needs an “id” of 1 to {max} lower-case letters, digits or dashes.', ['max' => self::ID_MAX]));
			}
			if (!is_string($name) || trim($name) === '' || mb_strlen($name) > self::NAME_MAX || !mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
				throw ApiException::invalid(Message::of('Each level needs a “name” of 1 to {max} characters.', ['max' => self::NAME_MAX]));
			}
			$description = $l->description ?? null;
			if ($description !== null && (!is_string($description) || mb_strlen($description) > self::DESCRIPTION_MAX || !mb_check_encoding($description, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $description) === 1)) {
				throw ApiException::invalid(Message::of('A level’s “description” is missing, null or one line of at most {max} characters.', ['max' => self::DESCRIPTION_MAX]));
			}
			if (isset($ids[$id])) {
				throw ApiException::invalid(Message::of('“{field}” names the level {id} twice.', ['field' => self::FIELD, 'id' => $id]));
			}
			$ids[$id] = true;
			self::rates($l->rates ?? null, 'levels[].rates');
		}
	}

	/** A rate series `{from, rate}` per day, each day once; missing or null passes. */
	private static function rates(mixed $rates, string $field): void {
		if ($rates === null) {
			return;
		}
		if (!is_array($rates) || !array_is_list($rates)) {
			throw ApiException::invalid(Message::of('“{field}” must be a list of { from, rate }.', ['field' => $field]));
		}
		$seen = [];
		foreach ($rates as $r) {
			$from = $r instanceof \stdClass ? ($r->from ?? null) : null;
			if (!is_string($from) || !Time::isDay($from) || !ProjectRules::isRate($r->rate ?? null)) {
				throw ApiException::invalid(Message::of('Each entry of “{field}” needs a day “from” (YYYY-MM-DD) and a “rate” ≥ 0.', ['field' => $field]));
			}
			if (isset($seen[$from])) {
				throw ApiException::invalid(Message::of('“{field}” names the day {from} twice.', ['field' => $field, 'from' => $from]));
			}
			$seen[$from] = true;
		}
	}

	/**
	 * The person's level series: null or a list of `{from: YYYY-MM-DD, level: id}`
	 * with no day twice. The id is not checked against the settings – the
	 * clients resolve it, an unknown one counts as no level.
	 *
	 * @throws ApiException 422
	 */
	public static function validatePerson(mixed $levels): void {
		if ($levels === null) {
			return;
		}
		if (!is_array($levels) || !array_is_list($levels)) {
			throw ApiException::invalid(Message::of('“{field}” must be a list of { from, level }.', ['field' => self::PERSON_FIELD]));
		}
		$seen = [];
		foreach ($levels as $e) {
			$from = $e instanceof \stdClass ? ($e->from ?? null) : null;
			$level = $e instanceof \stdClass ? ($e->level ?? null) : null;
			if (!is_string($from) || !Time::isDay($from) || !is_string($level) || preg_match(self::ID_PATTERN, $level) !== 1) {
				throw ApiException::invalid(Message::of('Each entry of “{field}” needs a day “from” (YYYY-MM-DD) and a “level” id.', ['field' => self::PERSON_FIELD]));
			}
			if (isset($seen[$from])) {
				throw ApiException::invalid(Message::of('“{field}” names the day {from} twice.', ['field' => self::PERSON_FIELD, 'from' => $from]));
			}
			$seen[$from] = true;
		}
	}

	/**
	 * The project's overrides: null or an object `{id: rate ≥ 0}`.
	 *
	 * @throws ApiException 422
	 */
	public static function validateProject(mixed $rates): void {
		if ($rates === null) {
			return;
		}
		if (!($rates instanceof \stdClass)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or an object of level id → rate.', ['field' => self::PROJECT_FIELD]));
		}
		foreach ((array)$rates as $id => $rate) {
			// A numeric key comes back as int – the pattern wants the text.
			if (preg_match(self::ID_PATTERN, (string)$id) !== 1 || !ProjectRules::isRate($rate)) {
				throw ApiException::invalid(Message::of('“{field}” must be null or an object of level id → rate.', ['field' => self::PROJECT_FIELD]));
			}
		}
	}

	/** The settings without the rates of the levels – what a member reads (id and name stay). */
	public static function withoutRates(\stdClass $settings): \stdClass {
		$levels = $settings->{self::FIELD} ?? null;
		if (!is_array($levels)) {
			return $settings;
		}
		$out = clone $settings;
		$out->{self::FIELD} = array_map(static function (mixed $l): mixed {
			if (!($l instanceof \stdClass)) {
				return $l;
			}
			$c = clone $l;
			unset($c->rates);
			return $c;
		}, $levels);
		return $out;
	}

	// ---------------------------------------------- 0.33.0: fee models

	/** Does the team have a level at all (`no_levels` otherwise)? */
	public static function hasLevels(?\stdClass $settings): bool {
		foreach (Fields::list($settings, self::FIELD) as $l) {
			if ($l instanceof \stdClass && self::isId($l->id ?? null)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The rate of a level in a currency on a day: the project's
	 * `level_rates` when the currency is the project's (its own, else the
	 * team's); else the level's entry with the latest `from` on or before
	 * the day when the currency is the team's. Null: none – no exchange rate.
	 */
	public static function rateOf(?\stdClass $settings, ?\stdClass $project, string $id, string $currency, string $day): ?float {
		$currency = strtoupper($currency);
		$team = ReceiptBooking::teamCurrency($settings);
		$own = $project?->{self::PROJECT_FIELD} ?? null;
		$pc = $project?->currency ?? null;
		$projectCurrency = is_string($pc) && preg_match(ProjectRules::CURRENCY_PATTERN, $pc) === 1 ? strtoupper($pc) : $team;
		if ($own instanceof \stdClass && $projectCurrency === $currency) {
			$r = ((array)$own)[$id] ?? null;
			if (ProjectRules::isRate($r)) {
				return (float)$r;
			}
		}
		if ($currency !== $team) {
			return null;
		}
		foreach (Fields::list($settings, self::FIELD) as $l) {
			if (!($l instanceof \stdClass) || ($l->id ?? null) !== $id) {
				continue;
			}
			$best = null;
			$from = '';
			foreach (Fields::list($l, 'rates') as $r) {
				$d = $r instanceof \stdClass ? ($r->from ?? null) : null;
				if (is_string($d) && $d <= $day && $d >= $from && ProjectRules::isRate($r->rate ?? null)) {
					$best = (float)$r->rate;
					$from = $d;
				}
			}
			return $best;
		}
		return null;
	}

	/**
	 * The packages of a budget by their `no` (lower case, trimmed).
	 *
	 * @return array<string,\stdClass>
	 */
	public static function packagesByNo(mixed $wps): array {
		$out = [];
		foreach (is_array($wps) ? $wps : [] as $wp) {
			if ($wp instanceof \stdClass && (is_string($wp->no ?? null) || is_int($wp->no ?? null))) {
				$out[mb_strtolower(trim((string)$wp->no))] ??= $wp;
			}
		}
		return $out;
	}

	/**
	 * The packages that are the `parent` of another, by `no` (lower case).
	 *
	 * @return array<string,true>
	 */
	public static function parents(mixed $wps): array {
		$out = [];
		foreach (is_array($wps) ? $wps : [] as $wp) {
			$p = $wp instanceof \stdClass ? ($wp->parent ?? null) : null;
			if (is_string($p) || is_int($p)) {
				$out[mb_strtolower(trim((string)$p))] = true;
			}
		}
		return $out;
	}

	/** The `no` of a package, lower case and trimmed; '' when it has none. */
	public static function noOf(\stdClass $wp): string {
		$no = $wp->no ?? null;
		return is_string($no) || is_int($no) ? mb_strtolower(trim((string)$no)) : '';
	}

	/**
	 * The level of a package: its own, else the nearest package above with
	 * one; null when none has.
	 *
	 * @param array<string,\stdClass> $byNo {@see packagesByNo()}
	 */
	public static function levelOf(\stdClass $wp, array $byNo): ?string {
		$seen = [];
		for ($cur = $wp; $cur !== null; ) {
			$l = $cur->level ?? null;
			if (self::isId($l)) {
				return $l;
			}
			$no = self::noOf($cur);
			if (isset($seen[$no])) {
				return null;
			}
			$seen[$no] = true;
			$p = $cur->parent ?? null;
			$cur = is_string($p) || is_int($p) ? ($byNo[mb_strtolower(trim((string)$p))] ?? null) : null;
		}
		return null;
	}

	/**
	 * The catalog's `booking_levels`: for each budget in the category model,
	 * each package without packages below that has codes, one entry per
	 * code `{code, level, start, end}`. Null when no budget has the
	 * category model. No amount, no rate, no name.
	 *
	 * @return ?list<array{code:string,level:?string,start:?string,end:?string}>
	 */
	public static function bookingLevels(\stdClass $project): ?array {
		$out = null;
		foreach (Fields::list($project, 'budgets') as $b) {
			if (!($b instanceof \stdClass) || FeeModelRules::modelOf($b) !== FeeModelRules::CATEGORY) {
				continue;
			}
			$out ??= [];
			$wps = $b->work_packages ?? null;
			$byNo = self::packagesByNo($wps);
			$parents = self::parents($wps);
			$start = Fields::str($b, 'start');
			$start = $start !== null && Time::isDay($start) ? $start : null;
			$end = Fields::str($b, 'end');
			$end = $end !== null && Time::isDay($end) ? $end : null;
			foreach (is_array($wps) ? $wps : [] as $wp) {
				$codes = $wp instanceof \stdClass ? ($wp->codes ?? null) : null;
				if (!($wp instanceof \stdClass) || isset($parents[self::noOf($wp)]) || !is_array($codes)) {
					continue;
				}
				$level = self::levelOf($wp, $byNo);
				foreach ($codes as $code) {
					if (is_string($code) && trim($code) !== '') {
						$out[] = ['code' => trim($code), 'level' => $level, 'start' => $start, 'end' => $end];
					}
				}
			}
		}
		return $out;
	}
}
