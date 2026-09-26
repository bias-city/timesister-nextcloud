<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\RoleGroupMapper;
use OCA\TimeSister\Db\Tenant;
use OCA\TimeSister\Db\TenantMapper;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Mitgliedschaft → Team und Rolle. Das Team ergibt sich immer aus den
 * Gruppen des Aufrufers, nie aus der Anfrage.
 */
final class TenantService {
	/** @var list<array{tenant_id:int,role:string,gid:string}>|null */
	private ?array $roleRows = null;

	public function __construct(
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private TenantMapper $tenants,
		private RoleGroupMapper $roleGroups,
	) {
	}

	/** Der angemeldete Aufrufer als Teammitglied, sonst no_team / ambiguous_team. */
	public function current(): Membership {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw ApiException::forbidden('Nicht angemeldet.');
		}
		return $this->membershipOf($user);
	}

	public function membershipOf(IUser $user): Membership {
		$r = MembershipResolver::resolve($this->groupManager->getUserGroupIds($user), $this->roleRows());
		if ($this->tenants->find($r['tenant_id']) === null) {
			throw ApiException::noTeam();
		}
		return new Membership($user->getUID(), $r['tenant_id'], $r['role']);
	}

	/** @return list<array{tenant_id:int,role:string,gid:string}> */
	public function roleRows(): array {
		return $this->roleRows ??= $this->roleGroups->allRows();
	}

	/** Nach Änderungen an den Teams. */
	public function reset(): void {
		$this->roleRows = null;
	}

	public function tenant(int $id): Tenant {
		$t = $this->tenants->find($id);
		if ($t === null) {
			throw ApiException::noTeam();
		}
		return $t;
	}

	/** @return array<string,string> Rolle oder `accounts` → Gruppe */
	private function rowsOf(int $tenantId): array {
		$gid = [];
		foreach ($this->roleRows() as $rg) {
			if ($rg['tenant_id'] === $tenantId) {
				$gid[$rg['role']] = $rg['gid'];
			}
		}
		return $gid;
	}

	/**
	 * Nur die vier Rollen-Gruppen, ohne Konten-Gruppe.
	 *
	 * @return array{user:?string,lead:?string,subadmin:?string,admin:?string}
	 */
	public function roleGroupsOf(int $tenantId): array {
		$gid = $this->rowsOf($tenantId);
		return [
			'user' => $gid['user'] ?? null,
			'lead' => $gid['lead'] ?? null,
			'subadmin' => $gid['subadmin'] ?? null,
			'admin' => $gid['admin'] ?? null,
		];
	}

	/** Die Konten-Gruppe des Teams oder null. Keine Rolle. */
	public function accountsGroupOf(int $tenantId): ?string {
		return $this->rowsOf($tenantId)[Role::ACCOUNTS] ?? null;
	}

	/**
	 * Für /me, /team und /admin/teams: die vier Rollen-Gruppen, dazu
	 * `accounts` nur, wenn gesetzt.
	 *
	 * @return array<string,?string>
	 */
	public function groupsOf(int $tenantId): array {
		$groups = $this->roleGroupsOf($tenantId);
		$accounts = $this->accountsGroupOf($tenantId);
		if ($accounts !== null) {
			$groups[Role::ACCOUNTS] = $accounts;
		}
		return $groups;
	}

	/** @return array<string,string> uid → stärkste Rolle im Team */
	public function memberRoles(int $tenantId): array {
		$byRole = [];
		foreach ($this->roleGroupsOf($tenantId) as $role => $gid) {
			$group = $gid === null ? null : $this->groupManager->get($gid);
			$byRole[$role] = $group === null
				? []
				: array_map(static fn (IUser $u) => $u->getUID(), array_values($group->getUsers()));
		}
		return MembershipResolver::strongestRoles($byRole);
	}

	public function displayName(string $uid): string {
		return $this->userManager->getDisplayName($uid) ?? $uid;
	}

	/** @return array{id:int,name:string,slug:string,groups:array<string,?string>} */
	public function presentTeam(Tenant $t): array {
		return [
			'id' => $t->getId(),
			'name' => $t->getName(),
			'slug' => $t->getSlug(),
			'groups' => $this->groupsOf($t->getId()),
		];
	}
}
