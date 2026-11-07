<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\CorrectionRules;
use OCA\TimeSister\Service\EntryRules;
use OCA\TimeSister\Service\Json;
use PHPUnit\Framework\TestCase;

/** Corrections of times (0.41.0): `from`/`to` in the body, the rights, start and end in the event. */
class CorrectionTimesTest extends TestCase {
	private const NOW = '20261107T120000Z';

	private static function ics(string ...$events): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n" . implode('', $events) . "END:VCALENDAR\r\n";
	}

	/** @param list<string> $lines */
	private static function event(string $start, string $end, array $lines = []): string {
		return "BEGIN:VEVENT\r\nUID:e1\r\nDTSTAMP:20260901T080000Z\r\n$start\r\n$end\r\nSUMMARY:#D200.1 Sitzung\r\n"
			. ($lines === [] ? '' : implode("\r\n", $lines) . "\r\n") . "END:VEVENT\r\n";
	}

	private static function obj(string $json): \stdClass {
		$o = Json::decode($json);
		self::assertInstanceOf(\stdClass::class, $o);
		return $o;
	}

	/** @return array{0:string,1:string,2:?string} status, message, code */
	private static function refused(callable $fn): array {
		try {
			$fn();
		} catch (ApiException $e) {
			return [(string)$e->getStatus(), $e->getText()->text(), $e->toData()['code'] ?? null];
		}
		return ['', '', null];
	}

	/** @return list<array{start:string,end:?string,rid:?string}> */
	private static function times(string $ics): array {
		$out = [];
		foreach (EntryRules::components($ics, ['VEVENT']) as $c) {
			$p = $c['props'];
			$out[] = ['start' => self::raw($p['DTSTART'][0] ?? null), 'end' => self::raw($p['DTEND'][0] ?? null), 'rid' => self::raw($p['RECURRENCE-ID'][0] ?? null)];
		}
		return $out;
	}

	/** @param array{value:string,params:array<string,string>}|null $p */
	private static function raw(?array $p): ?string {
		if ($p === null) {
			return null;
		}
		return (isset($p['params']['TZID']) ? 'TZID=' . $p['params']['TZID'] . ':' : '') . $p['value'];
	}

	public function testBodyFieldsAndOrder(): void {
		$p = CorrectionRules::parseBody(self::obj('{"entries":[{"uid":"anna","event_uid":"e1"}],"from":"2026-10-21T09:00:00+02:00","to":"2026-10-21T10:30:00+02:00"}'));
		$this->assertSame(['from' => '2026-10-21T09:00:00+02:00', 'to' => '2026-10-21T10:30:00+02:00'], $p['change']);
		$this->assertSame(['to' => '2026-10-21T10:30:00Z'], CorrectionRules::parseBody(self::obj('{"entries":[{"uid":"anna","event_uid":"e1"}],"to":"2026-10-21T10:30:00Z"}'))['change'], 'one of both is enough');
		[$status, $msg, $code] = self::refused(fn () => CorrectionRules::parseBody(self::obj('{"entries":[{"uid":"anna","event_uid":"e1"}],"from":"2026-10-21T10:00:00+02:00","to":"2026-10-21T10:00:00+02:00"}')));
		$this->assertSame(['422', 'correction_time_invalid'], [$status, $code]);
		$this->assertStringContainsString('after', $msg);
		[$status, , $code] = self::refused(fn () => CorrectionRules::parseBody(self::obj('{"entries":[{"uid":"anna","event_uid":"e1"}],"from":"09:00"}')));
		$this->assertSame(['422', 'correction_time_invalid'], [$status, $code], 'a time of day alone is not a time');
		[$status, , $code] = self::refused(fn () => CorrectionRules::parseBody(self::obj('{"entries":[{"uid":"anna","event_uid":"e1"}],"from":"2026-10-21T09:00:00"}')));
		$this->assertSame('correction_time_invalid', $code, 'the zone is required');
		$this->assertContains('from', CorrectionRules::CHANGE_FIELDS);
		$this->assertContains('to', CorrectionRules::CHANGE_FIELDS);
	}

	public function testTimesAreTheLeads(): void {
		$this->assertContains('from', CorrectionRules::LEAD_FIELDS);
		$this->assertContains('to', CorrectionRules::LEAD_FIELDS);
		$this->assertFalse(CorrectionRules::levelOnly(['from' => '2026-10-21T09:00:00Z']));
		[$status, , $code] = self::refused(fn () => CorrectionRules::requirePersonal('user', 'anna+' . str_repeat('a', 32), null,
			self::obj('{"person":"anna","event_uid":"e1","to":"2026-10-21T10:00:00Z"}'), ['anna']));
		$this->assertSame(['403', 'correction_lead_only'], [$status, $code], 'a member never moves times, also not their own');
		$this->assertSame('from', CorrectionRules::otherThanLevel(null, self::obj('{"from":"2026-10-21T09:00:00Z","level":"b"}')));
		$rec = self::obj('{"person":"anna","event_uid":"e1","from":"2026-10-21T09:00:00Z"}');
		CorrectionRules::validateRecord(CorrectionRules::key('anna', 'e1', null), $rec);
		$this->assertSame(['from' => '2026-10-21T09:00:00Z'], CorrectionRules::changeOf($rec), 'the record carries the times');
	}

	public function testSingleBookingInItsZone(): void {
		$ics = self::ics(self::event('DTSTART;TZID=Europe/Zurich:20261021T090000', 'DTEND;TZID=Europe/Zurich:20261021T100000'));
		$change = ['from' => '2026-10-22T08:15:00+02:00', 'to' => '2026-10-22T10:45:00+02:00'];
		$out = CorrectionRules::apply($ics, $change, self::NOW);
		$this->assertSame([['start' => 'TZID=Europe/Zurich:20261022T081500', 'end' => 'TZID=Europe/Zurich:20261022T104500', 'rid' => null]], self::times($out), 'date and time move');
		$this->assertTrue(CorrectionRules::met($out, $change));
		$this->assertFalse(CorrectionRules::met($ics, $change));
		// An instant in another zone lands in the event's zone; one field keeps the other.
		$out = CorrectionRules::apply($ics, ['to' => '2026-10-21T09:30:00Z'], self::NOW);
		$this->assertSame('TZID=Europe/Zurich:20261021T113000', self::times($out)[0]['end']);
		$this->assertSame('TZID=Europe/Zurich:20261021T090000', self::times($out)[0]['start'], 'the start stays');
		$this->assertStringContainsString('SUMMARY:#D200.1 Sitzung', $out, 'the rest stays');
		// UTC stays UTC, a DURATION becomes DTEND.
		$utc = self::ics(self::event('DTSTART:20261021T070000Z', 'DURATION:PT1H'));
		$out = CorrectionRules::apply($utc, ['from' => '2026-10-21T08:00:00+02:00'], self::NOW);
		$this->assertSame(['start' => '20261021T060000Z', 'end' => '20261021T080000Z', 'rid' => null], self::times($out)[0], 'the end of the duration stays');
		$this->assertStringNotContainsString('DURATION', $out);
	}

	public function testProblemsAreRefused(): void {
		$ics = self::ics(self::event('DTSTART;TZID=Europe/Zurich:20261021T090000', 'DTEND;TZID=Europe/Zurich:20261021T100000'));
		$this->assertNotNull(CorrectionRules::timeProblem($ics, ['to' => '2026-10-21T08:30:00+02:00']), 'the end before the kept start');
		$this->assertNull(CorrectionRules::timeProblem($ics, ['to' => '2026-10-21T11:00:00+02:00']));
		$this->assertNull(CorrectionRules::timeProblem($ics, ['title' => 'x']), 'no times, no problem');
		$day = self::ics(self::event('DTSTART;VALUE=DATE:20261021', 'DTEND;VALUE=DATE:20261022'));
		$this->assertStringContainsString('all-day', (string)CorrectionRules::timeProblem($day, ['from' => '2026-10-21T09:00:00Z']));
		$this->assertTrue(CorrectionRules::met($day, ['from' => '2026-10-21T09:00:00Z']), 'nothing to write for impossible times');
	}

	public function testSeriesAsAWholeKeepsDates(): void {
		$master = self::event('DTSTART;TZID=Europe/Zurich:20261005T090000', 'DTEND;TZID=Europe/Zurich:20261005T100000',
			['RRULE:FREQ=WEEKLY;COUNT=4', 'EXDATE;TZID=Europe/Zurich:20261019T090000']);
		$exception = self::event('DTSTART;TZID=Europe/Zurich:20261012T093000', 'DTEND;TZID=Europe/Zurich:20261012T103000',
			['RECURRENCE-ID;TZID=Europe/Zurich:20261012T090000']);
		$ics = self::ics($master, $exception);
		// The occurrence of 12 Oct, chosen in the list, sent as a whole: time of day and duration only.
		$change = ['from' => '2026-10-12T14:00:00+02:00', 'to' => '2026-10-12T16:30:00+02:00'];
		$out = CorrectionRules::apply($ics, $change, self::NOW);
		$this->assertSame([
			['start' => 'TZID=Europe/Zurich:20261005T140000', 'end' => 'TZID=Europe/Zurich:20261005T163000', 'rid' => null],
			['start' => 'TZID=Europe/Zurich:20261012T140000', 'end' => 'TZID=Europe/Zurich:20261012T163000', 'rid' => 'TZID=Europe/Zurich:20261012T140000'],
		], self::times($out), 'every VEVENT keeps its date; the exception still names its occurrence');
		$this->assertStringContainsString('EXDATE;TZID=Europe/Zurich:20261019T140000', $out, 'the excluded occurrence moves with the series');
		$this->assertTrue(CorrectionRules::met($out, $change));
		// Only the end: the duration from the start's time of day.
		$out = CorrectionRules::apply($ics, ['to' => '2026-10-12T11:00:00+02:00'], self::NOW);
		$this->assertSame('TZID=Europe/Zurich:20261005T110000', self::times($out)[0]['end']);
		$this->assertSame('TZID=Europe/Zurich:20261005T090000', self::times($out)[0]['start']);
	}

	public function testOneOccurrenceOnly(): void {
		$master = self::event('DTSTART;TZID=Europe/Zurich:20261005T090000', 'DTEND;TZID=Europe/Zurich:20261005T100000', ['RRULE:FREQ=WEEKLY;COUNT=4']);
		$change = ['from' => '2026-10-13T08:00:00+02:00', 'to' => '2026-10-13T09:15:00+02:00'];
		$out = CorrectionRules::apply(self::ics($master), $change, self::NOW, '20261012T090000');
		$this->assertSame([
			['start' => 'TZID=Europe/Zurich:20261005T090000', 'end' => 'TZID=Europe/Zurich:20261005T100000', 'rid' => null],
			['start' => 'TZID=Europe/Zurich:20261013T080000', 'end' => 'TZID=Europe/Zurich:20261013T091500', 'rid' => 'TZID=Europe/Zurich:20261012T090000'],
		], self::times($out), 'the exception moves, even to another day; the series stays');
		$this->assertTrue(CorrectionRules::met($out, $change, '20261012T090000'));
	}
}
