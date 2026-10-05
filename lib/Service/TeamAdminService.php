<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\BackupConsentMapper;
use OCA\TimeSister\Db\BackupMapper;
use OCA\TimeSister\Db\ClientStatusMapper;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\RoleGroup;
use OCA\TimeSister\Db\RoleGroupMapper;
use OCA\TimeSister\Db\Tenant;
use OCA\TimeSister\Db\TenantMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\Group\ISubAdmin;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserManager;

/** Create, change, delete teams and show their status. Only for Nextcloud admins. */
final class TeamAdminService {
	public const SILENT_DAYS = 14;

	public function __construct(
		private IDBConnection $db,
		private IGroupManager $groupManager,
		private ISubAdmin $subAdmin,
		private IUserManager $userManager,
		private TenantMapper $tenants,
		private RoleGroupMapper $roleGroups,
		private RecordMapper $records,
		private BackupMapper $backups,
		private ClientStatusMapper $status,
		private BackupConsentMapper $consents,
		private TenantService $tenantService,
		private WeekMarks $marks,
		private ITimeFactory $time,
	) {
	}

	/** @return list<array<string,mixed>> */
	public function list(): array {
		return array_map(fn (Tenant $t) => $this->present($t), $this->tenants->findAll());
	}

	public function find(int $id): Tenant {
		return $this->tenants->find($id) ?? throw ApiException::notFound('This team does not exist.');
	}

	/** @return array<string,mixed> as GET /team, without members, with counts per role and `left` */
	public function present(Tenant $t): array {
		$team = $this->tenantService->presentTeam($t);
		$counts = ['user' => 0, 'lead' => 0, 'admin' => 0, 'left' => 0];
		foreach ($this->tenantService->members($t->getId()) as $m) {
			$counts[$m['left_at'] === null ? $m['role'] : 'left']++;
		}
		$team['counts'] = $counts;
		return $team;
	}

	/**
	 * @param array<string,mixed> $in
	 * @return array<string,mixed>
	 */
	public function create(array $in): array {
		$v = TeamRules::validate($in);
		$this->checkGroups($v['groups'], null);
		[, $owner] = TeamRules::backupOwner($in);
		$this->checkOwner($owner, $v['groups']['team']);
		$settings = TeamRules::settings($in) + TeamRules::SETTINGS;
		if ($this->tenants->findBySlug($v['slug']) !== null) {
			throw ApiException::conflict('Another team already has this short name.');
		}
		$this->db->beginTransaction();
		try {
			$t = new Tenant();
			$t->setName($v['name']);
			$t->setSlug($v['slug']);
			$t->setRevision(0);
			$t->setCreatedAt($this->time->getTime());
			$t->setBackupOwner($owner);
			self::applySettings($t, $settings);
			$t = $this->tenants->insert($t);
			$this->insertGroups($t->getId(), $v['groups']);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $this->uniqueToConflict($e);
		}
		$this->tenantService->reset();
		return $this->present($t);
	}

	/**
	 * @param array<string,mixed> $in
	 * @return array<string,mixed>
	 */
	public function update(int $id, array $in): array {
		$t = $this->find($id);
		$v = TeamRules::validate($in);
		$this->checkGroups($v['groups'], $id);
		[$ownerGiven, $owner] = TeamRules::backupOwner($in);
		if ($ownerGiven) {
			$this->checkOwner($owner, $v['groups']['team']);
		}
		$settings = TeamRules::settings($in);
		$other = $this->tenants->findBySlug($v['slug']);
		if ($other !== null && $other->getId() !== $id) {
			throw ApiException::conflict('Another team already has this short name.');
		}
		$this->db->beginTransaction();
		try {
			$t->setName($v['name']);
			$t->setSlug($v['slug']);
			// The team group exists (checked): the mapping is whole again.
			$t->setBrokenAt(null);
			// Without backup_owner in the body, the choice stays.
			if ($ownerGiven) {
				$t->setBackupOwner($owner);
			}
			// Only the settings that were sent change.
			self::applySettings($t, $settings);
			$this->tenants->update($t);
			// Also removes old mappings from API version 1 (role and
			// account groups); the Nextcloud groups themselves stay.
			$this->roleGroups->deleteByTenant($id);
			$this->insertGroups($id, $v['groups']);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $this->uniqueToConflict($e);
		}
		$this->tenantService->reset();
		return $this->present($t);
	}

	// Deleting: TeamDeleteService (0.10.1).

	/**
	 * Status per team for the admin page.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function overview(): array {
		$now = $this->time->getTime();
		$limit = $now - self::SILENT_DAYS * 86400;
		$out = [];
		$today = gmdate('Y-m-d', $now);
		foreach ($this->tenants->findAll() as $t) {
			$stats = $this->records->stats($t->getId());
			$seen = $this->status->findByTenant($t->getId());
			$consents = $this->consents->findByTenant($t->getId());
			$lastDays = $this->backups->lastServerDays($t->getId());
			$silent = [];
			$consenting = 0;
			$withoutWeek = 0;
			$notShared = 0;
			$members = $this->tenantService->memberRoles($t->getId());
			foreach (array_map('strval', array_keys($members)) as $uid) {
				$role = $members[$uid];
				// Calendar not shared: reported this way (POST /status); never reported does not count.
				if (($seen[$uid] ?? null)?->getCalendarShared() === 0) {
					$notShared++;
				}
				// Without a backup this week only counts for those who gave
				// consent. Checked and unchanged counts as backed up (the
				// existing one stays).
				if (ConsentService::granted($consents[$uid] ?? null)) {
					$consenting++;
					if (!$this->marks->checked($uid, WeekMarks::ADMIN, $today)) {
						$withoutWeek++;
					}
				}
				$s = $seen[$uid] ?? null;
				if ($s === null || $s->getSeenAt() < $limit) {
					$silent[] = [
						'uid' => $uid,
						'display_name' => $this->tenantService->displayName($uid),
						'role' => $role,
						'seen_at' => $s === null ? null : Time::iso($s->getSeenAt()),
					];
				}
			}
			$gid = $this->tenantService->teamGroupOf($t->getId());
			$missing = $gid === null || !$this->groupManager->groupExists($gid) ? [Role::TEAM_GROUP] : [];
			$out[] = [
				'id' => $t->getId(),
				'revision' => $t->getRevision(),
				'broken' => $t->getBrokenAt() !== null || $missing !== [],
				'missing_groups' => $missing,
				'records' => $stats['live'],
				'persons_account_deleted' => $stats['persons_account_deleted'],
				'last_modified' => Time::iso($stats['last']),
				'silent' => $silent,
				'member_count' => count($members),
				'backup_consent' => $consenting,
				'without_backup_week' => $withoutWeek,
				'calendar_not_shared' => $notShared,
				'last_server_backup' => $lastDays === [] ? null : max($lastDays),
				'backup_owner_choice' => $t->getBackupOwner(),
				'admins' => array_map(fn (string $uid) => [
					'uid' => $uid, 'display_name' => $this->tenantService->displayName($uid),
				], $this->tenantService->adminsOf($t->getId())),
				// For the list and "edit team": all, including those who left.
				'members' => $this->tenantService->presentMembers($t->getId()),
			];
		}
		return $out;
	}

	/** @param array<string,bool> $settings */
	private static function applySettings(Tenant $t, array $settings): void {
		if (array_key_exists('leads_see_calendars', $settings)) {
			$t->setLeadsSeeCalendars($settings['leads_see_calendars'] ? 1 : 0);
		}
		if (array_key_exists('backup_required', $settings)) {
			$t->setBackupRequired($settings['backup_required'] ? 1 : 0);
		}
	}

	/** The backup owner must be an admin, i.e. a group admin of the team group (422). */
	private function checkOwner(?string $uid, string $teamGid): void {
		if ($uid === null) {
			return;
		}
		$user = $this->userManager->get($uid);
		$group = $this->groupManager->get($teamGid);
		if ($user === null || $group === null || !$this->subAdmin->isSubAdminOfGroup($user, $group)) {
			throw ApiException::invalid('The backup owner must be a Team Admin of the team.');
		}
	}

	/** @return list<array{id:string,name:string,team:?int}> all groups */
	public function allGroups(): array {
		$used = [];
		foreach ($this->roleGroups->allRows() as $rg) {
			$used[$rg['gid']] = $rg['tenant_id'];
		}
		$out = array_map(static fn (IGroup $g) => [
			'id' => $g->getGID(),
			'name' => $g->getDisplayName(),
			'team' => $used[$g->getGID()] ?? null,
		], $this->groupManager->search(''));
		usort($out, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		return $out;
	}

	/**
	 * Every group must exist (422) and must not be mapped to another team,
	 * not even through an old row from API version 1 (409).
	 *
	 * @param array<string,string> $groups
	 */
	private function checkGroups(array $groups, ?int $ownId): void {
		foreach ($groups as $gid) {
			if (!$this->groupManager->groupExists($gid)) {
				throw ApiException::invalid(Message::of('The group “{group}” does not exist.', ['group' => $gid]));
			}
			foreach ($this->roleGroups->findByGid($gid) as $rg) {
				if ($rg->getTenantId() !== $ownId) {
					throw ApiException::conflict(Message::of('The group “{group}” already belongs to another team.', ['group' => $gid]));
				}
			}
		}
	}

	/** @param array<string,string> $groups */
	private function insertGroups(int $tenantId, array $groups): void {
		foreach ($groups as $role => $gid) {
			$rg = new RoleGroup();
			$rg->setTenantId($tenantId);
			$rg->setRole($role);
			$rg->setGid($gid);
			$this->roleGroups->insert($rg);
		}
	}

	private function uniqueToConflict(\Throwable $e): \Throwable {
		if ($e instanceof DbException && $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
			return ApiException::conflict('The short name or group already belongs to another team.');
		}
		return $e;
	}
}
