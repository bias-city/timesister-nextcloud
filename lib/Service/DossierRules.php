<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The project dossier (0.22.0), pure: which entries of the book belong to
 * the project, `hours.csv` of them and `ledger.txt` of its invoices'
 * ledger lines.
 */
final class DossierRules {
	public const HOURS_HEAD = ['person', 'event_uid', 'start', 'end', 'hours', 'series', 'codes', 'title'];

	/** Does a code of the entry name the project (its code, or a work package below it: D196.4 belongs to D196)? @param list<string> $codes @param list<string> $projectCodes */
	public static function matches(array $codes, array $projectCodes): bool {
		foreach ($codes as $c) {
			foreach ($projectCodes as $p) {
				if ($p !== '' && (strcasecmp($c, $p) === 0 || stripos($c, $p . '.') === 0)) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The project's codes.
	 *
	 * @return list<string>
	 */
	public static function codesOf(\stdClass $project): array {
		$out = [];
		foreach (Fields::list($project, 'codes') as $c) {
			if (is_string($c) && $c !== '') {
				$out[] = $c;
			}
		}
		return $out;
	}

	/**
	 * Start, end and hours of an event's master; a series is named, its
	 * hours are those of one occurrence; an all-day event has no hours.
	 *
	 * @return array{start:string,end:string,hours:?float,series:bool}
	 */
	public static function occurrence(string $ics): array {
		$c = EntryRules::components($ics, ['VEVENT']);
		$master = $c[0] ?? null;
		foreach ($c as $x) {
			if (!isset($x['props']['RECURRENCE-ID'])) {
				$master = $x;
				break;
			}
		}
		$start = $master['props']['DTSTART'][0]['value'] ?? '';
		$end = $master['props']['DTEND'][0]['value'] ?? '';
		$a = self::time($start);
		$b = self::time($end);
		return [
			'start' => self::iso($start),
			'end' => self::iso($end),
			'hours' => $a === null || $b === null || $b < $a ? null : round((float)($b - $a) / 3600.0, 2),
			'series' => isset($master['props']['RRULE']),
		];
	}

	/** `20261003T083000` (local or with Z) as seconds; a date alone: null. */
	private static function time(string $v): ?int {
		if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z?$/', $v, $m) !== 1) {
			return null;
		}
		$t = gmmktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
		return $t === false ? null : $t;
	}

	private static function iso(string $v): string {
		if (preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2}))?/', $v, $m) !== 1) {
			return $v;
		}
		return $m[1] . '-' . $m[2] . '-' . $m[3] . (isset($m[4]) ? 'T' . $m[4] . ':' . $m[5] . ':' . $m[6] : '');
	}

	/**
	 * @param list<array{person:string,event_uid:string,start:string,end:string,hours:?float,series:bool,codes:list<string>,title:string}> $rows
	 */
	public static function hoursCsv(array $rows): string {
		$out = ExportRules::BOM . ExportRules::line(self::HOURS_HEAD);
		usort($rows, static fn (array $a, array $b): int => [$a['start'], $a['person']] <=> [$b['start'], $b['person']]);
		foreach ($rows as $r) {
			$out .= ExportRules::line([$r['person'], $r['event_uid'], $r['start'], $r['end'],
				$r['hours'] === null ? '' : ExportRules::amount($r['hours']), $r['series'] ? '1' : '0', implode(',', $r['codes']), $r['title']]);
		}
		return $out;
	}

	/** One line per ledger entry: seq, time, actor, action, number, hashes. @param list<array<string,mixed>> $entries */
	public static function ledgerText(array $entries): string {
		$out = '';
		foreach ($entries as $e) {
			$out .= implode(' ', [(string)$e['seq'], (string)$e['at'], (string)$e['actor'], (string)$e['action'], (string)$e['number'],
				'payload ' . (string)$e['payload_hash'], 'prev ' . (string)$e['prev_hash'], 'hash ' . (string)$e['hash']]) . "\n";
		}
		return $out;
	}
}
