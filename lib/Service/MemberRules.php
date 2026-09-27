<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/** Checking PUT /team/members/{uid} (API version 2). Pure. */
final class MemberRules {
	/** Only manager and admin set roles and leaving (before any further check). */
	public static function requireManager(string $actorRole): void {
		if (!Role::manages($actorRole)) {
			throw ApiException::forbidden('Only the team’s managers and admins set roles and leaving.');
		}
	}

	/**
	 * The body: `role` (user, lead, subadmin) and/or `left` (bool).
	 *
	 * @param array<string,mixed> $in
	 * @return array{role?:string,left?:bool}
	 */
	public static function validate(array $in): array {
		$out = [];
		if (array_key_exists('role', $in)) {
			$role = $in['role'];
			if ($role === Role::ADMIN) {
				throw ApiException::invalid('“admin” is not an app role: admins are the group admins of the team group in Nextcloud.');
			}
			if (!is_string($role) || !in_array($role, Role::APP_ROLES, true)) {
				throw ApiException::invalid('“role” must be user, lead or subadmin.');
			}
			$out['role'] = $role;
		}
		if (array_key_exists('left', $in)) {
			if (!is_bool($in['left'])) {
				throw ApiException::notBool('left');
			}
			$out['left'] = $in['left'];
		}
		if ($out === []) {
			throw ApiException::badRequest('“role” or “left” is missing.');
		}
		return $out;
	}

	/**
	 * Who may change what: manager and admin; only admins assign
	 * `subadmin` and change entries of managers and admins; nobody
	 * records their own leaving (they would lock themselves out).
	 *
	 * @param string $targetRole the account's current role in the team
	 * @param array{role?:string,left?:bool} $change
	 */
	public static function authorize(string $actorUid, string $actorRole, string $uid, string $targetRole, array $change): void {
		self::requireManager($actorRole);
		$admin = $actorRole === Role::ADMIN;
		if (($change['role'] ?? null) === Role::SUBADMIN && !$admin) {
			throw ApiException::forbidden('Only the team’s admins assign the role Manager.');
		}
		if (Role::manages($targetRole) && !$admin) {
			throw ApiException::forbidden('Only admins change the team’s managers and admins.');
		}
		if ($uid === $actorUid && ($change['left'] ?? false)) {
			throw ApiException::forbidden('Another admin of the team records your own leaving.');
		}
	}
}
