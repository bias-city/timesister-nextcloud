<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The Job words: “Job, Workload, Inbox, In Progress, Done, Outbox” in every
 * language, exactly as in the Mac app (`marke::JOBWORTE`). Like the role
 * words ({@see RoleName}) never through the l10n; sentences take them as
 * {placeholders}, and tests make sure no translation changes them.
 */
final class JobWord {
	public const JOB = 'Job';
	public const WORKLOAD = 'Workload';
	public const INBOX = 'Inbox';
	public const IN_PROGRESS = 'In Progress';
	public const DONE = 'Done';
	public const OUTBOX = 'Outbox';

	/** Key → word, in the order of the Mac app. */
	public const ALL = [
		'job' => self::JOB,
		'workload' => self::WORKLOAD,
		'inbox' => self::INBOX,
		'in_progress' => self::IN_PROGRESS,
		'done' => self::DONE,
		'outbox' => self::OUTBOX,
	];
}
