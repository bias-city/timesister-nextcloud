<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\CalendarChoice;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsShared;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Constants;
use PHPUnit\Framework\TestCase;

/** The personal page (0.49.0): which calendars it offers and what it shows as current. */
class CalendarChoiceTest extends TestCase {
	/** @return array{uri:string,name:?string,key:int,deleted:bool,shared:bool,writable:bool} */
	private static function cal(string $uri, ?string $name, array $over = []): array {
		return array_merge(['uri' => $uri, 'name' => $name, 'key' => 7, 'deleted' => false, 'shared' => false, 'writable' => true], $over);
	}

	public function testOnlyOwnWritableCalendarsSortedByName(): void {
		$rows = [
			self::cal('work', 'Work 10'),
			self::cal('personal', 'personal'),
			self::cal('shared_by_mia', 'Team plan', ['shared' => true]),
			self::cal('holidays-feed', 'Holidays', ['writable' => false]),
			self::cal('old', 'Old', ['deleted' => true]),
			self::cal('contact_birthdays', 'Contact birthdays'),
			self::cal('ghost', 'Ghost', ['key' => 0]),
			self::cal('zeit-mia', 'TimeSister – Mia Muster'),
			self::cal('blank', '  '),
			self::cal('work2', 'Work 9'),
			self::cal('work', 'Work duplicate'),
		];
		$out = CalendarChoice::offered($rows, 'zeit-mia', 'work2');
		$this->assertSame(['blank', 'personal', 'zeit-mia', 'work2', 'work'], array_column($out, 'uri'));
		$this->assertSame('blank', $out[0]['name'], 'without a name the URI stands in');
		$this->assertSame([false, false, true, false, false], array_column($out, 'current'));
		$this->assertSame([false, false, false, true, false], array_column($out, 'vacation'));
		$this->assertSame(['uri', 'name', 'current', 'vacation'], array_keys($out[0]));
	}

	public function testNothingNamed(): void {
		$out = CalendarChoice::offered([self::cal('personal', 'Personal')], null, null);
		$this->assertSame([['uri' => 'personal', 'name' => 'Personal', 'current' => false, 'vacation' => false]], $out);
		$this->assertNull(CalendarChoice::current($out, null));
		$this->assertNull(CalendarChoice::current($out, ['calendar_uri' => null, 'named_at' => null, 'last_read_at' => null, 'last_error' => null]));
	}

	public function testCurrentFoundOrGone(): void {
		$offered = CalendarChoice::offered([self::cal('zeit-mia', 'TimeSister – Mia Muster')], 'zeit-mia', null);
		$mine = ['calendar_uri' => 'zeit-mia', 'named_at' => '2026-11-11T08:00:00Z', 'last_read_at' => '2026-11-11T08:05:00Z', 'last_error' => null];
		$this->assertSame(['uri' => 'zeit-mia', 'name' => 'TimeSister – Mia Muster', 'found' => true, 'last_read_at' => '2026-11-11T08:05:00Z', 'last_error' => null], CalendarChoice::current($offered, $mine));
		$gone = CalendarChoice::current([], ['calendar_uri' => 'deleted-one', 'named_at' => null, 'last_read_at' => null, 'last_error' => 'not found']);
		$this->assertSame(['uri' => 'deleted-one', 'name' => 'deleted-one', 'found' => false, 'last_read_at' => null, 'last_error' => 'not found'], $gone);
	}

	public function testRowFromCalendar(): void {
		$own = $this->createMockForIntersectionOfInterfaces([ICalendar::class, ICalendarIsShared::class, ICalendarIsWritable::class]);
		$own->method('getUri')->willReturn('personal');
		$own->method('getDisplayName')->willReturn('Personal');
		$own->method('getKey')->willReturn('12');
		$own->method('isDeleted')->willReturn(false);
		$own->method('isShared')->willReturn(false);
		$own->method('isWritable')->willReturn(true);
		$this->assertSame(['uri' => 'personal', 'name' => 'Personal', 'key' => 12, 'deleted' => false, 'shared' => false, 'writable' => true], CalendarChoice::row($own));

		$shared = $this->createMockForIntersectionOfInterfaces([ICalendar::class, ICalendarIsShared::class, ICalendarIsWritable::class]);
		$shared->method('getUri')->willReturn('team_shared_by_noah');
		$shared->method('getKey')->willReturn('13');
		$shared->method('isShared')->willReturn(true);
		$shared->method('isWritable')->willReturn(true);
		$this->assertTrue(CalendarChoice::row($shared)['shared']);

		// Without the newer interfaces the permissions decide.
		$plain = $this->createMock(ICalendar::class);
		$plain->method('getUri')->willReturn('other');
		$plain->method('getKey')->willReturn('14');
		$plain->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$row = CalendarChoice::row($plain);
		$this->assertFalse($row['shared']);
		$this->assertFalse($row['writable']);
	}
}
