<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/** The notification about a correction (0.27.0): the lead's correction was restored in the person's event. */
final class CorrectionMessages {
	public const REAPPLIED = 'correction_reapplied';

	/**
	 * The subject line; `null` for an unknown subject.
	 *
	 * @param array<array-key,mixed> $p subject parameters: title, user
	 */
	public static function subject(string $subject, array $p): ?Message {
		$v = [
			'title' => is_string($p['title'] ?? null) ? $p['title'] : '',
			'user' => is_string($p['user'] ?? null) ? $p['user'] : '',
		];
		return match ($subject) {
			self::REAPPLIED => Message::of('The correction by {user} of “{title}” was restored – title, code, billable and level are changed by the lead of the project.', $v),
			default => null,
		};
	}

	/** The message below the subject. */
	public static function message(): Message {
		return Message::of('Open TimeSister to see the booking.');
	}
}
