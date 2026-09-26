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
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;

/** Teams anlegen, ändern, löschen und ihren Zustand zeigen. Nur für Nextcloud-Admins. */
final class TeamAdminService {
	public const SILENT_DAYS = 14;

	public function __construct(
		private IDBConnection $db,
		private IGroupManager $groupManager,
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

	/** @return array<string,mixed> wie GET /team, ohne members, mit counts */
	public function present(Tenant $t): array {
		$team = $this->tenantService->presentTeam($t);
		$counts = ['user' => 0, 'lead' => 0, 'subadmin' => 0, 'admin' => 0];
		foreach ($this->tenantService->memberRoles($t->getId()) as $role) {
			$counts[$role]++;
		}
		// Konten-Gruppe: alle ihre Mitglieder, nur wenn gesetzt.
		$accounts = $this->tenantService->accountsGroupOf($t->getId());
		if ($accounts !== null) {
			$group = $this->groupManager->get($accounts);
			$counts[Role::ACCOUNTS] = $group === null ? 0 : count($group->getUsers());
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
		$this->checkGroups(self::allGids($v['groups'], $v['accounts']), null);
		[, $owner] = TeamRules::backupOwner($in);
		$this->checkOwner($owner, $v['groups']['admin']);
		$settings = TeamRules::settings($in) + TeamRules::SETTINGS;
		if ($this->tenants->findBySlug($v['slug']) !== null) {
			throw ApiException::conflict('Diesen Kurznamen hat schon ein anderes Team.');
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
			$this->insertGroups($t->getId(), self::allGids($v['groups'], $v['accounts']));
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
		$t = $this->tenants->find($id) ?? throw ApiException::notFound('Dieses Team gibt es nicht.');
		$v = TeamRules::validate($in);
		$this->checkGroups(self::allGids($v['groups'], $v['accounts']), $id);
		[$ownerGiven, $owner] = TeamRules::backupOwner($in);
		if ($ownerGiven) {
			$this->checkOwner($owner, $v['groups']['admin']);
		}
		$settings = TeamRules::settings($in);
		$other = $this->tenants->findBySlug($v['slug']);
		if ($other !== null && $other->getId() !== $id) {
			throw ApiException::conflict('Diesen Kurznamen hat schon ein anderes Team.');
		}
		$this->db->beginTransaction();
		try {
			$t->setName($v['name']);
			$t->setSlug($v['slug']);
			// Alle vier Gruppen gibt es (geprüft): die Zuordnung ist wieder ganz.
			$t->setBrokenAt(null);
			// Ohne backup_owner im Rumpf bleibt die Wahl.
			if ($ownerGiven) {
				$t->setBackupOwner($owner);
			}
			// Nur die geschickten Einstellungen ändern sich.
			self::applySettings($t, $settings);
			$this->tenants->update($t);
			// Ohne accounts im Rumpf fällt die Konten-Gruppe hier weg.
			$this->roleGroups->deleteByTenant($id);
			$this->insertGroups($id, self::allGids($v['groups'], $v['accounts']));
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $this->uniqueToConflict($e);
		}
		$this->tenantService->reset();
		return $this->present($t);
	}

	/** Nur ohne Datensätze (auch Grabsteine) und ohne Sicherungen. */
	public function delete(int $id): void {
		$t = $this->tenants->find($id) ?? throw ApiException::notFound('Dieses Team gibt es nicht.');
		if ($this->records->stats($id)['all'] > 0 || $this->backups->countByTenant($id) > 0) {
			throw ApiException::conflict('Das Team hat noch Datensätze oder Sicherungen und lässt sich nicht löschen.');
		}
		$this->db->beginTransaction();
		try {
			$this->roleGroups->deleteByTenant($id);
			$this->status->deleteByTenant($id);
			$this->consents->deleteByTenant($id);
			$this->tenants->delete($t);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->tenantService->reset();
	}

	/**
	 * Zustand je Team für die Admin-Seite.
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
			$members = $this->tenantService->memberRoles($t->getId());
			foreach (array_map('strval', array_keys($members)) as $uid) {
				$role = $members[$uid];
				// Ohne Sicherung diese Woche zählt nur, wer freigegeben hat. Geprüft
				// und unverändert gilt als gesichert (es bleibt bei der vorhandenen).
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
			$missing = [];
			foreach ($this->tenantService->roleGroupsOf($t->getId()) as $role => $gid) {
				if ($gid === null || !$this->groupManager->groupExists($gid)) {
					$missing[] = $role;
				}
			}
			$out[] = [
				'id' => $t->getId(),
				'revision' => $t->getRevision(),
				'broken' => $t->getBrokenAt() !== null || $missing !== [],
				'missing_roles' => $missing,
				'records' => $stats['live'],
				'persons_account_deleted' => $stats['persons_account_deleted'],
				'last_modified' => Time::iso($stats['last']),
				'silent' => $silent,
				'member_count' => count($members),
				'backup_consent' => $consenting,
				'without_backup_week' => $withoutWeek,
				'last_server_backup' => $lastDays === [] ? null : max($lastDays),
				'backup_owner_choice' => $t->getBackupOwner(),
				'admins' => array_map(fn (string $uid) => [
					'uid' => $uid, 'display_name' => $this->tenantService->displayName($uid),
				], $this->tenantService->adminsOf($t->getId())),
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

	/** Das Sicherungs-Konto muss in der admin-Gruppe des Teams stehen (422). */
	private function checkOwner(?string $uid, string $adminGid): void {
		if ($uid !== null && !$this->groupManager->isInGroup($uid, $adminGid)) {
			throw ApiException::invalid('Das Sicherungs-Konto muss Admin des Teams sein.');
		}
	}

	/** @return list<array{id:string,name:string,team:?int}> alle Gruppen */
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
	 * Rollen-Gruppen und, falls gesetzt, die Konten-Gruppe als eine Zuordnung.
	 *
	 * @param array<string,string> $groups
	 * @return array<string,string>
	 */
	private static function allGids(array $groups, ?string $accounts): array {
		if ($accounts !== null) {
			$groups[Role::ACCOUNTS] = $accounts;
		}
		return $groups;
	}

	/**
	 * Jede Gruppe muss es geben (422) und darf keinem anderen Team gehören,
	 * weder als Rolle noch als Konten-Gruppe (409).
	 *
	 * @param array<string,string> $groups
	 */
	private function checkGroups(array $groups, ?int $ownId): void {
		foreach ($groups as $gid) {
			if (!$this->groupManager->groupExists($gid)) {
				throw ApiException::invalid("Die Gruppe „{$gid}“ gibt es nicht.");
			}
			foreach ($this->roleGroups->findByGid($gid) as $rg) {
				if ($rg->getTenantId() !== $ownId) {
					throw ApiException::conflict("Die Gruppe „{$gid}“ gehört schon zu einem anderen Team.");
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
			return ApiException::conflict('Kurzname oder Gruppe gehört schon zu einem anderen Team.');
		}
		return $e;
	}
}
