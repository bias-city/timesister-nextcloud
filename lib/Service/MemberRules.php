<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/** Checking PUT /team/members/{uid}. Pure. */
final class MemberRules {
	/** Only Team Admins set roles, leaving and “allow overriding” (before any further check). */
	public static function requireTeamAdmin(string $actorRole): void {
		if (!Role::manages($actorRole)) {
			throw ApiException::forbidden('Only Team Admins set roles and leaving.');
		}
	}

	/**
	 * The body: `role` (admin, lead, user), `left` (bool) and/or
	 * `may_override` (bool).
	 *
	 * @param array<string,mixed> $in
	 * @return array{role?:string,left?:bool,may_override?:bool}
	 */
	public static function validate(array $in): array {
		$out = [];
		if (array_key_exists('role', $in)) {
			$role = $in['role'];
			if (!is_string($role) || !in_array($role, Role::APP_ROLES, true)) {
				throw ApiException::invalid('“role” must be admin, lead or user.');
			}
			$out['role'] = $role;
		}
		foreach (['left', 'may_override'] as $field) {
			if (array_key_exists($field, $in)) {
				if (!is_bool($in[$field])) {
					throw ApiException::notBool($field);
				}
				$out[$field] = $in[$field];
			}
		}
		if ($out === []) {
			throw ApiException::badRequest('“role”, “left” or “may_override” is missing.');
		}
		return $out;
	}

	/**
	 * Who may change what: only Team Admins. Nobody records their own
	 * leaving (they would lock themselves out), and the team always keeps
	 * at least one Team Admin.
	 *
	 * @param string $targetRole the account's current role in the team
	 * @param array{role?:string,left?:bool,may_override?:bool} $change
	 * @param int $adminCount Team Admins of the team who have not left
	 */
	public static function authorize(string $actorUid, string $actorRole, string $uid, string $targetRole, array $change, int $adminCount): void {
		self::requireTeamAdmin($actorRole);
		if ($uid === $actorUid && ($change['left'] ?? false)) {
			throw ApiException::forbidden('Another Team Admin of the team records your own leaving.');
		}
		$losesAdmin = $targetRole === Role::ADMIN
			&& ((isset($change['role']) && $change['role'] !== Role::ADMIN) || ($change['left'] ?? false));
		if ($losesAdmin && $adminCount <= 1) {
			throw ApiException::conflict('The team always keeps at least one Team Admin.');
		}
	}
}
