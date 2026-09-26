<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Setzt einzelne VCALENDAR-Objekte (je Termin eines, wie der Export sie
 * liefert) zu einer .ics zusammen: eigener Kopf, jede VTIMEZONE einmal (nach
 * TZID), dann alle übrigen Komponenten in ihrer Reihenfolge. Arbeitet auf
 * Zeilen, ohne Bibliothek. Rein.
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
	 * Die Komponenten direkt unter VCALENDAR samt ihren Unterkomponenten.
	 * Eigenschaften des VCALENDAR selbst (VERSION, PRODID, METHOD, X-WR-…)
	 * fallen weg; eine nicht geschlossene Komponente am Ende auch.
	 *
	 * @return list<array{0:string,1:list<string>}> Name, Zeilen
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
			// Fortsetzungszeilen beginnen mit Leerraum und sind nie BEGIN/END.
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
	 * Wert einer Eigenschaft der Komponente selbst (nicht ihrer
	 * Unterkomponenten), entfaltet; null, wenn sie fehlt.
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
