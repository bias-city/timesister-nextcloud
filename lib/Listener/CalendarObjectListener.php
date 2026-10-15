<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Listener;

use OCA\TimeSister\Db\TimeCalendarMapper;
use OCA\TimeSister\Service\EntryService;
use OCP\Calendar\Events\AbstractCalendarObjectEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * The main path of the time entries (0.17.0): a calendar object written,
 * deleted, trashed, restored or moved. One cheap thing: if the calendar
 * is a named time calendar, that one object is stored or tombstoned;
 * otherwise nothing. Never a full read here – a phone syncing a thousand
 * events fires this a thousand times.
 *
 * @template-implements IEventListener<Event>
 */
final class CalendarObjectListener implements IEventListener {
	public function __construct(
		private TimeCalendarMapper $calendars,
		private EntryService $entries,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof CalendarObjectMovedEvent) {
				$this->vanish($event->getSourceCalendarId(), $event->getObjectData());
				$this->store($event->getTargetCalendarId(), $event->getObjectData());
			} elseif ($event instanceof CalendarObjectDeletedEvent || $event instanceof CalendarObjectMovedToTrashEvent) {
				$this->vanish($event->getCalendarId(), $event->getObjectData());
			} elseif ($event instanceof AbstractCalendarObjectEvent) {
				$this->store($event->getCalendarId(), $event->getObjectData());
			}
		} catch (\Throwable $e) {
			// The calendar write must never fail because of the book; the job catches up.
			$this->logger->warning('TimeSister: calendar event not applied to the time entries', ['app' => 'timesister', 'exception' => $e]);
		}
	}

	/** @param array<string,mixed> $object */
	private function store(int $calendarId, array $object): void {
		$ics = $object['calendardata'] ?? null;
		if (!is_string($ics) || $ics === '') {
			return;
		}
		foreach ($this->calendars->findByCalendarId($calendarId) as $src) {
			$this->entries->storeObject($src, $ics, (string)($object['uri'] ?? ''), (string)($object['etag'] ?? ''));
		}
	}

	/** @param array<string,mixed> $object */
	private function vanish(int $calendarId, array $object): void {
		$ics = $object['calendardata'] ?? null;
		foreach ($this->calendars->findByCalendarId($calendarId) as $src) {
			$this->entries->vanishObject($src, (string)($object['uri'] ?? ''), is_string($ics) ? $ics : null);
		}
	}
}
