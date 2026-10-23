<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\CorrectionRules;
use OCA\TimeSister\Service\EntryRules;
use OCA\TimeSister\Service\Json;
use PHPUnit\Framework\TestCase;

/** The correction as a record (K-Offline) and per occurrence (K-Serie): key, content, exception, idempotence. */
class CorrectionRecordTest extends TestCase {
	private const NOW = '20261021T120000Z';
	private const SERIE = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nBEGIN:VEVENT\r\nUID:s1\r\nDTSTAMP:20261001T080000Z\r\n"
		. "DTSTART;TZID=Europe/Zurich:20261005T090000\r\nDTEND;TZID=Europe/Zurich:20261005T110000\r\nRRULE:FREQ=WEEKLY;COUNT=4\r\n"
		. "SUMMARY:#D200 Sitzung\r\nSEQUENCE:2\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

	private static function obj(string $json): \stdClass {
		$o = Json::decode($json);
		self::assertInstanceOf(\stdClass::class, $o);
		return $o;
	}

	private static function outcome(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getText()->text();
		}
		return '';
	}

	/** @return list<array<string,list<string>>> property → values per VEVENT */
	private static function events(string $ics): array {
		$out = [];
		foreach (EntryRules::components($ics, ['VEVENT']) as $c) {
			$p = [];
			foreach ($c['props'] as $name => $list) {
				$p[$name] = array_map(static fn (array $x) => $x['value'], $list);
			}
			$out[] = $p;
		}
		return $out;
	}

	public function testKeyAndPerson(): void {
		$a = CorrectionRules::key('anna', 's1', null);
		$b = CorrectionRules::key('anna', 's1', '20261012T090000');
		self::assertSame('anna+' . substr(hash('sha256', 's1'), 0, 32), $a, 'without recurrence like the billing mark');
		self::assertNotSame($a, $b);
		self::assertSame('anna', CorrectionRules::personOf($a));
		self::assertNull(CorrectionRules::personOf('anna'));
	}

	public function testRecordValidation(): void {
		$key = CorrectionRules::key('anna', 's1', null);
		$ok = self::obj('{"person":"anna","event_uid":"s1","title":"Werkstatt","level":null,"status":"applied","by":"x"}');
		self::assertSame('', self::outcome(static fn () => CorrectionRules::validateRecord($key, $ok)));
		self::assertSame(['title' => 'Werkstatt', 'level' => null], CorrectionRules::changeOf($ok));
		self::assertNull(CorrectionRules::recurrenceOf($ok));
		$cases = [
			['{"person":"anna","event_uid":"s1"}', '422 A correction changes at least one of title, code, billable, level.'],
			['{"person":"anna","event_uid":"s1","title":"x","recurrence":"2026-10-12"}', '422 “recurrence” must be YYYYMMDDTHHMMSS or YYYYMMDD, or be left out.'],
			['{"person":"anna","event_uid":"s2","title":"x"}', '422 The key of a correction is the person’s key, “+” and 32 hex digits of the SHA-256 of the event UID (and recurrence).'],
			['{"person":"anna","event_uid":"s1","title":"x","date":"2026-10-12"}', '422 “date” is not a field of a correction.'],
			['{"person":"","event_uid":"s1","title":"x"}', '422 For a correction, “person” must be the person’s key and “event_uid” the event’s UID.'],
		];
		foreach ($cases as [$json, $want]) {
			self::assertSame($want, self::outcome(static fn () => CorrectionRules::validateRecord($key, self::obj($json))), $json);
		}
		$k2 = CorrectionRules::key('anna', 's1', '20261012T090000');
		$rec = self::obj('{"person":"anna","event_uid":"s1","recurrence":"20261012T090000","code":"D300"}');
		self::assertSame('', self::outcome(static fn () => CorrectionRules::validateRecord($k2, $rec)));
		self::assertSame('20261012T090000', CorrectionRules::recurrenceOf($rec));
		$with = CorrectionRules::withServerFields($rec, 'lead', 1_761_000_000, ['status' => 'applied', 'etag' => 'e9', 'codes' => ['d300']]);
		self::assertSame('applied', $with->status);
		self::assertSame('lead', $with->by);
		self::assertSame('2025-10-20T22:40:00Z', $with->at);
		self::assertSame(['d300'], $with->codes);
		self::assertFalse(property_exists($with, 'message'));
		$again = CorrectionRules::withServerFields($with, 'lead', 1_761_000_100, ['status' => 'applied', 'etag' => 'e10', 'codes' => [], 'reapplied' => 2]);
		self::assertSame(2, $again->reapplied);
		self::assertSame('2025-10-20T22:41:40Z', $again->reapplied_at);
	}

	public function testWholeSeriesIsIdempotent(): void {
		$change = ['title' => 'Werkstatt', 'code' => 'D300', 'billable' => false, 'level' => 'fach'];
		self::assertFalse(CorrectionRules::met(self::SERIE, $change));
		$new = CorrectionRules::apply(self::SERIE, $change, self::NOW);
		$ev = self::events($new);
		self::assertCount(1, $ev, 'no exception for the whole series');
		self::assertSame(['#D300 Werkstatt'], $ev[0]['SUMMARY']);
		self::assertSame(['non-billable'], $ev[0]['CATEGORIES']);
		self::assertSame(['fach'], $ev[0]['X-TIMESISTER-LEVEL']);
		self::assertSame(['3'], $ev[0]['SEQUENCE']);
		self::assertTrue(CorrectionRules::met($new, $change), 'applying twice changes nothing');
		self::assertTrue(CorrectionRules::met($new, ['billable' => false]));
		self::assertFalse(CorrectionRules::met($new, ['billable' => true]));
		self::assertFalse(CorrectionRules::met($new, ['level' => null]));
		self::assertTrue(CorrectionRules::met(CorrectionRules::apply($new, ['level' => null], self::NOW), ['level' => null]));
	}

	public function testOnlyThisOccurrenceCreatesAnException(): void {
		$change = ['title' => 'Werkstatt', 'code' => 'D300'];
		$rec = '20261012T090000';
		self::assertFalse(CorrectionRules::met(self::SERIE, $change, $rec), 'without the exception: not met');
		$new = CorrectionRules::apply(self::SERIE, $change, self::NOW, $rec);
		$ev = self::events($new);
		self::assertCount(2, $ev);
		self::assertSame(['#D200 Sitzung'], $ev[0]['SUMMARY'], 'the master stays');
		self::assertArrayHasKey('RRULE', $ev[0]);
		self::assertSame(['#D300 Werkstatt'], $ev[1]['SUMMARY']);
		self::assertSame(['20261012T090000'], $ev[1]['RECURRENCE-ID']);
		self::assertSame(['20261012T090000'], $ev[1]['DTSTART']);
		self::assertSame(['20261012T110000'], $ev[1]['DTEND'], 'the master’s duration');
		self::assertArrayNotHasKey('RRULE', $ev[1]);
		self::assertSame(['s1'], $ev[1]['UID']);
		self::assertStringContainsString("RECURRENCE-ID;TZID=Europe/Zurich:20261012T090000", $new, 'the zone of DTSTART');
		self::assertTrue(CorrectionRules::met($new, $change, $rec));
		self::assertFalse(CorrectionRules::met($new, $change), 'the whole series is not met');
		// A second correction of the same occurrence changes the existing exception.
		$again = CorrectionRules::apply($new, ['title' => 'Nochmal'], self::NOW, $rec);
		self::assertCount(2, self::events($again));
		self::assertSame(['#D300 Nochmal'], self::events($again)[1]['SUMMARY']);
	}

	public function testExceptionAllDayAndUtc(): void {
		$utc = str_replace(['DTSTART;TZID=Europe/Zurich:20261005T090000', 'DTEND;TZID=Europe/Zurich:20261005T110000'],
			['DTSTART:20261005T070000Z', 'DTEND:20261005T090000Z'], self::SERIE);
		$ev = self::events(CorrectionRules::apply($utc, ['title' => 'X'], self::NOW, '20261012T070000'));
		self::assertSame(['20261012T070000Z'], $ev[1]['RECURRENCE-ID']);
		self::assertSame(['20261012T090000Z'], $ev[1]['DTEND']);
		$day = str_replace(['DTSTART;TZID=Europe/Zurich:20261005T090000', 'DTEND;TZID=Europe/Zurich:20261005T110000'],
			['DTSTART;VALUE=DATE:20261005', 'DTEND;VALUE=DATE:20261006'], self::SERIE);
		$new = CorrectionRules::apply($day, ['title' => 'X'], self::NOW, '20261012');
		$ev = self::events($new);
		self::assertSame(['20261012'], $ev[1]['RECURRENCE-ID']);
		self::assertSame(['20261013'], $ev[1]['DTEND']);
		self::assertStringContainsString('RECURRENCE-ID;VALUE=DATE:20261012', $new);
	}

	public function testOwnCodeOfAnInvitation(): void {
		$ics = str_replace('SUMMARY:#D200 Sitzung', "SUMMARY:Sitzung beim Kunden\r\nX-TIMESISTER-CODE:D200", self::SERIE);
		$new = CorrectionRules::apply($ics, ['code' => 'd300'], self::NOW);
		$ev = self::events($new);
		self::assertSame(['d300'], $ev[0]['X-TIMESISTER-CODE']);
		self::assertSame(['Sitzung beim Kunden'], $ev[0]['SUMMARY']);
		self::assertTrue(CorrectionRules::met($new, ['code' => 'D300']), 'codes compare case-insensitively');
	}
}
