<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/** Checking a team from the admin side. Pure. */
final class TeamRules {
	public const SLUG_PATTERN = '/^[a-z0-9-]{2,32}$/';

	/**
	 * API version 2: `groups` = `{ team }`, an existing Nextcloud group.
	 * Other keys (role or account groups from API version 1) → 422.
	 *
	 * @param array<string,mixed> $in
	 * @return array{name:string,slug:string,groups:array{team:string}}
	 */
	public static function validate(array $in): array {
		$name = $in['name'] ?? null;
		if (!is_string($name) || ($name = trim($name)) === '' || mb_strlen($name) > 100) {
			throw ApiException::invalid('The team name is missing or longer than 100 characters.');
		}
		$slug = $in['slug'] ?? null;
		if (!is_string($slug) || !preg_match(self::SLUG_PATTERN, $slug)) {
			throw ApiException::invalid('The short name must consist of 2–32 characters a–z, 0–9 and -.');
		}
		$groups = $in['groups'] ?? null;
		if (!is_array($groups)) {
			throw ApiException::invalid('The team group is missing.');
		}
		foreach (array_keys($groups) as $key) {
			if ($key !== Role::TEAM_GROUP) {
				throw ApiException::invalid(Message::of('Unknown group “{key}”: a team only has the team group “team”.', ['key' => $key]));
			}
		}
		$gid = $groups[Role::TEAM_GROUP] ?? null;
		if (!is_string($gid) || $gid === '' || strlen($gid) > 64) {
			throw ApiException::invalid('The team group is missing.');
		}
		return ['name' => $name, 'slug' => $slug, 'groups' => [Role::TEAM_GROUP => $gid]];
	}

	/**
	 * The backup owner from the body: if the key is missing, the choice
	 * stays ([false, null]); null or empty means automatic ([true, null]).
	 *
	 * @param array<string,mixed> $in
	 * @return array{0:bool,1:?string}
	 */
	public static function backupOwner(array $in): array {
		if (!array_key_exists('backup_owner', $in)) {
			return [false, null];
		}
		$uid = $in['backup_owner'];
		if ($uid === null || $uid === '') {
			return [true, null];
		}
		if (!is_string($uid) || strlen($uid) > 64) {
			throw ApiException::invalid('The backup owner is invalid.');
		}
		return [true, $uid];
	}

	/** Team settings (API version 1.2) with their default values. */
	public const SETTINGS = ['backup_required' => false, 'invoicing' => true];
	/** Settings that are a date or null (0.17.0). */
	public const DATE_SETTINGS = ['entries_since'];

	/**
	 * Team settings from the body. If `settings` or a key is missing, the
	 * value stays. Unknown keys, a non-boolean value or a malformed date:
	 * 422. `documents` (0.19.0) is an object, checked by DocumentRules and
	 * merged over the stored one.
	 *
	 * @param array<string,mixed> $in
	 * @return array<string,mixed>
	 */
	public static function settings(array $in, ?string $documentsJson = null): array {
		$s = $in['settings'] ?? null;
		if ($s === null) {
			return [];
		}
		if (!is_array($s)) {
			throw ApiException::invalid('“settings” must be an object.');
		}
		$out = [];
		foreach ($s as $key => $value) {
			if (is_string($key) && in_array($key, self::DATE_SETTINGS, true)) {
				$out[$key] = EntryRules::parseSince($value);
				continue;
			}
			if ($key === DocumentRules::FIELD) {
				$out[$key] = DocumentRules::validate($value, DocumentRules::settings($documentsJson));
				continue;
			}
			if (!is_string($key) || !array_key_exists($key, self::SETTINGS)) {
				throw ApiException::invalid(Message::of('Unknown team setting “{key}”.', ['key' => $key]));
			}
			if (!is_bool($value)) {
				throw ApiException::notBool($key);
			}
			$out[$key] = $value;
		}
		return $out;
	}
}
