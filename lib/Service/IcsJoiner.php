<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Assembles individual VCALENDAR objects (one per event, as the export
 * delivers them) into one .ics: its own header, every VTIMEZONE once (by
 * TZID), then all remaining components in their order. Works on lines,
 * without a library. Pure.
 */
final class IcsJoiner {
	/** @param iterable<string> $calendars */
	public static function join(iterable $calendars, string $prodId): string {
		$zones = [];
		$body = [];
		foreach ($calendars as $ics) {
			foreach (self::components($ics) as [$name, $lines]) {
				if ($name === 'VTIMEZONE') {
					$zones[self::property($lines, 'TZID') ?? ''] ??= $lines;
				} else {
					$body[] = $lines;
				}
			}
		}
		$out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:" . $prodId . "\r\nCALSCALE:GREGORIAN\r\n";
		foreach ([...array_values($zones), ...$body] as $lines) {
			$out .= implode("\r\n", $lines) . "\r\n";
		}
		return $out . "END:VCALENDAR\r\n";
	}

	/**
	 * The components directly under VCALENDAR, with their sub-components.
	 * Properties of VCALENDAR itself (VERSION, PRODID, METHOD, X-WR-…) are
	 * dropped; an unclosed component at the end too.
	 *
	 * @return list<array{0:string,1:list<string>}> name, lines
	 */
	public static function components(string $ics): array {
		if (str_starts_with($ics, "\xEF\xBB\xBF")) {
			$ics = substr($ics, 3);
		}
		$out = [];
		$depth = 0;
		$name = '';
		$cur = null;
		foreach (preg_split('/\r\n|\n|\r/', $ics) ?: [] as $line) {
			if ($line === '') {
				continue;
			}
			// Continuation lines start with whitespace and are never BEGIN/END.
			$upper = strtoupper($line);
			if (str_starts_with($upper, 'BEGIN:')) {
				$depth++;
				if ($depth === 2) {
					$name = trim(substr($upper, 6));
					$cur = [$line];
				} elseif ($depth > 2 && $cur !== null) {
					$cur[] = $line;
				}
				continue;
			}
			if (str_starts_with($upper, 'END:')) {
				if ($depth > 2 && $cur !== null) {
					$cur[] = $line;
				} elseif ($depth === 2 && $cur !== null) {
					$cur[] = $line;
					$out[] = [$name, $cur];
					$cur = null;
				}
				$depth = max(0, $depth - 1);
				continue;
			}
			if ($depth >= 2 && $cur !== null) {
				$cur[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Value of a property of the component itself (not its sub-components),
	 * unfolded; null if it is missing.
	 *
	 * @param list<string> $lines
	 */
	public static function property(array $lines, string $prop): ?string {
		$depth = 0;
		$n = count($lines);
		for ($i = 1; $i < $n - 1; $i++) {
			$upper = strtoupper($lines[$i]);
			if (str_starts_with($upper, 'BEGIN:')) {
				$depth++;
			} elseif (str_starts_with($upper, 'END:')) {
				$depth--;
			} elseif ($depth === 0 && (str_starts_with($upper, $prop . ':') || str_starts_with($upper, $prop . ';'))) {
				$line = $lines[$i];
				while ($i + 1 < $n && in_array($lines[$i + 1][0] ?? '', [' ', "\t"], true)) {
					$line .= substr($lines[++$i], 1);
				}
				$colon = strpos($line, ':');
				return $colon === false ? null : substr($line, $colon + 1);
			}
		}
		return null;
	}
}
