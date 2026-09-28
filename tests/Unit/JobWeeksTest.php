<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\JobWeeks;
use PHPUnit\Framework\TestCase;

/** The weekly numbers of a person's Jobs (0.7.2): reading the report, spreading a Job, the capacity check. */
class JobWeeksTest extends TestCase {
	/** Monday of week 40. */
	private const MON = '2026-09-28';

	/** @param array<string,mixed> $v */
	private static function obj(array $v): \stdClass {
		return json_decode((string)json_encode($v));
	}

	private static function code(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getErrorCode();
		}
		return 'ok';
	}

	/** @param array<string,array{booked:float|int,planned:float|int}> $jobs @return array<string,mixed> */
	private static function week(string $start, float $available, array $jobs = [], array $days = [1, 1, 1, 1, 1, 0, 0]): array {
		return ['start' => $start, 'full' => 42, 'available' => $available, 'holidays' => 0, 'vacation' => 0, 'days' => $days, 'jobs' => $jobs];
	}

	/** @param list<array<string,mixed>> $weeks @return array<string,mixed> */
	private static function report(array $weeks, array $jobs = ['j-a']): array {
		return JobWeeks::parse(self::obj(['pensum' => 80, 'jobs' => $jobs, 'weeks' => $weeks]));
	}

	public function testParseNormalises(): void {
		$r = self::report([
			self::week('2026-10-05', 33.6, ['j-b' => ['booked' => 0, 'planned' => 4.333], 'j-a' => ['booked' => 1, 'planned' => 2]]),
			self::week(self::MON, 33.6),
		], ['j-b', 'j-a']);
		$this->assertSame(80.0, $r['pensum']);
		$this->assertSame(['j-a', 'j-b'], $r['jobs']);
		$this->assertSame([self::MON, '2026-10-05'], array_column($r['weeks'], 'start'));
		$this->assertSame(['j-a', 'j-b'], array_keys($r['weeks'][1]['jobs']));
		$this->assertSame(4.33, $r['weeks'][1]['jobs']['j-b']['planned']);
		$this->assertSame([1.0, 1.0, 1.0, 1.0, 1.0, 0.0, 0.0], $r['weeks'][0]['days']);
	}

	public function testParseRejects(): void {
		$this->assertSame('422 invalid', self::code(fn () => self::report([self::week('2026-09-29', 30)])), 'not a Monday');
		$this->assertSame('422 invalid', self::code(fn () => self::report([self::week(self::MON, 30), self::week(self::MON, 30)])), 'twice');
		$this->assertSame('422 invalid', self::code(fn () => self::report([self::week(self::MON, -1)])), 'negative');
		$this->assertSame('422 invalid', self::code(fn () => self::report([self::week(self::MON, 30, [], [1, 1, 1])])), 'seven days');
		$this->assertSame('422 invalid', self::code(fn () => self::report([self::week(self::MON, 30, [], [2, 1, 1, 1, 1, 0, 0])])), 'weight at most 1');
		$this->assertSame('400 invalid', self::code(fn () => self::report([self::week(self::MON, 30, ['a b' => ['booked' => 1, 'planned' => 0]])])), 'key');
		$this->assertSame('422 invalid', self::code(fn () => JobWeeks::parse(self::obj(['weeks' => 'x']))));
		$many = [];
		for ($i = 0; $i <= JobWeeks::MAX_WEEKS; $i++) {
			$many[] = self::week(date('Y-m-d', strtotime(self::MON . " +$i weeks")), 30);
		}
		$this->assertSame('422 invalid', self::code(fn () => self::report($many)), 'too many weeks');
		$this->assertSame('ok', self::code(fn () => self::report(array_slice($many, 0, JobWeeks::MAX_WEEKS))));
	}

	public function testSpreadOverWorkingDays(): void {
		$weeks = [];
		foreach (self::report([self::week(self::MON, 30), self::week('2026-10-05', 30, [], [0, 1, 1, 1, 1, 0, 0])])['weeks'] as $w) {
			$weeks[$w['start']] = $w;
		}
		// Ten working days, the Monday of the second week off: 5 and 4 of 9.
		$s = JobWeeks::spread($weeks, 18, self::MON, '2026-10-09', self::MON);
		$this->assertEqualsWithDelta(10.0, $s[self::MON], 1e-9);
		$this->assertEqualsWithDelta(8.0, $s['2026-10-05'], 1e-9);
		// From today on: Wednesday to Friday of week 40, then Tuesday to Friday.
		$s = JobWeeks::spread($weeks, 14, '2026-09-01', '2026-10-09', '2026-09-30');
		$this->assertEqualsWithDelta(6.0, $s[self::MON], 1e-9);
		$this->assertEqualsWithDelta(8.0, $s['2026-10-05'], 1e-9);
		// Unreported weeks: Monday to Friday.
		$s = JobWeeks::spread($weeks, 10, '2026-10-12', '2026-10-23', self::MON);
		$this->assertEqualsWithDelta(5.0, $s['2026-10-12'], 1e-9);
		$this->assertEqualsWithDelta(5.0, $s['2026-10-19'], 1e-9);
		// Only a weekend: all days.
		$s = JobWeeks::spread($weeks, 4, '2026-10-03', '2026-10-04', self::MON);
		$this->assertEqualsWithDelta(4.0, $s[self::MON], 1e-9);
		// Over: nothing.
		$this->assertSame([], JobWeeks::spread($weeks, 4, '2026-09-01', '2026-09-20', self::MON));
	}

	public function testOverOnlyWhereTheJobTakesSomething(): void {
		$r = self::report([
			self::week('2026-09-21', 10, ['j-a' => ['booked' => 20, 'planned' => 0]]),
			self::week(self::MON, 32, ['j-a' => ['booked' => 10, 'planned' => 20]]),
			self::week('2026-10-05', 32, ['j-a' => ['booked' => 0, 'planned' => 10]]),
		]);
		$probe = ['key' => 'j-new', 'hours' => 10.0, 'start' => self::MON, 'end' => '2026-10-02'];
		$o = JobWeeks::over($r, $probe, ['j-a'], [], self::MON);
		$this->assertCount(1, $o);
		$this->assertSame(['start' => self::MON, 'week' => 40, 'load' => 40.0, 'available' => 32.0, 'over' => 8.0], $o[0]);
		$this->assertSame('40 (+8 h)', JobWeeks::weeksText($o));
		// The same hours in week 41 fit; the past week above the line is not checked.
		$this->assertSame([], JobWeeks::over($r, ['start' => '2026-10-05', 'end' => '2026-10-09'] + $probe, ['j-a'], [], self::MON));
		// A Job that is no longer the person's does not count.
		$this->assertSame([], JobWeeks::over($r, $probe, [], [], self::MON));
		// The Job itself in the report does not count twice.
		$this->assertSame([], JobWeeks::over($r, ['key' => 'j-a', 'hours' => 2.0] + $probe, ['j-a'], [], self::MON));
		// A running Job the report does not know yet counts with its open hours.
		$extra = [['key' => 'j-late', 'rest' => 10.0, 'start' => '2026-10-05', 'end' => '2026-10-09']];
		$o = JobWeeks::over($r, ['start' => '2026-10-05', 'end' => '2026-10-09', 'hours' => 14.0] + $probe, ['j-a'], $extra, self::MON);
		$this->assertSame([['start' => '2026-10-05', 'week' => 41, 'load' => 34.0, 'available' => 32.0, 'over' => 2.0]], $o);
		// Weeks the report does not cover are not checked.
		$this->assertSame([], JobWeeks::over($r, ['start' => '2026-11-02', 'end' => '2026-11-06', 'hours' => 90.0] + $probe, ['j-a'], [], self::MON));
		// Within the rounding room.
		$this->assertSame([], JobWeeks::over($r, ['hours' => 2.04] + $probe, ['j-a'], [], self::MON));
	}

	/**
	 * Tim has a vacation in week 42 and a counter-proposal of 40 h for
	 * 12.10.–19.10.: week 43 holds (40 of 42 h), but all 40 h fall on the
	 * Monday after the vacation – above a full day of 8.4 h (0.7.3).
	 */
	public function testOverADayAfterTheVacation(): void {
		$r = self::report([
			self::week('2026-10-12', 0, [], [0, 0, 0, 0, 0, 0, 0]),
			self::week('2026-10-19', 42),
		], []);
		$probe = ['key' => 'j-tim', 'hours' => 40.0, 'start' => '2026-10-12', 'end' => '2026-10-19'];
		$o = JobWeeks::over($r, $probe, [], [], self::MON);
		$this->assertSame([['start' => '2026-10-19', 'week' => 43, 'load' => 40.0, 'available' => 8.4, 'over' => 31.6, 'day' => '2026-10-19']], $o);
		$this->assertSame('43 (+31.6 h, 2026-10-19)', JobWeeks::weeksText($o));
		// Four days: 10 h each, still too much; five days: 8 h each fit.
		$this->assertCount(1, JobWeeks::over($r, ['end' => '2026-10-22'] + $probe, [], [], self::MON));
		$this->assertSame([], JobWeeks::over($r, ['end' => '2026-10-23'] + $probe, [], [], self::MON));
		// The other Jobs count per day too: 10 h in week 43 leave 6.4 h a day,
		// 30 h over four days (7.5 h each) do not fit although the week holds.
		$r = self::report([
			self::week('2026-10-12', 0, [], [0, 0, 0, 0, 0, 0, 0]),
			self::week('2026-10-19', 42, ['j-a' => ['booked' => 0, 'planned' => 10]]),
		]);
		$o = JobWeeks::over($r, ['hours' => 30.0, 'end' => '2026-10-22'] + $probe, ['j-a'], [], self::MON);
		$this->assertSame('2026-10-19', $o[0]['day'] ?? null);
		$this->assertEqualsWithDelta(1.1, $o[0]['over'], 1e-9);
		$this->assertSame([], JobWeeks::over($r, ['hours' => 30.0, 'end' => '2026-10-23'] + $probe, ['j-a'], [], self::MON));
		// Only a weekend: no working day, no capacity.
		$o = JobWeeks::over($r, ['start' => '2026-10-24', 'end' => '2026-10-25', 'hours' => 2.0] + $probe, ['j-a'], [], self::MON);
		$this->assertSame('2026-10-24', $o[0]['day'] ?? null);
	}

	/** In the current week the booked hours lie before today and do not count per day. */
	public function testDayLoadInTheCurrentWeek(): void {
		$thu = '2026-10-01';
		$r = self::report([self::week(self::MON, 42, ['j-a' => ['booked' => 25.2, 'planned' => 4.8]])]);
		$probe = ['key' => 'j-new', 'hours' => 12.0, 'start' => $thu, 'end' => '2026-10-02'];
		$this->assertSame([], JobWeeks::over($r, $probe, ['j-a'], [], $thu), '2.4 + 6 h a day');
		$o = JobWeeks::over($r, ['hours' => 8.0, 'end' => $thu] + $probe, ['j-a'], [], $thu);
		$this->assertSame(['start' => self::MON, 'week' => 40, 'load' => 10.4, 'available' => 8.4, 'over' => 2.0, 'day' => $thu], $o[0]);
	}

	public function testPresentMergesWhatTheReaderDoesNotSee(): void {
		$r = self::report([self::week(self::MON, 32, ['j-a' => ['booked' => 3, 'planned' => 5], 'j-x' => ['booked' => 1, 'planned' => 2]])], ['j-a', 'j-x']);
		$p = JobWeeks::present($r, fn (string $k) => $k === 'j-a');
		$this->assertSame(40, $p[0]['week']);
		$this->assertSame(11.0, $p[0]['load']);
		$this->assertSame(['j-a' => ['booked' => 3.0, 'planned' => 5.0]], $p[0]['jobs']);
		$this->assertSame(['booked' => 1.0, 'planned' => 2.0], $p[0]['other']);
		$p = JobWeeks::present($r, fn (string $k) => true);
		$this->assertNull($p[0]['other']);
		$this->assertArrayNotHasKey('days', $p[0]);
	}
}
