<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\MemberMapper;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\RoleGroupMapper;
use OCA\TimeSister\Db\Tenant;
use OCA\TimeSister\Db\TenantMapper;
use OCP\Group\ISubAdmin;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Membership → team and role (API version 2). The team always follows
 * from the caller's team group, never from the request.
 */
final class TenantService {
	/** @var list<array{tenant_id:int,role:string,gid:string}>|null */
	private ?array $roleRows = null;
	/** @var array<int,array<string,array{role:string,left_at:?int}>> team → uid → entry */
	private array $members = [];
	/** @var array<int,array<string,list<array{kind:string,key:string,code:?string,name:string}>>> team → uid → active projects led (per request) */
	private array $leads = [];

	public function __construct(
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private ISubAdmin $subAdmin,
		private IUserManager $userManager,
		private TenantMapper $tenants,
		private RoleGroupMapper $roleGroups,
		private MemberMapper $memberMapper,
		private RecordMapper $records,
	) {
	}

	/** The logged-in caller as a team member, otherwise no_team / ambiguous_team. */
	public function current(): Membership {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw ApiException::forbidden('Not logged in.');
		}
		return $this->membershipOf($user);
	}

	public function membershipOf(IUser $user): Membership {
		$managed = array_map(static fn (IGroup $g) => $g->getGID(), $this->subAdmin->getSubAdminsGroups($user));
		$r = MembershipResolver::resolve(
			$this->groupManager->getUserGroupIds($user),
			array_values($managed),
			$this->roleRows(),
			$this->memberMapper->entriesByUid($user->getUID()),
		);
		if ($this->tenants->find($r['tenant_id']) === null) {
			throw ApiException::noTeam();
		}
		$uid = $user->getUID();
		return new Membership($uid, $r['tenant_id'], MembershipResolver::roleOf($r['admin'], $this->leadOf($r['tenant_id'], $uid) !== []));
	}

	/**
	 * The active projects an account leads (0.11.0) – the source of the
	 * role Lead. Read once per request and team.
	 *
	 * @return list<array{kind:string,key:string,code:?string,name:string}>
	 */
	public function leadOf(int $tenantId, string $uid): array {
		return $this->leadsOf($tenantId)[$uid] ?? [];
	}

	/** @return array<string,list<array{kind:string,key:string,code:?string,name:string}>> uid → active projects led */
	public function leadsOf(int $tenantId): array {
		return $this->leads[$tenantId] ??= LeadResolver::leadsByUid(
			$this->records->findLiveByKind($tenantId, 'person'),
			$this->records->findLiveByKind($tenantId, 'project'),
		);
	}

	/** @return list<array{tenant_id:int,role:string,gid:string}> */
	public function roleRows(): array {
		return $this->roleRows ??= $this->roleGroups->allRows();
	}

	/** After changes to teams or roles. */
	public function reset(): void {
		$this->roleRows = null;
		$this->members = [];
		$this->leads = [];
	}

	public function tenant(int $id): Tenant {
		$t = $this->tenants->find($id);
		if ($t === null) {
			throw ApiException::noTeam();
		}
		return $t;
	}

	/** The team group, or null. Old rows from API version 1 do not count. */
	public function teamGroupOf(int $tenantId): ?string {
		return MembershipResolver::teamGroups($this->roleRows())[$tenantId] ?? null;
	}

	/** @return array{team:?string} for /me, /team and /admin/teams */
	public function groupsOf(int $tenantId): array {
		return [Role::TEAM_GROUP => $this->teamGroupOf($tenantId)];
	}

	/**
	 * All members, including those who left: accounts of the team group
	 * and its group admins, with role and `left_at`.
	 *
	 * @return array<string,array{role:string,left_at:?int}>
	 */
	public function members(int $tenantId): array {
		return $this->members[$tenantId] ??= $this->loadMembers($tenantId);
	}

	/** @return array<string,array{role:string,left_at:?int}> */
	private function loadMembers(int $tenantId): array {
		$gid = $this->teamGroupOf($tenantId);
		$group = $gid === null ? null : $this->groupManager->get($gid);
		if ($group === null) {
			return [];
		}
		$uids = static fn (array $users): array => array_values(array_map(static fn (IUser $u) => $u->getUID(), $users));
		return MembershipResolver::members(
			$uids($group->getUsers()),
			$uids($this->subAdmin->getGroupsSubAdmins($group)),
			$this->memberMapper->entriesByTenant($tenantId),
			array_map('strval', array_keys($this->leadsOf($tenantId))),
		);
	}

	/** @return array<string,string> uid → role, without those who left */
	public function memberRoles(int $tenantId): array {
		return MembershipResolver::activeRoles($this->members($tenantId));
	}

	/** @return list<string> identifiers with this role, without those who left, sorted */
	public function withRole(int $tenantId, string $role): array {
		$uids = array_map('strval', array_keys(array_filter($this->memberRoles($tenantId), static fn (string $r) => $r === $role)));
		sort($uids, SORT_STRING);
		return $uids;
	}

	/** @return list<string> accounts with role admin, by identifier */
	public function adminsOf(int $tenantId): array {
		return $this->withRole($tenantId, Role::ADMIN);
	}

	/** The backup owner: the choice, as long as it is still admin, otherwise the first admin. */
	public function backupOwner(Tenant $t): ?string {
		return BackupRules::pickOwner($t->getBackupOwner(), $this->adminsOf($t->getId()));
	}

	public function displayName(string $uid): string {
		return $this->userManager->getDisplayName($uid) ?? $uid;
	}

	/**
	 * A member as in GET /team; `lead_of` counts the active projects led,
	 * `lead_projects` names them (0.11.0).
	 *
	 * @param array{role:string,left_at:?int} $m
	 * @return array{uid:string,display_name:string,role:string,left_at:?string,lead_of:int,lead_projects:list<string>}
	 */
	public function presentMember(int $tenantId, string $uid, array $m): array {
		$led = $this->leadOf($tenantId, $uid);
		return [
			'uid' => $uid,
			'display_name' => $this->displayName($uid),
			'role' => $m['role'],
			'left_at' => Time::iso($m['left_at']),
			'lead_of' => count($led),
			'lead_projects' => array_map(static fn (array $p): string => $p['name'], $led),
		];
	}

	/** @return list<array{uid:string,display_name:string,role:string,left_at:?string,lead_of:int,lead_projects:list<string>}> all members, including those who left */
	public function presentMembers(int $tenantId): array {
		$members = $this->members($tenantId);
		$out = [];
		// Purely numeric identifiers come as int keys.
		foreach (array_map('strval', array_keys($members)) as $uid) {
			$out[] = $this->presentMember($tenantId, $uid, $members[$uid]);
		}
		return $out;
	}

	/** @return array{id:int,name:string,slug:string,groups:array{team:?string},backup_owner:?string,settings:array{backup_required:bool,entries_since:?string,invoicing:bool,documents:array{root:?string,chain_mail:?string},datev:array<string,string|int|null>,webhooks:list<array{url:string,events:list<string>,secret_set:bool}>}} */
	public function presentTeam(Tenant $t): array {
		return [
			'id' => $t->getId(),
			'name' => $t->getName(),
			'slug' => $t->getSlug(),
			'groups' => $this->groupsOf($t->getId()),
			'backup_owner' => $this->backupOwner($t),
			'settings' => self::settingsOf($t),
		];
	}

	/** @return array{backup_required:bool,entries_since:?string,invoicing:bool,documents:array{root:?string,chain_mail:?string},datev:array<string,string|int|null>,webhooks:list<array{url:string,events:list<string>,secret_set:bool}>} */
	public static function settingsOf(Tenant $t): array {
		return [
			'backup_required' => $t->getBackupRequired() === 1,
			// The stored cutoff (0.17.0); the effective one is `entries_since` in /me.
			'entries_since' => $t->getEntriesSince(),
			// Issuing on the server (0.18.0).
			'invoicing' => $t->invoicingOn(),
			// The document folders (0.19.0); null means the default.
			'documents' => DocumentRules::settings($t->getDocumentsJson()),
			// The DATEV accounts and the webhooks (0.20.0); a secret is never shown.
			'datev' => ExportRules::datev($t->getDatevJson()),
			'webhooks' => WebhookRules::present(WebhookRules::hooks($t->getWebhooksJson())),
		];
	}
}
