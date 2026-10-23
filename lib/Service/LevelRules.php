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
 * Pure; checks only the shape – the server does not compute with it.
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
	private const ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';

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
}
