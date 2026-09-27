<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCP\Calendar\CalendarExportOptions;
use OCP\Calendar\ICalendarExport;
use OCP\Calendar\IManager;

/**
 * Exports an account's time calendar with Nextcloud's public API
 * (`ICalendarExport`, since Nextcloud 32), without a user context.
 */
final class CalendarExporter {
	public const PRODID = '-//B/IAS//TimeSister Server Backup//EN';

	public function __construct(
		private IManager $calendars,
	) {
	}

	/** The whole .ics, or null if the account has no time calendar. */
	public function export(string $uid, ?string $calendarUrl): ?string {
		$cal = $this->find($uid, $calendarUrl);
		if ($cal === null) {
			return null;
		}
		$parts = (static function () use ($cal): \Generator {
			foreach ($cal->export(new CalendarExportOptions()) as $vcal) {
				// OCP gives one Sabre VCalendar per event; we only need the text.
				yield (string)$vcal->serialize();
			}
		})();
		return IcsJoiner::join($parts, self::PRODID);
	}

	/**
	 * `calendar_url` from the status report, otherwise `zeit-<uid>`;
	 * always in the account's own home, so only calendars it can see.
	 */
	private function find(string $uid, ?string $calendarUrl): ?ICalendarExport {
		$uris = array_unique(array_filter([BackupRules::calendarUriFromUrl($calendarUrl, $uid), 'zeit-' . $uid]));
		foreach ($uris as $uri) {
			foreach ($this->calendars->getCalendarsForPrincipal('principals/users/' . $uid, [$uri]) as $cal) {
				if ($cal instanceof ICalendarExport && $cal->getUri() === $uri && !$cal->isDeleted()) {
					return $cal;
				}
			}
		}
		return null;
	}
}
