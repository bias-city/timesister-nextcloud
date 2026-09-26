<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/** Zeiten als ISO 8601 in UTC, Tage als YYYY-MM-DD. Rein. */
final class Time {
	private const ISO = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/';

	public static function iso(?int $ts): ?string {
		return $ts === null ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
	}

	/** ISO 8601 mit Zeitzone → Unix-Zeit, sonst null. */
	public static function parseIso(string $s): ?int {
		if (!preg_match(self::ISO, $s)) {
			return null;
		}
		try {
			$d = new \DateTimeImmutable($s);
		} catch (\Exception) {
			return null;
		}
		// Kalendertag muss echt sein (kein 2026-02-31).
		if (!self::isDay(substr($s, 0, 10))) {
			return null;
		}
		return $d->getTimestamp();
	}

	public static function isDay(string $s): bool {
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
			return false;
		}
		return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
	}
}
