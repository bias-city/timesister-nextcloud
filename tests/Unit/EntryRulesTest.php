<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

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
