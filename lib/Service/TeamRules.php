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
}
