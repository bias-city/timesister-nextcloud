<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/** Prüfung einer Kalendersicherung. Rein. */
final class BackupRules {
	public const MAX_BYTES = 20 * 1024 * 1024; // 20 MB nach dem Dekodieren

	public static function checkDay(mixed $day): string {
		if (!is_string($day) || !Time::isDay($day)) {
			throw ApiException::invalid('„taken_on“ muss ein Tag im Format JJJJ-MM-TT sein.');
		}
		return $day;
	}

	/**
	 * Base64 → Inhalt der `.ics`.
	 *
	 * @throws ApiException 413 zu gross, 422 kein Base64 oder kein Kalender
	 */
	public static function decode(mixed $b64): string {
		if (!is_string($b64) || $b64 === '') {
			throw ApiException::invalid('„ics_base64“ fehlt.');
		}
		// Zeilenumbrüche sind in Base64 üblich; alles andere ist ein Fehler.
		$clean = preg_replace('/[\r\n\t ]+/', '', $b64);
		if ($clean === null) {
			throw ApiException::invalid('„ics_base64“ ist kein gültiges Base64.');
		}
		if (strlen($clean) > 4 * (int)ceil(self::MAX_BYTES / 3)) {
			throw ApiException::tooLarge('Eine Kalendersicherung darf höchstens 20 MB gross sein.');
		}
		$bin = base64_decode($clean, true);
		if ($bin === false) {
			throw ApiException::invalid('„ics_base64“ ist kein gültiges Base64.');
		}
		if (strlen($bin) > self::MAX_BYTES) {
			throw ApiException::tooLarge('Eine Kalendersicherung darf höchstens 20 MB gross sein.');
		}
		if (!self::isCalendar($bin)) {
			throw ApiException::invalid('Die Sicherung muss mit BEGIN:VCALENDAR beginnen.');
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
	 * Ordnername für ein Konto: lesbar, wenn die Kennung harmlos ist, sonst
	 * ein Hash. Nie ein Pfad aus der Anfrage.
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
}
