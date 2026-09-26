<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/** Prüfung eines Lebenszeichens. Rein. */
final class StatusRules {
	/**
	 * @param array<string,mixed> $in
	 * @return array{app_version:?string,last_sync:?int,last_backup:?string,calendar_url:?string}
	 */
	public static function validate(array $in): array {
		$v = $in['app_version'] ?? null;
		if ($v !== null && (!is_string($v) || !preg_match('/^[A-Za-z0-9._+-]{1,32}$/', $v))) {
			throw ApiException::invalid('„app_version“ ist ungültig.');
		}
		$sync = $in['last_sync'] ?? null;
		if ($sync !== null) {
			$sync = is_string($sync) ? Time::parseIso($sync) : null;
			if ($sync === null) {
				throw ApiException::invalid('„last_sync“ muss eine Zeit nach ISO 8601 sein.');
			}
		}
		$backup = $in['last_backup'] ?? null;
		if ($backup !== null && (!is_string($backup) || !Time::isDay($backup))) {
			throw ApiException::invalid('„last_backup“ muss ein Tag im Format JJJJ-MM-TT sein.');
		}
		$url = $in['calendar_url'] ?? null;
		if ($url !== null) {
			if (!is_string($url) || strlen($url) > 1000
				|| !preg_match('#^https?://#i', $url)
				|| filter_var($url, FILTER_VALIDATE_URL) === false) {
				throw ApiException::invalid('„calendar_url“ muss eine http(s)-Adresse sein.');
			}
		}
		return ['app_version' => $v, 'last_sync' => $sync, 'last_backup' => $backup, 'calendar_url' => $url];
	}
}
