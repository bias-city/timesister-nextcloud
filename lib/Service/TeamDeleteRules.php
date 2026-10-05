<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/** Pure rules for deleting a team (0.10.1): the typed confirmation and what counts as content. */
final class TeamDeleteRules {
	/**
	 * The team name must be typed; trimmed, case does not matter.
	 *
	 * @throws ApiException 422 `confirm`
	 */
	public static function checkConfirm(string $teamName, mixed $given): void {
		if (!is_string($given) || !self::same($teamName, $given)) {
			throw ApiException::confirm('Type the team name to confirm the deletion.');
		}
	}

	public static function same(string $teamName, string $given): bool {
		return mb_strtolower(trim($given)) === mb_strtolower(trim($teamName));
	}

	/** Records (including tombstones) or backups: then a ZIP is stored first. */
	public static function hasContent(int $records, int $backups): bool {
		return $records > 0 || $backups > 0;
	}

	/**
	 * The answer of DELETE /admin/teams/{id}.
	 *
	 * @param array{protected:string,visible:?string}|null $backup
	 * @param array<string,int> $counts
	 * @return array<string,mixed>
	 */
	public static function answer(int $id, ?array $backup, array $counts): array {
		return ['id' => $id, 'deleted' => true, 'backup' => $backup, 'counts' => $counts];
	}
}
