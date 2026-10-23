<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Entry;

/**
 * The rules of the time entries (0.17.0), pure: what the server reads
 * out of a calendar object (codes, first start, last end, cancelled),
 * the cutoff, the split of a feed into objects and the role filter.
 * The server never expands recurrences; the client's parser does.
 *
 * The iCal reading here is deliberately small (unfold, components,
 * properties) – only what the filter needs, nothing is rewritten.
 */
final class EntryRules {
	/** One object (its `ics`) at most. */
	public const MAX_OBJECT = 256 * 1024;
	/** Objects per calendar or feed in one full read. */
	public const MAX_OBJECTS = 100000;
	/** A feed at most, and the seconds to wait for it. */
	public const FEED_MAX = 20 * 1024 * 1024;
	public const FEED_TIMEOUT = 20;
	/** A calendar with at most this many objects is read at once when it is named; larger ones wait for the job. */
	public const READ_NOW_MAX = 3000;
	/** `last_error` of a named calendar whose member left the team: not read until the person is back. */
	public const LEFT = 'member left';
	/** Objects of a kind that is no time entry are skipped. */
	private const COMPONENTS = ['VEVENT'];
	private const CODE_FIELDS = ['SUMMARY', 'LOCATION', 'DESCRIPTION'];
	private const CODE_PROPERTY = 'X-TIMESISTER-CODE';

	// --------------------------------------------------------------- codes

	/**
	 * The `#code`s in a text – the client's `buchung::codes_finden`: `#`
	 * not preceded by a letter or digit, then letters, digits, `.`, `-`,
	 * `_`, `/`; a share after `:` (`60%`, `1.5h`) is dropped; trailing
	 * `.`, `-`, `_`, `/` are stripped. Lower-cased, in order, no doubles.
	 *
	 * @return list<string>
	 */
	public static function codesIn(string $text): array {
		$out = [];
		$chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
		if ($chars === false) {
			return [];
		}
		$n = count($chars);
		$isCode = static fn (string $c): bool => (bool)preg_match('/^[\p{L}\p{N}.\-_\/]$/u', $c);
		for ($i = 0; $i < $n; $i++) {
			if ($chars[$i] !== '#' || ($i > 0 && preg_match('/^[\p{L}\p{N}]$/u', $chars[$i - 1]))) {
				continue;
			}
			$j = $i + 1;
			while ($j < $n && $isCode($chars[$j])) {
				$j++;
			}
			if ($j === $i + 1) {
				continue;
			}
			$code = self::trimCode(implode('', array_slice($chars, $i + 1, $j - $i - 1)));
			$i = $j - 1;
			if ($code !== '' && !in_array($code, $out, true)) {
				$out[] = $code;
			}
		}
		return $out;
	}

	/** Trailing punctuation off, lower-cased. */
	private static function trimCode(string $code): string {
		return mb_strtolower(rtrim($code, '.-_/'));
	}

	// ------------------------------------------------------------- parsing

	/**
	 * What the server keeps of an object: the UID, the master's summary
	 * and `cancelled`, the codes of every component, first start and last
	 * end as dates (`last_end` null while the recurrence has no end or a
	 * COUNT). Null: no VEVENT, or no UID.
	 *
	 * @return ?array{uid:string,summary:string,codes:list<string>,first_start:?string,last_end:?string,cancelled:bool,level:?string}
	 */
	public static function parse(string $ics): ?array {
		$components = self::components($ics, self::COMPONENTS);
		if ($components === []) {
			return null;
		}
		$master = null;
		foreach ($components as $c) {
			if (!isset($c['props']['RECURRENCE-ID'])) {
				$master = $c;
				break;
			}
		}
		$master ??= $components[0];
		$uid = trim(self::unescape(self::first($master, 'UID') ?? ''));
		if ($uid === '') {
			return null;
		}
		$codes = [];
		$firstStart = null;
		$lastEnd = null;
		$open = false;
		foreach ($components as $c) {
			foreach (self::CODE_FIELDS as $f) {
				foreach ($c['props'][$f] ?? [] as $p) {
					foreach (self::codesIn(self::unescape($p['value'])) as $code) {
						$codes[$code] = true;
					}
				}
			}
			foreach ($c['props'][self::CODE_PROPERTY] ?? [] as $p) {
				foreach (explode(',', self::unescape($p['value'])) as $raw) {
					$code = self::trimCode(ltrim(trim($raw), '#'));
					if ($code !== '') {
						$codes[$code] = true;
					}
				}
			}
			$start = self::day($c, 'DTSTART');
			if ($start !== null && ($firstStart === null || $start < $firstStart)) {
				$firstStart = $start;
			}
			$end = self::endOf($c);
			if ($end === null) {
				$open = true;
			} elseif ($lastEnd === null || $end > $lastEnd) {
				$lastEnd = $end;
			}
		}
		$status = strtoupper(trim(self::first($master, 'STATUS') ?? ''));
		$summary = trim(self::unescape(self::first($master, 'SUMMARY') ?? ''));
		return [
			'uid' => $uid,
			'summary' => mb_strlen($summary) > 255 ? mb_substr($summary, 0, 255) : $summary,
			'codes' => array_keys($codes),
			'first_start' => $firstStart,
			'last_end' => $open ? null : $lastEnd,
			'cancelled' => $status === 'CANCELLED',
			// 0.27.0: the level of the booking as the clients wrote it (`X-TIMESISTER-LEVEL`), master only.
			'level' => CorrectionRules::levelOf($master),
		];
	}

	/**
	 * The last day a component covers: UNTIL of its RRULE, else DTEND
	 * (exclusive for all-day events), else DTSTART plus DURATION, else
	 * DTSTART. Null: a recurrence without end or with a COUNT.
	 *
	 * @param array{props:array<string,list<array{params:array<string,string>,value:string}>>,...} $c
	 */
	private static function endOf(array $c): ?string {
		$rrule = self::first($c, 'RRULE');
		if ($rrule !== null) {
			if (preg_match('/(?:^|;)UNTIL=(\d{8})/i', $rrule, $m)) {
				return self::isoDay($m[1]);
			}
			return null;
		}
		$end = self::day($c, 'DTEND');
		if ($end !== null) {
			$p = $c['props']['DTEND'][0];
			$allDay = strtoupper($p['params']['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', trim($p['value'])) === 1;
			return $allDay ? self::addDays($end, -1) : $end;
		}
		$start = self::day($c, 'DTSTART');
		if ($start === null) {
			return null;
		}
		$dur = self::first($c, 'DURATION');
		if ($dur !== null && preg_match('/^-?P(?:(\d+)W)?(?:(\d+)D)?/i', $dur, $m)) {
			$days = (int)($m[1] ?? 0) * 7 + (int)($m[2] ?? 0);
			// A duration ending at midnight ends the day before.
			$toMidnight = $days > 0 && !preg_match('/T/i', $dur);
			$p = $c['props']['DTSTART'][0];
			$allDay = strtoupper($p['params']['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', trim($p['value'])) === 1;
			return self::addDays($start, $days - ($allDay && $toMidnight ? 1 : 0));
		}
		return $start;
	}

	/** @param array{props:array<string,list<array{params:array<string,string>,value:string}>>,...} $c */
	private static function day(array $c, string $name): ?string {
		$v = self::first($c, $name);
		return $v === null ? null : self::isoDay($v);
	}

	/** `20261015` or `20261015T080000Z` → `2026-10-15`; the date part as written. */
	private static function isoDay(string $v): ?string {
		$v = trim($v);
		if (!preg_match('/^(\d{4})(\d{2})(\d{2})(?:T|$)/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			return null;
		}
		return $m[1] . '-' . $m[2] . '-' . $m[3];
	}

	private static function addDays(string $day, int $n): string {
		$d = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));
		return $d === false ? $day : $d->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
	}

	/** @param array{props:array<string,list<array{params:array<string,string>,value:string}>>,...} $c */
	private static function first(array $c, string $name): ?string {
		return $c['props'][$name][0]['value'] ?? null;
	}

	/** Text values: `\n`, `\,`, `\;`, `\\`. */
	public static function unescape(string $s): string {
		return str_replace(['\\n', '\\N', '\\,', '\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $s);
	}

	/**
	 * The top-level components of a VCALENDAR with their properties (nested
	 * ones such as VALARM are skipped, their lines kept in `lines`).
	 *
	 * @param list<string> $names which components, empty: all
	 * @return list<array{name:string,props:array<string,list<array{params:array<string,string>,value:string}>>,lines:list<string>}>
	 */
	public static function components(string $ics, array $names = []): array {
		$out = [];
		$depth = 0;
		/** @var ?array{name:string,props:array<string,list<array{params:array<string,string>,value:string}>>,lines:list<string>} $current */
		$current = null;
		foreach (self::lines($ics) as $line) {
			$upper = strtoupper($line);
			if (str_starts_with($upper, 'BEGIN:')) {
				$name = trim(substr($upper, 6));
				if ($name === 'VCALENDAR') {
					continue;
				}
				$depth++;
				if ($depth === 1) {
					$current = ['name' => $name, 'props' => [], 'lines' => [$line]];
				} elseif ($current !== null) {
					$current['lines'][] = $line;
				}
				continue;
			}
			if (str_starts_with($upper, 'END:')) {
				$name = trim(substr($upper, 4));
				if ($name === 'VCALENDAR') {
					break;
				}
				if ($current !== null) {
					$current['lines'][] = $line;
				}
				$depth--;
				if ($depth === 0 && $current !== null) {
					if ($names === [] || in_array($current['name'], $names, true)) {
						$out[] = $current;
					}
					$current = null;
				}
				continue;
			}
			if ($current === null) {
				continue;
			}
			$current['lines'][] = $line;
			if ($depth !== 1) {
				continue;
			}
			$p = self::property($line);
			if ($p !== null) {
				$current['props'][$p['name']][] = ['params' => $p['params'], 'value' => $p['value']];
			}
		}
		return $out;
	}

	/**
	 * Unfolded lines; empty ones dropped.
	 *
	 * @return list<string>
	 */
	public static function lines(string $ics): array {
		$unfolded = preg_replace('/\r?\n[ \t]/', '', $ics) ?? $ics;
		return array_values(array_filter(preg_split('/\r\n|\n|\r/', $unfolded) ?: [], static fn (string $l) => $l !== ''));
	}

	/**
	 * `NAME;PARAM=value:value` – the name upper-cased, parameters by
	 * upper-cased name (quotes stripped), the raw value.
	 *
	 * @return ?array{name:string,params:array<string,string>,value:string}
	 */
	public static function property(string $line): ?array {
		$len = strlen($line);
		$quoted = false;
		$colon = null;
		for ($i = 0; $i < $len; $i++) {
			$ch = $line[$i];
			if ($ch === '"') {
				$quoted = !$quoted;
			} elseif ($ch === ':' && !$quoted) {
				$colon = $i;
				break;
			}
		}
		if ($colon === null || $colon === 0) {
			return null;
		}
		$head = explode(';', substr($line, 0, $colon));
		$name = strtoupper(trim(array_shift($head)));
		if ($name === '' || !preg_match('/^[A-Z0-9-]+$/', $name)) {
			return null;
		}
		$params = [];
		foreach ($head as $param) {
			$eq = strpos($param, '=');
			if ($eq === false) {
				continue;
			}
			$params[strtoupper(trim(substr($param, 0, $eq)))] = trim(substr($param, $eq + 1), '"');
		}
		return ['name' => $name, 'params' => $params, 'value' => substr($line, $colon + 1)];
	}

	// ---------------------------------------------------------------- feed

	/**
	 * A feed split into objects: every component sharing one UID becomes
	 * one VCALENDAR, the feed's VTIMEZONEs go with each. Components without
	 * a UID are dropped. Keyed by UID, in order of first appearance.
	 *
	 * @return array<string,string>
	 */
	public static function splitFeed(string $ics): array {
		$timezones = [];
		$prodid = null;
		foreach (self::lines($ics) as $line) {
			if (stripos($line, 'PRODID:') === 0) {
				$prodid = $line;
				break;
			}
			if (stripos($line, 'BEGIN:V') === 0 && stripos($line, 'BEGIN:VCALENDAR') !== 0) {
				break;
			}
		}
		$byUid = [];
		foreach (self::components($ics) as $c) {
			if ($c['name'] === 'VTIMEZONE') {
				$timezones[] = $c['lines'];
				continue;
			}
			if (!in_array($c['name'], self::COMPONENTS, true)) {
				continue;
			}
			$uid = trim(self::unescape(self::first($c, 'UID') ?? ''));
			if ($uid === '') {
				continue;
			}
			$byUid[$uid][] = $c['lines'];
		}
		$out = [];
		foreach ($byUid as $uid => $blocks) {
			$lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', $prodid ?? 'PRODID:-//B/IAS//TimeSister Feed//EN'];
			foreach ($timezones as $tz) {
				array_push($lines, ...$tz);
			}
			foreach ($blocks as $b) {
				array_push($lines, ...$b);
			}
			$lines[] = 'END:VCALENDAR';
			$out[$uid] = implode("\r\n", $lines) . "\r\n";
		}
		return $out;
	}

	/** `POST /entries/list` (0.28.0): at most this many objects and deletions per call. */
	public const LIST_MAX = 2000;

	/**
	 * The body of `POST /entries/list`: `uid` (the external person), `put`
	 * (iCalendar objects with one UID each) and `delete` (event UIDs).
	 *
	 * @return array{uid:string,put:list<string>,delete:list<string>}
	 * @throws ApiException 422
	 */
	public static function parseListBody(\stdClass $body): array {
		foreach (array_map('strval', array_keys(get_object_vars($body))) as $f) {
			if (!in_array($f, ['uid', 'put', 'delete'], true)) {
				throw ApiException::invalid(Message::of('“{field}” is not a field of a list.', ['field' => $f]));
			}
		}
		$uid = $body->uid ?? null;
		if (!is_string($uid) || preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $uid) !== 1 || trim($uid, '.') === '') {
			throw ApiException::invalid('“uid” must be the key of a person.');
		}
		$put = $body->put ?? [];
		$delete = $body->delete ?? [];
		if (!is_array($put) || !array_is_list($put) || !is_array($delete) || !array_is_list($delete)
			|| count($put) + count($delete) > self::LIST_MAX) {
			throw ApiException::invalid(Message::of('“put” and “delete” must be lists of at most {max} items together.', ['max' => self::LIST_MAX]));
		}
		foreach ($put as $ics) {
			if (!is_string($ics) || strlen($ics) > self::MAX_OBJECT || self::parse($ics) === null || !self::oneUid($ics)) {
				throw ApiException::invalid('Each item of “put” must be one event in iCalendar, at most 256 KB.');
			}
		}
		foreach ($delete as $ev) {
			if (!is_string($ev) || trim($ev) === '' || strlen($ev) > 255 || preg_match('/[\x00-\x1F\x7F]/', $ev) === 1) {
				throw ApiException::invalid('Each item of “delete” must be an event UID.');
			}
		}
		return ['uid' => $uid, 'put' => $put, 'delete' => array_values(array_unique($delete))];
	}

	/**
	 * One object is one event: every VEVENT carries the same UID (a series
	 * with its exceptions). Components with another UID would be stored
	 * under the master's and never show as entries of their own.
	 */
	public static function oneUid(string $ics): bool {
		$uid = null;
		foreach (self::components($ics, self::COMPONENTS) as $c) {
			$u = trim(self::unescape(self::first($c, 'UID') ?? ''));
			if ($u === '' || ($uid !== null && $u !== $uid)) {
				return false;
			}
			$uid = $u;
		}
		return $uid !== null;
	}

	/** Only http(s) feeds are read. */
	public static function isFeedUrl(mixed $url): bool {
		return is_string($url) && preg_match('#^https?://[^\s/?\#]+#i', trim($url)) === 1;
	}

	// -------------------------------------------------------------- cutoff

	/** Stored when the recurrence has no end or the object ends on/after the cutoff. */
	public static function withinCutoff(?string $lastEnd, string $since): bool {
		return $lastEnd === null || $lastEnd >= $since;
	}

	/** The default cutoff: the first of the month two years before the team was created. */
	public static function defaultSince(int $createdAt): string {
		$d = (new \DateTimeImmutable('@' . $createdAt))->setTimezone(new \DateTimeZone('UTC'));
		return $d->setDate((int)$d->format('Y') - 2, (int)$d->format('n'), 1)->format('Y-m-d');
	}

	/** The effective cutoff: the stored one, else the default. */
	public static function effectiveSince(?string $stored, int $createdAt): string {
		return $stored !== null && Time::isDay($stored) ? $stored : self::defaultSince($createdAt);
	}

	/** `entries_since` from a body: `YYYY-MM-DD` or null; otherwise 422. */
	public static function parseSince(mixed $v): ?string {
		if ($v === null) {
			return null;
		}
		if (!is_string($v) || !Time::isDay($v)) {
			throw ApiException::invalid('“entries_since” must be a date (YYYY-MM-DD) or null.');
		}
		return $v;
	}

	// ------------------------------------------------------------- naming

	/**
	 * The calendar URI out of what the client sent: a CalDAV URL or the
	 * bare URI – its last path segment. Null: empty or malformed.
	 */
	public static function calendarUri(mixed $value): ?string {
		if (!is_string($value)) {
			return null;
		}
		$value = trim($value);
		if ($value === '' || strlen($value) > 2048) {
			return null;
		}
		$path = str_contains($value, '://') ? parse_url($value, PHP_URL_PATH) : $value;
		if (!is_string($path)) {
			return null;
		}
		$segments = array_values(array_filter(explode('/', $path), static fn (string $s) => $s !== ''));
		if ($segments === []) {
			return null;
		}
		$uri = rawurldecode(end($segments));
		if ($uri === '.' || $uri === '..' || str_contains($uri, '/') || strlen($uri) > 255 || preg_match('/[\x00-\x1f]/', $uri)) {
			return null;
		}
		return $uri;
	}

	/** Etags come quoted from the dav backend; stored bare. */
	public static function bareEtag(mixed $etag): string {
		return is_string($etag) ? trim($etag, '"') : '';
	}

	// --------------------------------------------------------- role filter

	/**
	 * Who sees an entry: Team Admins everything, everyone their own,
	 * a Lead also the entries carrying a code of a project they lead – the
	 * code itself or a part of it (`d196.4.1` belongs to `d196`).
	 *
	 * @param list<string> $ledCodes lower-cased codes of the projects led (with subprojects)
	 * @param list<string> $codes the entry's codes
	 */
	public static function visible(string $role, string $me, array $ledCodes, string $entryUid, array $codes): bool {
		if (Role::manages($role) || $entryUid === $me) {
			return true;
		}
		if ($role !== Role::LEAD) {
			return false;
		}
		foreach ($codes as $c) {
			foreach ($ledCodes as $led) {
				if ($c === $led || str_starts_with($c, $led . '.')) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * A page of the book in revision order (0.28.0): `$chunk($cursor)` reads
	 * the next rows after a revision, `$keep` is the role filter; the page
	 * is filled from as many chunks as it takes. `more` is true when a
	 * further page may follow.
	 *
	 * @param callable(int):list<Entry> $chunk
	 * @param callable(Entry):bool $keep
	 * @return array{entries:list<Entry>,more:bool}
	 */
	public static function pageOf(callable $chunk, callable $keep, int $since, int $limit): array {
		$out = [];
		$more = false;
		$cursor = $since;
		while (count($out) < $limit) {
			$rows = $chunk($cursor);
			foreach ($rows as $e) {
				$cursor = $e->getRevision();
				if ($keep($e)) {
					$out[] = $e;
				}
			}
			if (count($rows) < $limit) {
				break;
			}
			if (count($out) >= $limit) {
				$more = true;
			}
		}
		if (count($out) > $limit) {
			$more = true;
			$out = array_slice($out, 0, $limit);
		}
		return ['entries' => $out, 'more' => $more];
	}

	/**
	 * The codes of the projects a Lead leads, with the subprojects',
	 * lower-cased.
	 *
	 * @param list<mixed> $projects the data of the projects led
	 * @return list<string>
	 */
	public static function ledCodes(array $projects): array {
		$out = [];
		foreach ($projects as $d) {
			if (!($d instanceof \stdClass)) {
				continue;
			}
			$json = json_encode($d);
			foreach (JobRules::projectCodes((array)json_decode($json === false ? '{}' : $json, true)) as $c) {
				$c = mb_strtolower($c);
				if (!in_array($c, $out, true)) {
					$out[] = $c;
				}
			}
		}
		return $out;
	}

	/** A vanished object: the book keeps it when it is billed, else a tombstone. */
	public static function keepsVanished(bool $billed): bool {
		return $billed;
	}
}
