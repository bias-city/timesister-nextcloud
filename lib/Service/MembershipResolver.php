<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Bestimmt Team und Rolle eines Kontos (Fassung 2). Rein.
 *
 * - Mitglied: in der Teamgruppe oder Gruppenadmin davon, und nicht
 *   ausgetreten (`left_at` in ts_members).
 * - kein Team: `no_team`; zwei Teams: `ambiguous_team`.
 * - Rolle: `admin`, wer die Teamgruppe verwaltet; sonst die App-Rolle
 *   `subadmin` oder `lead`; sonst `user`.
 * - Nur Zeilen mit `team` aus ts_role_groups zählen, alte (Fassung 1) nicht.
 */
final class MembershipResolver {
	/**
	 * @param list<string> $userGroupIds Gruppen des Kontos
	 * @param list<string> $subAdminGroupIds Gruppen, die das Konto in Nextcloud verwaltet
	 * @param list<array{tenant_id:int,role:string,gid:string}> $rows alle Zeilen aus ts_role_groups
	 * @param array<int,array{role:?string,left_at:?int}> $entries Team → Eintrag des Kontos aus ts_members
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
			// Ausgetreten: gehört nicht mehr zu diesem Team, auch wenn es in der Gruppe steht.
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
	 * @return array<int,string> Team → Teamgruppe
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

	/** Rolle aus Gruppenadmin und App-Rolle; unbekannte App-Rollen gelten als `user`. */
	public static function roleOf(bool $admin, ?string $appRole): string {
		if ($admin) {
			return Role::ADMIN;
		}
		return $appRole === Role::LEAD || $appRole === Role::SUBADMIN ? $appRole : Role::USER;
	}

	/**
	 * Alle Mitglieder eines Teams, auch Ausgetretene, nach Kennung.
	 *
	 * @param list<string> $groupUsers Konten der Teamgruppe
	 * @param list<string> $subAdmins Gruppenadmins der Teamgruppe
	 * @param array<string,array{role:?string,left_at:?int}> $entries uid → Eintrag aus ts_members
	 * @return array<string,array{role:string,left_at:?int}>
	 */
	public static function members(array $groupUsers, array $subAdmins, array $entries): array {
		$admins = array_flip($subAdmins);
		$out = [];
		foreach (array_merge($groupUsers, $subAdmins) as $uid) {
			$e = $entries[$uid] ?? null;
			$out[$uid] = [
				'role' => self::roleOf(isset($admins[$uid]), $e['role'] ?? null),
				'left_at' => $e['left_at'] ?? null,
			];
		}
		ksort($out, SORT_STRING);
		return $out;
	}

	/**
	 * @param array<string,array{role:string,left_at:?int}> $members
	 * @return array<string,string> uid → Rolle, ohne Ausgetretene
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
