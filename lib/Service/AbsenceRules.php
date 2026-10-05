<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCP\IL10N;

/**
 * The shared vacation calendar, 0.9.0: what the clients report, which
 * categories the team shows, and the all-day events the server writes into
 * the Team Admin's calendar «TimeSister – Vacation». Pure – no Nextcloud.
 *
 * Every mirrored event carries `X-TIMESISTER-PERSON`, `X-TIMESISTER-QUELLE`
 * (the source event) and `X-TIMESISTER-ART` (the category); anything
 * without these marks is somebody else's and is never touched. Removed
 * absences are rewritten with `STATUS:CANCELLED` – the public API cannot
 * delete; a Team Admin's Mac tidies up.
 */
final class AbsenceRules {
	public const KINDS = ['vacation', 'sickness', 'parental', 'civil_service', 'unpaid'];
	public const DEFAULT_KINDS = ['vacation'];
	/** At most this many absences per person and report. */
	public const MAX_ITEMS = 400;
	public const PRODID = '-//B/IAS//TimeSister Server Absences//EN';
	public const P_PERSON = 'X-TIMESISTER-PERSON';
	public const P_SOURCE = 'X-TIMESISTER-QUELLE';
	public const P_KIND = 'X-TIMESISTER-ART';
	/** C0 controls and DEL – never in a reported field, never raw in an ICS line. */
	public const CONTROL_CHARS = '/[\x00-\x1F\x7F]/';

	/** The category's word, in the team's language. */
	public static function label(IL10N $l, string $kind): string {
		return match ($kind) {
			'sickness' => $l->t('Sickness'),
			'parental' => $l->t('Maternity/paternity'),
			'civil_service' => $l->t('Civil service'),
			'unpaid' => $l->t('Unpaid'),
			default => $l->t('Vacation'),
		};
	}

	/**
	 * The body of `PUT /me/absences`: `items`, each with `source_uid`,
	 * `kind`, `start` and `end` (exclusive, after `start`). The same
	 * source twice: the last one counts.
	 *
	 * @return list<array{source_uid:string,kind:string,start:string,end:string}>
	 */
	public static function parseItems(mixed $items): array {
		if (!is_array($items)) {
			throw ApiException::invalid(Message::of('“{field}” must be a list.', ['field' => 'items']));
		}
		if (count($items) > self::MAX_ITEMS) {
			throw ApiException::tooLarge(Message::of('At most {max} absences per person.', ['max' => self::MAX_ITEMS]));
		}
		$out = [];
		foreach (array_values($items) as $n => $i) {
			$bad = static fn (string $field): ApiException => ApiException::invalid(Message::of('“{field}” of item {n} is invalid.', ['field' => $field, 'n' => $n + 1]));
			if (!($i instanceof \stdClass)) {
				throw $bad('items');
			}
			// Control characters would break the ICS lines the server writes: 400.
			foreach (['source_uid', 'kind', 'start', 'end'] as $field) {
				$v = $i->$field ?? null;
				if (is_string($v) && preg_match(self::CONTROL_CHARS, $v)) {
					throw ApiException::badRequest(Message::of('“{field}” of item {n} must not contain control characters.', ['field' => $field, 'n' => $n + 1]));
				}
			}
			$source = $i->source_uid ?? null;
			if (!is_string($source) || trim($source) === '' || strlen($source) > 255) {
				throw $bad('source_uid');
			}
			$kind = $i->kind ?? 'vacation';
			if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
				throw $bad('kind');
			}
			$start = $i->start ?? null;
			if (!is_string($start) || !Time::isDay($start)) {
				throw $bad('start');
			}
			$end = $i->end ?? null;
			if (!is_string($end) || !Time::isDay($end) || $end <= $start) {
				throw $bad('end');
			}
			$out[trim($source)] = ['source_uid' => trim($source), 'kind' => $kind, 'start' => $start, 'end' => $end];
		}
		return array_values($out);
	}

	/**
	 * `absence_calendar_kinds` of the settings record: the categories the
	 * calendar shows. Missing: vacation only. Unknown names are dropped.
	 *
	 * @return list<string>
	 */
	public static function kinds(mixed $settings): array {
		$v = $settings instanceof \stdClass ? ($settings->absence_calendar_kinds ?? null) : null;
		if (!is_array($v)) {
			return self::DEFAULT_KINDS;
		}
		return array_values(array_filter(self::KINDS, static fn (string $k) => in_array($k, $v, true)));
	}

	/**
	 * `vacation_calendar` of the settings record: where the calendar is and
	 * whose it is; null without one.
	 *
	 * @return ?array{url:string,owner:string,uri:string}
	 */
	public static function vacationCalendar(mixed $settings): ?array {
		$v = $settings instanceof \stdClass ? ($settings->vacation_calendar ?? null) : null;
		if (!($v instanceof \stdClass)) {
			return null;
		}
		$url = $v->url ?? null;
		$owner = $v->owner ?? null;
		if (!is_string($url) || !is_string($owner) || trim($url) === '' || trim($owner) === '') {
			return null;
		}
		$parts = explode('/', trim(rtrim($url, '/')));
		$uri = end($parts);
		if ($uri === '') {
			return null;
		}
		return ['url' => $url, 'owner' => $owner, 'uri' => $uri];
	}

	/**
	 * A `vacation_calendar` may only be used when the owner is in the team
	 * (a Team Admin, when `$adminOnly`) and the URL names a calendar of that
	 * owner – this keeps the job out of foreign accounts. Otherwise 422.
	 *
	 * @param array{url:string,owner:string,uri:string} $vc
	 * @param array<string,string> $roles uid → role, those who left excluded
	 */
	public static function checkVacationCalendar(array $vc, array $roles, bool $adminOnly): void {
		$role = $roles[$vc['owner']] ?? null;
		if ($role === null || ($adminOnly && $role !== Role::ADMIN)) {
			throw ApiException::invalid('“vacation_calendar.owner” must be a Team Admin of this team.');
		}
		if (BackupRules::calendarUriFromUrl($vc['url'], $vc['owner']) === null) {
			throw ApiException::invalid(Message::of('“vacation_calendar.url” must be a calendar of “{owner}” (…/remote.php/dav/calendars/{owner}/<calendar>/).', ['owner' => $vc['owner']]));
		}
	}

	/** Does the person record say “not in the vacation calendar”? */
	public static function optedOut(?\stdClass $person): bool {
		return $person !== null && ($person->vacation_calendar_optout ?? null) === true;
	}

	/** «Initials Name» from the person record – or the account's identifier. */
	public static function personName(?\stdClass $person, string $uid): string {
		if ($person === null) {
			return $uid;
		}
		$f = $person->first_name ?? null;
		$l = $person->last_name ?? null;
		$i = $person->initials ?? null;
		$first = is_string($f) ? trim($f) : '';
		$last = is_string($l) ? trim($l) : '';
		$name = trim("$first $last");
		$initials = is_string($i) ? trim($i) : '';
		if ($initials === '' && $name !== '') {
			$initials = mb_strtoupper(mb_substr($first, 0, 1)) . mb_strtoupper(mb_substr($last, 0, 1));
		}
		return trim("$initials " . ($name === '' ? $uid : $name));
	}

	/** The event's UID – from person, source and category, like the Mac app derives it. */
	public static function eventUid(string $uid, string $sourceUid, string $kind): string {
		return 'ferien-' . substr(hash('sha256', mb_strtolower($uid) . "\n" . $sourceUid . "\n" . $kind), 0, 24) . '@timesister';
	}

	public static function objectName(string $eventUid): string {
		return $eventUid . '.ics';
	}

	/**
	 * The all-day event. `$cancelled`: the absence is gone – written again
	 * with `STATUS:CANCELLED` until a Team Admin's Mac deletes it.
	 *
	 * @param array{uid:string,source_uid:string,kind:string,start:string,end:string} $row
	 */
	public static function ics(array $row, string $label, string $personName, string $stamp, bool $cancelled = false): string {
		// RFC 5545 escaping; a bare CR would end the line, so it becomes \n like LF,
		// and the other control characters are dropped.
		$esc = static fn (string $s): string => str_replace(['\\', ';', ',', "\r\n", "\r", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
			(string)preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $s));
		$lines = [
			'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:' . self::PRODID,
			'BEGIN:VEVENT',
			'UID:' . self::eventUid($row['uid'], $row['source_uid'], $row['kind']),
			'DTSTAMP:' . $stamp,
			'DTSTART;VALUE=DATE:' . str_replace('-', '', $row['start']),
			'DTEND;VALUE=DATE:' . str_replace('-', '', $row['end']),
			'SUMMARY:' . $esc($label . ' · ' . $personName),
		];
		if ($cancelled) {
			$lines[] = 'STATUS:CANCELLED';
		}
		$lines[] = self::P_SOURCE . ':' . $esc($row['source_uid']);
		$lines[] = self::P_PERSON . ':' . $esc($row['uid']);
		$lines[] = self::P_KIND . ':' . $row['kind'];
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';
		return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
	}

	/** RFC 5545 line folding at 75 octets, never inside a UTF-8 sequence. */
	public static function fold(string $line): string {
		if (strlen($line) <= 75) {
			return $line;
		}
		$out = '';
		$cur = '';
		foreach (mb_str_split($line) as $ch) {
			if (strlen($cur) + strlen($ch) > ($out === '' ? 75 : 74)) {
				$out .= ($out === '' ? '' : "\r\n ") . $cur;
				$cur = '';
			}
			$cur .= $ch;
		}
		return $out . ($out === '' ? '' : "\r\n ") . $cur;
	}

	/**
	 * One of our events as read back from the calendar – or null for
	 * anything without our marks.
	 *
	 * @return ?array{uid:string,source_uid:string,kind:string,start:string,end:string,summary:string,cancelled:bool}
	 */
	public static function parseMirror(string $ics): ?array {
		$props = [];
		$inEvent = false;
		foreach (preg_split('/\r\n|\n|\r/', preg_replace('/\r?\n[ \t]/', '', $ics) ?? $ics) ?: [] as $line) {
			$upper = strtoupper($line);
			if ($upper === 'BEGIN:VEVENT') {
				$inEvent = true;
				continue;
			}
			if ($upper === 'END:VEVENT') {
				break;
			}
			if (!$inEvent) {
				continue;
			}
			$colon = strpos($line, ':');
			if ($colon === false) {
				continue;
			}
			$name = strtoupper((string)strtok(substr($line, 0, $colon), ';'));
			$props[$name] ??= substr($line, $colon + 1);
		}
		$unesc = static fn (string $s): string => str_replace(['\\n', '\\,', '\\;', '\\\\'], ["\n", ',', ';', '\\'], $s);
		$person = trim($unesc($props[self::P_PERSON] ?? ''));
		$source = trim($unesc($props[self::P_SOURCE] ?? ''));
		if ($person === '' || $source === '') {
			return null;
		}
		$day = static function (?string $v): ?string {
			$v = substr((string)$v, 0, 8);
			return preg_match('/^\d{8}$/', $v) ? substr($v, 0, 4) . '-' . substr($v, 4, 2) . '-' . substr($v, 6, 2) : null;
		};
		$start = $day($props['DTSTART'] ?? null);
		if ($start === null) {
			return null;
		}
		$end = $day($props['DTEND'] ?? null) ?? (new \DateTimeImmutable($start))->modify('+1 day')->format('Y-m-d');
		$kind = strtolower(trim($props[self::P_KIND] ?? 'vacation'));
		return [
			'uid' => $person, 'source_uid' => $source, 'kind' => in_array($kind, self::KINDS, true) ? $kind : 'vacation',
			'start' => $start, 'end' => $end, 'summary' => $unesc($props['SUMMARY'] ?? ''),
			'cancelled' => strtoupper(trim($props['STATUS'] ?? '')) === 'CANCELLED',
		];
	}

	/**
	 * What to write: every wanted absence (category shown, person not opted
	 * out) that is missing or differs, and – cancelled – every mirror of ours
	 * that is no longer wanted. Mirrors already cancelled stay as they are.
	 *
	 * @param list<array{uid:string,source_uid:string,kind:string,start:string,end:string}> $rows
	 * @param list<string> $kinds
	 * @param array<string,string> $names uid → «Initials Name»
	 * @param array<string,bool> $optedOut uid → true
	 * @param list<array{uid:string,source_uid:string,kind:string,start:string,end:string,summary:string,cancelled:bool}> $existing
	 * @param array<string,string> $labels kind → word
	 * @return array{write:list<array{uid:string,source_uid:string,kind:string,start:string,end:string}>,cancel:list<array{uid:string,source_uid:string,kind:string,start:string,end:string}>}
	 */
	public static function plan(array $rows, array $kinds, array $names, array $optedOut, array $existing, array $labels): array {
		$key = static fn (array $r): string => mb_strtolower($r['uid']) . "\n" . $r['source_uid'] . "\n" . $r['kind'];
		$wanted = [];
		foreach ($rows as $r) {
			if (!in_array($r['kind'], $kinds, true) || ($optedOut[$r['uid']] ?? false)) {
				continue;
			}
			$wanted[$key($r)] = $r;
		}
		$write = [];
		$cancel = [];
		$seen = [];
		foreach ($existing as $e) {
			$k = $key($e);
			$seen[$k] = true;
			$w = $wanted[$k] ?? null;
			if ($w === null) {
				if (!$e['cancelled']) {
					$cancel[] = ['uid' => $e['uid'], 'source_uid' => $e['source_uid'], 'kind' => $e['kind'], 'start' => $e['start'], 'end' => $e['end']];
				}
				continue;
			}
			$summary = ($labels[$w['kind']] ?? $w['kind']) . ' · ' . ($names[$w['uid']] ?? $w['uid']);
			if ($e['cancelled'] || $e['start'] !== $w['start'] || $e['end'] !== $w['end'] || $e['summary'] !== $summary) {
				$write[] = $w;
			}
		}
		foreach ($wanted as $k => $w) {
			if (!isset($seen[$k])) {
				$write[] = $w;
			}
		}
		return ['write' => $write, 'cancel' => $cancel];
	}
}
