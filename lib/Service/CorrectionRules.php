<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Corrections of bookings (0.27.0, PLAN-SAETZE S3 / B2, K-Offline, K-Serie):
 * a Team Admin or the Lead of the project changes title, code, billable and
 * level of a booking; the app writes that into the event of the person's
 * time calendar. Pure: the body, the record, the rights, the lock, and the
 * rewriting of the iCal text. Date, start and duration are never touched;
 * the level is stored as `X-TIMESISTER-LEVEL:<id>` (never a rate); billable
 * as the category `non-billable`, exactly as the clients write it.
 *
 * Since K-Serie a change may name a `recurrence` (`YYYYMMDDTHHMMSS` in the
 * series' wall time, or `YYYYMMDD`): then it goes into the exception of that
 * occurrence only – created as a copy of the master when there is none –
 * instead of every VEVENT of the object.
 */
final class CorrectionRules {
	/** The record kind of a correction (K-Offline): the target state of a booking's corrected fields. */
	public const KIND = 'correction';
	public const LEVEL_PROPERTY = 'X-TIMESISTER-LEVEL';
	public const CODE_PROPERTY = 'X-TIMESISTER-CODE';
	public const NON_BILLABLE = 'non-billable';
	/** Written by older clients, still recognised. */
	private const NON_BILLABLE_OLD = ['nicht anrechenbar'];
	public const MAX_ENTRIES = 500;
	public const TITLE_MAX = 500;
	public const CODE_MAX = 40;
	public const HASH_LENGTH = 32;
	/** The person's key must leave room for `+` and the hash within 128. */
	public const PERSON_MAX = 128 - 1 - self::HASH_LENGTH;
	public const UID_MAX = 1024;
	/** Record status set by the server: applied, or why not. */
	public const APPLIED = 'applied';
	public const STATUSES = [self::APPLIED, 'locked', 'forbidden', 'not_found', 'failed'];
	/** Fields of the record the server owns; whatever a client sends there is dropped. */
	public const SERVER_FIELDS = ['status', 'message', 'by', 'at', 'etag', 'codes', 'reapplied', 'reapplied_at'];
	private const LEVEL_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';
	private const CODE_PATTERN = '/^[\p{L}\p{N}.\-_\/]{1,40}$/u';
	private const RECURRENCE_PATTERN = '/^\d{8}(T\d{6})?$/';
	/** A `#code` with an optional share (`:60%`, `:1.5h`), not glued to a word. */
	private const TOKEN = '/(?<![\p{L}\p{N}])#[\p{L}\p{N}.\-_\/]+(?::[0-9][0-9.,]*[%hH]?)?/u';
	private const FIELDS = ['entries', 'title', 'code', 'billable', 'level'];
	private const RECORD_FIELDS = ['person', 'event_uid', 'recurrence', 'title', 'code', 'billable', 'level'];
	/** Invoice statuses that lock a booking (a draft does not). */
	private const LOCKING = ['issued', 'paid', HandoverRules::STATUS];

	// ---------------------------------------------------------------- body

	/**
	 * `{ entries: [{uid, event_uid}], title?, code?, billable?, level? }` –
	 * at least one of the four fields; `level: null` removes the level.
	 *
	 * @return array{entries:list<array{uid:string,event_uid:string}>,change:array<string,mixed>}
	 * @throws ApiException 422
	 */
	public static function parseBody(\stdClass $body): array {
		foreach (array_map('strval', array_keys(get_object_vars($body))) as $f) {
			if (!in_array($f, self::FIELDS, true)) {
				throw ApiException::invalid(Message::of('“{field}” is not a field of a correction.', ['field' => $f]));
			}
		}
		$list = $body->entries ?? null;
		if (!is_array($list) || !array_is_list($list) || $list === [] || count($list) > self::MAX_ENTRIES) {
			throw ApiException::invalid(Message::of('“entries” must be a list of 1 to {max} bookings { uid, event_uid }.', ['max' => self::MAX_ENTRIES]));
		}
		$entries = [];
		foreach ($list as $e) {
			$uid = $e instanceof \stdClass ? ($e->uid ?? null) : null;
			$ev = $e instanceof \stdClass ? ($e->event_uid ?? null) : null;
			if (!is_string($uid) || trim($uid) === '' || strlen($uid) > 128 || !is_string($ev) || trim($ev) === '' || strlen($ev) > 255
				|| preg_match('/[\x00-\x1F\x7F]/', $uid . $ev) === 1) {
				throw ApiException::invalid('Each entry needs “uid” (the account) and “event_uid”.');
			}
			$entries[] = ['uid' => trim($uid), 'event_uid' => trim($ev)];
		}
		return ['entries' => $entries, 'change' => self::parseChange($body)];
	}

	/**
	 * The four fields of a change, checked and cleaned; at least one.
	 *
	 * @return array<string,mixed>
	 * @throws ApiException 422
	 */
	public static function parseChange(\stdClass $body): array {
		$change = [];
		if (property_exists($body, 'title')) {
			$t = $body->title;
			if (!is_string($t) || trim($t) === '' || mb_strlen($t) > self::TITLE_MAX || !mb_check_encoding($t, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $t) === 1) {
				throw ApiException::invalid(Message::of('“{field}” must be a text of at most {max} characters.', ['field' => 'title', 'max' => self::TITLE_MAX]));
			}
			$change['title'] = trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
		}
		if (property_exists($body, 'code')) {
			$c = is_string($body->code) ? ltrim(trim($body->code), '#') : null;
			if ($c === null || $c === '' || preg_match(self::CODE_PATTERN, $c) !== 1 || rtrim($c, '.-_/') !== $c) {
				throw ApiException::invalid(Message::of('“{field}” must be a project code of 1 to {max} characters.', ['field' => 'code', 'max' => self::CODE_MAX]));
			}
			$change['code'] = $c;
		}
		if (property_exists($body, 'billable')) {
			if (!is_bool($body->billable)) {
				throw ApiException::notBool('billable');
			}
			$change['billable'] = $body->billable;
		}
		if (property_exists($body, 'level')) {
			$l = $body->level;
			if ($l !== null && (!is_string($l) || preg_match(self::LEVEL_PATTERN, $l) !== 1)) {
				throw ApiException::invalid('“level” must be the id of a level, or null.');
			}
			$change['level'] = $l;
		}
		if ($change === []) {
			throw ApiException::invalid('A correction changes at least one of title, code, billable, level.');
		}
		return $change;
	}

	// -------------------------------------------------------------- record

	/**
	 * The key of a correction record: the person's key, `+` and the first 32
	 * hex digits of the SHA-256 of the event UID – with a recurrence of
	 * `UID\nrecurrence` – so a key stays within the record key pattern.
	 */
	public static function key(string $person, string $eventUid, ?string $recurrence): string {
		$text = $recurrence === null ? $eventUid : $eventUid . "\n" . $recurrence;
		return $person . '+' . substr(hash('sha256', $text), 0, self::HASH_LENGTH);
	}

	/** The person's key out of a correction key; null if the key is not one. */
	public static function personOf(string $key): ?string {
		return BillingRules::personOf($key);
	}

	/**
	 * Content check of a record: `person` (a key), `event_uid`, optional
	 * `recurrence`, and the four fields of the change (at least one). The
	 * server's fields are dropped – {@see withServerFields()} sets them.
	 *
	 * @throws ApiException 422
	 */
	public static function validateRecord(string $key, \stdClass $data): void {
		foreach (array_map('strval', array_keys(get_object_vars($data))) as $f) {
			if (!in_array($f, self::RECORD_FIELDS, true) && !in_array($f, self::SERVER_FIELDS, true)) {
				throw ApiException::invalid(Message::of('“{field}” is not a field of a correction.', ['field' => $f]));
			}
		}
		$person = $data->person ?? null;
		$uid = $data->event_uid ?? null;
		if (!is_string($person) || !is_string($uid) || trim($uid) === '' || strlen($uid) > self::UID_MAX
			|| strlen($person) > self::PERSON_MAX || !RecordValidator::isKey($person) || preg_match('/[\x00-\x1F\x7F]/', $uid) === 1) {
			throw ApiException::invalid('For a correction, “person” must be the person’s key and “event_uid” the event’s UID.');
		}
		$recurrence = $data->recurrence ?? null;
		if ($recurrence !== null && (!is_string($recurrence) || preg_match(self::RECURRENCE_PATTERN, $recurrence) !== 1)) {
			throw ApiException::invalid('“recurrence” must be YYYYMMDDTHHMMSS or YYYYMMDD, or be left out.');
		}
		if (self::key($person, $uid, $recurrence) !== $key) {
			throw ApiException::invalid('The key of a correction is the person’s key, “+” and 32 hex digits of the SHA-256 of the event UID (and recurrence).');
		}
		self::parseChange($data);
	}

	/** The change of a validated record. @return array<string,mixed> */
	public static function changeOf(\stdClass $data): array {
		$body = new \stdClass();
		foreach (['title', 'code', 'billable', 'level'] as $f) {
			if (property_exists($data, $f)) {
				$body->$f = $data->$f;
			}
		}
		return self::parseChange($body);
	}

	/** The recurrence of a record, or null. */
	public static function recurrenceOf(\stdClass $data): ?string {
		$r = $data->recurrence ?? null;
		return is_string($r) && preg_match(self::RECURRENCE_PATTERN, $r) === 1 ? $r : null;
	}

	/**
	 * The record with the server's fields: who, when, the outcome; `etag`
	 * and `codes` when applied.
	 *
	 * @param array<string,mixed> $fields status, message?, etag?, codes?, reapplied?, reapplied_at?
	 */
	public static function withServerFields(\stdClass $data, string $by, int $now, array $fields): \stdClass {
		$d = clone $data;
		foreach (self::SERVER_FIELDS as $f) {
			unset($d->$f);
		}
		$d->status = (string)($fields['status'] ?? 'failed');
		if (isset($fields['message']) && $fields['message'] !== '') {
			$d->message = (string)$fields['message'];
		}
		$d->by = $by;
		$d->at = gmdate('Y-m-d\TH:i:s\Z', $now);
		if (isset($fields['etag'])) {
			$d->etag = (string)$fields['etag'];
		}
		if (isset($fields['codes']) && is_array($fields['codes'])) {
			$d->codes = array_values($fields['codes']);
		}
		if (isset($fields['reapplied'])) {
			$d->reapplied = (int)$fields['reapplied'];
			$d->reapplied_at = gmdate('Y-m-d\TH:i:s\Z', $now);
		}
		return $d;
	}

	// -------------------------------------------------------------- rights

	/**
	 * Team Admins correct everything. A Lead corrects a booking whose codes
	 * all belong to projects they lead (at least one code), and may move it
	 * only to a code of a project they lead. Members never.
	 *
	 * @param list<string> $ledCodes lower-cased, with subprojects
	 * @param list<string> $entryCodes the entry's codes, lower-cased
	 */
	public static function allowed(string $role, array $ledCodes, array $entryCodes, ?string $newCode): bool {
		if (Role::manages($role)) {
			return true;
		}
		if ($role !== Role::LEAD || $entryCodes === []) {
			return false;
		}
		foreach ($entryCodes as $c) {
			if (!self::led($c, $ledCodes)) {
				return false;
			}
		}
		return $newCode === null || self::led(mb_strtolower($newCode), $ledCodes);
	}

	/** @param list<string> $ledCodes */
	private static function led(string $code, array $ledCodes): bool {
		foreach ($ledCodes as $led) {
			if ($code === $led || str_starts_with($code, $led . '.')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Locked: a row of an issued (paid, handed over) invoice bills the event,
	 * or a live `billing` record marks it billed. Drafts do not lock.
	 *
	 * @param list<array{invoice_id:int,start:string,number:string,status:string,rkey:string}> $billings
	 * @return ?string the invoice number (or `''` for a bare billing mark); null when free
	 */
	public static function lockedBy(array $billings, bool $billingRecord): ?string {
		foreach ($billings as $b) {
			if (in_array($b['status'], self::LOCKING, true)) {
				return $b['number'];
			}
		}
		return $billingRecord ? '' : null;
	}

	// --------------------------------------------------------------- iCal

	/**
	 * The object with the change applied – to every VEVENT (a series as a
	 * whole, exceptions included), or with `$recurrence` to the exception of
	 * that occurrence only (created from the master when there is none).
	 * SUMMARY carries `#code title`; with an own `X-TIMESISTER-CODE`
	 * (foreign invitation) the code goes there and the title stays;
	 * `billable` is the category `non-billable`; the level is
	 * `X-TIMESISTER-LEVEL`. SEQUENCE, DTSTAMP and LAST-MODIFIED are advanced
	 * so other clients re-read. Nested components (VALARM) and everything
	 * else stay as they are.
	 *
	 * @param array<string,mixed> $change
	 * @param string $nowUtc `YYYYMMDDTHHMMSSZ`
	 */
	public static function apply(string $ics, array $change, string $nowUtc, ?string $recurrence = null): string {
		[$head, $blocks, $tail] = self::split($ics);
		if ($recurrence !== null) {
			$hit = self::exceptionIndex($blocks, $recurrence);
			if ($hit === null) {
				$master = self::masterIndex($blocks);
				if ($master === null) {
					return $ics;
				}
				array_splice($blocks, $master + 1, 0, [self::exceptionLines($blocks[$master], $recurrence)]);
				$hit = $master + 1;
			}
			$blocks[$hit] = self::rewrite($blocks[$hit], $change, $nowUtc);
		} else {
			foreach ($blocks as $i => $b) {
				$blocks[$i] = self::rewrite($b, $change, $nowUtc);
			}
		}
		$out = $head;
		foreach ($blocks as $b) {
			array_push($out, 'BEGIN:VEVENT', ...$b);
			$out[] = 'END:VEVENT';
		}
		array_push($out, ...$tail);
		return implode("\r\n", array_map([self::class, 'fold'], $out)) . "\r\n";
	}

	/**
	 * Does the object already carry the target state? Without the exception
	 * of a named occurrence: no. Applying twice then changes nothing.
	 *
	 * @param array<string,mixed> $change
	 */
	public static function met(string $ics, array $change, ?string $recurrence = null): bool {
		[, $blocks] = self::split($ics);
		if ($blocks === []) {
			return false;
		}
		if ($recurrence !== null) {
			$hit = self::exceptionIndex($blocks, $recurrence);
			return $hit !== null && self::metBy($blocks[$hit], $change);
		}
		foreach ($blocks as $b) {
			if (!self::metBy($b, $change)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The lines before the first top-level VEVENT, the VEVENT blocks (unfolded
	 * lines without BEGIN/END), and the lines after.
	 *
	 * @return array{0:list<string>,1:list<list<string>>,2:list<string>}
	 */
	private static function split(string $ics): array {
		$head = [];
		$tail = [];
		$blocks = [];
		$depth = 0;
		$block = null;
		foreach (EntryRules::lines($ics) as $line) {
			$upper = strtoupper($line);
			if (str_starts_with($upper, 'BEGIN:')) {
				$name = trim(substr($upper, 6));
				if ($name !== 'VCALENDAR') {
					$depth++;
					if ($depth === 1 && $name === 'VEVENT') {
						$block = [];
						continue;
					}
				}
			} elseif (str_starts_with($upper, 'END:')) {
				$name = trim(substr($upper, 4));
				if ($name !== 'VCALENDAR') {
					$depth--;
					if ($depth === 0 && $block !== null) {
						$blocks[] = $block;
						$block = null;
						continue;
					}
				}
			}
			if ($block !== null) {
				$block[] = $line;
			} elseif ($blocks === []) {
				$head[] = $line;
			} else {
				$tail[] = $line;
			}
		}
		// END:VCALENDAR belongs to the tail; a tail before any event is the head.
		return [$head, $blocks, $tail];
	}

	/**
	 * The properties of a block (top level only).
	 *
	 * @param list<string> $lines
	 * @return array<string,list<array{i:int,value:string,params:array<string,string>}>>
	 */
	private static function propsOf(array $lines): array {
		$props = [];
		$nested = 0;
		foreach ($lines as $i => $line) {
			$upper = strtoupper($line);
			if (str_starts_with($upper, 'BEGIN:')) {
				$nested++;
				continue;
			}
			if (str_starts_with($upper, 'END:')) {
				$nested--;
				continue;
			}
			if ($nested > 0) {
				continue;
			}
			$p = EntryRules::property($line);
			if ($p !== null) {
				$props[$p['name']][] = ['i' => $i, 'value' => $p['value'], 'params' => $p['params']];
			}
		}
		return $props;
	}

	/** Only the digits and the `T` of an iCal time (`20261021T090000`), at most 15. */
	private static function timeDigits(string $v): string {
		return substr(preg_replace('/[^0-9T]/', '', $v) ?? '', 0, 15);
	}

	/** @param list<list<string>> $blocks */
	private static function exceptionIndex(array $blocks, string $recurrence): ?int {
		$want = self::timeDigits($recurrence);
		foreach ($blocks as $i => $b) {
			$rid = self::propsOf($b)['RECURRENCE-ID'][0]['value'] ?? null;
			if ($rid !== null && str_starts_with(self::timeDigits($rid), $want)) {
				return $i;
			}
		}
		return null;
	}

	/** @param list<list<string>> $blocks */
	private static function masterIndex(array $blocks): ?int {
		foreach ($blocks as $i => $b) {
			if (!isset(self::propsOf($b)['RECURRENCE-ID'])) {
				return $i;
			}
		}
		return $blocks === [] ? null : 0;
	}

	/**
	 * The exception for an occurrence: the master's lines without the rule,
	 * with `RECURRENCE-ID` and `DTSTART` on the occurrence (the master's
	 * zone and form) and `DTEND` moved by the master's duration.
	 *
	 * @param list<string> $master
	 * @return list<string>
	 */
	public static function exceptionLines(array $master, string $recurrence): array {
		$props = self::propsOf($master);
		$start = $props['DTSTART'][0] ?? null;
		$allDay = $start !== null && (strtoupper($start['params']['VALUE'] ?? '') === 'DATE' || strlen($start['value']) === 8);
		$utc = $start !== null && str_ends_with($start['value'], 'Z');
		$value = $allDay ? substr($recurrence, 0, 8) : self::timeDigits($recurrence) . ($utc ? 'Z' : '');
		$params = $start !== null ? self::paramText($start['params']) : '';
		$out = [];
		$nested = 0;
		foreach ($master as $i => $line) {
			$upper = strtoupper($line);
			if (str_starts_with($upper, 'BEGIN:')) {
				$nested++;
			}
			$name = $nested === 0 ? (EntryRules::property($line)['name'] ?? null) : null;
			if (str_starts_with($upper, 'END:')) {
				$nested--;
			}
			if (in_array($name, ['RRULE', 'RDATE', 'EXDATE', 'EXRULE', 'RECURRENCE-ID'], true)) {
				continue;
			}
			if ($name === 'DTSTART') {
				$out[] = 'DTSTART' . $params . ':' . $value;
				$out[] = 'RECURRENCE-ID' . $params . ':' . $value;
				continue;
			}
			if ($name === 'DTEND' && $start !== null) {
				$end = $props['DTEND'][0];
				$seconds = self::seconds($start['value'], $end['value'], $allDay);
				$out[] = 'DTEND' . self::paramText($end['params']) . ':' . self::plus($value, $seconds, $allDay);
				continue;
			}
			$out[] = $line;
		}
		return $out;
	}

	/** @param array<string,string> $params */
	private static function paramText(array $params): string {
		$s = '';
		foreach ($params as $k => $v) {
			$s .= ';' . $k . '=' . (preg_match('/[;:,]/', $v) === 1 ? '"' . $v . '"' : $v);
		}
		return $s;
	}

	private static function parseTime(string $v, bool $allDay): ?\DateTimeImmutable {
		$d = self::timeDigits($v);
		$t = $allDay || strlen($d) === 8
			? \DateTimeImmutable::createFromFormat('!Ymd', substr($d, 0, 8), new \DateTimeZone('UTC'))
			: \DateTimeImmutable::createFromFormat('!Ymd\THis', $d, new \DateTimeZone('UTC'));
		return $t === false ? null : $t;
	}

	private static function seconds(string $from, string $to, bool $allDay): int {
		$a = self::parseTime($from, $allDay);
		$b = self::parseTime($to, $allDay);
		if ($a === null || $b === null) {
			return $allDay ? 86400 : 3600;
		}
		return max(0, $b->getTimestamp() - $a->getTimestamp());
	}

	private static function plus(string $value, int $seconds, bool $allDay): string {
		$t = self::parseTime($value, $allDay);
		if ($t === null) {
			return $value;
		}
		$n = $t->modify("+{$seconds} seconds");
		return $allDay ? $n->format('Ymd') : $n->format('Ymd\THis') . (str_ends_with($value, 'Z') ? 'Z' : '');
	}

	/**
	 * One VEVENT's lines (unfolded, without BEGIN/END) with the change.
	 *
	 * @param list<string> $lines
	 * @param array<string,mixed> $change
	 * @return list<string>
	 */
	private static function rewrite(array $lines, array $change, string $nowUtc): array {
		$props = self::propsOf($lines);
		$set = self::targets($props, $change);
		$drop = [];
		if (array_key_exists('code', $change)) {
			if (isset($props[self::CODE_PROPERTY])) {
				$drop[] = self::CODE_PROPERTY;
			}
		}
		if (array_key_exists('billable', $change)) {
			$drop[] = 'CATEGORIES';
		}
		if (array_key_exists('level', $change)) {
			$drop[] = self::LEVEL_PROPERTY;
		}
		$seq = (int)($props['SEQUENCE'][0]['value'] ?? 0);
		$set['SEQUENCE'] = (string)($seq + 1);
		$set['DTSTAMP'] = $nowUtc;
		$set['LAST-MODIFIED'] = $nowUtc;
		$drop = array_merge($drop, ['SEQUENCE', 'DTSTAMP', 'LAST-MODIFIED']);
		// Replace in place where the property stands, append what is new.
		$replaced = [];
		$skip = [];
		foreach ($drop as $name) {
			foreach ($props[$name] ?? [] as $p) {
				$skip[$p['i']] = true;
			}
		}
		$out = [];
		$nested = 0;
		foreach ($lines as $i => $line) {
			$upper = strtoupper($line);
			if (str_starts_with($upper, 'BEGIN:')) {
				$nested++;
			}
			$name = $nested === 0 ? (EntryRules::property($line)['name'] ?? null) : null;
			if (str_starts_with($upper, 'END:')) {
				$nested--;
			}
			if ($name !== null && array_key_exists($name, $set) && !isset($replaced[$name])) {
				if ($set[$name] !== null) {
					$out[] = self::line($name, $set[$name], $name === 'CATEGORIES');
				}
				$replaced[$name] = true;
				continue;
			}
			if (isset($skip[$i])) {
				continue;
			}
			$out[] = $line;
		}
		foreach ($set as $name => $value) {
			if (!isset($replaced[$name]) && $value !== null) {
				$out[] = self::line($name, $value, $name === 'CATEGORIES');
			}
		}
		return $out;
	}

	/**
	 * The property values the change asks for: SUMMARY, LOCATION, DESCRIPTION,
	 * X-TIMESISTER-CODE, CATEGORIES (null: none), X-TIMESISTER-LEVEL (null: none).
	 *
	 * @param array<string,list<array{i:int,value:string,params:array<string,string>}>> $props
	 * @param array<string,mixed> $change
	 * @return array<string,?string>
	 */
	private static function targets(array $props, array $change): array {
		$set = [];
		$summary = EntryRules::unescape($props['SUMMARY'][0]['value'] ?? '');
		$ownCode = isset($props[self::CODE_PROPERTY]);
		if (array_key_exists('code', $change)) {
			$code = (string)$change['code'];
			foreach (['LOCATION', 'DESCRIPTION'] as $f) {
				if (isset($props[$f][0])) {
					$text = EntryRules::unescape($props[$f][0]['value']);
					$clean = self::withoutTokens($text);
					if ($clean !== $text) {
						$set[$f] = $clean;
					}
				}
			}
			if ($ownCode) {
				$set[self::CODE_PROPERTY] = $code;
			} else {
				$summary = self::summaryWith('#' . $code, self::withoutTokens($summary));
				$set['SUMMARY'] = $summary;
			}
		}
		if (array_key_exists('title', $change)) {
			$title = (string)$change['title'];
			$summary = $ownCode ? $title : self::summaryWith(self::tokensOf($summary), $title);
			$set['SUMMARY'] = $summary;
		}
		if (array_key_exists('billable', $change)) {
			$values = [];
			foreach ($props['CATEGORIES'] ?? [] as $c) {
				foreach (preg_split('/(?<!\\\\),/', $c['value']) ?: [] as $v) {
					$v = trim(EntryRules::unescape($v));
					if ($v !== '' && !self::isNonBillable($v)) {
						$values[] = $v;
					}
				}
			}
			if ($change['billable'] === false) {
				$values[] = self::NON_BILLABLE;
			}
			$set['CATEGORIES'] = $values === [] ? null : implode(',', array_map([self::class, 'escape'], $values));
		}
		if (array_key_exists('level', $change)) {
			$set[self::LEVEL_PROPERTY] = $change['level'] === null ? null : (string)$change['level'];
		}
		return $set;
	}

	/**
	 * Does one VEVENT carry the change already? Compared on the values the
	 * change would set – whitespace aside.
	 *
	 * @param list<string> $lines
	 * @param array<string,mixed> $change
	 */
	private static function metBy(array $lines, array $change): bool {
		$props = self::propsOf($lines);
		foreach (self::targets($props, $change) as $name => $want) {
			if ($name === 'CATEGORIES') {
				$non = false;
				foreach ($props['CATEGORIES'] ?? [] as $c) {
					foreach (preg_split('/(?<!\\\\),/', $c['value']) ?: [] as $v) {
						if (self::isNonBillable(trim(EntryRules::unescape($v)))) {
							$non = true;
						}
					}
				}
				if ($non !== ($change['billable'] === false)) {
					return false;
				}
				continue;
			}
			$have = isset($props[$name][0]) ? trim(EntryRules::unescape($props[$name][0]['value'])) : null;
			$have = $have === null ? null : trim(preg_replace('/\s+/u', ' ', $have) ?? $have);
			if ($name === self::LEVEL_PROPERTY || $name === self::CODE_PROPERTY) {
				if (mb_strtolower((string)$have) !== mb_strtolower((string)$want) || (($have === null || $have === '') !== ($want === null))) {
					return false;
				}
				continue;
			}
			if (($have ?? '') !== ($want ?? '')) {
				return false;
			}
		}
		return true;
	}

	/** `#code` first, then the title; a title that is empty gives the code alone. */
	private static function summaryWith(string $codes, string $title): string {
		return trim($codes . ' ' . trim($title));
	}

	/** The `#code` tokens of a text, in order, as one string. */
	private static function tokensOf(string $text): string {
		preg_match_all(self::TOKEN, $text, $m);
		return implode(' ', $m[0]);
	}

	/** The text without its `#code` tokens (a share like `:50%` goes with them). */
	private static function withoutTokens(string $text): string {
		$clean = preg_replace(self::TOKEN, '', $text) ?? $text;
		return trim(preg_replace('/\s+/u', ' ', $clean) ?? $clean);
	}

	private static function isNonBillable(string $v): bool {
		$w = mb_strtolower(trim($v));
		return $w === self::NON_BILLABLE || in_array($w, self::NON_BILLABLE_OLD, true);
	}

	public static function escape(string $s): string {
		return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $s);
	}

	/** A property line; CATEGORIES is already escaped per value. */
	private static function line(string $name, string $value, bool $raw): string {
		return $name . ':' . ($raw ? $value : self::escape($value));
	}

	/** RFC 5545 folding at 75 octets, on UTF-8 boundaries. */
	public static function fold(string $line): string {
		if (strlen($line) <= 75) {
			return $line;
		}
		$out = '';
		$first = true;
		while ($line !== '') {
			$chunk = mb_strcut($line, 0, $first ? 75 : 74, 'UTF-8');
			if ($chunk === '') {
				$chunk = substr($line, 0, 1);
			}
			$out .= ($first ? '' : "\r\n ") . $chunk;
			$line = substr($line, strlen($chunk));
			$first = false;
		}
		return $out;
	}

	/**
	 * The level of a parsed component (`X-TIMESISTER-LEVEL`), or null.
	 *
	 * @param array{props:array<string,list<array{params:array<string,string>,value:string}>>,...} $c
	 */
	public static function levelOf(array $c): ?string {
		$v = trim($c['props'][self::LEVEL_PROPERTY][0]['value'] ?? '');
		return $v !== '' && preg_match(self::LEVEL_PATTERN, $v) === 1 ? $v : null;
	}
}
