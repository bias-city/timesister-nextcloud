<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Team setting `catalogs` (0.25.1): the template catalogs the team uses,
 * as `id@edition`. Null leaves the choice to the client (by country); an
 * empty list means none. Pure.
 */
final class CatalogRules {
	public const FIELD = 'catalogs';
	public const MAX = 50;
	public const PATTERN = '/^[a-z0-9][a-z0-9._-]{0,99}@[A-Za-z0-9._-]{1,100}$/';

	/**
	 * @return ?list<string>
	 * @throws ApiException 422
	 */
	public static function validate(mixed $v): ?array {
		if ($v === null) {
			return null;
		}
		if (!is_array($v) || !array_is_list($v) || count($v) > self::MAX) {
			throw ApiException::invalid(Message::of('“catalogs” must be null or a list of at most {max} catalogs.', ['max' => self::MAX]));
		}
		$out = [];
		foreach ($v as $c) {
			if (!is_string($c) || preg_match(self::PATTERN, $c) !== 1) {
				throw ApiException::invalid('Each catalog in “catalogs” is “id@edition”: the id in lowercase letters, digits, dots, dashes and underscores, the edition in letters, digits, dots, dashes and underscores, each at most 100.');
			}
			if (in_array($c, $out, true)) {
				throw ApiException::invalid(Message::of('“catalogs” names “{catalog}” twice.', ['catalog' => $c]));
			}
			$out[] = $c;
		}
		return $out;
	}

	/**
	 * The stored setting (JSON); anything unreadable counts as null.
	 *
	 * @return ?list<string>
	 */
	public static function decode(?string $json): ?array {
		if ($json === null || $json === '') {
			return null;
		}
		try {
			return self::validate(json_decode($json, true));
		} catch (ApiException) {
			return null;
		}
	}

	/** @param ?list<string> $c */
	public static function encode(?array $c): ?string {
		return $c === null ? null : json_encode($c, Json::FLAGS);
	}
}
