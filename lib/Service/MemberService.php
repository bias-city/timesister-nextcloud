<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Member;
use OCA\TimeSister\Db\MemberMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Role, leaving date and “allow overriding” of a member. One service for
 * PUT /team/members/{uid} and the admin page. “Team Admin” is the group
 * admin of the team group in Nextcloud, set and withdrawn through ISubAdmin.
 */
final class MemberService {
	public function __construct(
		private MemberMapper $members,
		private TenantService $tenants,
		private AccessService $access,
		private ISubAdmin $subAdmin,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param string $actorRole the caller's role in the team; `admin` for Nextcloud admins on the admin page
	 * @param array<string,mixed> $in `role`, `left` and/or `may_override`
	 * @return array{uid:string,display_name:string,role:string,left_at:?string,may_override:bool} the entry as in GET /team
	 */
	public function update(int $tenantId, string $actorUid, string $actorRole, string $uid, array $in): array {
		MemberRules::requireTeamAdmin($actorRole);
		$change = MemberRules::validate($in);
		$current = $this->tenants->members($tenantId)[$uid] ?? null;
		if ($current === null) {
			throw ApiException::notFound('This account is not in the team group.');
		}
		MemberRules::authorize($actorUid, $actorRole, $uid, $current['role'], $change, count($this->tenants->adminsOf($tenantId)));
		$before = $this->access->snapshot($tenantId);
		if (isset($change['role']) && $change['role'] !== $current['role']) {
			$this->setGroupAdmin($tenantId, $uid, $change['role'] === Role::ADMIN);
		}
		$now = $this->time->getTime();
		for ($attempt = 1; ; $attempt++) {
			$found = $this->members->findOne($tenantId, $uid);
			$m = $found ?? new Member();
			if ($found === null) {
				$m->setTenantId($tenantId);
				$m->setUid($uid);
			}
			if (array_key_exists('role', $change)) {
				// Only lead is stored: user is null, admin lives in Nextcloud.
				$m->setRole($change['role'] === Role::LEAD ? Role::LEAD : null);
			}
			if (array_key_exists('left', $change)) {
				// Already left: the first date stays.
				$m->setLeftAt($change['left'] ? ($m->getLeftAt() ?? $now) : null);
			}
			if (array_key_exists('may_override', $change)) {
				$m->setMayOverride($change['may_override'] ? 1 : 0);
			}
			$m->setUpdatedBy($actorUid);
			$m->setUpdatedAt($now);
			try {
				$found === null ? $this->members->insert($m) : $this->members->update($m);
				break;
			} catch (DbException $e) {
				// Two changes at the same time: the second updates.
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION || $attempt >= 2) {
					throw $e;
				}
			}
		}
		$this->tenants->reset();
		// A role or leaving that withdraws shares: the owners are reminded.
		$this->access->remindWithdrawals($tenantId, $before);
		return $this->tenants->presentMember($uid, $this->tenants->members($tenantId)[$uid] ?? $current);
	}

	/**
	 * Team Admin on or off: the group admin of the team group. Whoever
	 * loses it stays a member, so they are added to the team group.
	 */
	private function setGroupAdmin(int $tenantId, string $uid, bool $admin): void {
		$gid = $this->tenants->teamGroupOf($tenantId);
		$group = $gid === null ? null : $this->groupManager->get($gid);
		$user = $this->userManager->get($uid);
		if ($group === null || $user === null) {
			throw ApiException::notFound('This account is not in the team group.');
		}
		$is = $this->subAdmin->isSubAdminOfGroup($user, $group);
		if ($admin && !$is) {
			$this->subAdmin->createSubAdmin($user, $group);
		} elseif (!$admin && $is) {
			if (!$group->inGroup($user)) {
				$group->addUser($user);
			}
			$this->subAdmin->deleteSubAdmin($user, $group);
		}
	}
}
