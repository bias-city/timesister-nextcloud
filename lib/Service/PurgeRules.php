<?php

declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Removing the app's data (0.43.0), the pure part: the options, the typed
 * sentence, and the note of a downloaded team backup that every deletion
 * requires. Nothing here touches Nextcloud.
 *
 * What is never deleted, with or without option: accounts, Nextcloud
 * groups, any calendar other than the team's vacation calendar and – only
 * with the option – the people's time calendars, and any file the app did
 * not write itself.
 */
final class PurgeRules {
	/** The sentence the admin types; the page shows it translated and the server accepts both. */
	public const SENTENCE = 'I want to uninstall all data of the app.';
	/** App config key of the download note, followed by the team ID. */
	public const NOTE_KEY = 'purge_export_';
	/** A download older than this does not count any more. */
	public const NOTE_TTL = 86400;

	/**
	 * The three options from a body; unknown input falls back to the defaults.
	 *
	 * @return array{folders:bool,vacation:bool,time_calendars:bool}
	 */
	public static function options(mixed $in): array {
		$get = static function (string $k, bool $default) use ($in): bool {
			$v = null;
			if (is_array($in)) {
				$v = $in[$k] ?? null;
			} elseif ($in instanceof \stdClass) {
				$v = $in->$k ?? null;
			}
			if ($v === null) {
				return $default;
			}
			return $v === true || $v === 1 || $v === '1' || $v === 'true';
		};
		return ['folders' => $get('folders', true), 'vacation' => $get('vacation', true), 'time_calendars' => $get('time_calendars', false)];
	}

	/**
	 * The typed sentence, trimmed and compared exactly to one of the accepted forms.
	 *
	 * @param list<string> $accepted
	 * @throws ApiException 422 `confirm`
	 */
	public static function checkSentence(mixed $typed, array $accepted): void {
		$t = is_string($typed) ? trim($typed) : '';
		foreach ($accepted as $a) {
			if ($t !== '' && $t === trim($a)) {
				return;
			}
		}
		throw ApiException::confirm(Message::of('To confirm, type the sentence exactly as shown.'));
	}

	/**
	 * The note stored when a team backup is downloaded.
	 *
	 * @param list<string> $calendars members whose time calendar is in the ZIP
	 * @param list<string> $missing members who have a time calendar that is not in the ZIP (too large, failed)
	 * @return array{sha256:string,at:int,revision:int,entries_revision:int,calendars:list<string>,missing:list<string>}
	 */
	public static function note(string $sha256, int $now, int $revision, int $entriesRevision, array $calendars, array $missing): array {
		return ['sha256' => $sha256, 'at' => $now, 'revision' => $revision, 'entries_revision' => $entriesRevision,
			'calendars' => $calendars, 'missing' => $missing];
	}

	/** The note from its stored JSON, or null when absent or unreadable. */
	public static function noteFrom(string $json): ?array {
		if ($json === '') {
			return null;
		}
		try {
			$d = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		return is_array($d) && isset($d['sha256'], $d['at'], $d['revision'], $d['entries_revision']) ? $d : null;
	}

	/** Is the note a valid backup of the team as it is now? */
	public static function fresh(?array $note, int $revision, int $entriesRevision, int $now): bool {
		return $note !== null
			&& (int)$note['at'] + self::NOTE_TTL > $now
			&& (int)$note['revision'] === $revision
			&& (int)$note['entries_revision'] === $entriesRevision;
	}

	/**
	 * Every deletion needs the token of a fresh download: the SHA-256 of the
	 * ZIP the admin received, for the team as it is now.
	 *
	 * @throws ApiException 409 `conflict` with `reason` export_required or export_stale
	 */
	public static function checkExport(?array $note, mixed $token, int $revision, int $entriesRevision, int $now): void {
		if ($note === null) {
			throw ApiException::conflict(Message::of('Download the team backup first; nothing was deleted.'), ['reason' => 'export_required']);
		}
		if (!self::fresh($note, $revision, $entriesRevision, $now)) {
			throw ApiException::conflict(Message::of('The team changed since the backup was downloaded; download it again. Nothing was deleted.'), ['reason' => 'export_stale']);
		}
		if (!is_string($token) || !hash_equals((string)$note['sha256'], $token)) {
			throw ApiException::conflict(Message::of('The backup token does not match the downloaded ZIP; nothing was deleted.'), ['reason' => 'export_token']);
		}
	}

	/**
	 * Time calendars go only when the ZIP holds every one that exists.
	 *
	 * @throws ApiException 409 `conflict` with `reason` export_incomplete
	 */
	public static function checkCalendarsComplete(?array $note): void {
		/** @var list<string> $missing */
		$missing = [];
		if ($note !== null && is_array($note['missing'] ?? null)) {
			foreach ($note['missing'] as $m) {
				$missing[] = is_scalar($m) ? (string)$m : '';
			}
		}
		if ($missing !== []) {
			throw ApiException::conflict(Message::of('The backup does not hold every time calendar ({list}); the time calendars stay. Nothing was deleted.', ['list' => implode(', ', $missing)]), ['reason' => 'export_incomplete', 'missing' => $missing]);
		}
	}

	/** The team's own ZIPs in the visible `_team` folder: only the app's naming, never other files. */
	public static function isTeamZip(string $slug, string $name): bool {
		return (bool)preg_match('/^timesister-' . preg_quote($slug, '/') . '-\d{4}-\d{2}-\d{2}_\d{4}\.zip$/', $name);
	}
}
