<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\CorrectionRules;
use OCA\TimeSister\Service\EntryRules;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\Role;
use PHPUnit\Framework\TestCase;

/** Corrections (0.27.0): the body, the rights, the lock, the rewritten iCal. */
class CorrectionRulesTest extends TestCase {
	private const NOW = '20261021T120000Z';

	private static function ics(string ...$events): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n" . implode('', $events) . "END:VCALENDAR\r\n";
	}

	/** @param list<string> $lines */
	private static function event(string $uid, array $lines): string {
		return "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20260901T080000Z\r\nDTSTART:20261001T080000Z\r\nDTEND:20261001T100000Z\r\n" . implode("\r\n", $lines) . "\r\nEND:VEVENT\r\n";
	}

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

	/** @return array<string,list<string>> property name → values of the first VEVENT */
	private static function props(string $ics): array {
		$c = EntryRules::components($ics, ['VEVENT'])[0];
		$out = [];
		foreach ($c['props'] as $name => $list) {
			$out[$name] = array_map(static fn (array $p) => $p['value'], $list);
		}
		return $out;
	}

	public function testBody(): void {
		$p = CorrectionRules::parseBody(self::obj('{"entries":[{"uid":"anna","event_uid":"e1"}],"title":"  Besprechung  Bauherr ","code":"#D200.1","billable":false,"level":null}'));
		$this->assertSame([['uid' => 'anna', 'event_uid' => 'e1']], $p['entries']);
		$this->assertSame(['title' => 'Besprechung Bauherr', 'code' => 'D200.1', 'billable' => false, 'level' => null], $p['change']);
		$this->assertStringContainsString('at least one', self::outcome(fn () => CorrectionRules::parseBody(self::obj('{"entries":[{"uid":"anna","event_uid":"e1"}]}'))));
		$this->assertStringContainsString('entries', self::outcome(fn () => CorrectionRules::parseBody(self::obj('{"entries":[],"title":"x"}'))));
		$this->assertStringContainsString('uid', self::outcome(fn () => CorrectionRules::parseBody(self::obj('{"entries":[{"uid":"anna"}],"title":"x"}'))));
		$this->assertStringContainsString('code', self::outcome(fn () => CorrectionRules::parseBody(self::obj('{"entries":[{"uid":"anna","event_uid":"e1"}],"code":"D 200"}'))));
		$this->assertStringContainsString('level', self::outcome(fn () => CorrectionRules::parseBody(self::obj('{"entries":[{"uid":"anna","event_uid":"e1"}],"level":"Lead"}'))));
		$this->assertStringContainsString('field of a correction', self::outcome(fn () => CorrectionRules::parseBody(self::obj('{"entries":[{"uid":"anna","event_uid":"e1"}],"start":"09:00"}'))), 'date, start, duration never change');
		$this->assertStringContainsString('billable', self::outcome(fn () => CorrectionRules::parseBody(self::obj('{"entries":[{"uid":"anna","event_uid":"e1"}],"billable":"no"}'))));
	}

	public function testRights(): void {
		$led = ['d200', 'd200.4'];
		$this->assertTrue(CorrectionRules::allowed(Role::ADMIN, [], ['x9'], 'Y1'));
		$this->assertFalse(CorrectionRules::allowed(Role::USER, $led, ['d200'], null), 'members never');
		$this->assertTrue(CorrectionRules::allowed(Role::LEAD, $led, ['d200.1'], null), 'a code of the led project');
		$this->assertTrue(CorrectionRules::allowed(Role::LEAD, $led, ['d200.1'], 'D200.4.2'), 'moved within the led project');
		$this->assertFalse(CorrectionRules::allowed(Role::LEAD, $led, ['d200.1'], 'X1'), 'the new project must be led too');
		$this->assertFalse(CorrectionRules::allowed(Role::LEAD, $led, ['d200.1', 'x1'], null), 'every code of the entry must be led');
		$this->assertFalse(CorrectionRules::allowed(Role::LEAD, $led, [], null), 'no code, no project');
		$this->assertFalse(CorrectionRules::allowed(Role::LEAD, $led, ['d2001'], null), 'd2001 is not under d200');
	}

	public function testLock(): void {
		$row = static fn (string $status, string $number): array => ['invoice_id' => 1, 'start' => '2026-10-01T08:00:00', 'number' => $number, 'status' => $status, 'rkey' => 'inv-1'];
		$this->assertNull(CorrectionRules::lockedBy([], false));
		$this->assertNull(CorrectionRules::lockedBy([$row('draft', '')], false), 'a draft does not lock');
		$this->assertNull(CorrectionRules::lockedBy([$row('cancelled', '2026-0007')], false), 'a reversed invoice does not lock');
		$this->assertSame('2026-0007', CorrectionRules::lockedBy([$row('issued', '2026-0007')], false));
		$this->assertSame('2026-0008', CorrectionRules::lockedBy([$row('paid', '2026-0008')], false));
		$this->assertSame('', CorrectionRules::lockedBy([], true), 'a billing mark locks too');
	}

	public function testRewriteTitleCodeBillableLevel(): void {
		$ics = self::ics(self::event('e1', ['SUMMARY:#D200.1 Besprechung #D200.2:50%', 'DESCRIPTION:Notiz zu #D200.1', 'CATEGORIES:Kunde\,A,nicht anrechenbar', 'SEQUENCE:2']));
		$out = CorrectionRules::apply($ics, ['code' => 'D300', 'title' => 'Neu, mit Komma', 'billable' => false, 'level' => 'lead'], self::NOW);
		$p = self::props($out);
		$this->assertSame(['#D300 Neu\, mit Komma'], $p['SUMMARY']);
		$this->assertSame(['Notiz zu'], $p['DESCRIPTION'], 'old codes leave the notes too');
		$this->assertSame(['Kunde\,A,non-billable'], $p['CATEGORIES']);
		$this->assertSame(['lead'], $p['X-TIMESISTER-LEVEL']);
		$this->assertSame(['3'], $p['SEQUENCE']);
		$this->assertSame([self::NOW], $p['DTSTAMP']);
		$this->assertSame([self::NOW], $p['LAST-MODIFIED']);
		$this->assertSame(['20261001T080000Z'], $p['DTSTART'], 'times never change');
		$this->assertSame(['d300'], EntryRules::parse($out)['codes']);
		$this->assertSame('lead', EntryRules::parse($out)['level']);
		// Billable again, level removed: the category and the property go; the rest stays.
		$back = self::props(CorrectionRules::apply($out, ['billable' => true, 'level' => null], self::NOW));
		$this->assertSame(['Kunde\,A'], $back['CATEGORIES']);
		$this->assertArrayNotHasKey('X-TIMESISTER-LEVEL', $back);
		$this->assertSame(['#D300 Neu\, mit Komma'], $back['SUMMARY']);
		$this->assertSame(['4'], $back['SEQUENCE']);
	}

	public function testTitleKeepsCodesAndCodeKeepsTitle(): void {
		$ics = self::ics(self::event('e1', ['SUMMARY:Sitzung #D200.1', 'CATEGORIES:non-billable']));
		$p = self::props(CorrectionRules::apply($ics, ['title' => 'Werkstatt'], self::NOW));
		$this->assertSame(['#D200.1 Werkstatt'], $p['SUMMARY'], 'the code moves to the front, the title is new');
		$this->assertSame(['non-billable'], $p['CATEGORIES'], 'untouched');
		$p = self::props(CorrectionRules::apply($ics, ['code' => 'D200.2'], self::NOW));
		$this->assertSame(['#D200.2 Sitzung'], $p['SUMMARY']);
		// Non-billable category alone: dropped entirely when billable.
		$p = self::props(CorrectionRules::apply($ics, ['billable' => true], self::NOW));
		$this->assertArrayNotHasKey('CATEGORIES', $p);
	}

	public function testOwnCodePropertyAndSeriesAndAlarm(): void {
		$master = self::event('s1', ['SUMMARY:Jour fixe', 'X-TIMESISTER-CODE:D200', 'RRULE:FREQ=WEEKLY;COUNT=4', "BEGIN:VALARM\r\nACTION:DISPLAY\r\nSUMMARY:Alarm\r\nTRIGGER:-PT10M\r\nEND:VALARM"]);
		$exception = self::event('s1', ['RECURRENCE-ID:20261008T080000Z', 'SUMMARY:Jour fixe (verschoben)', 'X-TIMESISTER-CODE:D200']);
		$out = CorrectionRules::apply(self::ics($master, $exception), ['code' => 'D300', 'level' => 'fach'], self::NOW);
		$comps = EntryRules::components($out, ['VEVENT']);
		$this->assertCount(2, $comps);
		foreach ($comps as $c) {
			$this->assertSame('D300', $c['props']['X-TIMESISTER-CODE'][0]['value'], 'a foreign invitation keeps its title, the own code changes');
			$this->assertSame('fach', $c['props']['X-TIMESISTER-LEVEL'][0]['value'], 'the series as a whole, exceptions included');
			$this->assertStringNotContainsString('#', $c['props']['SUMMARY'][0]['value']);
		}
		$this->assertStringContainsString("BEGIN:VALARM\r\nACTION:DISPLAY\r\nSUMMARY:Alarm\r\n", $out, 'nested components untouched');
		$this->assertSame(['d300'], EntryRules::parse($out)['codes']);
	}

	public function testFolding(): void {
		$title = str_repeat('Langer Titel mit Umlauten äöü ', 6);
		$out = CorrectionRules::apply(self::ics(self::event('e1', ['SUMMARY:#D200 x'])), ['title' => $title], self::NOW);
		foreach (explode("\r\n", $out) as $line) {
			$this->assertLessThanOrEqual(75, strlen($line), $line);
		}
		$this->assertSame(['#D200 ' . trim($title)], self::props($out)['SUMMARY'], 'unfolds to the whole title');
		$this->assertTrue(mb_check_encoding($out, 'UTF-8'));
	}
}
