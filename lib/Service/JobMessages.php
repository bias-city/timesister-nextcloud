<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The sentences of the Job notifications, per subject. Pure, so the texts
 * are testable without Nextcloud; the Notifier translates them.
 */
final class JobMessages {
	public const OFFERED = 'job_offered';
	public const COUNTER = 'job_counter';
	public const COUNTER_ACCEPTED = 'job_counter_accepted';
	public const COUNTER_REJECTED = 'job_counter_rejected';
	public const COUNTER_LAPSED = 'job_counter_lapsed';
	public const CHANGED = 'job_changed';
	public const RETURNED = 'job_returned';
	public const DELETED = 'job_deleted';

	/**
	 * The subject line; `null` for an unknown subject.
	 *
	 * @param array<array-key,mixed> $p subject parameters: title, user
	 */
	public static function subject(string $subject, array $p): ?Message {
		$v = [
			'job' => JobWord::JOB,
			'title' => is_string($p['title'] ?? null) ? $p['title'] : '',
			'user' => is_string($p['user'] ?? null) ? $p['user'] : '',
		];
		return match ($subject) {
			self::OFFERED => Message::of('{user} offers you a {job}: {title}', $v),
			self::COUNTER => Message::of('{user} sent a counter-proposal for the {job} “{title}”', $v),
			self::COUNTER_ACCEPTED => Message::of('{user} accepted your counter-proposal for “{title}”', $v),
			self::COUNTER_REJECTED => Message::of('{user} rejected your counter-proposal for “{title}”', $v),
			self::COUNTER_LAPSED => Message::of('The {job} “{title}” has gone to someone else – your counter-proposal has lapsed', $v),
			self::CHANGED => Message::of('{user} changed the {job} “{title}”', $v),
			self::RETURNED => Message::of('{user} returned the {job} “{title}”', $v),
			self::DELETED => Message::of('{user} deleted the {job} “{title}”', $v),
			default => null,
		};
	}

	/** The message below the subject. */
	public static function message(): Message {
		return Message::of('Open TimeSister to see the {job}.', ['job' => JobWord::JOB]);
	}
}
