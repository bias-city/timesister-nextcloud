<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Db\TimeCalendarMapper;
use OCA\TimeSister\Listener\CalendarObjectListener;
use OCA\TimeSister\Service\EntryService;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedEvent;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Time entries (0.17.0): the listener never lets an exception through to
 * the calendar write – here the database is a mock without a query
 * builder, so the first lookup throws; the listener logs and returns.
 */
class CalendarObjectListenerTest extends TestCase {
	public function testSwallowsFailures(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(3))->method('warning');
		$listener = new CalendarObjectListener(
			new TimeCalendarMapper($this->createMock(IDBConnection::class)),
			(new \ReflectionClass(EntryService::class))->newInstanceWithoutConstructor(),
			$logger,
		);
		$object = ['uri' => 'x.ics', 'etag' => '"1"', 'calendardata' => "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:x\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"];
		$listener->handle(new CalendarObjectCreatedEvent(7, [], [], $object));
		$listener->handle(new CalendarObjectDeletedEvent(7, [], [], $object));
		$listener->handle(new CalendarObjectMovedEvent(7, [], 8, [], [], [], $object));
		$this->addToAssertionCount(1);
	}

	public function testIgnoresEventsWithoutObjectData(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$listener = new CalendarObjectListener(
			new TimeCalendarMapper($this->createMock(IDBConnection::class)),
			(new \ReflectionClass(EntryService::class))->newInstanceWithoutConstructor(),
			$logger,
		);
		// Without calendar data nothing is looked up, so nothing can fail.
		$listener->handle(new CalendarObjectCreatedEvent(7, [], [], []));
		$this->addToAssertionCount(1);
	}
}
