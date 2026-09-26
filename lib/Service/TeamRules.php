<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/** Prüfung eines Teams aus der Verwaltung. Rein. */
final class TeamRules {
	public const SLUG_PATTERN = '/^[a-z0-9-]{2,32}$/';

	/**
	 * @param array<string,mixed> $in
	 * @return array{name:string,slug:string,groups:array{user:string,lead:string,subadmin:string,admin:string},accounts:?string}
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
			throw ApiException::invalid('Die vier Rollen-Gruppen fehlen.');
		}
		$out = [];
		foreach (Role::ALL as $role) {
			$gid = $groups[$role] ?? null;
			if (!is_string($gid) || $gid === '' || strlen($gid) > 64) {
				throw ApiException::invalid("Die Gruppe für die Rolle „{$role}“ fehlt.");
			}
			$out[$role] = $gid;
		}
		if (count(array_unique($out)) !== 4) {
			throw ApiException::invalid('Jede Rolle braucht eine eigene Gruppe.');
		}
		/** @var array{user:string,lead:string,subadmin:string,admin:string} $out */
		return ['name' => $name, 'slug' => $slug, 'groups' => $out, 'accounts' => self::accounts($groups['accounts'] ?? null, $out)];
	}

	/**
	 * Die optionale Konten-Gruppe: fehlt, null oder leer heisst keine. Sie
	 * darf keine der vier Rollen-Gruppen desselben Teams sein.
	 *
	 * @param array<string,string> $roleGroups
	 */
	public static function accounts(mixed $gid, array $roleGroups): ?string {
		if ($gid === null || $gid === '') {
			return null;
		}
		if (!is_string($gid) || strlen($gid) > 64) {
			throw ApiException::invalid('Die Konten-Gruppe ist ungültig.');
		}
		if (in_array($gid, $roleGroups, true)) {
			throw ApiException::invalid('Die Konten-Gruppe darf keine Rollen-Gruppe des Teams sein.');
		}
		return $gid;
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
