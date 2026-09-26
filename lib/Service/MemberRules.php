<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/** Prüfung von PUT /team/members/{uid} (Fassung 2). Rein. */
final class MemberRules {
	/** Rollen und Austritte setzen nur Verwaltung und Admin (vor jeder weiteren Prüfung). */
	public static function requireManager(string $actorRole): void {
		if (!Role::manages($actorRole)) {
			throw ApiException::forbidden('Rollen und Austritte setzen nur Verwaltung und Admin des Teams.');
		}
	}

	/**
	 * Der Rumpf: `role` (user, lead, subadmin) und/oder `left` (bool).
	 *
	 * @param array<string,mixed> $in
	 * @return array{role?:string,left?:bool}
	 */
	public static function validate(array $in): array {
		$out = [];
		if (array_key_exists('role', $in)) {
			$role = $in['role'];
			if ($role === Role::ADMIN) {
				throw ApiException::invalid('„admin“ ist keine App-Rolle: Admin ist, wer die Teamgruppe in Nextcloud verwaltet.');
			}
			if (!is_string($role) || !in_array($role, Role::APP_ROLES, true)) {
				throw ApiException::invalid('„role“ muss user, lead oder subadmin sein.');
			}
			$out['role'] = $role;
		}
		if (array_key_exists('left', $in)) {
			if (!is_bool($in['left'])) {
				throw ApiException::invalid('„left“ muss true oder false sein.');
			}
			$out['left'] = $in['left'];
		}
		if ($out === []) {
			throw ApiException::badRequest('„role“ oder „left“ fehlt.');
		}
		return $out;
	}

	/**
	 * Wer was ändern darf: Verwaltung und Admin; `subadmin` vergeben und
	 * Einträge von Verwaltung und Admins ändern nur Admins; den eigenen
	 * Austritt vermerkt niemand selbst (er sperrte sich aus).
	 *
	 * @param string $targetRole die jetzige Rolle des Kontos im Team
	 * @param array{role?:string,left?:bool} $change
	 */
	public static function authorize(string $actorUid, string $actorRole, string $uid, string $targetRole, array $change): void {
		self::requireManager($actorRole);
		$admin = $actorRole === Role::ADMIN;
		if (($change['role'] ?? null) === Role::SUBADMIN && !$admin) {
			throw ApiException::forbidden('Die Rolle Verwaltung vergeben nur Admins des Teams.');
		}
		if (Role::manages($targetRole) && !$admin) {
			throw ApiException::forbidden('Verwaltung und Admins des Teams ändern nur Admins.');
		}
		if ($uid === $actorUid && ($change['left'] ?? false)) {
			throw ApiException::forbidden('Den eigenen Austritt vermerkt ein anderer Admin des Teams.');
		}
	}
}
