<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Die Rechte-Matrix aus API.md. Rein: kennt nur Rolle, Kennung, Art,
 * Schlüssel und die Konten eines Personendatensatzes.
 *
 * |                              | user   | lead   | subadmin, admin  |
 * |------------------------------|--------|--------|------------------|
 * | setting, region, project     | lesen  | lesen  | lesen, schreiben |
 * | person, eigene               | lesen  | lesen  | lesen, schreiben |
 * | person, fremde               | –      | lesen  | lesen, schreiben |
 * | customer                     | –      | lesen  | lesen, schreiben |
 * | Verlauf                      | eigene Person    | alle   |
 * | Kalendersicherungen          | eigene           | alle   |
 * | Lebenszeichen melden         | eigenes | eigenes | eigenes |
 * | Lebenszeichen lesen, Team    | –      | –      | ja               |
 *
 * Projekte voll sehen nur subadmin, admin und die Leitungen dieses Projekts
 * (`data.leads`), auch im Verlauf; alle anderen den Buchungskatalog. Siehe
 * ProjectAccess.
 */
final class AccessPolicy {
	/** Arten, die jedes Teammitglied lesen darf. */
	public const READABLE_BY_ALL = ['setting', 'region', 'project'];

	/**
	 * Eigene Person: Schlüssel gleich der Kennung oder `data.accounts`
	 * enthält sie.
	 *
	 * @param list<string> $accounts
	 */
	public static function isOwnPerson(string $uid, string $key, array $accounts): bool {
		return $key === $uid || in_array($uid, $accounts, true);
	}

	/** @param list<string> $accounts Konten des Datensatzes (nur bei `person`) */
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
	 * Der volle Projektdatensatz und sein Verlauf.
	 *
	 * @param list<string> $ownKeys Personenschlüssel des Aufrufers
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

	/** Lebenszeichen aller lesen, Team lesen. */
	public function canReadTeam(Membership $m): bool {
		return $m->manages();
	}

	public function requireWrite(Membership $m): void {
		if (!$this->canWrite($m)) {
			throw ApiException::forbidden('Schreiben dürfen nur Verwaltung und Admin des Teams.');
		}
	}

	public function requireTeamRead(Membership $m): void {
		if (!$this->canReadTeam($m)) {
			throw ApiException::forbidden('Das dürfen nur Verwaltung und Admin des Teams.');
		}
	}
}
