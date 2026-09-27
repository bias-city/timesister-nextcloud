<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/** Checking a calendar backup, week, name and backup owner. Pure. */
final class BackupRules {
	public const MAX_BYTES = 20 * 1024 * 1024; // 20 MB after decoding
	public const SOURCE_CLIENT = 'client';
	public const SOURCE_SERVER = 'server';
	/**
	 * Folder of the visible copies in the backup owner's home and in the
	 * person's own home. Up to 0.3 it was “TimeSister-Sicherungen”; files
	 * there stay where they are and remain tracked by their stored path.
	 */
	public const VISIBLE_ROOT = 'TimeSister Backups';

	public static function checkDay(mixed $day): string {
		if (!is_string($day) || !Time::isDay($day)) {
			throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => 'taken_on']));
		}
		return $day;
	}

	/**
	 * Base64 → contents of the `.ics`.
	 *
	 * @throws ApiException 413 too large, 422 not Base64 or not a calendar
	 */
	public static function decode(mixed $b64): string {
		if (!is_string($b64) || $b64 === '') {
			throw ApiException::missing('ics_base64');
		}
		// Line breaks are common in Base64; anything else is an error.
		$clean = preg_replace('/[\r\n\t ]+/', '', $b64);
		if ($clean === null) {
			throw ApiException::invalid('“ics_base64” is not valid Base64.');
		}
		if (strlen($clean) > 4 * (int)ceil(self::MAX_BYTES / 3)) {
			throw ApiException::tooLarge('A calendar backup may be at most 20 MB.');
		}
		$bin = base64_decode($clean, true);
		if ($bin === false) {
			throw ApiException::invalid('“ics_base64” is not valid Base64.');
		}
		if (strlen($bin) > self::MAX_BYTES) {
			throw ApiException::tooLarge('A calendar backup may be at most 20 MB.');
		}
		if (!self::isCalendar($bin)) {
			throw ApiException::invalid('The backup must start with BEGIN:VCALENDAR.');
		}
		return $bin;
	}

	public static function isCalendar(string $bin): bool {
		$head = substr($bin, 0, 256);
		if (str_starts_with($head, "\xEF\xBB\xBF")) {
			$head = substr($head, 3);
		}
		$head = ltrim($head);
		return strncasecmp($head, 'BEGIN:VCALENDAR', 15) === 0;
	}

	/**
	 * Folder name for an account: readable when the identifier is harmless,
	 * otherwise a hash. Never a path from the request.
	 */
	public static function folderFor(string $uid): string {
		if (preg_match('/^[A-Za-z0-9_@-][A-Za-z0-9_.@-]{0,63}$/', $uid)) {
			return 'u-' . $uid;
		}
		return 'h-' . hash('sha256', $uid);
	}

	public static function fileFor(string $day): string {
		return self::checkDay($day) . '.ics';
	}

	/** ISO week of a day, e.g. `2026-W39` (Monday to Sunday). */
	public static function isoWeek(string $day): string {
		$d = \DateTimeImmutable::createFromFormat('!Y-m-d', self::checkDay($day), new \DateTimeZone('UTC'));
		if ($d === false) {
			throw ApiException::invalid('Invalid day.');
		}
		return $d->format('o-\WW');
	}

	public static function sameIsoWeek(string $a, string $b): bool {
		return self::isoWeek($a) === self::isoWeek($b);
	}

	/**
	 * Folder or file name from foreign text: `/` and `\` become `-`,
	 * control characters become space, invisible format characters are
	 * dropped; no dot and no space at the edge, at most `$max` characters.
	 * Can be empty.
	 */
	public static function cleanName(string $s, int $max = 100): string {
		$s = mb_scrub($s, 'UTF-8');
		$s = str_replace(['/', '\\'], '-', $s);
		$s = (string)preg_replace('/\p{Cf}/u', '', $s);
		$s = (string)preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]/u', ' ', $s);
		$s = (string)preg_replace('/\s+/u', ' ', $s);
		$s = trim($s, " .");
		if (mb_strlen($s) > $max) {
			$s = rtrim(mb_substr($s, 0, $max), ' .');
		}
		return $s;
	}

	/** `<display name> (<uid>)`, both cleaned. */
	public static function personFolder(string $displayName, string $uid): string {
		$u = self::cleanName($uid, 64);
		if ($u === '') {
			$u = substr(hash('sha256', $uid), 0, 16);
		}
		$n = self::cleanName($displayName, 100);
		return ($n === '' ? $u : $n) . ' (' . $u . ')';
	}

	/**
	 * The calendar URI from `calendar_url`, only when the address lives in
	 * the account's own home (`…/remote.php/dav/calendars/<uid>/<uri>/`).
	 * This way the server never backs up a calendar the account cannot see.
	 */
	public static function calendarUriFromUrl(?string $url, string $uid): ?string {
		if ($url === null || $url === '') {
			return null;
		}
		$path = parse_url($url, PHP_URL_PATH);
		if (!is_string($path) || !preg_match('#/remote\.php/dav/calendars/([^/]+)/([^/]+)/?$#', $path, $m)) {
			return null;
		}
		if (rawurldecode($m[1]) !== $uid) {
			return null;
		}
		$uri = rawurldecode($m[2]);
		if ($uri === '.' || $uri === '..' || str_contains($uri, '/')) {
			return null;
		}
		return $uri;
	}

	/**
	 * The backup owner: the chosen one, if still admin, otherwise the first
	 * admin by identifier; null without an admin.
	 *
	 * @param list<string> $admins
	 */
	public static function pickOwner(?string $chosen, array $admins): ?string {
		if ($chosen !== null && in_array($chosen, $admins, true)) {
			return $chosen;
		}
		sort($admins, SORT_STRING);
		return $admins[0] ?? null;
	}

	/**
	 * Body of `PUT /backups/consent`. `consent` must be true or false
	 * (400); when turning it on, `notice` is required, 1–32 characters
	 * without control characters (422). `notice` does not matter when
	 * withdrawing.
	 *
	 * @param array<string,mixed> $in
	 * @return array{consent:bool,notice:?string}
	 */
	public static function consentInput(array $in): array {
		if (array_key_exists('uid', $in)) {
			throw ApiException::ownAccountOnly();
		}
		$consent = $in['consent'] ?? null;
		if (!is_bool($consent)) {
			throw ApiException::notBool('consent', true);
		}
		if (!$consent) {
			return ['consent' => false, 'notice' => null];
		}
		$notice = $in['notice'] ?? null;
		if (!is_string($notice) || $notice === '' || mb_strlen($notice) > 32
			|| !mb_check_encoding($notice, 'UTF-8') || preg_match('/\p{Cc}/u', $notice)) {
			throw ApiException::invalid('Consent needs “notice”, the version of the information text (at most 32 characters).');
		}
		return ['consent' => true, 'notice' => $notice];
	}
}
