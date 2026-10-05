<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\AbsenceRules;
use OCA\TimeSister\Service\ApiException;
use PHPUnit\Framework\TestCase;

/** The shared vacation calendar, 0.9.0: report, categories, events, plan. */
class AbsenceRulesTest extends TestCase {
	private const LABELS = ['vacation' => 'Ferien', 'sickness' => 'Krankheit', 'parental' => 'Mutter-/Vaterschaft', 'civil_service' => 'Zivildienst', 'unpaid' => 'Unbezahlt'];

	private static function code(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getErrorCode();
		}
		return 'ok';
	}

	private static function item(string $source, string $start, string $end, ?string $kind = 'vacation'): \stdClass {
		$o = (object)['source_uid' => $source, 'start' => $start, 'end' => $end];
		if ($kind !== null) {
			$o->kind = $kind;
		}
		return $o;
	}

	public function testParseItems(): void {
		$items = AbsenceRules::parseItems([
			self::item('a@x', '2026-07-06', '2026-07-11'),
			self::item('b@x', '2026-08-03', '2026-08-04', null),           // kind missing: vacation
			self::item('a@x', '2026-07-06', '2026-07-10', 'sickness'),    // same source again: the last one counts
		]);
		$this->assertCount(2, $items);
		$this->assertSame(['source_uid' => 'a@x', 'kind' => 'sickness', 'start' => '2026-07-06', 'end' => '2026-07-10'], $items[0]);
		$this->assertSame('vacation', $items[1]['kind']);
		$this->assertSame([], AbsenceRules::parseItems([]));

		$this->assertSame('422 invalid', self::code(fn () => AbsenceRules::parseItems('x')));
		$this->assertSame('422 invalid', self::code(fn () => AbsenceRules::parseItems([self::item('', '2026-07-06', '2026-07-11')])));
		$this->assertSame('422 invalid', self::code(fn () => AbsenceRules::parseItems([self::item(str_repeat('u', 256), '2026-07-06', '2026-07-11')])));
		$this->assertSame('422 invalid', self::code(fn () => AbsenceRules::parseItems([self::item('a', '2026-07-06', '2026-07-11', 'holiday')])));
		$this->assertSame('422 invalid', self::code(fn () => AbsenceRules::parseItems([self::item('a', '2026-02-31', '2026-03-01')])), 'no such day');
		$this->assertSame('422 invalid', self::code(fn () => AbsenceRules::parseItems([self::item('a', '2026-07-06', '2026-07-06')])), 'end must follow start');
		$this->assertSame('422 invalid', self::code(fn () => AbsenceRules::parseItems(['a'])));
		$many = array_map(fn (int $i) => self::item("u$i", '2026-07-06', '2026-07-07'), range(1, AbsenceRules::MAX_ITEMS + 1));
		$this->assertSame('413 too_large', self::code(fn () => AbsenceRules::parseItems($many)));
	}

	/** 0.9.1: control characters in a reported field are refused (400), and a CR never reaches an ICS line raw. */
	public function testControlCharactersAreRefusedAndEscaped(): void {
		$this->assertSame('400 invalid', self::code(fn () => AbsenceRules::parseItems([self::item("zz\r\nSUMMARY:x", '2026-07-06', '2026-07-11')])));
		$this->assertSame('400 invalid', self::code(fn () => AbsenceRules::parseItems([self::item("a\0b", '2026-07-06', '2026-07-11')])));
		$this->assertSame('400 invalid', self::code(fn () => AbsenceRules::parseItems([self::item('a', "2026-07-06\t", '2026-07-11')])));
		$this->assertSame('400 invalid', self::code(fn () => AbsenceRules::parseItems([self::item('a', '2026-07-06', '2026-07-11', "vacation\x7f")])));
		$this->assertCount(1, AbsenceRules::parseItems([self::item('a/20260706T090000Z', '2026-07-06', '2026-07-11')]), 'plain values still pass');

		$row = ['uid' => 'Mia', 'source_uid' => "abc\r\nSUMMARY:Eingeschleust", 'kind' => 'vacation', 'start' => '2026-07-06', 'end' => '2026-07-11'];
		$ics = AbsenceRules::ics($row, 'Ferien', "Mi\x00M Mia\rMuster", '20260101T000000Z');
		$this->assertStringContainsString("X-TIMESISTER-QUELLE:abc\\nSUMMARY:Eingeschleust\r\n", $ics);
		$this->assertStringContainsString("SUMMARY:Ferien · MiM Mia\\nMuster\r\n", $ics);
		$this->assertSame(1, substr_count($ics, "\r\nSUMMARY:"), 'no second property');
		$back = AbsenceRules::parseMirror($ics);
		$this->assertSame("abc\nSUMMARY:Eingeschleust", $back['source_uid'] ?? null, 'round trip keeps the key stable');
	}

	/** 0.9.1: the calendar must belong to a member of the team – a Team Admin when stored – and the URL to that owner. */
	public function testVacationCalendarMustBeInTheTeam(): void {
		$roles = ['atadmin' => 'admin', 'atlead' => 'lead', 'atuser' => 'user'];
		$vc = static fn (string $owner, ?string $urlOwner = null): array => [
			'owner' => $owner, 'uri' => 'timesister-ferien',
			'url' => 'http://x/remote.php/dav/calendars/' . ($urlOwner ?? $owner) . '/timesister-ferien/',
		];
		$ok = static fn (array $vc, bool $adminOnly): string => self::code(fn () => AbsenceRules::checkVacationCalendar($vc, $roles, $adminOnly));
		$this->assertSame('ok', $ok($vc('atadmin'), true));
		$this->assertSame('ok', $ok($vc('atlead'), false), 'the job accepts any member');
		$this->assertSame('422 invalid', $ok($vc('atlead'), true), 'storing needs a Team Admin');
		$this->assertSame('422 invalid', $ok($vc('pbadmin'), false), 'not in this team');
		$this->assertSame('422 invalid', $ok($vc('atadmin', 'pbadmin'), true), 'URL of somebody else');
		$this->assertSame('422 invalid', $ok(['owner' => 'atadmin', 'uri' => 'personal', 'url' => 'http://x/personal'], true), 'no CalDAV path');
		$this->assertSame('ok', $ok($vc('atadmin'), false));
	}

	public function testKindsAndCalendarFromSettings(): void {
		$this->assertSame(['vacation'], AbsenceRules::kinds(null));
		$this->assertSame(['vacation'], AbsenceRules::kinds((object)['vacation_code' => 'FERIEN']));
		$this->assertSame(['vacation', 'unpaid'], AbsenceRules::kinds((object)['absence_calendar_kinds' => ['unpaid', 'x', 'vacation']]), 'fixed order, unknown dropped');
		$this->assertSame([], AbsenceRules::kinds((object)['absence_calendar_kinds' => []]), 'empty means: show nothing');

		$this->assertNull(AbsenceRules::vacationCalendar(null));
		$this->assertNull(AbsenceRules::vacationCalendar((object)['vacation_calendar' => (object)['url' => '', 'owner' => 'a']]));
		$vc = AbsenceRules::vacationCalendar((object)['vacation_calendar' => (object)['url' => 'http://x/remote.php/dav/calendars/atadmin/timesister-ferien/', 'owner' => 'atadmin']]);
		$this->assertSame(['url' => 'http://x/remote.php/dav/calendars/atadmin/timesister-ferien/', 'owner' => 'atadmin', 'uri' => 'timesister-ferien'], $vc);
	}

	public function testPersonNameAndOptOut(): void {
		$this->assertSame('mia', AbsenceRules::personName(null, 'mia'));
		$this->assertSame('MiM Mia Muster', AbsenceRules::personName((object)['first_name' => 'Mia', 'last_name' => 'Muster', 'initials' => 'MiM'], 'mia'));
		$this->assertSame('MM Mia Muster', AbsenceRules::personName((object)['first_name' => 'Mia', 'last_name' => 'Muster'], 'mia'), 'initials from the name when none is set');
		$this->assertSame('mia', AbsenceRules::personName((object)['first_name' => '', 'last_name' => ''], 'mia'));
		$this->assertFalse(AbsenceRules::optedOut(null));
		$this->assertFalse(AbsenceRules::optedOut((object)['vacation_calendar_optout' => false]));
		$this->assertTrue(AbsenceRules::optedOut((object)['vacation_calendar_optout' => true]));
	}

	public function testIcsAndBack(): void {
		$row = ['uid' => 'Mia', 'source_uid' => 'a1@x/20260706', 'kind' => 'civil_service', 'start' => '2026-07-06', 'end' => '2026-07-11'];
		$ics = AbsenceRules::ics($row, 'Zivildienst', 'MiM Mia Muster, jun.', '20260101T000000Z');
		$this->assertStringContainsString("DTSTART;VALUE=DATE:20260706\r\n", $ics);
		$this->assertStringContainsString("DTEND;VALUE=DATE:20260711\r\n", $ics);
		$this->assertStringContainsString("SUMMARY:Zivildienst · MiM Mia Muster\\, jun.\r\n", $ics);
		$this->assertStringContainsString("X-TIMESISTER-QUELLE:a1@x/20260706\r\n", $ics);
		$this->assertStringContainsString("X-TIMESISTER-PERSON:Mia\r\n", $ics);
		$this->assertStringContainsString("X-TIMESISTER-ART:civil_service\r\n", $ics);
		$this->assertStringNotContainsString('DESCRIPTION', $ics);
		$this->assertStringNotContainsString('STATUS', $ics);
		$uid = AbsenceRules::eventUid('Mia', 'a1@x/20260706', 'civil_service');
		$this->assertMatchesRegularExpression('/^ferien-[0-9a-f]{24}@timesister$/', $uid);
		$this->assertSame($uid, AbsenceRules::eventUid('mia', 'a1@x/20260706', 'civil_service'), 'identifier case does not matter');
		$this->assertNotSame($uid, AbsenceRules::eventUid('mia', 'a1@x/20260706', 'vacation'), 'another category, another event');
		$this->assertStringContainsString("UID:$uid\r\n", $ics);
		$this->assertSame("$uid.ics", AbsenceRules::objectName($uid));

		$back = AbsenceRules::parseMirror($ics);
		$this->assertSame(['uid' => 'Mia', 'source_uid' => 'a1@x/20260706', 'kind' => 'civil_service', 'start' => '2026-07-06', 'end' => '2026-07-11',
			'summary' => 'Zivildienst · MiM Mia Muster, jun.', 'cancelled' => false], $back);
		$cancelled = AbsenceRules::ics($row, 'Zivildienst', 'MiM Mia Muster', '20260101T000000Z', true);
		$this->assertStringContainsString("STATUS:CANCELLED\r\n", $cancelled);
		$this->assertTrue(AbsenceRules::parseMirror($cancelled)['cancelled'] ?? false);
		// A long summary is folded and comes back whole.
		$long = AbsenceRules::ics($row, 'Ferien', str_repeat('Äöü ', 40), '20260101T000000Z');
		$this->assertStringContainsString("\r\n ", $long);
		$this->assertSame('Ferien · ' . str_repeat('Äöü ', 40), AbsenceRules::parseMirror($long)['summary'] ?? '');
		// Somebody else's event, and one without a category (older mirror).
		$this->assertNull(AbsenceRules::parseMirror("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:f\r\nDTSTART;VALUE=DATE:20260706\r\nSUMMARY:Betriebsferien\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"));
		$old = str_replace("X-TIMESISTER-ART:civil_service\r\n", '', $ics);
		$this->assertSame('vacation', AbsenceRules::parseMirror($old)['kind'] ?? null);
		$this->assertSame('2026-07-07', AbsenceRules::parseMirror(str_replace("DTEND;VALUE=DATE:20260711\r\n", '', $ics))['end'] ?? null, 'without DTEND: one day');
	}

	public function testPlanWritesChangesAndCancelsTheGone(): void {
		$r = fn (string $uid, string $src, string $kind, string $start, string $end) => ['uid' => $uid, 'source_uid' => $src, 'kind' => $kind, 'start' => $start, 'end' => $end];
		$e = fn (array $row, string $summary, bool $cancelled = false) => $row + ['summary' => $summary, 'cancelled' => $cancelled];
		$rows = [
			$r('mia', 'a@x', 'vacation', '2026-07-06', '2026-07-11'),   // unchanged
			$r('mia', 'b@x', 'vacation', '2026-08-03', '2026-08-05'),   // longer than before
			$r('mia', 'c@x', 'sickness', '2026-09-01', '2026-09-02'),   // category not shown
			$r('noah', 'd@x', 'vacation', '2027-02-01', '2027-02-06'),  // new
			$r('lea', 'e@x', 'vacation', '2026-10-05', '2026-10-10'),   // opted out
		];
		$existing = [
			$e($r('Mia', 'a@x', 'vacation', '2026-07-06', '2026-07-11'), 'Ferien · MiM Mia Muster'),
			$e($r('mia', 'b@x', 'vacation', '2026-08-03', '2026-08-04'), 'Ferien · MiM Mia Muster'),
			$e($r('mia', 'c@x', 'sickness', '2026-09-01', '2026-09-02'), 'Krankheit · MiM Mia Muster'),   // shown earlier: cancel
			$e($r('lea', 'e@x', 'vacation', '2026-10-05', '2026-10-10'), 'Ferien · Lea Lang'),          // opted out now: cancel
			$e($r('mia', 'gone@x', 'vacation', '2026-03-02', '2026-03-03'), 'Ferien · MiM Mia Muster', true), // already cancelled: leave
		];
		$names = ['mia' => 'MiM Mia Muster', 'noah' => 'NN Noah Nagel', 'lea' => 'Lea Lang'];
		$p = AbsenceRules::plan($rows, ['vacation'], $names, ['lea' => true], $existing, self::LABELS);
		$this->assertSame([$rows[1], $rows[3]], $p['write']);
		$this->assertSame([$r('mia', 'c@x', 'sickness', '2026-09-01', '2026-09-02'), $r('lea', 'e@x', 'vacation', '2026-10-05', '2026-10-10')], $p['cancel']);
		// A new name: the summary changes, so it is written again.
		$p2 = AbsenceRules::plan([$rows[0]], ['vacation'], ['mia' => 'MiM Mia Meier'], [], [$existing[0]], self::LABELS);
		$this->assertSame([$rows[0]], $p2['write']);
		// Cancelled before, wanted again: written again.
		$p3 = AbsenceRules::plan([$r('mia', 'gone@x', 'vacation', '2026-03-02', '2026-03-03')], ['vacation'], $names, [], [$existing[4]], self::LABELS);
		$this->assertCount(1, $p3['write']);
		$this->assertSame([], $p3['cancel']);
		// Nothing wanted, nothing there: nothing to do.
		$this->assertSame(['write' => [], 'cancel' => []], AbsenceRules::plan([], ['vacation'], [], [], [], self::LABELS));
	}

	public function testFold(): void {
		$this->assertSame('short', AbsenceRules::fold('short'));
		$line = 'SUMMARY:' . str_repeat('é', 60);
		$folded = AbsenceRules::fold($line);
		foreach (explode("\r\n", $folded) as $part) {
			$this->assertLessThanOrEqual(75, strlen($part));
		}
		$this->assertSame($line, str_replace("\r\n ", '', $folded));
	}
}
