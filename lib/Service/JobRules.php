<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Jobs (0.6.0): checking what comes in, the target in the project, and the
 * two rules – volume and no overlap – plus who sees a Job. Pure: knows
 * only arrays, so everything here is testable without Nextcloud.
 *
 * A Job is kept as an array in the form of the record's `data` (API.md,
 * “Jobs”). States: offered, counter, rejected, declined, in_progress,
 * returned, done. Offered to several (a market), each recipient may accept,
 * decline or counter-propose; the first to be accepted gets the Job.
 */
final class JobRules {
	public const OFFERED = 'offered';
	public const COUNTER = 'counter';
	public const REJECTED = 'rejected';
	public const DECLINED = 'declined';
	public const IN_PROGRESS = 'in_progress';
	public const RETURNED = 'returned';
	public const DONE = 'done';
	public const STATES = [self::OFFERED, self::COUNTER, self::REJECTED, self::DECLINED, self::IN_PROGRESS, self::RETURNED, self::DONE];
	/** States in which a Job holds its full hours of the work package. */
	public const HOLDING = [self::OFFERED, self::COUNTER, self::IN_PROGRESS];

	public const MAX_HOURS = 100000.0;
	public const MAX_RECIPIENTS = 50;
	public const MAX_DESCRIPTION = 4000;
	public const MAX_TITLE = 200;
	public const MAX_NOTE = 1000;
	/** Longest period in days (ten years). */
	public const MAX_DAYS = 3660;
	public const KEY_MAX = 64;
	public const MAX_PROGRESS = 500;
	/** Hours are compared with this tolerance (rounding to hundredths). */
	public const EPS = 0.005;

	/** A Job key: like a record key, at most 64 characters (notification object). */
	public static function checkKey(string $key): void {
		if (strlen($key) > self::KEY_MAX || !RecordValidator::isKey($key)) {
			throw ApiException::badRequest(Message::of('Invalid {job} key. Allowed are 1–64 characters from A–Z, a–z, 0–9 and @ . _ + -', ['job' => JobWord::JOB]));
		}
	}

	// ------------------------------------------------------------ fields

	public static function hours(mixed $v, string $field = 'hours'): float {
		if (is_int($v)) {
			$v = (float)$v;
		}
		if (!is_float($v) || !is_finite($v) || $v <= 0 || $v > self::MAX_HOURS) {
			throw ApiException::invalid(Message::of('“{field}” must be a number of hours above 0.', ['field' => $field]));
		}
		return round($v, 2);
	}

	public static function day(mixed $v, string $field): string {
		if (!is_string($v) || !Time::isDay($v)) {
			throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => $field]));
		}
		return $v;
	}

	public static function period(string $start, string $end): void {
		if ($end < $start) {
			throw ApiException::invalid('The end is before the start.');
		}
		$days = (int)((new \DateTimeImmutable($end))->diff(new \DateTimeImmutable($start))->days);
		if ($days > self::MAX_DAYS) {
			throw ApiException::invalid(Message::of('A {job} runs for at most ten years.', ['job' => JobWord::JOB]));
		}
	}

	/** Text of at most `$max` characters, trimmed; empty is null. */
	public static function text(mixed $v, string $field, int $max): ?string {
		if ($v === null) {
			return null;
		}
		if (!is_string($v) || mb_strlen($v) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v)) {
			throw ApiException::invalid(Message::of('“{field}” must be a text of at most {max} characters.', ['field' => $field, 'max' => $max]));
		}
		$v = trim($v);
		return $v === '' ? null : $v;
	}

	/** @return list<string> */
	public static function recipients(mixed $v): array {
		if (!is_array($v) || !array_is_list($v) || $v === [] || count($v) > self::MAX_RECIPIENTS) {
			throw ApiException::invalid(Message::of('“recipients” must be a list of 1 to {max} accounts.', ['max' => self::MAX_RECIPIENTS]));
		}
		$out = [];
		foreach ($v as $uid) {
			if (!is_string($uid) || $uid === '' || strlen($uid) > 64) {
				throw ApiException::invalid(Message::of('“recipients” must be a list of 1 to {max} accounts.', ['max' => self::MAX_RECIPIENTS]));
			}
			$out[$uid] = true;
		}
		return array_map('strval', array_keys($out));
	}

	/** A key or code from the body: text, 1–128 characters. */
	private static function name(mixed $v, string $field): string {
		if (!is_string($v) || trim($v) === '' || strlen($v) > 128) {
			throw ApiException::invalid(Message::of('“{field}” is invalid.', ['field' => $field]));
		}
		return trim($v);
	}

	/**
	 * The body of an offer.
	 *
	 * @return array{project:string,code:string,budget:?string,work_package:?string,title:?string,description:?string,hours:float,start:string,end:string,recipients:list<string>}
	 */
	public static function offer(\stdClass $b): array {
		foreach (['project', 'code', 'hours', 'start', 'end', 'recipients'] as $field) {
			if (!property_exists($b, $field)) {
				throw ApiException::missing($field);
			}
		}
		$budget = ($b->budget ?? null) === null ? null : self::name($b->budget, 'budget');
		$wp = ($b->work_package ?? null) === null ? null : self::name($b->work_package, 'work_package');
		if (($budget === null) !== ($wp === null)) {
			throw ApiException::invalid('“budget” and “work_package” go together.');
		}
		$start = self::day($b->start, 'start');
		$end = self::day($b->end, 'end');
		self::period($start, $end);
		return [
			'project' => self::name($b->project, 'project'),
			'code' => self::name($b->code, 'code'),
			'budget' => $budget,
			'work_package' => $wp,
			'title' => self::text($b->title ?? null, 'title', self::MAX_TITLE),
			'description' => self::text($b->description ?? null, 'description', self::MAX_DESCRIPTION),
			'hours' => self::hours($b->hours),
			'start' => $start,
			'end' => $end,
			'recipients' => self::recipients($b->recipients),
		];
	}

	/**
	 * The body of a change: only the fields sent. Project, budget and work
	 * package stay – for another target a new Job is created.
	 *
	 * @return array<string,mixed>
	 */
	public static function change(\stdClass $b): array {
		foreach (['project', 'budget', 'work_package'] as $field) {
			if (property_exists($b, $field)) {
				throw ApiException::invalid(Message::of('“{field}” of a {job} does not change; create a new {job} instead.', ['field' => $field, 'job' => JobWord::JOB]));
			}
		}
		$out = [];
		if (property_exists($b, 'code')) {
			$out['code'] = self::name($b->code, 'code');
		}
		if (property_exists($b, 'title')) {
			$out['title'] = self::text($b->title, 'title', self::MAX_TITLE);
		}
		if (property_exists($b, 'description')) {
			$out['description'] = self::text($b->description, 'description', self::MAX_DESCRIPTION);
		}
		if (property_exists($b, 'hours')) {
			$out['hours'] = self::hours($b->hours);
		}
		if (property_exists($b, 'start')) {
			$out['start'] = self::day($b->start, 'start');
		}
		if (property_exists($b, 'end')) {
			$out['end'] = self::day($b->end, 'end');
		}
		if (property_exists($b, 'recipients')) {
			$out['recipients'] = self::recipients($b->recipients);
		}
		if ($out === []) {
			throw ApiException::invalid('Nothing to change.');
		}
		return $out;
	}

	/**
	 * A counter-proposal: hours, period and/or description, plus a note.
	 *
	 * @return array{hours?:float,start?:string,end?:string,description?:?string,note:?string}
	 */
	public static function counter(\stdClass $b): array {
		$out = [];
		if (property_exists($b, 'hours')) {
			$out['hours'] = self::hours($b->hours);
		}
		if (property_exists($b, 'start')) {
			$out['start'] = self::day($b->start, 'start');
		}
		if (property_exists($b, 'end')) {
			$out['end'] = self::day($b->end, 'end');
		}
		if (property_exists($b, 'description')) {
			$out['description'] = self::text($b->description, 'description', self::MAX_DESCRIPTION);
		}
		if ($out === []) {
			throw ApiException::invalid('A counter-proposal changes hours, period or description.');
		}
		$out['note'] = self::text($b->note ?? null, 'note', self::MAX_NOTE);
		return $out;
	}

	/**
	 * Progress reported by the assignee's client: per Job the booked hours
	 * (already capped at the volume) and whether they are all invoiced.
	 *
	 * @return list<array{key:string,hours:float,invoiced:bool}>
	 */
	public static function progress(\stdClass $b): array {
		$jobs = $b->jobs ?? null;
		if (!($jobs instanceof \stdClass)) {
			throw ApiException::invalid('“jobs” must be an object: key → { hours, invoiced }.');
		}
		/** @var array<array-key,mixed> $all numeric keys come as int */
		$all = get_object_vars($jobs);
		if (count($all) > self::MAX_PROGRESS) {
			throw ApiException::tooLarge(Message::of('At most {limit} entries per call.', ['limit' => self::MAX_PROGRESS]));
		}
		$out = [];
		foreach ($all as $key => $p) {
			$key = (string)$key;
			self::checkKey($key);
			if (!($p instanceof \stdClass)) {
				throw ApiException::invalid('“jobs” must be an object: key → { hours, invoiced }.');
			}
			$h = $p->hours ?? null;
			if (is_int($h)) {
				$h = (float)$h;
			}
			if (!is_float($h) || !is_finite($h) || $h < 0 || $h > self::MAX_HOURS) {
				throw ApiException::invalidField('hours');
			}
			$inv = $p->invoiced ?? false;
			if (!is_bool($inv)) {
				throw ApiException::notBool('invoiced');
			}
			$out[] = ['key' => $key, 'hours' => round($h, 2), 'invoiced' => $inv];
		}
		return $out;
	}

	// ------------------------------------------------ target in the project

	/**
	 * Where a Job books: its codes and, with a budget, the hours of the
	 * work package. Checks that project, budget, work package and code fit.
	 *
	 * @param array<string,mixed> $project the project's `data`
	 * @return array{codes:list<string>,ap_hours:?float,ap_name:?string}
	 */
	public static function target(array $project, string $code, ?string $budget, ?string $wp): array {
		$lc = mb_strtolower($code);
		if ($budget === null || $wp === null) {
			$known = array_map('mb_strtolower', self::projectCodes($project));
			if (!in_array($lc, $known, true)) {
				throw new ApiException(422, ApiException::INVALID, Message::of('The code {code} does not belong to this project.', ['code' => $code]), ['rule' => 'code']);
			}
			return ['codes' => [$code], 'ap_hours' => null, 'ap_name' => null];
		}
		foreach ((array)($project['budgets'] ?? []) as $b) {
			if (!is_array($b) || ($b['id'] ?? null) !== $budget) {
				continue;
			}
			foreach ((array)($b['work_packages'] ?? []) as $ap) {
				if (!is_array($ap) || (string)($ap['no'] ?? '') !== $wp) {
					continue;
				}
				$codes = array_values(array_filter(array_map(static fn ($c) => is_string($c) ? trim($c) : '', (array)($ap['codes'] ?? [])), static fn ($c) => $c !== ''));
				if ($codes !== [] && !in_array($lc, array_map('mb_strtolower', $codes), true)) {
					throw new ApiException(422, ApiException::INVALID, Message::of('The code {code} does not belong to this work package.', ['code' => $code]), ['rule' => 'code']);
				}
				$hours = 0.0;
				foreach ((array)($ap['hours'] ?? []) as $h) {
					$hours += is_int($h) || is_float($h) ? (float)$h : 0.0;
				}
				$name = is_string($ap['name'] ?? null) && trim($ap['name']) !== '' ? trim($ap['name']) : $wp;
				return ['codes' => $codes === [] ? [$code] : $codes, 'ap_hours' => round($hours, 2), 'ap_name' => $name];
			}
			throw new ApiException(422, ApiException::INVALID, Message::of('This work package does not exist in the budget.'), ['rule' => 'work_package']);
		}
		throw new ApiException(422, ApiException::INVALID, Message::of('This budget does not exist in the project.'), ['rule' => 'budget']);
	}

	/**
	 * The project's codes: `codes` and the subprojects' `code`.
	 *
	 * @param array<string,mixed> $project
	 * @return list<string>
	 */
	public static function projectCodes(array $project): array {
		$out = [];
		foreach ((array)($project['codes'] ?? []) as $c) {
			if (is_string($c) && trim($c) !== '') {
				$out[] = trim($c);
			}
		}
		foreach ((array)($project['subprojects'] ?? []) as $s) {
			if (is_array($s) && is_string($s['code'] ?? null) && trim($s['code']) !== '') {
				$out[] = trim($s['code']);
			}
		}
		return $out;
	}

	// ----------------------------------------------------------- the rules

	/**
	 * What a Job holds of its work package: offers and running Jobs their
	 * full hours; returned, declined, rejected or Done ones what was booked.
	 *
	 * @param array<string,mixed> $job
	 */
	public static function hold(array $job): float {
		$hours = (float)($job['hours'] ?? 0);
		if (in_array($job['state'] ?? '', self::HOLDING, true)) {
			return $hours;
		}
		return min($hours, (float)($job['progress']['hours'] ?? 0));
	}

	/**
	 * Volume: all Jobs of a work package together never above its hours.
	 *
	 * @param list<array<string,mixed>> $others the other live Jobs of the team
	 * @param array<string,mixed> $job the Job as it would be
	 */
	public static function checkVolume(array $job, float $apHours, array $others): void {
		if (($job['budget'] ?? null) === null) {
			return;
		}
		$used = 0.0;
		foreach ($others as $o) {
			if (($o['id'] ?? null) !== ($job['id'] ?? null) && self::samePackage($o, $job)) {
				$used += self::hold($o);
			}
		}
		$need = self::hold($job);
		if ($used + $need > $apHours + self::EPS) {
			$free = max(0.0, round($apHours - $used, 2));
			throw new ApiException(422, ApiException::INVALID, Message::of('Too many hours: the work package has {free} h left of {total} h.', ['free' => self::num($free), 'total' => self::num($apHours)]), ['rule' => 'volume', 'free' => $free, 'total' => $apHours]);
		}
	}

	/**
	 * No overlap: two Jobs on the same work package or code, in
	 * overlapping periods, for the same person.
	 *
	 * @param array<string,mixed> $job the Job as it would be
	 * @param list<string> $uids the persons to check
	 * @param list<array<string,mixed>> $others the other live Jobs of the team
	 * @param ?callable(string):string $name display name of an account
	 */
	public static function checkOverlap(array $job, array $uids, array $others, ?callable $name = null): void {
		foreach ($others as $o) {
			if (($o['id'] ?? null) === ($job['id'] ?? null) || !self::sameTarget($o, $job) || !self::overlapping($o, $job)) {
				continue;
			}
			foreach ($uids as $uid) {
				if (self::activeFor($o, $uid)) {
					throw new ApiException(422, ApiException::INVALID, Message::of('{user} already has a {job} on this code in this period ({from} – {to}).', ['user' => $name === null ? $uid : $name($uid), 'job' => JobWord::JOB, 'from' => (string)$o['start'], 'to' => (string)$o['end']]), ['rule' => 'overlap', 'user' => $uid, 'other' => (string)($o['id'] ?? '')]);
				}
			}
		}
	}

	/** @param array<string,mixed> $a @param array<string,mixed> $b */
	public static function samePackage(array $a, array $b): bool {
		return ($a['budget'] ?? null) !== null
			&& ($a['project'] ?? null) === ($b['project'] ?? null)
			&& ($a['budget'] ?? null) === ($b['budget'] ?? null)
			&& ($a['work_package'] ?? null) === ($b['work_package'] ?? null);
	}

	/** Same work package, or a common code. @param array<string,mixed> $a @param array<string,mixed> $b */
	public static function sameTarget(array $a, array $b): bool {
		if (self::samePackage($a, $b)) {
			return true;
		}
		$codes = static fn (array $j): array => array_map('mb_strtolower', array_map('strval', (array)($j['codes'] ?? [$j['code'] ?? ''])));
		return array_intersect($codes($a), $codes($b)) !== [];
	}

	/** @param array<string,mixed> $a @param array<string,mixed> $b */
	public static function overlapping(array $a, array $b): bool {
		return (string)$a['start'] <= (string)$b['end'] && (string)$b['start'] <= (string)$a['end'];
	}

	/**
	 * Does the Job count for this person – open offer to them, their
	 * pending counter-proposal, or running with them?
	 *
	 * @param array<string,mixed> $job
	 */
	public static function activeFor(array $job, string $uid): bool {
		return match ($job['state'] ?? '') {
			self::OFFERED => in_array($uid, self::openRecipients($job), true) || self::pendingCounter($job, $uid) !== null,
			self::COUNTER => self::pendingCounter($job, $uid) !== null,
			self::IN_PROGRESS => ($job['assignee'] ?? null) === $uid,
			default => false,
		};
	}

	/**
	 * Recipients who still have the offer in their Inbox: not declined, no
	 * counter-proposal of their own (pending or answered).
	 *
	 * @param array<string,mixed> $job
	 * @return list<string>
	 */
	public static function openRecipients(array $job): array {
		$out = array_map('strval', (array)($job['declined_by'] ?? []));
		foreach (self::counters($job) as $c) {
			$out[] = (string)($c['by'] ?? '');
		}
		return array_values(array_filter(array_map('strval', (array)($job['recipients'] ?? [])), static fn ($u) => !in_array($u, $out, true)));
	}

	// -------------------------------------------------- counter-proposals

	/**
	 * The counter-proposals, one per recipient (0.7.0). A Job from 0.6.0
	 * kept a single `counter`; it reads as a list of one.
	 *
	 * @param array<string,mixed> $job
	 * @return list<array<string,mixed>>
	 */
	public static function counters(array $job): array {
		if (is_array($job['counters'] ?? null)) {
			return array_values(array_filter($job['counters'], 'is_array'));
		}
		$old = $job['counter'] ?? null;
		return is_array($old) && is_string($old['by'] ?? null) ? [$old] : [];
	}

	/**
	 * The counter-proposals still waiting for the sender.
	 *
	 * @param array<string,mixed> $job
	 * @return list<array<string,mixed>>
	 */
	public static function pendingCounters(array $job): array {
		return array_values(array_filter(self::counters($job), static fn (array $c) => !isset($c['answer'])));
	}

	/**
	 * @param array<string,mixed> $job
	 * @return ?array<string,mixed>
	 */
	public static function pendingCounter(array $job, string $uid): ?array {
		foreach (self::pendingCounters($job) as $c) {
			if (($c['by'] ?? null) === $uid) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * The state of a Job nobody has yet: offered while someone has it in
	 * their Inbox, else waiting for a counter-proposal's answer, else
	 * rejected (a counter-proposal was rejected) or declined (by all).
	 *
	 * @param array<string,mixed> $job
	 */
	public static function openState(array $job): string {
		if (self::openRecipients($job) !== []) {
			return self::OFFERED;
		}
		if (self::pendingCounters($job) !== []) {
			return self::COUNTER;
		}
		foreach (self::counters($job) as $c) {
			if (($c['answer'] ?? null) === 'rejected') {
				return self::REJECTED;
			}
		}
		return self::DECLINED;
	}

	/**
	 * An answer to a counter-proposal names whose (`by`); it may be left
	 * out while only one waits.
	 */
	public static function counterAnswer(?\stdClass $b): ?string {
		$by = $b?->by ?? null;
		if ($by === null) {
			return null;
		}
		if (!is_string($by) || $by === '' || mb_strlen($by) > 64) {
			throw ApiException::invalid('“by” must be the account of the person who made the counter-proposal.');
		}
		return $by;
	}

	// ------------------------------------------------------------ who sees

	public const VIEW_FULL = 'full';
	public const VIEW_GONE = 'gone';
	public const VIEW_NONE = 'none';

	/**
	 * Who sees a Job: Team Admins, the project's Leads and the sender all of
	 * it; a person while it is in their Inbox, In Progress or Done. Whoever
	 * was involved before gets it as gone (a tombstone), so their client
	 * drops it; everyone else never hears of it.
	 *
	 * @param array<string,mixed> $job
	 * @param list<string> $accounts everyone ever involved (the record's `accounts`)
	 */
	public static function view(array $job, string $uid, bool $manages, bool $leadOfProject, array $accounts): string {
		if ($manages || $leadOfProject || ($job['sender'] ?? null) === $uid || self::activeFor($job, $uid)) {
			return self::VIEW_FULL;
		}
		if (($job['assignee'] ?? null) === $uid && ($job['state'] ?? '') === self::DONE) {
			return self::VIEW_FULL;
		}
		return in_array($uid, $accounts, true) ? self::VIEW_GONE : self::VIEW_NONE;
	}

	/**
	 * The Job for someone who sees it but does not manage it (a recipient,
	 * the assignee): of the counter-proposals only their own – of the others
	 * that they wait, without who or what –, and a log without the others'
	 * counter-proposals and their answers.
	 */
	public static function trimView(\stdClass $job, string $uid): \stdClass {
		$j = clone $job;
		$counters = $j->counters ?? null;
		if (is_array($counters)) {
			$out = [];
			foreach ($counters as $c) {
				if (!($c instanceof \stdClass)) {
					continue;
				}
				if (($c->by ?? null) === $uid) {
					$out[] = $c;
				} elseif (($c->answer ?? null) === null) {
					$out[] = (object)['other' => true];
				}
			}
			$j->counters = $out;
		}
		if (($j->counter ?? null) instanceof \stdClass && ($j->counter->by ?? null) !== $uid) {
			$j->counter = null;
		}
		$entries = $j->log ?? null;
		if (is_array($entries)) {
			$log = [];
			foreach ($entries as $e) {
				if (!($e instanceof \stdClass)) {
					continue;
				}
				$action = (string)($e->action ?? '');
				$whose = $action === 'counter' ? ($e->by ?? null) : ($e->from ?? null);
				if (in_array($action, ['counter', 'accept_counter', 'reject_counter'], true) && $whose !== $uid) {
					continue;
				}
				$lapsed = $e->lapsed ?? null;
				if (is_array($lapsed)) {
					$e = clone $e;
					$mine = array_values(array_filter($lapsed, static fn (mixed $u): bool => $u === $uid));
					if ($mine === []) {
						unset($e->lapsed);
					} else {
						$e->lapsed = $mine;
					}
				}
				$log[] = $e;
			}
			$j->log = $log;
		}
		return $j;
	}

	/**
	 * Everyone ever involved: the previous accounts plus sender, recipients,
	 * assignee.
	 *
	 * @param array<string,mixed> $job
	 * @param list<string> $before
	 * @return list<string>
	 */
	public static function accounts(array $job, array $before): array {
		$all = $before;
		$all[] = (string)($job['sender'] ?? '');
		foreach ((array)($job['recipients'] ?? []) as $u) {
			$all[] = (string)$u;
		}
		if (is_string($job['assignee'] ?? null)) {
			$all[] = $job['assignee'];
		}
		return array_values(array_unique(array_filter($all, static fn ($u) => $u !== '')));
	}

	/** Hours for a sentence: 12, 12.5. */
	public static function num(float $h): string {
		return rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.');
	}
}
