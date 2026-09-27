<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The rights matrix from API.md. Pure: knows only role, identifier, kind,
 * key and the accounts of a person record.
 *
 * |                              | user   | lead   | subadmin, admin  |
 * |------------------------------|--------|--------|------------------|
 * | setting, region, project     | read   | read   | read, write      |
 * | person, own                  | read   | read   | read, write      |
 * | person, others'              | –      | read   | read, write      |
 * | customer                     | –      | read   | read, write      |
 * | history                      | own person       | all    |
 * | calendar backups             | own              | all    |
 * | report status                | own     | own     | own     |
 * | read status, team            | –      | –      | yes              |
 *
 * Only subadmin, admin and this project's leads (`data.leads`) see full
 * projects, including in the history; everyone else sees the booking
 * catalog. See ProjectAccess.
 */
final class AccessPolicy {
	/** Kinds every team member may read. */
	public const READABLE_BY_ALL = ['setting', 'region', 'project'];

	/**
	 * Own person: key equals the identifier, or `data.accounts` contains
	 * it.
	 *
	 * @param list<string> $accounts
	 */
	public static function isOwnPerson(string $uid, string $key, array $accounts): bool {
		return $key === $uid || in_array($uid, $accounts, true);
	}

	/** @param list<string> $accounts the record's accounts (only for `person`) */
	public function canRead(Membership $m, string $kind, string $key, array $accounts = []): bool {
		if (Role::readsAll($m->role)) {
			return true;
		}
		if (in_array($kind, self::READABLE_BY_ALL, true)) {
			return true;
		}
		if ($kind === 'person') {
			return self::isOwnPerson($m->uid, $key, $accounts);
		}
		return false;
	}

	/**
	 * The full project record and its history.
	 *
	 * @param list<string> $ownKeys the caller's person keys
	 */
	public function canSeeFullProject(Membership $m, mixed $projectData, array $ownKeys): bool {
		return $m->manages() || ProjectAccess::isLead($projectData, $ownKeys);
	}

	public function canWrite(Membership $m): bool {
		return $m->manages();
	}

	/** @param list<string> $accounts */
	public function canReadHistory(Membership $m, string $kind, string $key, array $accounts = []): bool {
		if ($m->manages()) {
			return true;
		}
		return $kind === 'person' && self::isOwnPerson($m->uid, $key, $accounts);
	}

	public function canSeeBackupsOf(Membership $m, string $uid): bool {
		return $uid === $m->uid || $m->manages();
	}

	/** Read everyone's status reports, read the team. */
	public function canReadTeam(Membership $m): bool {
		return $m->manages();
	}

	public function requireWrite(Membership $m): void {
		if (!$this->canWrite($m)) {
			throw ApiException::forbidden('Only the team’s managers and admins may write.');
		}
	}

	public function requireTeamRead(Membership $m): void {
		if (!$this->canReadTeam($m)) {
			throw ApiException::forbidden('Only the team’s managers and admins may do that.');
		}
	}
}
