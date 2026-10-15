<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/** Checking a status report. Pure. */
final class StatusRules {
	/**
	 * @param array<string,mixed> $in
	 * @return array{app_version:?string,last_sync:?int,last_backup:?string,calendar_url:?string,projects:?list<string>}
	 */
	public static function validate(array $in): array {
		$v = $in['app_version'] ?? null;
		if ($v !== null && (!is_string($v) || !preg_match('/^[A-Za-z0-9._+-]{1,32}$/', $v))) {
			throw ApiException::invalidField('app_version');
		}
		$sync = $in['last_sync'] ?? null;
		if ($sync !== null) {
			$sync = is_string($sync) ? Time::parseIso($sync) : null;
			if ($sync === null) {
				throw ApiException::invalid('“last_sync” must be a time in ISO 8601.');
			}
		}
		$backup = $in['last_backup'] ?? null;
		if ($backup !== null && (!is_string($backup) || !Time::isDay($backup))) {
			throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => 'last_backup']));
		}
		$url = $in['calendar_url'] ?? null;
		if ($url !== null) {
			if (!is_string($url) || strlen($url) > 1000
				|| !preg_match('#^https?://#i', $url)
				|| filter_var($url, FILTER_VALIDATE_URL) === false) {
				throw ApiException::invalid('“calendar_url” must be an http(s) address.');
			}
		}
		$projects = $in['projects'] ?? null;
		$projects = $projects === null ? null : self::projects($projects);
		return ['app_version' => $v, 'last_sync' => $sync, 'last_backup' => $backup, 'calendar_url' => $url, 'projects' => $projects];
	}

	/** At most this many project keys in `projects`. */
	public const MAX_PROJECTS = 500;

	/**
	 * `projects` (0.12.0): the project keys the person booked on in the last
	 * 12 months – at most 500, each 1–64 bytes without control characters.
	 * Without duplicates, sorted.
	 *
	 * @return list<string>
	 */
	public static function projects(mixed $in): array {
		$bad = static fn (): ApiException => ApiException::badRequest(Message::of('“{field}” must be a list of at most {max} project keys.', ['field' => 'projects', 'max' => self::MAX_PROJECTS]));
		if (!is_array($in) || !array_is_list($in) || count($in) > self::MAX_PROJECTS) {
			throw $bad();
		}
		$out = [];
		foreach ($in as $key) {
			if (!is_string($key) || $key === '' || strlen($key) > 64 || preg_match('/[\x00-\x1F\x7F]/', $key)) {
				throw $bad();
			}
			$out[$key] = true;
		}
		$keys = array_map('strval', array_keys($out));
		sort($keys, SORT_STRING);
		return $keys;
	}
}
