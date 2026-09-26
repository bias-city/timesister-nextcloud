<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Bestimmt Team und Rolle aus den Gruppen eines Kontos. Rein.
 *
 * - keine Rollen-Gruppe eines Teams: `no_team`
 * - Rollen-Gruppen zweier Teams: `ambiguous_team`
 * - sonst die stärkste Rolle aus den Gruppen dieses Teams
 */
final class MembershipResolver {
	/**
	 * @param list<string> $userGroupIds Gruppen des Kontos
	 * @param list<array{tenant_id:int,role:string,gid:string}> $roleGroups alle Rollen-Gruppen aller Teams
	 * @return array{tenant_id:int,role:string}
	 * @throws ApiException
	 */
	public static function resolve(array $userGroupIds, array $roleGroups): array {
		$mine = array_flip($userGroupIds);
		$found = [];
		foreach ($roleGroups as $rg) {
			if (!isset($mine[$rg['gid']]) || !Role::isValid($rg['role'])) {
				continue;
			}
			$t = $rg['tenant_id'];
			$found[$t] = isset($found[$t]) ? Role::stronger($found[$t], $rg['role']) : $rg['role'];
		}
		if ($found === []) {
			throw ApiException::noTeam();
		}
		if (count($found) > 1) {
			throw ApiException::ambiguousTeam();
		}
		$tenant = array_key_first($found);
		return ['tenant_id' => $tenant, 'role' => $found[$tenant]];
	}

	/**
	 * Mitglieder eines Teams, jedes nur unter seiner stärksten Rolle.
	 *
	 * @param array<string,list<string>> $usersByRole Rolle → Konten der Rollen-Gruppe
	 * @return array<string,string> uid → stärkste Rolle, nach uid sortiert
	 */
	public static function strongestRoles(array $usersByRole): array {
		$out = [];
		foreach (Role::ALL as $role) {
			foreach ($usersByRole[$role] ?? [] as $uid) {
				$out[$uid] = isset($out[$uid]) ? Role::stronger($out[$uid], $role) : $role;
			}
		}
		ksort($out, SORT_STRING);
		return $out;
	}

	/**
	 * @param array<string,string> $roles uid → Rolle
	 * @return array{user:list<string>,lead:list<string>,subadmin:list<string>,admin:list<string>}
	 */
	public static function membersByRole(array $roles): array {
		$pick = static fn (string $want): array => array_map(
			'strval',
			array_keys(array_filter($roles, static fn (string $r) => $r === $want)),
		);
		return ['user' => $pick('user'), 'lead' => $pick('lead'), 'subadmin' => $pick('subadmin'), 'admin' => $pick('admin')];
	}
}
