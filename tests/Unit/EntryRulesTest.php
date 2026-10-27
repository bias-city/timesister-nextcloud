<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Db\Entry;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\EntryRules;
use OCA\TimeSister\Service\Json;
use PHPUnit\Framework\TestCase;

/** Time entries (0.17.0): codes, parsing, cutoff, feed split, role filter, kept. */
class EntryRulesTest extends TestCase {
	private static function ics(string ...$events): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n" . implode('', $events) . "END:VCALENDAR\r\n";
	}

	/** @param list<string> $lines */
	private static function event(string $uid, array $lines): string {
		return "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20260901T080000Z\r\n" . implode("\r\n", $lines) . "\r\nEND:VEVENT\r\n";
	}

	public function testCodes(): void {
		$this->assertSame(['d200'], EntryRules::codesIn('#D200 Baubesprechung'));
		$this->assertSame(['d200'], EntryRules::codesIn('Baubesprechung #D200'));
		$this->assertSame(['d200.1'], EntryRules::codesIn('#D200.1 Rohbau'));
		$this->assertSame(['d200.7.2'], EntryRules::codesIn('#D200.7.2 tief'));
		$this->assertSame(['d200'], EntryRules::codesIn('Termin zu #D200.'), 'trailing dot');
		$this->assertSame(['d200'], EntryRules::codesIn('(#D200) Besprechung'));
		$this->assertSame(['d200', 'd201'], EntryRules::codesIn('#D200, danach #D201'));
		$this->assertSame(['d200'], EntryRules::codesIn('#D200:60% und #D200:1.5h'), 'share dropped, no doubles');
		$this->assertSame([], EntryRules::codesIn('Nr#5 und abc#def'), 'inside a word');
		$this->assertSame([], EntryRules::codesIn('# allein und #'), 'lone hash');
		$this->assertSame(['ab-c_d/e'], EntryRules::codesIn('#ab-c_d/e-'));
		$this->assertSame(['süd'], EntryRules::codesIn('#Süd'), 'letters beyond ASCII');
	}

	public function testParseReadsCodesFromEveryField(): void {
		$ics = self::ics(self::event('u1', ['DTSTART:20260910T080000Z', 'DTEND:20260910T090000Z', 'SUMMARY:Sitzung #D200',
			'LOCATION:Basel #D201', 'DESCRIPTION:Notizen\\n#d202 und #D200', 'X-TIMESISTER-CODE:#D203, d204']));
		$p = EntryRules::parse($ics);
		$this->assertNotNull($p);
		$this->assertSame('u1', $p['uid']);
		$this->assertSame('Sitzung #D200', $p['summary']);
		$this->assertSame(['d200', 'd201', 'd202', 'd203', 'd204'], $p['codes']);
		$this->assertSame('2026-09-10', $p['first_start']);
		$this->assertSame('2026-09-10', $p['last_end']);
		$this->assertFalse($p['cancelled']);
	}

	public function testParseEnds(): void {
		$until = self::ics(self::event('u', ['DTSTART;TZID=Europe/Zurich:20260101T080000', 'DTEND;TZID=Europe/Zurich:20260101T090000', 'RRULE:FREQ=WEEKLY;UNTIL=20260331T060000Z']));
		$this->assertSame('2026-03-31', EntryRules::parse($until)['last_end'] ?? 'x');
		$count = self::ics(self::event('u', ['DTSTART:20260101T080000Z', 'DTEND:20260101T090000Z', 'RRULE:FREQ=WEEKLY;COUNT=3']));
		$this->assertNull(EntryRules::parse($count)['last_end'], 'COUNT: not expanded');
		$open = self::ics(self::event('u', ['DTSTART:20260101T080000Z', 'DTEND:20260101T090000Z', 'RRULE:FREQ=DAILY']));
		$this->assertNull(EntryRules::parse($open)['last_end'], 'no end');
		$allDay = self::ics(self::event('u', ['DTSTART;VALUE=DATE:20260301', 'DTEND;VALUE=DATE:20260303']));
		$this->assertSame('2026-03-02', EntryRules::parse($allDay)['last_end'] ?? 'x', 'DTEND exclusive');
		$duration = self::ics(self::event('u', ['DTSTART:20260301T220000Z', 'DURATION:PT3H']));
		$this->assertSame('2026-03-01', EntryRules::parse($duration)['last_end'] ?? 'x');
		$days = self::ics(self::event('u', ['DTSTART;VALUE=DATE:20260301', 'DURATION:P2D']));
		$this->assertSame('2026-03-02', EntryRules::parse($days)['last_end'] ?? 'x');
		$exception = self::ics(
			self::event('u', ['DTSTART:20260101T080000Z', 'DTEND:20260101T090000Z', 'RRULE:FREQ=WEEKLY;UNTIL=20260131T000000Z', 'SUMMARY:#A']),
			self::event('u', ['RECURRENCE-ID:20260115T080000Z', 'DTSTART:20260401T080000Z', 'DTEND:20260401T090000Z', 'SUMMARY:moved #B']),
		);
		$p = EntryRules::parse($exception);
		$this->assertSame('2026-04-01', $p['last_end'] ?? 'x', 'an exception moved after UNTIL counts');
		$this->assertSame('2026-01-01', $p['first_start'] ?? 'x');
		$this->assertSame(['a', 'b'], $p['codes'] ?? [], 'codes of the exception too');
		$this->assertSame('#A', $p['summary'] ?? 'x', 'the master names it');
	}

	public function testParseCancelledAndEdgeCases(): void {
		$c = self::ics(self::event('u', ['DTSTART:20260101T080000Z', 'STATUS:CANCELLED', 'BEGIN:VALARM', 'TRIGGER:-PT10M', 'SUMMARY:#alarm', 'END:VALARM']));
		$p = EntryRules::parse($c);
		$this->assertTrue($p['cancelled'] ?? false);
		$this->assertSame([], $p['codes'] ?? ['x'], 'nested VALARM does not count');
		$this->assertNull(EntryRules::parse("BEGIN:VCALENDAR\r\nBEGIN:VTODO\r\nUID:t\r\nEND:VTODO\r\nEND:VCALENDAR\r\n"), 'no VEVENT');
		$this->assertNull(EntryRules::parse(self::ics("BEGIN:VEVENT\r\nDTSTART:20260101T080000Z\r\nEND:VEVENT\r\n")), 'no UID');
		$folded = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:f\r\nSUMMARY:lang\r\n  #D300 gefaltet\r\nDTSTART:20260101T080000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$this->assertSame(['d300'], EntryRules::parse($folded)['codes'] ?? [], 'folded line');
		$quoted = 'ATTENDEE;CN="Mr: Colon":mailto:a@b';
		$this->assertSame('mailto:a@b', EntryRules::property($quoted)['value'] ?? 'x');
	}

	public function testCutoff(): void {
		$this->assertTrue(EntryRules::withinCutoff(null, '2024-10-01'));
		$this->assertTrue(EntryRules::withinCutoff('2024-10-01', '2024-10-01'));
		$this->assertFalse(EntryRules::withinCutoff('2024-09-30', '2024-10-01'));
		$this->assertSame('2024-09-01', EntryRules::defaultSince(gmmktime(10, 0, 0, 9, 28, 2026)));
		$this->assertSame('2022-02-01', EntryRules::defaultSince(gmmktime(0, 0, 0, 2, 29, 2024)), 'leap day');
		$this->assertSame('2024-09-01', EntryRules::effectiveSince(null, gmmktime(10, 0, 0, 9, 28, 2026)));
		$this->assertSame('2025-01-01', EntryRules::effectiveSince('2025-01-01', gmmktime(10, 0, 0, 9, 28, 2026)));
		$this->assertNull(EntryRules::parseSince(null));
		$this->assertSame('2025-01-01', EntryRules::parseSince('2025-01-01'));
		foreach (['2025-02-30', '2025-1-1', 20250101, '', true] as $bad) {
			try {
				EntryRules::parseSince($bad);
				$this->fail('accepted ' . json_encode($bad));
			} catch (ApiException $e) {
				$this->assertSame('422 invalid', $e->getStatus() . ' ' . $e->getErrorCode());
			}
		}
	}

	public function testCalendarUri(): void {
		$this->assertSame('zeit-mia', EntryRules::calendarUri('https://cloud.example.test/remote.php/dav/calendars/mia/zeit-mia/'));
		$this->assertSame('zeit-mia', EntryRules::calendarUri('zeit-mia'));
		$this->assertSame('Zeit Mia', EntryRules::calendarUri('/remote.php/dav/calendars/mia/Zeit%20Mia'));
		$this->assertNull(EntryRules::calendarUri(''));
		$this->assertNull(EntryRules::calendarUri('   '));
		$this->assertNull(EntryRules::calendarUri('https://x/..'));
		$this->assertNull(EntryRules::calendarUri(null));
		$this->assertNull(EntryRules::calendarUri(['a']));
		$this->assertSame('abc', EntryRules::bareEtag('"abc"'));
	}

	public function testFeedSplit(): void {
		$feed = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Google//EN\r\nBEGIN:VTIMEZONE\r\nTZID:Europe/Zurich\r\nEND:VTIMEZONE\r\n"
			. self::event('a', ['DTSTART:20260101T080000Z', 'SUMMARY:#A'])
			. self::event('b', ['DTSTART:20260102T080000Z', 'SUMMARY:#B'])
			. self::event('a', ['RECURRENCE-ID:20260108T080000Z', 'DTSTART:20260108T090000Z'])
			. "BEGIN:VEVENT\r\nDTSTART:20260101T080000Z\r\nSUMMARY:no uid\r\nEND:VEVENT\r\n"
			. "END:VCALENDAR\r\n";
		$parts = EntryRules::splitFeed($feed);
		$this->assertSame(['a', 'b'], array_keys($parts));
		$this->assertSame(2, substr_count($parts['a'], 'BEGIN:VEVENT'), 'both components of a');
		$this->assertStringContainsString("PRODID:-//Google//EN\r\n", $parts['a']);
		$this->assertStringContainsString("BEGIN:VTIMEZONE\r\nTZID:Europe/Zurich\r\nEND:VTIMEZONE", $parts['b']);
		$this->assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $parts['b']);
		$this->assertStringEndsWith("END:VCALENDAR\r\n", $parts['b']);
		$this->assertSame(['b'], EntryRules::parse($parts['b'])['codes'] ?? []);
		$this->assertSame([], EntryRules::splitFeed(''));
		$this->assertTrue(EntryRules::isFeedUrl('https://calendar.google.com/x.ics'));
		$this->assertTrue(EntryRules::isFeedUrl('http://localhost:8081/f.ics'));
		$this->assertFalse(EntryRules::isFeedUrl('webcal://x/f.ics'));
		$this->assertFalse(EntryRules::isFeedUrl('file:///etc/passwd'));
		$this->assertFalse(EntryRules::isFeedUrl(null));
	}

	/** `POST /entries/list` (0.28.0): uid, objects, deletions; everything else is 422. */
	public function testListBody(): void {
		$ics = self::ics(self::event('e1', ['DTSTART:20260901T080000Z', 'DTEND:20260901T100000Z', 'SUMMARY:#D200 x']));
		$p = EntryRules::parseListBody(Json::decode('{"uid":"ext-max","put":[' . json_encode($ics) . '],"delete":["e2","e2"]}'));
		$this->assertSame('ext-max', $p['uid']);
		$this->assertCount(1, $p['put']);
		$this->assertSame(['e2'], $p['delete']);
		$this->assertSame(['uid' => 'ext-max', 'put' => [], 'delete' => []], EntryRules::parseListBody(Json::decode('{"uid":"ext-max"}')));
		// One object is one event: a series with its exception passes, two UIDs in one object do not.
		$series = self::ics(self::event('s1', ['DTSTART:20260901T080000Z', 'DTEND:20260901T100000Z', 'RRULE:FREQ=DAILY;COUNT=3', 'SUMMARY:#D200 s']),
			self::event('s1', ['RECURRENCE-ID:20260902T080000Z', 'DTSTART:20260902T090000Z', 'DTEND:20260902T100000Z', 'SUMMARY:#D200 s moved']));
		$this->assertTrue(EntryRules::oneUid($series));
		$this->assertCount(1, EntryRules::parseListBody(Json::decode('{"uid":"ext-max","put":[' . json_encode($series) . ']}'))['put']);
		$two = self::ics(self::event('t1', ['DTSTART:20260901T080000Z', 'DTEND:20260901T100000Z', 'SUMMARY:a']), self::event('t2', ['DTSTART:20260902T080000Z', 'DTEND:20260902T100000Z', 'SUMMARY:b']));
		$this->assertFalse(EntryRules::oneUid($two));
		$this->assertFalse(EntryRules::oneUid(self::ics(self::event('', ['DTSTART:20260901T080000Z']))), 'an empty UID');
		$this->assertFalse(EntryRules::oneUid(self::ics()), 'no event');
		// Two objects with the same UID would silently become one entry.
		$again = self::ics(self::event('e1', ['DTSTART:20260902T080000Z', 'DTEND:20260902T100000Z', 'SUMMARY:#D200 y']));
		try {
			EntryRules::parseListBody(Json::decode('{"uid":"ext-max","put":[' . json_encode($ics) . ',' . json_encode($again) . ']}'));
			$this->fail('a UID twice in one list');
		} catch (ApiException $e) {
			$this->assertSame(422, $e->getStatus());
			$this->assertStringContainsString('“e1” appears twice', $e->getMessage());
		}
		foreach (['{"uid":"../x","put":[]}', '{"put":[]}', '{"uid":"ext-max","put":["junk"]}', '{"uid":"ext-max","put":"x"}',
			'{"uid":"ext-max","delete":[""]}', '{"uid":"ext-max","extra":1}', '{"uid":"ext-max","put":[' . json_encode($two) . ']}'] as $bad) {
			try {
				EntryRules::parseListBody(Json::decode($bad));
				$this->fail('accepted: ' . $bad);
			} catch (ApiException $e) {
				$this->assertStringContainsString('', $e->getMessage());
			}
		}
	}

	/** A book row without `ics`, as the page reads it. */
	private static function row(int $id, int $revision, string $uid, string $codes, int $deleted = 0): Entry {
		$e = new Entry();
		$e->setId($id);
		$e->setRevision($revision);
		$e->setUid($uid);
		$e->setCodes($codes);
		$e->setDeleted($deleted);
		return $e;
	}

	/** 0.28.0: the page is filtered by role chunk by chunk, before the texts are read. */
	public function testPageOf(): void {
		$led = EntryRules::ledCodes([Json::decode('{"codes":["D196"]}')]);
		$book = [
			self::row(1, 1, 'anna', 'd196'), self::row(2, 2, 'bruno', 'q1'), self::row(3, 3, 'bruno', 'd196.4'),
			self::row(4, 4, 'lea', ''), self::row(5, 5, 'anna', 'd1960'), self::row(6, 6, 'bruno', 'd196', 1),
		];
		$reads = 0;
		$chunk = static function (int $since) use ($book, &$reads): array {
			$reads++;
			return array_values(array_slice(array_values(array_filter($book, static fn (Entry $e): bool => $e->getRevision() > $since)), 0, 2));
		};
		$ids = static fn (array $page): array => array_map(static fn (Entry $e): int => $e->getId(), $page['entries']);
		$keep = static fn (string $role, string $me): callable => static fn (Entry $e): bool => EntryRules::visible($role, $me, $led, $e->getUid(), $e->codeList());
		// The Lead lea: her own row and the rows of d196 (also the tombstone), not q1, not d1960.
		$p = EntryRules::pageOf($chunk, $keep('lead', 'lea'), 0, 2);
		$this->assertSame([1, 3], $ids($p));
		$this->assertTrue($p['more']);
		$p = EntryRules::pageOf($chunk, $keep('lead', 'lea'), 3, 2);
		$this->assertSame([4, 6], $ids($p));
		$this->assertFalse($p['more'], 'the last chunk was short');
		// A member sees only their own rows, through every chunk.
		$reads = 0;
		$p = EntryRules::pageOf($chunk, $keep('user', 'bruno'), 0, 2);
		$this->assertSame([2, 3], $ids($p));
		$this->assertTrue($p['more']);
		$this->assertSame(2, $reads, 'two chunks of two rows filled the page');
		$p = EntryRules::pageOf($chunk, $keep('user', 'bruno'), 3, 2);
		$this->assertSame([6], $ids($p));
		$this->assertFalse($p['more']);
		// A Team Admin: everything, page by page.
		$p = EntryRules::pageOf($chunk, $keep('admin', 'x'), 0, 2);
		$this->assertSame([1, 2], $ids($p));
		$this->assertTrue($p['more']);
		$this->assertSame([], $ids(EntryRules::pageOf($chunk, $keep('user', 'nobody'), 0, 2)));
	}

	public function testRoleFilter(): void {
		$led = EntryRules::ledCodes([Json::decode('{"codes":["D196"],"subprojects":[{"code":"D196.4","name":"x"}]}'), Json::decode('{"codes":["Q1"]}'), 'junk']);
		$this->assertSame(['d196', 'd196.4', 'q1'], $led);
		$this->assertTrue(EntryRules::visible('admin', 'a', [], 'b', []));
		$this->assertTrue(EntryRules::visible('user', 'a', [], 'a', []));
		$this->assertFalse(EntryRules::visible('user', 'a', $led, 'b', ['d196']));
		$this->assertTrue(EntryRules::visible('lead', 'a', $led, 'b', ['d196']));
		$this->assertTrue(EntryRules::visible('lead', 'a', $led, 'b', ['d196.4.1']), 'a part of the project');
		$this->assertTrue(EntryRules::visible('lead', 'a', $led, 'b', ['zz', 'q1']));
		$this->assertFalse(EntryRules::visible('lead', 'a', $led, 'b', ['d1960']), 'not a prefix without the dot');
		$this->assertFalse(EntryRules::visible('lead', 'a', $led, 'b', []));
		$this->assertTrue(EntryRules::visible('lead', 'a', [], 'a', []));
	}

	public function testKept(): void {
		$this->assertTrue(EntryRules::keepsVanished(true));
		$this->assertFalse(EntryRules::keepsVanished(false));
	}
}
