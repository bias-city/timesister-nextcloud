<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Bookings for the Leads of a project (0.14.0): a Lead sees no time
 * calendars, only what the people report about their bookings on the
 * Lead's projects, and marks bookings billable as records. Pure.
 *
 * - `booking-report`, key `{uid}+{project}+{YYYY-MM}`: one per account,
 *   project and month, written only by that account.
 * - `billable`, key `{uid}+{project}+{hash}` (32 hex digits of the booking
 *   ID's SHA-256): a Lead's or Team Admin's mark; the owner's client applies
 *   it to its event.
 *
 * Accounts never contain `+` (Nextcloud), so the first `+` ends the account
 * and the last one starts the month or hash; the project lies between.
 * Both kinds accept only their documented fields: a Lead reads exactly
 * these and nothing else of a person's events.
 */
final class BookingRules {
	public const REPORT = 'booking-report';
	public const MARK = 'billable';
	public const KINDS = [self::REPORT, self::MARK];

	public const MAX_BOOKINGS = 2000;
	/** A month with many long notes needs more than the usual 256 KB. */
	public const MAX_BYTES = 786432;
	public const ID_MAX = 1024;
	public const CODE_MAX = 64;
	public const TITLE_MAX = 500;
	public const NOTES_MAX = 4000;
	/** One month in minutes: a booking never runs longer. */
	public const MAX_MINUTES = 44640;
	public const HASH_LENGTH = 32;

	private const REPORT_FIELDS = ['schema', 'person', 'project', 'month', 'bookings', 'at'];
	private const BOOKING_FIELDS = ['id', 'date', 'start', 'end', 'minutes', 'code', 'title', 'notes', 'billable', 'billed'];
	private const MARK_FIELDS = ['schema', 'person', 'project', 'uid', 'billable'];
	/** Control characters; notes keep tab and line breaks. */
	private const CONTROL = '/[\x00-\x1F\x7F]/';
	private const CONTROL_NOTES = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

	public static function isKind(string $kind): bool {
		return in_array($kind, self::KINDS, true);
	}

	public static function reportKey(string $uid, string $project, string $month): string {
		return $uid . '+' . $project . '+' . $month;
	}

	public static function markKey(string $uid, string $project, string $bookingId): string {
		return $uid . '+' . $project . '+' . substr(hash('sha256', $bookingId), 0, self::HASH_LENGTH);
	}

	/**
	 * Account and project of a key of either kind; null if the key is not
	 * one (wrong tail, empty parts).
	 *
	 * @return ?array{uid:string,project:string,tail:string}
	 */
	public static function parse(string $kind, string $key): ?array {
		$first = strpos($key, '+');
		$last = strrpos($key, '+');
		if ($first === false || $first === 0 || $last === false || $last <= $first + 1) {
			return null;
		}
		$tail = substr($key, $last + 1);
		$ok = $kind === self::REPORT ? self::isMonth($tail)
			: ($kind === self::MARK && preg_match('/^[0-9a-f]{' . self::HASH_LENGTH . '}$/', $tail) === 1);
		if (!$ok) {
			return null;
		}
		return ['uid' => substr($key, 0, $first), 'project' => substr($key, $first + 1, $last - $first - 1), 'tail' => $tail];
	}

	/** @return array{uid:string,project:string,tail:string} or 422 */
	public static function requireKey(string $kind, string $key): array {
		$k = self::parse($kind, $key);
		if ($k === null && $kind === self::REPORT) {
			throw ApiException::invalid('The key of a booking report is the account, “+”, the project key, “+” and the month (YYYY-MM).');
		}
		if ($k === null) {
			throw ApiException::invalid('The key of a billable mark is the account, “+”, the project key, “+” and 32 hex digits of the booking ID’s SHA-256.');
		}
		return $k;
	}

	public static function isMonth(string $s): bool {
		return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $s) === 1;
	}

	// ------------------------------------------------------------ rights

	/**
	 * Reading: Team Admins, the account in the key, and the current Leads
	 * of the project (`$led`: the active projects the caller leads).
	 *
	 * @param array<string,true> $led
	 */
	public static function canRead(string $role, string $uid, string $kind, string $key, array $led): bool {
		if (Role::manages($role)) {
			return true;
		}
		$k = self::parse($kind, $key);
		return $k !== null && ($k['uid'] === $uid || isset($led[$k['project']]));
	}

	/**
	 * Writing: a report only the account itself; a mark Team Admins and the
	 * project's current Leads.
	 *
	 * @param array<string,true> $led
	 */
	public static function canWrite(string $role, string $uid, string $kind, string $key, array $led): bool {
		$k = self::parse($kind, $key);
		if ($k === null) {
			return false;
		}
		if ($kind === self::REPORT) {
			return $k['uid'] === $uid;
		}
		return Role::manages($role) || isset($led[$k['project']]);
	}

	/** 403 for a write the caller may not do (422 first for a malformed key). @param array<string,true> $led */
	public static function requireWrite(string $role, string $uid, string $kind, string $key, array $led): void {
		self::requireKey($kind, $key);
		if (self::canWrite($role, $uid, $kind, $key, $led)) {
			return;
		}
		if ($kind === self::REPORT) {
			throw ApiException::forbidden('Only the person themselves reports their bookings.');
		}
		throw ApiException::forbidden('Only Team Admins and the project’s Leads mark a booking as billable.');
	}

	// ------------------------------------------------------------ content

	public static function validate(string $kind, string $key, \stdClass $data): void {
		$kind === self::REPORT ? self::validateReport($key, $data) : self::validateMark($key, $data);
	}

	private static function validateReport(string $key, \stdClass $d): void {
		$k = self::requireKey(self::REPORT, $key);
		self::only($d, self::REPORT_FIELDS);
		self::schema($d);
		if (($d->person ?? null) !== $k['uid'] || ($d->project ?? null) !== $k['project'] || ($d->month ?? null) !== $k['tail']) {
			throw ApiException::invalid('“person”, “project” and “month” must match the key.');
		}
		$at = $d->at ?? null;
		if (!is_string($at) || Time::parseIso($at) === null) {
			throw ApiException::invalid(Message::of('“{field}” must be a time in ISO 8601.', ['field' => 'at']));
		}
		$list = $d->bookings ?? null;
		if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_BOOKINGS) {
			throw ApiException::invalid(Message::of('“bookings” must be a list of at most {max} bookings.', ['max' => self::MAX_BOOKINGS]));
		}
		$seen = [];
		foreach ($list as $i => $b) {
			try {
				$id = self::booking($b, $k['tail']);
				if (isset($seen[$id])) {
					throw ApiException::invalid('The same booking ID is there twice.');
				}
				$seen[$id] = true;
			} catch (ApiException $e) {
				throw ApiException::invalid(Message::of('Booking {n}: {message}', ['n' => $i + 1, 'message' => $e->getText()]));
			}
		}
	}

	/** One booking of a report; returns its ID. */
	private static function booking(mixed $b, string $month): string {
		if (!($b instanceof \stdClass)) {
			throw ApiException::invalid('Each booking must be a JSON object.');
		}
		self::only($b, self::BOOKING_FIELDS);
		$id = $b->id ?? null;
		if (!is_string($id) || $id === '' || strlen($id) > self::ID_MAX || preg_match(self::CONTROL, $id)) {
			throw ApiException::invalidField('id');
		}
		$date = $b->date ?? null;
		if (!is_string($date) || !Time::isDay($date)) {
			throw ApiException::invalidField('date');
		}
		if (!str_starts_with($date, $month . '-')) {
			throw ApiException::invalid('The date is not in the month of the report.');
		}
		foreach (['start', 'end'] as $f) {
			$t = $b->{$f} ?? null;
			if ($t !== null && (!is_string($t) || !preg_match('/^(([01]\d|2[0-3]):[0-5]\d|24:00)$/', $t))) {
				throw ApiException::invalidField($f);
			}
		}
		$min = $b->minutes ?? null;
		if (!is_int($min) || $min < 0 || $min > self::MAX_MINUTES) {
			throw ApiException::invalidField('minutes');
		}
		self::text($b->code ?? null, 'code', self::CODE_MAX, self::CONTROL);
		self::text($b->title ?? null, 'title', self::TITLE_MAX, self::CONTROL);
		self::text($b->notes ?? null, 'notes', self::NOTES_MAX, self::CONTROL_NOTES);
		foreach (['billable', 'billed'] as $f) {
			$v = $b->{$f} ?? null;
			if ($v !== null && !is_bool($v)) {
				throw ApiException::notBool($f);
			}
		}
		return $id;
	}

	private static function validateMark(string $key, \stdClass $d): void {
		$k = self::requireKey(self::MARK, $key);
		self::only($d, self::MARK_FIELDS);
		self::schema($d);
		$person = $d->person ?? null;
		$project = $d->project ?? null;
		$uid = $d->uid ?? null;
		if (!is_string($uid) || $uid === '' || strlen($uid) > self::ID_MAX || preg_match(self::CONTROL, $uid)) {
			throw ApiException::invalidField('uid');
		}
		if (!is_string($person) || !is_string($project) || self::markKey($person, $project, $uid) !== $key || $person !== $k['uid']) {
			throw ApiException::invalid('“person”, “project” and “uid” must match the key.');
		}
		if (!is_bool($d->billable ?? null)) {
			throw ApiException::notBool('billable');
		}
	}

	/** Only the documented fields: nothing else reaches the other side. @param list<string> $allowed */
	private static function only(\stdClass $d, array $allowed): void {
		/** @var array<array-key,mixed> $fields numeric names come as int */
		$fields = get_object_vars($d);
		foreach (array_map('strval', array_keys($fields)) as $f) {
			if (!in_array($f, $allowed, true)) {
				throw ApiException::invalid(Message::of('“{field}” is not a field of this record.', ['field' => $f]));
			}
		}
	}

	private static function schema(\stdClass $d): void {
		$s = $d->schema ?? null;
		if ($s !== null && (!is_int($s) || $s < 1 || $s > 1000)) {
			throw ApiException::invalidField('schema');
		}
	}

	/**
	 * Null or text of at most `$max` characters without the control characters of `$control`.
	 *
	 * @param non-empty-string $control
	 */
	private static function text(mixed $v, string $field, int $max, string $control): void {
		if ($v === null) {
			return;
		}
		if (!is_string($v) || mb_strlen($v) > $max || preg_match($control, $v) || !mb_check_encoding($v, 'UTF-8')) {
			throw ApiException::invalid(Message::of('“{field}” must be a text of at most {max} characters.', ['field' => $field, 'max' => $max]));
		}
	}
}
