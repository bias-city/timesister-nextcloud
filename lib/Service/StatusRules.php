<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/** Checking a status report. Pure. */
final class StatusRules {
	/**
	 * @param array<string,mixed> $in
	 * @return array{app_version:?string,last_sync:?int,last_backup:?string,calendar_url:?string,calendar_shared:?bool,applied_shares:?array<string,string>}
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
		$shared = $in['calendar_shared'] ?? null;
		if ($shared !== null && !is_bool($shared)) {
			throw ApiException::notBool('calendar_shared');
		}
		$applied = $in['applied_shares'] ?? null;
		$applied = $applied === null ? null : AccessRules::validateApplied($applied);
		return ['app_version' => $v, 'last_sync' => $sync, 'last_backup' => $backup, 'calendar_url' => $url, 'calendar_shared' => $shared, 'applied_shares' => $applied];
	}
}
