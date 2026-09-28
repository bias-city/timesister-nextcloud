<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The transitions of a Job: offer, change, accept, counter-propose, answer
 * the counter-proposal, decline, return, declare Done, paid, progress.
 * Pure: takes the Job as an array and returns the new one; a transition
 * that is already done returns the Job unchanged (a resent request is not
 * an error). Who may manage a Job (Lead, Team Admin) the caller checks;
 * whether the caller is a recipient or the assignee is checked here.
 *
 * Offered to several (a market): whoever accepts first gets the Job. A
 * counter-proposal is per recipient (`counters`) and goes to the sender
 * while the offer stays open for the others; once someone has the Job,
 * the counter-proposals still waiting lapse.
 */
final class JobFlow {
	/** The log keeps the first entry and the newest ones. */
	public const LOG_MAX = 200;

	/**
	 * A new Job, offered to its recipients.
	 *
	 * @param array{project:string,code:string,budget:?string,work_package:?string,title:?string,description:?string,hours:float,start:string,end:string,recipients:list<string>} $f
	 * @param array{codes:list<string>,ap_hours:?float,ap_name:?string} $target
	 * @return array<string,mixed>
	 */
	public static function create(string $key, array $f, array $target, string $uid, string $now): array {
		$job = [
			'schema' => 1,
			'id' => $key,
			'project' => $f['project'],
			'code' => $f['code'],
			'codes' => $target['codes'],
			'budget' => $f['budget'],
			'work_package' => $f['work_package'],
			'title' => $f['title'] ?? $target['ap_name'],
			'description' => $f['description'],
			'hours' => $f['hours'],
			'start' => $f['start'],
			'end' => $f['end'],
			'sender' => $uid,
			'recipients' => $f['recipients'],
			'declined_by' => [],
			'assignee' => null,
			'state' => JobRules::OFFERED,
			'counters' => [],
			'change' => null,
			'progress' => ['hours' => 0.0, 'invoiced' => false, 'at' => null],
			'done' => null,
			'paid' => null,
			'created_at' => $now,
			'log' => [],
		];
		return self::log($job, $now, $uid, 'offer', ['to' => $f['recipients']]);
	}

	/**
	 * A Lead or Team Admin changes the Job. Declined, rejected and returned
	 * Jobs go out again when `recipients` is sent; an open offer can get
	 * other recipients; an accepted Job keeps its person.
	 *
	 * @param array<string,mixed> $job
	 * @param array<string,mixed> $c from JobRules::change()
	 * @param ?list<string> $codes the codes for a new `code`
	 * @return array<string,mixed>
	 */
	public static function change(array $job, array $c, ?array $codes, string $uid, string $now): array {
		$state = (string)$job['state'];
		if (JobRules::pendingCounters($job) !== []) {
			throw ApiException::conflict('Answer the counter-proposal first.');
		}
		if ($state === JobRules::DONE) {
			throw ApiException::conflict(Message::of('A {job} that is {done} no longer changes.', ['job' => JobWord::JOB, 'done' => JobWord::DONE]));
		}
		$reoffer = array_key_exists('recipients', $c) && in_array($state, [JobRules::DECLINED, JobRules::REJECTED, JobRules::RETURNED], true);
		if ($state === JobRules::IN_PROGRESS && array_key_exists('recipients', $c) && $c['recipients'] !== $job['recipients']) {
			throw ApiException::conflict(Message::of('An accepted {job} keeps its person; it can go to someone else once it is returned.', ['job' => JobWord::JOB]));
		}
		$fields = [];
		foreach (['code', 'title', 'description', 'hours', 'start', 'end', 'recipients'] as $f) {
			if (array_key_exists($f, $c) && !self::same($c[$f], $job[$f] ?? null)) {
				$fields[$f] = ['from' => $job[$f] ?? null, 'to' => $c[$f]];
				$job[$f] = $c[$f];
			}
		}
		JobRules::period((string)$job['start'], (string)$job['end']);
		if (isset($fields['code']) && $codes !== null) {
			$job['codes'] = $codes;
		}
		if ($reoffer) {
			$job['state'] = JobRules::OFFERED;
			$job['declined_by'] = [];
			$job['assignee'] = null;
			$job = self::withCounters($job, []);
			$job['done'] = null;
			$job['progress'] = ['hours' => 0.0, 'invoiced' => false, 'at' => null];
			$job['change'] = $fields === [] ? null : ['by' => $uid, 'at' => $now, 'fields' => $fields];
			return self::log($job, $now, $uid, 'reoffer', ['to' => $job['recipients']] + ($fields === [] ? [] : ['fields' => $fields]));
		}
		if ($fields === []) {
			return $job;
		}
		if (isset($fields['recipients'])) {
			// Whoever declined (or had a counter-proposal rejected) and stays a recipient stays out.
			$old = array_map('strval', (array)$fields['recipients']['from']);
			$stays = static fn (string $u): bool => in_array($u, $old, true) && in_array($u, (array)$job['recipients'], true);
			$job['declined_by'] = array_values(array_filter(array_map('strval', (array)$job['declined_by']), $stays));
			$job = self::withCounters($job, array_values(array_filter(JobRules::counters($job), static fn (array $c) => $stays((string)($c['by'] ?? '')))));
			if ($state === JobRules::OFFERED) {
				$job['state'] = JobRules::openState($job);
			}
		}
		$job['change'] = ['by' => $uid, 'at' => $now, 'fields' => $fields];
		return self::log($job, $now, $uid, 'change', ['fields' => $fields]);
	}

	/**
	 * Accept (commit): the first recipient wins – the caller runs this under
	 * the team's lock.
	 *
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	public static function accept(array $job, string $uid, string $now): array {
		if ($job['state'] === JobRules::IN_PROGRESS && $job['assignee'] === $uid) {
			return $job;
		}
		if ($job['state'] === JobRules::IN_PROGRESS) {
			throw ApiException::conflict(Message::of('Someone else has already accepted this {job}.', ['job' => JobWord::JOB]));
		}
		self::requireOpen($job, $uid);
		$job['state'] = JobRules::IN_PROGRESS;
		$job['assignee'] = $uid;
		[$job, $lapsed] = self::lapse($job, $now);
		return self::log($job, $now, $uid, 'accept', $lapsed === [] ? [] : ['lapsed' => $lapsed]);
	}

	/**
	 * A counter-proposal: the Job leaves the caller's Inbox and waits for
	 * the sender; offered to several, it stays open for the others.
	 *
	 * @param array<string,mixed> $job
	 * @param array{hours?:float,start?:string,end?:string,description?:?string,note:?string} $c
	 * @return array<string,mixed>
	 */
	public static function counter(array $job, array $c, string $uid, string $now): array {
		$mine = JobRules::pendingCounter($job, $uid);
		if ($mine === null) {
			self::requireOpen($job, $uid);
		}
		$proposal = ['by' => $uid] + $c;
		if ($mine !== null && self::withoutTime($mine) === self::withoutTime($proposal)) {
			return $job;
		}
		JobRules::period((string)($c['start'] ?? $job['start']), (string)($c['end'] ?? $job['end']));
		$list = array_values(array_filter(JobRules::counters($job), static fn (array $x) => ($x['by'] ?? null) !== $uid || isset($x['answer'])));
		$list[] = $proposal + ['at' => $now];
		$job = self::withCounters($job, $list);
		$job['state'] = JobRules::openState($job);
		return self::log($job, $now, $uid, 'counter', ['proposal' => array_diff_key($c, ['note' => true])]);
	}

	/**
	 * The sender takes a counter-proposal: its values apply, the person
	 * who made it has the Job In Progress; the others lose it. `$by` may
	 * be left out while only one waits.
	 *
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	public static function acceptCounter(array $job, ?string $by, string $uid, string $now): array {
		$p = self::answerable($job, $by, 'accepted');
		if ($p === null) {
			return $job;
		}
		foreach (['hours', 'start', 'end', 'description'] as $f) {
			if (array_key_exists($f, $p)) {
				$job[$f] = $p[$f];
			}
		}
		$who = (string)$p['by'];
		$job['state'] = JobRules::IN_PROGRESS;
		$job['assignee'] = $who;
		$job = self::answer($job, $who, 'accepted', $now);
		[$job, $lapsed] = self::lapse($job, $now);
		return self::log($job, $now, $uid, 'accept_counter', ['from' => $who] + ($lapsed === [] ? [] : ['lapsed' => $lapsed]));
	}

	/**
	 * The sender rejects a counter-proposal: that person is out; the Job
	 * stays offered to the others, else it is rejected.
	 *
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	public static function rejectCounter(array $job, ?string $by, string $uid, string $now): array {
		$p = self::answerable($job, $by, 'rejected');
		if ($p === null) {
			return $job;
		}
		$who = (string)$p['by'];
		$job = self::answer($job, $who, 'rejected', $now);
		$job['state'] = JobRules::openState($job);
		return self::log($job, $now, $uid, 'reject_counter', ['from' => $who]);
	}

	/**
	 * The counter-proposal to answer; `null` if it is answered that way
	 * already (a resent request).
	 *
	 * @param array<string,mixed> $job
	 * @return ?array<string,mixed>
	 */
	private static function answerable(array $job, ?string $by, string $answer): ?array {
		$pending = JobRules::pendingCounters($job);
		if ($by === null) {
			if (count($pending) > 1) {
				throw ApiException::invalid('Several counter-proposals are waiting – say whose (“by”).');
			}
			$by = (string)($pending[0]['by'] ?? '');
		}
		foreach (JobRules::counters($job) as $c) {
			if (($c['by'] ?? null) !== $by || !isset($c['answer'])) {
				continue;
			}
			if ($c['answer'] === $answer && JobRules::pendingCounter($job, $by) === null) {
				return null;
			}
		}
		$p = JobRules::pendingCounter($job, $by);
		if ($p !== null && !in_array($job['state'], [JobRules::OFFERED, JobRules::COUNTER], true)) {
			$p = null;
		}
		if ($p === null) {
			$lapsed = array_filter(JobRules::counters($job), static fn (array $c) => ($c['by'] ?? null) === $by && ($c['answer'] ?? null) === 'lapsed');
			if ($lapsed !== []) {
				throw ApiException::conflict(Message::of('This counter-proposal has lapsed: the {job} has gone to someone else.', ['job' => JobWord::JOB]));
			}
			throw ApiException::conflict('There is no counter-proposal to answer.');
		}
		return $p;
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private static function answer(array $job, string $by, string $answer, string $now): array {
		$list = JobRules::counters($job);
		foreach ($list as $i => $c) {
			if (($c['by'] ?? null) === $by && !isset($c['answer'])) {
				$list[$i]['answer'] = $answer;
				$list[$i]['answered_at'] = $now;
			}
		}
		return self::withCounters($job, $list);
	}

	/**
	 * Someone has the Job: the counter-proposals still waiting lapse.
	 *
	 * @param array<string,mixed> $job
	 * @return array{0:array<string,mixed>,1:list<string>} the Job and whose lapsed
	 */
	private static function lapse(array $job, string $now): array {
		$list = JobRules::counters($job);
		$lapsed = [];
		foreach ($list as $i => $c) {
			if (!isset($c['answer'])) {
				$list[$i]['answer'] = 'lapsed';
				$list[$i]['answered_at'] = $now;
				$lapsed[] = (string)($c['by'] ?? '');
			}
		}
		return [$lapsed === [] ? $job : self::withCounters($job, $list), $lapsed];
	}

	/**
	 * Sets the counter-proposals; a single `counter` from 0.6.0 goes.
	 *
	 * @param array<string,mixed> $job
	 * @param array<array-key,array<string,mixed>> $list
	 * @return array<string,mixed>
	 */
	private static function withCounters(array $job, array $list): array {
		unset($job['counter']);
		$job['counters'] = array_values($list);
		return $job;
	}

	/**
	 * Decline: gone for the caller; once all have declined, the Job is
	 * declined.
	 *
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	public static function decline(array $job, string $uid, string $now): array {
		if (in_array($uid, (array)$job['declined_by'], true)) {
			return $job;
		}
		self::requireOpen($job, $uid);
		$job['declined_by'][] = $uid;
		$job['state'] = JobRules::openState($job);
		return self::log($job, $now, $uid, 'decline');
	}

	/**
	 * Return: at any time, by the person who has it.
	 *
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	public static function giveBack(array $job, ?string $note, string $uid, string $now): array {
		if ($job['state'] === JobRules::RETURNED && $job['assignee'] === $uid) {
			return $job;
		}
		self::requireAssignee($job, $uid);
		$job['state'] = JobRules::RETURNED;
		return self::log($job, $now, $uid, 'return', $note === null ? [] : ['note' => $note]);
	}

	/**
	 * Declare Done: at any time, also with hours left.
	 *
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	public static function done(array $job, string $uid, string $now): array {
		if ($job['state'] === JobRules::DONE && $job['assignee'] === $uid) {
			return $job;
		}
		self::requireAssignee($job, $uid);
		$job['state'] = JobRules::DONE;
		$job['done'] = ['by' => $uid, 'at' => $now, 'reason' => 'declared'];
		return self::log($job, $now, $uid, 'done');
	}

	/**
	 * Paid – set or taken back by a Team Admin, only when Done.
	 *
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	public static function paid(array $job, bool $paid, string $uid, string $now): array {
		if ($job['state'] !== JobRules::DONE) {
			throw ApiException::conflict(Message::of('Only a {job} that is {done} can be paid.', ['job' => JobWord::JOB, 'done' => JobWord::DONE]));
		}
		if ($paid === ($job['paid'] !== null)) {
			return $job;
		}
		$job['paid'] = $paid ? ['by' => $uid, 'at' => $now] : null;
		return self::log($job, $now, $uid, $paid ? 'paid' : 'unpaid');
	}

	/**
	 * The booked hours from the assignee's client, capped at the volume.
	 * Reaching the volume makes the Job Done by itself. `null`: this Job is
	 * not the caller's to report.
	 *
	 * @param array<string,mixed> $job
	 * @return ?array<string,mixed>
	 */
	public static function progress(array $job, float $hours, bool $invoiced, string $uid, string $now): ?array {
		if (($job['assignee'] ?? null) !== $uid || !in_array($job['state'], [JobRules::IN_PROGRESS, JobRules::DONE], true)) {
			return null;
		}
		$hours = min($hours, (float)$job['hours']);
		$old = (array)($job['progress'] ?? []);
		if (abs((float)($old['hours'] ?? 0) - $hours) < JobRules::EPS && (bool)($old['invoiced'] ?? false) === $invoiced) {
			return $job;
		}
		$job['progress'] = ['hours' => $hours, 'invoiced' => $invoiced, 'at' => $now];
		if ($job['state'] === JobRules::IN_PROGRESS && $hours >= (float)$job['hours'] - JobRules::EPS) {
			$job['state'] = JobRules::DONE;
			$job['done'] = ['by' => $uid, 'at' => $now, 'reason' => 'fulfilled'];
			return self::log($job, $now, $uid, 'done', ['reason' => 'fulfilled']);
		}
		return $job;
	}

	/** Equal as data: numbers by value (PHP may read 40.0 back as 40). */
	private static function same(mixed $a, mixed $b): bool {
		if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
			return abs((float)$a - (float)$b) < JobRules::EPS;
		}
		return $a === $b;
	}

	/** @param array<string,mixed> $job */
	private static function requireOpen(array $job, string $uid): void {
		if ($job['state'] !== JobRules::OFFERED || !in_array($uid, JobRules::openRecipients($job), true)) {
			throw ApiException::conflict(Message::of('This {job} is no longer offered to you.', ['job' => JobWord::JOB]));
		}
	}

	/** @param array<string,mixed> $job */
	private static function requireAssignee(array $job, string $uid): void {
		if ($job['state'] !== JobRules::IN_PROGRESS || $job['assignee'] !== $uid) {
			throw ApiException::conflict(Message::of('This {job} is not {in_progress} with you.', ['job' => JobWord::JOB, 'in_progress' => JobWord::IN_PROGRESS]));
		}
	}

	/**
	 * @param array<string,mixed> $c
	 * @return array<string,mixed>
	 */
	private static function withoutTime(array $c): array {
		unset($c['at'], $c['answer'], $c['answered_at']);
		ksort($c);
		return $c;
	}

	/**
	 * @param array<string,mixed> $job
	 * @param array<string,mixed> $details
	 * @return array<string,mixed>
	 */
	private static function log(array $job, string $now, string $uid, string $action, array $details = []): array {
		$log = array_values((array)($job['log'] ?? []));
		$log[] = ['at' => $now, 'by' => $uid, 'action' => $action] + $details;
		if (count($log) > self::LOG_MAX) {
			$log = array_merge([$log[0]], array_slice($log, -(self::LOG_MAX - 1)));
		}
		$job['log'] = $log;
		return $job;
	}
}
