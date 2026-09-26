<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/** Prüfung eines Teams aus der Verwaltung. Rein. */
final class TeamRules {
	public const SLUG_PATTERN = '/^[a-z0-9-]{2,32}$/';

	/**
	 * Fassung 2: `groups` = `{ team }`, eine bestehende Nextcloud-Gruppe.
	 * Andere Schlüssel (Rollen- oder Konten-Gruppen aus Fassung 1) → 422.
	 *
	 * @param array<string,mixed> $in
	 * @return array{name:string,slug:string,groups:array{team:string}}
	 */
	public static function validate(array $in): array {
		$name = $in['name'] ?? null;
		if (!is_string($name) || ($name = trim($name)) === '' || mb_strlen($name) > 100) {
			throw ApiException::invalid('Der Name des Teams fehlt oder ist länger als 100 Zeichen.');
		}
		$slug = $in['slug'] ?? null;
		if (!is_string($slug) || !preg_match(self::SLUG_PATTERN, $slug)) {
			throw ApiException::invalid('Der Kurzname muss aus 2–32 Zeichen a–z, 0–9 und - bestehen.');
		}
		$groups = $in['groups'] ?? null;
		if (!is_array($groups)) {
			throw ApiException::invalid('Die Teamgruppe fehlt.');
		}
		foreach (array_keys($groups) as $key) {
			if ($key !== Role::TEAM_GROUP) {
				throw ApiException::invalid("Unbekannte Gruppe „{$key}“: Ein Team hat nur die Teamgruppe „team“.");
			}
		}
		$gid = $groups[Role::TEAM_GROUP] ?? null;
		if (!is_string($gid) || $gid === '' || strlen($gid) > 64) {
			throw ApiException::invalid('Die Teamgruppe fehlt.');
		}
		return ['name' => $name, 'slug' => $slug, 'groups' => [Role::TEAM_GROUP => $gid]];
	}

	/**
	 * Das Sicherungs-Konto aus dem Rumpf: fehlt der Schlüssel, bleibt die
	 * Wahl ([false, null]); null oder leer heisst automatisch ([true, null]).
	 *
	 * @param array<string,mixed> $in
	 * @return array{0:bool,1:?string}
	 */
	public static function backupOwner(array $in): array {
		if (!array_key_exists('backup_owner', $in)) {
			return [false, null];
		}
		$uid = $in['backup_owner'];
		if ($uid === null || $uid === '') {
			return [true, null];
		}
		if (!is_string($uid) || strlen($uid) > 64) {
			throw ApiException::invalid('Das Sicherungs-Konto ist ungültig.');
		}
		return [true, $uid];
	}

	/** Team-Einstellungen (Fassung 1.2) mit ihren Standardwerten. */
	public const SETTINGS = ['leads_see_calendars' => true, 'backup_required' => false];

	/**
	 * Team-Einstellungen aus dem Rumpf. Fehlt `settings` oder ein Schlüssel,
	 * bleibt der Wert. Unbekannte Schlüssel oder kein Wahrheitswert: 422.
	 *
	 * @param array<string,mixed> $in
	 * @return array<string,bool>
	 */
	public static function settings(array $in): array {
		$s = $in['settings'] ?? null;
		if ($s === null) {
			return [];
		}
		if (!is_array($s)) {
			throw ApiException::invalid('„settings“ muss ein Objekt sein.');
		}
		$out = [];
		foreach ($s as $key => $value) {
			if (!is_string($key) || !array_key_exists($key, self::SETTINGS)) {
				throw ApiException::invalid("Unbekannte Team-Einstellung „{$key}“.");
			}
			if (!is_bool($value)) {
				throw ApiException::invalid("„{$key}“ muss true oder false sein.");
			}
			$out[$key] = $value;
		}
		return $out;
	}
}
