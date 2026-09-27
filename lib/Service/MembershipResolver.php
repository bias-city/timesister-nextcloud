<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Determines an account's team and role (API version 2). Pure.
 *
 * - Member: in the team group or a group admin of it, and not left
 *   (`left_at` in ts_members).
 * - No team: `no_team`; two teams: `ambiguous_team`.
 * - Role: `admin` for whoever manages the team group; otherwise the app
 *   role `lead` (an old `subadmin` counts as `lead`); otherwise `user`.
 * - Only rows with `team` from ts_role_groups count, old ones (API
 *   version 1) do not.
 */
final class MembershipResolver {
	/**
	 * @param list<string> $userGroupIds the account's groups
	 * @param list<string> $subAdminGroupIds groups the account manages in Nextcloud
	 * @param list<array{tenant_id:int,role:string,gid:string}> $rows all rows from ts_role_groups
	 * @param array<int,array{role:?string,left_at:?int,may_override?:bool}> $entries team → the account's entry from ts_members
	 * @return array{tenant_id:int,role:string}
	 * @throws ApiException
	 */
	public static function resolve(array $userGroupIds, array $subAdminGroupIds, array $rows, array $entries): array {
		$in = array_flip($userGroupIds);
		$manages = array_flip($subAdminGroupIds);
		$found = [];
		foreach (self::teamGroups($rows) as $tenant => $gid) {
			$admin = isset($manages[$gid]);
			if (!$admin && !isset($in[$gid])) {
				continue;
			}
			// Left: no longer belongs to this team, even if listed in the group.
			if (($entries[$tenant]['left_at'] ?? null) !== null) {
				continue;
			}
			$found[$tenant] = $admin;
		}
		if ($found === []) {
			throw ApiException::noTeam();
		}
		if (count($found) > 1) {
			throw ApiException::ambiguousTeam();
		}
		$tenant = array_key_first($found);
		return ['tenant_id' => $tenant, 'role' => self::roleOf($found[$tenant], $entries[$tenant]['role'] ?? null)];
	}

	/**
	 * @param list<array{tenant_id:int,role:string,gid:string}> $rows
	 * @return array<int,string> team → team group
	 */
	public static function teamGroups(array $rows): array {
		$out = [];
		foreach ($rows as $rg) {
			if ($rg['role'] === Role::TEAM_GROUP) {
				$out[$rg['tenant_id']] = $rg['gid'];
			}
		}
		return $out;
	}

	/** Role from group admin and app role; unknown app roles count as `user`. */
	public static function roleOf(bool $admin, ?string $appRole): string {
		if ($admin) {
			return Role::ADMIN;
		}
		return $appRole === Role::LEAD || $appRole === Role::LEGACY_SUBADMIN ? Role::LEAD : Role::USER;
	}

	/**
	 * All members of a team, including those who left, by identifier.
	 *
	 * @param list<string> $groupUsers accounts of the team group
	 * @param list<string> $subAdmins group admins of the team group
	 * @param array<string,array{role:?string,left_at:?int,may_override?:bool}> $entries uid → entry from ts_members
	 * @return array<string,array{role:string,left_at:?int,may_override:bool}>
	 */
	public static function members(array $groupUsers, array $subAdmins, array $entries): array {
		$admins = array_flip($subAdmins);
		$out = [];
		foreach (array_merge($groupUsers, $subAdmins) as $uid) {
			$e = $entries[$uid] ?? null;
			$out[$uid] = [
				'role' => self::roleOf(isset($admins[$uid]), $e['role'] ?? null),
				'left_at' => $e['left_at'] ?? null,
				'may_override' => $e['may_override'] ?? false,
			];
		}
		ksort($out, SORT_STRING);
		return $out;
	}

	/**
	 * @param array<string,array{role:string,left_at:?int,may_override:bool}> $members
	 * @return array<string,string> uid → role, without those who left
	 */
	public static function activeRoles(array $members): array {
		$out = [];
		foreach ($members as $uid => $m) {
			if ($m['left_at'] === null) {
				$out[$uid] = $m['role'];
			}
		}
		return $out;
	}
}
