<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The weekly numbers a person's client reports (0.7.2): per week the
 * capacity line and what their Jobs take, per Job booked and planned hours,
 * and the weight of each day for spreading a Job. With them the server
 * checks the capacity when a sender accepts a counter-proposal. Pure.
 */
final class JobWeeks {
	public const MAX_WEEKS = 80;
	public const MAX_JOBS = 500;
	public const MAX_WEEK_JOBS = 200;
	/** Hours of one week, and a sanity limit for every number. */
	public const MAX_HOURS = 1000.0;
	/** Rounding room before a week counts as above the line (as in the client). */
	public const ROOM = 0.05;

	// ------------------------------------------------------------ parsing

	/**
	 * The report as sent by the client, checked and normalised; weeks sorted.
	 *
	 * @return array{pensum:?float,jobs:list<string>,weeks:list<array{start:string,full:float,available:float,holidays:float,vacation:float,days:list<float>,jobs:array<string,array{booked:float,planned:float}>}>}
	 */
	public static function parse(\stdClass $b): array {
		$pensum = $b->pensum ?? null;
		if ($pensum !== null) {
			$pensum = self::number($pensum, 'pensum', 1000.0);
		}
		$jobs = $b->jobs ?? [];
		if (!is_array($jobs) || count($jobs) > self::MAX_JOBS) {
			throw ApiException::invalid(Message::of('“{field}” must be a list of at most {limit} entries.', ['field' => 'jobs', 'limit' => self::MAX_JOBS]));
		}
		$keys = [];
		foreach ($jobs as $k) {
			if (!is_string($k)) {
				throw ApiException::invalidField('jobs');
			}
			JobRules::checkKey($k);
			$keys[$k] = true;
		}
		$weeks = $b->weeks ?? null;
		if (!is_array($weeks) || count($weeks) > self::MAX_WEEKS) {
			throw ApiException::invalid(Message::of('“{field}” must be a list of at most {limit} entries.', ['field' => 'weeks', 'limit' => self::MAX_WEEKS]));
		}
		$out = [];
		foreach ($weeks as $w) {
			if (!($w instanceof \stdClass)) {
				throw ApiException::invalidField('weeks');
			}
			$start = JobRules::day($w->start ?? null, 'start');
			if (self::monday($start) !== $start) {
				throw ApiException::invalid(Message::of('A week starts on a Monday: {day}.', ['day' => $start]));
			}
			if (isset($out[$start])) {
				throw ApiException::invalid(Message::of('The week {day} is there twice.', ['day' => $start]));
			}
			$days = $w->days ?? [1, 1, 1, 1, 1, 0, 0];
			if (!is_array($days) || count($days) !== 7) {
				throw ApiException::invalidField('days');
			}
			$parts = $w->jobs ?? [];
			// An empty map may come as [] from JSON encoders.
			$parts = $parts === [] ? new \stdClass() : $parts;
			if (!($parts instanceof \stdClass)) {
				throw ApiException::invalidField('jobs');
			}
			/** @var array<array-key,mixed> $all numeric keys come as int */
			$all = get_object_vars($parts);
			if (count($all) > self::MAX_WEEK_JOBS) {
				throw ApiException::tooLarge(Message::of('At most {limit} entries per call.', ['limit' => self::MAX_WEEK_JOBS]));
			}
			$p = [];
			foreach ($all as $k => $v) {
				$k = (string)$k;
				JobRules::checkKey($k);
				if (!($v instanceof \stdClass)) {
					throw ApiException::invalidField('jobs');
				}
				$p[$k] = ['booked' => self::number($v->booked ?? 0, 'booked'), 'planned' => self::number($v->planned ?? 0, 'planned')];
			}
			ksort($p);
			$out[$start] = [
				'start' => $start,
				'full' => self::number($w->full ?? 0, 'full'),
				'available' => self::number($w->available ?? 0, 'available'),
				'holidays' => self::number($w->holidays ?? 0, 'holidays', 7.0),
				'vacation' => self::number($w->vacation ?? 0, 'vacation'),
				'days' => array_map(static fn (mixed $d): float => self::number($d, 'days', 1.0), array_values($days)),
				'jobs' => $p,
			];
		}
		ksort($out);
		$keys = array_keys($keys);
		sort($keys);
		return ['pensum' => $pensum, 'jobs' => array_map('strval', $keys), 'weeks' => array_values($out)];
	}

	private static function number(mixed $v, string $field, float $max = self::MAX_HOURS): float {
		if (is_int($v)) {
			$v = (float)$v;
		}
		if (!is_float($v) || !is_finite($v) || $v < 0 || $v > $max) {
			throw ApiException::invalidField($field);
		}
		return round($v, 2);
	}

	// ---------------------------------------------------------- reading

	/**
	 * A stored report for a reader: per week the load, the Jobs the reader
	 * sees by key and the others merged into `other` (null if none).
	 *
	 * @param array<string,mixed> $report from {@see parse()}
	 * @param callable(string):bool $sees whether the reader sees this Job
	 * @return list<array<string,mixed>>
	 */
	public static function present(array $report, callable $sees): array {
		$out = [];
		foreach ((array)($report['weeks'] ?? []) as $w) {
			$w = (array)$w;
			$jobs = [];
			$other = ['booked' => 0.0, 'planned' => 0.0];
			$load = 0.0;
			foreach ((array)($w['jobs'] ?? []) as $k => $p) {
				$p = (array)$p;
				$b = (float)($p['booked'] ?? 0);
				$pl = (float)($p['planned'] ?? 0);
				$load += $b + $pl;
				if ($sees((string)$k)) {
					$jobs[(string)$k] = ['booked' => $b, 'planned' => $pl];
				} else {
					$other['booked'] += $b;
					$other['planned'] += $pl;
				}
			}
			$start = (string)($w['start'] ?? '');
			$out[] = [
				'start' => $start,
				'week' => self::weekNumber($start),
				'full' => (float)($w['full'] ?? 0),
				'available' => (float)($w['available'] ?? 0),
				'holidays' => (float)($w['holidays'] ?? 0),
				'vacation' => (float)($w['vacation'] ?? 0),
				'load' => round($load, 2),
				'jobs' => $jobs === [] ? new \stdClass() : $jobs,
				'other' => $other['booked'] + $other['planned'] > 0.004
					? ['booked' => round($other['booked'], 2), 'planned' => round($other['planned'], 2)] : null,
			];
		}
		return $out;
	}

	// ----------------------------------------------------------- the check

	/**
	 * **Capacity check** for a Job someone is to take on (`probe`: key,
	 * hours, start, end): the weeks from the current one on in which it
	 * lifts the person above their capacity line – only where it takes
	 * something itself and only weeks the report covers. `valid`: the Jobs
	 * that are still the person's (others in the report no longer count);
	 * `extra`: their running Jobs the report does not know yet, with the
	 * hours still open.
	 *
	 * @param array<string,mixed> $report from {@see parse()}
	 * @param array{key:string,hours:float,start:string,end:string} $probe
	 * @param list<string> $valid
	 * @param list<array{key:string,rest:float,start:string,end:string}> $extra
	 * @return list<array{start:string,week:int,load:float,available:float,over:float}>
	 */
	public static function over(array $report, array $probe, array $valid, array $extra, string $today): array {
		$weeks = [];
		foreach ((array)($report['weeks'] ?? []) as $w) {
			$w = (array)$w;
			$weeks[(string)$w['start']] = $w;
		}
		$load = [];
		foreach ($weeks as $start => $w) {
			$sum = 0.0;
			foreach ((array)($w['jobs'] ?? []) as $k => $p) {
				if ((string)$k !== $probe['key'] && in_array((string)$k, $valid, true)) {
					$p = (array)$p;
					$sum += (float)($p['booked'] ?? 0) + (float)($p['planned'] ?? 0);
				}
			}
			$load[$start] = $sum;
		}
		foreach ($extra as $e) {
			if ($e['key'] === $probe['key']) {
				continue;
			}
			foreach (self::spread($weeks, $e['rest'], $e['start'], $e['end'], $today) as $start => $h) {
				$load[$start] = ($load[$start] ?? 0.0) + $h;
			}
		}
		$now = self::monday($today);
		$out = [];
		foreach (self::spread($weeks, $probe['hours'], $probe['start'], $probe['end'], $today) as $start => $h) {
			if ($start < $now || $h <= 0.01 || !isset($weeks[$start])) {
				continue;
			}
			$available = (float)($weeks[$start]['available'] ?? 0);
			$total = ($load[$start] ?? 0.0) + $h;
			if ($total > $available + self::ROOM) {
				$out[] = ['start' => $start, 'week' => self::weekNumber($start), 'load' => round($total, 2),
					'available' => round($available, 2), 'over' => round($total - $available, 2)];
			}
		}
		return $out;
	}

	/**
	 * `hours` evenly over the working days from `max(from, today)` to `to`
	 * (the day weights of the report; unreported weeks Monday–Friday). No
	 * working day left: over the weekdays, else over all days.
	 *
	 * @param array<string,array<string,mixed>> $weeks start → week
	 * @return array<string,float> Monday → hours
	 */
	public static function spread(array $weeks, float $hours, string $from, string $to, string $today): array {
		$a = max($from, $today);
		if ($hours <= 0 || $to < $a || !Time::isDay($a) || !Time::isDay($to)) {
			return [];
		}
		$utc = new \DateTimeZone('UTC');
		$d = new \DateTimeImmutable($a, $utc);
		$end = new \DateTimeImmutable($to, $utc);
		$days = [];
		for ($i = 0; $d <= $end && $i < JobRules::MAX_DAYS; $i++, $d = $d->modify('+1 day')) {
			$day = $d->format('Y-m-d');
			$dow = (int)$d->format('N') - 1;
			$monday = $d->modify('-' . $dow . ' days')->format('Y-m-d');
			$w = isset($weeks[$monday]) ? (float)(((array)$weeks[$monday]['days'])[$dow] ?? 0) : ($dow < 5 ? 1.0 : 0.0);
			$days[] = [$monday, $w, $dow < 5 ? 1.0 : 0.0];
		}
		$col = 1;
		$sum = array_sum(array_column($days, 1));
		if ($sum <= 0) {
			$col = 2;
			$sum = array_sum(array_column($days, 2));
		}
		$div = $sum > 0 ? $sum : (float)count($days);
		$out = [];
		foreach ($days as $x) {
			$w = $sum > 0 ? $x[$col] : 1.0;
			if ($w > 0) {
				$out[$x[0]] = ($out[$x[0]] ?? 0.0) + $hours * $w / $div;
			}
		}
		return $out;
	}

	/** The weeks as a part of a sentence: "42 (+3.5 h), 43 (+1 h)". @param list<array{week:int,over:float}> $over */
	public static function weeksText(array $over): string {
		return implode(', ', array_map(static fn (array $w): string => $w['week'] . ' (+' . JobRules::num($w['over']) . ' h)', $over));
	}

	public static function monday(string $day): string {
		$d = new \DateTimeImmutable($day, new \DateTimeZone('UTC'));
		return $d->modify('-' . ((int)$d->format('N') - 1) . ' days')->format('Y-m-d');
	}

	public static function weekNumber(string $day): int {
		return Time::isDay($day) ? (int)(new \DateTimeImmutable($day, new \DateTimeZone('UTC')))->format('W') : 0;
	}
}
