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
 * - Role: `admin` for whoever manages the team group; otherwise `lead`
 *   for whoever leads an active project ({@see LeadResolver}, 0.11.0);
 *   otherwise `user`. A stored app role no longer counts.
 * - Only rows with `team` from ts_role_groups count, old ones (API
 *   version 1) do not.
 */
final class MembershipResolver {
	/**
	 * @param list<string> $userGroupIds the account's groups
	 * @param list<string> $subAdminGroupIds groups the account manages in Nextcloud
	 * @param list<array{tenant_id:int,role:string,gid:string}> $rows all rows from ts_role_groups
	 * @param array<int,array{role:?string,left_at:?int}> $entries team → the account's entry from ts_members
	 * @return array{tenant_id:int,admin:bool} the team and whether the account manages its group
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
		return ['tenant_id' => $tenant, 'admin' => $found[$tenant]];
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

	/**
	 * The one place the role comes from (0.11.0): group admin → `admin`;
	 * leads an active project → `lead`; otherwise `user`.
	 */
	public static function roleOf(bool $admin, bool $leadsProject): string {
		if ($admin) {
			return Role::ADMIN;
		}
		return $leadsProject ? Role::LEAD : Role::USER;
	}

	/**
	 * All members of a team, including those who left, by identifier.
	 *
	 * @param list<string> $groupUsers accounts of the team group
	 * @param list<string> $subAdmins group admins of the team group
	 * @param array<string,array{role:?string,left_at:?int}> $entries uid → entry from ts_members
	 * @param list<string> $leadUids accounts that lead an active project
	 * @return array<string,array{role:string,left_at:?int}>
	 */
	public static function members(array $groupUsers, array $subAdmins, array $entries, array $leadUids = []): array {
		$admins = array_flip($subAdmins);
		$leads = array_flip($leadUids);
		$out = [];
		foreach (array_merge($groupUsers, $subAdmins) as $uid) {
			$e = $entries[$uid] ?? null;
			$out[$uid] = [
				'role' => self::roleOf(isset($admins[$uid]), isset($leads[$uid])),
				'left_at' => $e['left_at'] ?? null,
			];
		}
		ksort($out, SORT_STRING);
		return $out;
	}

	/**
	 * @param array<string,array{role:string,left_at:?int}> $members
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
