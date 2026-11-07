<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The times of a correction (0.41.0, PLAN-LISTEN S6): `from` and `to`, ISO
 * 8601 with a zone, move start and end of a booking. One booking or one
 * occurrence takes both as they are (date included); a series as a whole
 * takes the time of day and the duration only – every VEVENT keeps its date,
 * and the exceptions' RECURRENCE-ID and the master's EXDATE/RDATE move with
 * the master's start, so the series stays whole. Pure.
 */
final class CorrectionTimes {
	public const FIELDS = ['from', 'to'];
	private const DAY = 86400;

	/**
	 * `from` and `to` of a body into the change; at least one may be missing,
	 * when both are there `to` lies after `from`.
	 *
	 * @param array<string,mixed> $change
	 * @throws ApiException 422 `correction_time_invalid`
	 */
	public static function parse(\stdClass $body, array &$change): void {
		$at = [];
		foreach (self::FIELDS as $f) {
			if (!property_exists($body, $f)) {
				continue;
			}
			$v = $body->$f;
			$ts = is_string($v) ? Time::parseIso(trim($v)) : null;
			if (!is_string($v) || $ts === null) {
				throw ApiException::invalid(Message::of('“{field}” must be a time in ISO 8601 with its zone, like 2026-10-21T09:00:00+02:00.', ['field' => $f]))
					->code(ErrorCode::CORRECTION_TIME_INVALID)->field($f);
			}
			$change[$f] = trim($v);
			$at[$f] = $ts;
		}
		if (isset($at['from'], $at['to']) && $at['to'] <= $at['from']) {
			throw ApiException::invalid(Message::of('The end “to” must lie after the start “from”.'))->code(ErrorCode::CORRECTION_TIME_INVALID)->field('to');
		}
	}

	/** Does the change move times? @param array<string,mixed> $change */
	public static function touches(array $change): bool {
		return isset($change['from']) || isset($change['to']);
	}

	/**
	 * The new start and end of the target blocks (wall time of each block's
	 * DTSTART, `YYYYMMDDTHHMMSS`) and the shift of the series' recurrence ids
	 * in seconds – or the reason why the times cannot change.
	 *
	 * @param list<list<string>> $blocks
	 * @param list<int> $targets
	 * @param array<string,mixed> $change
	 * @return array{times:array<int,array{0:string,1:string}>,shift:int}|string
	 */
	public static function plan(array $blocks, array $targets, array $change, bool $whole, int $master): array|string {
		$base = $blocks[$whole ? $master : $targets[0]] ?? [];
		$props = CorrectionRules::propsOf($base);
		$start = self::timeProp($props['DTSTART'][0] ?? null);
		if ($start === null || $start['allDay']) {
			return Message::of('An all-day booking has no start and end to correct.')->text();
		}
		$end = self::timeProp($props['DTEND'][0] ?? null);
		$baseEnd = $end !== null ? $end['wall'] : self::plusDuration($start['wall'], $props['DURATION'][0]['value'] ?? '');
		$from = isset($change['from']) ? self::wallOf((string)$change['from'], $start['zone']) : null;
		$to = isset($change['to']) ? self::wallOf((string)$change['to'], $end['zone'] ?? $start['zone']) : null;
		if (!$whole) {
			$a = $from ?? $start['wall'];
			$b = $to ?? $baseEnd;
			if (self::secs($b) <= self::secs($a)) {
				return Message::of('The end must lie after the start.')->text();
			}
			return ['times' => [$targets[0] => [$a, $b]], 'shift' => 0];
		}
		// A series as a whole: time of day and duration, never the date.
		$day = substr($start['wall'], 0, 9);
		if ($from !== null) {
			$tod = substr($from, 9, 6);
			$dur = $to !== null ? self::secs($to) - self::secs($from) : self::secs($baseEnd) - self::secs($day . $tod);
		} else {
			$tod = substr($start['wall'], 9, 6);
			$dur = self::secs((string)$to) - self::secs(substr((string)$to, 0, 9) . $tod);
			if ($dur <= 0) {
				$dur += self::DAY;
			}
		}
		if ($dur <= 0) {
			return Message::of('The end must lie after the start.')->text();
		}
		$times = [];
		foreach ($targets as $i) {
			$s = self::timeProp(CorrectionRules::propsOf($blocks[$i])['DTSTART'][0] ?? null);
			if ($s === null || $s['allDay']) {
				continue;
			}
			$a = substr($s['wall'], 0, 9) . $tod;
			$times[$i] = [$a, self::plus($a, $dur)];
		}
		return ['times' => $times, 'shift' => self::secs($day . $tod) - self::secs($start['wall'])];
	}

	/**
	 * One block with its new start and end; with a shift the RECURRENCE-ID
	 * and the EXDATE/RDATE times move as well (date values stay). DTEND takes
	 * the form of DTSTART, a DURATION goes.
	 *
	 * @param list<string> $lines
	 * @param array{0:string,1:string}|null $times
	 * @return list<string>
	 */
	public static function write(array $lines, ?array $times, int $shift): array {
		$props = CorrectionRules::propsOf($lines);
		$start = $props['DTSTART'][0] ?? null;
		$utc = $start !== null && str_ends_with($start['value'], 'Z');
		$params = $start !== null ? CorrectionRules::paramText($start['params']) : '';
		$out = [];
		$nested = 0;
		$endWritten = false;
		foreach ($lines as $line) {
			$upper = strtoupper($line);
			if (str_starts_with($upper, 'BEGIN:')) {
				$nested++;
			}
			$p = $nested === 0 ? EntryRules::property($line) : null;
			if (str_starts_with($upper, 'END:')) {
				$nested--;
			}
			$name = $p['name'] ?? null;
			if ($times !== null && $name === 'DTSTART') {
				$out[] = 'DTSTART' . $params . ':' . $times[0] . ($utc ? 'Z' : '');
				continue;
			}
			if ($times !== null && ($name === 'DTEND' || $name === 'DURATION')) {
				if (!$endWritten) {
					$out[] = 'DTEND' . $params . ':' . $times[1] . ($utc ? 'Z' : '');
					$endWritten = true;
				}
				continue;
			}
			if ($shift !== 0 && $p !== null && in_array($name, ['RECURRENCE-ID', 'EXDATE', 'RDATE'], true)) {
				$values = array_map(static fn (string $v): string => self::shifted($v, $shift), explode(',', $p['value']));
				$out[] = $name . CorrectionRules::paramText($p['params']) . ':' . implode(',', $values);
				continue;
			}
			$out[] = $line;
		}
		if ($times !== null && !$endWritten) {
			$out[] = 'DTEND' . $params . ':' . $times[1] . ($utc ? 'Z' : '');
		}
		return $out;
	}

	/**
	 * Does the block carry the planned times already?
	 *
	 * @param list<string> $lines
	 * @param array{0:string,1:string} $times
	 */
	public static function metBy(array $lines, array $times): bool {
		$props = CorrectionRules::propsOf($lines);
		$s = self::timeProp($props['DTSTART'][0] ?? null);
		$e = self::timeProp($props['DTEND'][0] ?? null);
		return $s !== null && $e !== null && !isset($props['DURATION']) && $s['wall'] === $times[0] && $e['wall'] === $times[1];
	}

	/**
	 * A DTSTART/DTEND: its wall time, its zone (TZID or UTC; null when
	 * floating or a zone PHP does not know), all day or not.
	 *
	 * @param array{i:int,value:string,params:array<string,string>}|null $p
	 * @return array{wall:string,zone:?\DateTimeZone,allDay:bool}|null
	 */
	private static function timeProp(?array $p): ?array {
		if ($p === null) {
			return null;
		}
		$digits = substr(preg_replace('/[^0-9T]/', '', $p['value']) ?? '', 0, 15);
		$allDay = strtoupper($p['params']['VALUE'] ?? '') === 'DATE' || strlen($digits) === 8;
		if (!$allDay && preg_match('/^\d{8}T\d{6}$/', $digits) !== 1) {
			return null;
		}
		$zone = null;
		$tzid = $p['params']['TZID'] ?? '';
		if (str_ends_with($p['value'], 'Z')) {
			$zone = new \DateTimeZone('UTC');
		} elseif ($tzid !== '') {
			try {
				$zone = new \DateTimeZone($tzid);
			} catch (\Exception) {
				$zone = null;
			}
		}
		return ['wall' => $digits, 'zone' => $zone, 'allDay' => $allDay];
	}

	/** The wall time of an ISO time in a zone; without a zone the digits as the client wrote them. */
	private static function wallOf(string $iso, ?\DateTimeZone $zone): string {
		if ($zone !== null) {
			return (new \DateTimeImmutable($iso))->setTimezone($zone)->format('Ymd\THis');
		}
		return substr(preg_replace('/[^0-9T]/', '', substr($iso, 0, 19)) ?? '', 0, 15);
	}

	/** Wall time as seconds (wall arithmetic, zone aside). */
	private static function secs(string $wall): int {
		$t = \DateTimeImmutable::createFromFormat('!Ymd\THis', $wall, new \DateTimeZone('UTC'));
		return $t === false ? 0 : $t->getTimestamp();
	}

	private static function plus(string $wall, int $seconds): string {
		return gmdate('Ymd\THis', self::secs($wall) + $seconds);
	}

	/** Start plus an iCal DURATION (`PT1H30M`, `P1D`); without one the start. */
	private static function plusDuration(string $wall, string $duration): string {
		if (preg_match('/^\+?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', trim($duration), $m) !== 1) {
			return $wall;
		}
		$s = (int)($m[1] ?? 0) * 7 * self::DAY + (int)($m[2] ?? 0) * self::DAY + (int)($m[3] ?? 0) * 3600 + (int)($m[4] ?? 0) * 60 + (int)($m[5] ?? 0);
		return self::plus($wall, $s);
	}

	/** A recurrence time moved by seconds; dates (`YYYYMMDD`) stay. */
	private static function shifted(string $value, int $shift): string {
		$digits = substr(preg_replace('/[^0-9T]/', '', $value) ?? '', 0, 15);
		if (preg_match('/^\d{8}T\d{6}$/', $digits) !== 1) {
			return $value;
		}
		return self::plus($digits, $shift) . (str_ends_with($value, 'Z') ? 'Z' : '');
	}
}
