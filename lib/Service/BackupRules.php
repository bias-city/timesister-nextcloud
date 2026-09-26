<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/** Prüfung einer Kalendersicherung, Woche, Namen und Sicherungs-Konto. Rein. */
final class BackupRules {
	public const MAX_BYTES = 20 * 1024 * 1024; // 20 MB nach dem Dekodieren
	public const SOURCE_CLIENT = 'client';
	public const SOURCE_SERVER = 'server';
	/** Ordner der sichtbaren Kopien im Heim des Sicherungs-Kontos. */
	public const VISIBLE_ROOT = 'TimeSister-Sicherungen';

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

	/** ISO-Woche eines Tages, z. B. `2026-W39` (Montag bis Sonntag). */
	public static function isoWeek(string $day): string {
		$d = \DateTimeImmutable::createFromFormat('!Y-m-d', self::checkDay($day), new \DateTimeZone('UTC'));
		if ($d === false) {
			throw ApiException::invalid('Ungültiger Tag.');
		}
		return $d->format('o-\WW');
	}

	public static function sameIsoWeek(string $a, string $b): bool {
		return self::isoWeek($a) === self::isoWeek($b);
	}

	/**
	 * Ordner- oder Dateiname aus fremdem Text: `/` und `\` werden `-`,
	 * Steuerzeichen Leerraum, unsichtbare Formatzeichen fallen weg; kein
	 * Punkt und kein Leerraum am Rand, höchstens `$max` Zeichen. Kann leer sein.
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

	/** `<Anzeigename> (<uid>)`, beides gesäubert. */
	public static function personFolder(string $displayName, string $uid): string {
		$u = self::cleanName($uid, 64);
		if ($u === '') {
			$u = substr(hash('sha256', $uid), 0, 16);
		}
		$n = self::cleanName($displayName, 100);
		return ($n === '' ? $u : $n) . ' (' . $u . ')';
	}

	/**
	 * Die Kalender-URI aus `calendar_url`, nur wenn die Adresse im Heim des
	 * Kontos selbst liegt (`…/remote.php/dav/calendars/<uid>/<uri>/`). So
	 * sichert der Server nie einen Kalender, den das Konto nicht sieht.
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
	 * Das Sicherungs-Konto: die Wahl, wenn sie noch admin ist, sonst der
	 * erste admin nach Kennung; ohne admin null.
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
	 * Rumpf von `PUT /backups/consent`. `consent` muss true oder false sein
	 * (400); beim Einschalten ist `notice` Pflicht, 1–32 Zeichen ohne
	 * Steuerzeichen (422). Beim Zurückziehen zählt `notice` nicht.
	 *
	 * @param array<string,mixed> $in
	 * @return array{consent:bool,notice:?string}
	 */
	public static function consentInput(array $in): array {
		if (array_key_exists('uid', $in)) {
			throw ApiException::badRequest('Die Freigabe gilt nur für das eigene Konto; „uid“ gehört nicht in den Rumpf.');
		}
		$consent = $in['consent'] ?? null;
		if (!is_bool($consent)) {
			throw ApiException::badRequest('„consent“ muss true oder false sein.');
		}
		if (!$consent) {
			return ['consent' => false, 'notice' => null];
		}
		$notice = $in['notice'] ?? null;
		if (!is_string($notice) || $notice === '' || mb_strlen($notice) > 32
			|| !mb_check_encoding($notice, 'UTF-8') || preg_match('/\p{Cc}/u', $notice)) {
			throw ApiException::invalid('Zum Freigeben fehlt „notice“, die Fassung des Aufklärungstexts (höchstens 32 Zeichen).');
		}
		return ['consent' => true, 'notice' => $notice];
	}
}
