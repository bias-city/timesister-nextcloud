<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\IcsJoiner;
use PHPUnit\Framework\TestCase;

/** Assembling the export: one VCALENDAR per event → one .ics. */
class IcsJoinerTest extends TestCase {
	private const ZONE = "BEGIN:VTIMEZONE\r\nTZID:Europe/Zurich\r\nBEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nEND:STANDARD\r\nEND:VTIMEZONE";

	private static function cal(string $inner, string $head = "VERSION:2.0\r\nPRODID:-//Sabre//Sabre VObject 4.5.6//EN\r\nCALSCALE:GREGORIAN"): string {
		return "BEGIN:VCALENDAR\r\n" . $head . "\r\n" . $inner . "\r\nEND:VCALENDAR\r\n";
	}

	private static function event(string $uid, string $extra = ''): string {
		return "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20260926T080000Z\r\nDTSTART;TZID=Europe/Zurich:20260926T090000\r\nSUMMARY:Test $uid\r\n" . ($extra === '' ? '' : $extra . "\r\n") . 'END:VEVENT';
	}

	public function testJoinDedupesTimezonesAndKeepsEvents(): void {
		$a = self::cal(self::ZONE . "\r\n" . self::event('a', "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT5M\r\nEND:VALARM"));
		$b = self::cal(self::ZONE . "\r\n" . self::event('b'));
		$out = IcsJoiner::join([$a, $b], '-//Test//DE');

		$this->assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//DE\r\nCALSCALE:GREGORIAN\r\n", $out);
		$this->assertStringEndsWith("END:VCALENDAR\r\n", $out);
		$this->assertSame(1, substr_count($out, 'BEGIN:VCALENDAR'));
		$this->assertSame(1, substr_count($out, 'BEGIN:VTIMEZONE'), 'timezone only once');
		$this->assertSame(2, substr_count($out, 'BEGIN:VEVENT'));
		$this->assertSame(1, substr_count($out, 'BEGIN:VALARM'), 'subcomponents stay');
		$this->assertSame(1, substr_count($out, 'PRODID:'), 'the head of the individual parts is dropped');
		$this->assertLessThan(strpos($out, 'BEGIN:VEVENT'), strpos($out, 'BEGIN:VTIMEZONE'), 'timezones first');
		$this->assertLessThan(strpos($out, 'UID:b'), strpos($out, 'UID:a'), 'order stays');
		$this->assertStringNotContainsString("\n\n", str_replace("\r", '', $out));
		// Splits apart again: the same components
		$names = array_map(fn ($c) => $c[0], IcsJoiner::components($out));
		$this->assertSame(['VTIMEZONE', 'VEVENT', 'VEVENT'], $names);
	}

	public function testDifferentTimezonesStay(): void {
		$ny = str_replace('Europe/Zurich', 'America/New_York', self::ZONE);
		$out = IcsJoiner::join([self::cal(self::ZONE . "\r\n" . self::event('a')), self::cal($ny . "\r\n" . self::event('b'))], 'x');
		$this->assertSame(2, substr_count($out, 'BEGIN:VTIMEZONE'));
	}

	public function testFoldedLinesAndLfAndBom(): void {
		$folded = "BEGIN:VEVENT\nUID:lang\nDESCRIPTION:a very long description that is folded\n  and contains BEGIN:VEVENT\nEND:VEVENT";
		$ics = "\xEF\xBB\xBFBEGIN:VCALENDAR\nVERSION:2.0\nX-WR-CALNAME:Zeit\nMETHOD:PUBLISH\n" . $folded . "\nEND:VCALENDAR\n";
		$parts = IcsJoiner::components($ics);
		$this->assertCount(1, $parts);
		$this->assertSame('VEVENT', $parts[0][0]);
		$this->assertCount(5, $parts[0][1]);
		$out = IcsJoiner::join([$ics], 'x');
		$this->assertStringNotContainsString('X-WR-CALNAME', $out);
		$this->assertStringNotContainsString('METHOD', $out);
		$this->assertStringContainsString("\r\n  and contains BEGIN:VEVENT\r\n", $out);
	}

	public function testTzidFoldedAndLowercase(): void {
		$zone = "begin:vtimezone\r\nTZID;X-LIC-LOCATION=Europe/Zurich:Europe/\r\n Zurich\r\nEND:VTIMEZONE";
		$parts = IcsJoiner::components(self::cal($zone));
		$this->assertSame('VTIMEZONE', $parts[0][0]);
		$this->assertSame('Europe/Zurich', IcsJoiner::property($parts[0][1], 'TZID'));
		// A subcomponent's TZID does not count
		$this->assertNull(IcsJoiner::property(["BEGIN:VEVENT", "BEGIN:VALARM", "TZID:x", "END:VALARM", "END:VEVENT"], 'TZID'));
	}

	public function testBrokenInput(): void {
		$this->assertSame([], IcsJoiner::components(''));
		$this->assertSame([], IcsJoiner::components("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:offen\r\n"), 'unclosed is dropped');
		$this->assertSame([], IcsJoiner::components("END:VEVENT\r\nEND:VCALENDAR\r\n"));
		$empty = IcsJoiner::join([], 'x');
		$this->assertSame("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nCALSCALE:GREGORIAN\r\nEND:VCALENDAR\r\n", $empty);
	}
}
