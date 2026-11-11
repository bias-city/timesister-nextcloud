<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsShared;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Constants;

/**
 * Which calendars the personal settings page offers as time calendar
 * (0.49.0). Pure apart from reading the calendar objects: only the
 * account's own, writable calendars – no shared-in ones, no subscriptions,
 * nothing in the trash, not the birthday calendar.
 */
final class CalendarChoice {
	/** The dav app's birthday calendar, generated from the contacts. */
	public const BIRTHDAYS = 'contact_birthdays';

	/** @return array{uri:string,name:?string,key:int,deleted:bool,shared:bool,writable:bool} */
	public static function row(ICalendar $cal): array {
		return [
			'uri' => $cal->getUri(),
			'name' => $cal->getDisplayName(),
			'key' => (int)$cal->getKey(),
			'deleted' => $cal->isDeleted(),
			'shared' => $cal instanceof ICalendarIsShared && $cal->isShared(),
			'writable' => $cal instanceof ICalendarIsWritable
				? $cal->isWritable()
				: ($cal->getPermissions() & Constants::PERMISSION_CREATE) !== 0,
		];
	}

	/**
	 * The offered calendars, sorted by name. `current` marks the named
	 * one; `vacation` the team's vacation calendar, shown but not offered.
	 *
	 * @param list<array{uri:string,name:?string,key:int,deleted:bool,shared:bool,writable:bool}> $rows
	 * @return list<array{uri:string,name:string,current:bool,vacation:bool}>
	 */
	public static function offered(array $rows, ?string $currentUri, ?string $vacationUri): array {
		$out = [];
		$seen = [];
		foreach ($rows as $r) {
			$uri = $r['uri'];
			if ($uri === '' || $uri === self::BIRTHDAYS || isset($seen[$uri])
				|| $r['deleted'] || $r['shared'] || !$r['writable'] || $r['key'] <= 0) {
				continue;
			}
			$seen[$uri] = true;
			$out[] = [
				'uri' => $uri,
				'name' => self::name($r['name'], $uri),
				'current' => $uri === $currentUri,
				'vacation' => $uri === $vacationUri,
			];
		}
		usort($out, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['uri'], $b['uri']));
		return $out;
	}

	/**
	 * The named calendar for the page: its name from the offered list,
	 * `found` false when it is no longer among them (deleted, moved).
	 *
	 * @param list<array{uri:string,name:string,current:bool,vacation:bool}> $offered
	 * @param ?array{calendar_uri:?string,calendar_id?:?int,named_at:?string,last_read_at:?string,last_error:?string} $mine
	 * @return ?array{uri:string,name:string,found:bool,last_read_at:?string,last_error:?string}
	 */
	public static function current(array $offered, ?array $mine): ?array {
		$uri = $mine['calendar_uri'] ?? null;
		if ($mine === null || $uri === null || $uri === '') {
			return null;
		}
		$name = $uri;
		$found = false;
		foreach ($offered as $c) {
			if ($c['uri'] === $uri) {
				$name = $c['name'];
				$found = true;
				break;
			}
		}
		return ['uri' => $uri, 'name' => $name, 'found' => $found, 'last_read_at' => $mine['last_read_at'], 'last_error' => $mine['last_error']];
	}

	private static function name(?string $name, string $uri): string {
		$name = trim((string)$name);
		return $name === '' ? $uri : $name;
	}
}
