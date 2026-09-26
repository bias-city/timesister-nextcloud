<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCP\Calendar\CalendarExportOptions;
use OCP\Calendar\ICalendarExport;
use OCP\Calendar\IManager;

/**
 * Exportiert den Zeitkalender eines Kontos mit Nextclouds öffentlicher
 * Schnittstelle (`ICalendarExport`, seit Nextcloud 32), ohne Benutzerkontext.
 */
final class CalendarExporter {
	public const PRODID = '-//B/IAS//TimeSister Server-Sicherung//DE';

	public function __construct(
		private IManager $calendars,
	) {
	}

	/** Die ganze .ics oder null, wenn das Konto keinen Zeitkalender hat. */
	public function export(string $uid, ?string $calendarUrl): ?string {
		$cal = $this->find($uid, $calendarUrl);
		if ($cal === null) {
			return null;
		}
		$parts = (static function () use ($cal): \Generator {
			foreach ($cal->export(new CalendarExportOptions()) as $vcal) {
				// OCP liefert je Termin ein Sabre-VCalendar; wir brauchen nur den Text.
				yield (string)$vcal->serialize();
			}
		})();
		return IcsJoiner::join($parts, self::PRODID);
	}

	/**
	 * `calendar_url` aus dem Lebenszeichen, sonst `zeit-<uid>`; immer im
	 * Heim des Kontos selbst, also nur Kalender, die es sieht.
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
