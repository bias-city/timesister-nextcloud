<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Access;
use OCA\TimeSister\Db\AccessMapper;
use OCA\TimeSister\Db\ClientStatusMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * The shares matrix on the server (GET/PUT /team/access, reminders,
 * `/me.share_targets`). The rules are in {@see AccessRules}; here only
 * storage, the team and notifications.
 */
final class AccessService {
	public function __construct(
		private AccessMapper $mapper,
		private ClientStatusMapper $status,
		private TenantService $tenants,
		private ShareReminder $reminder,
		private IDBConnection $db,
		private ITimeFactory $time,
	) {
	}

	/** @return array<string,array<string,array<string,array{level:string,at:int,by:string}>>> source → viewer → owner → entry */
	private function entries(int $tenantId): array {
		$out = [AccessRules::SOURCE_ADMIN => [], AccessRules::SOURCE_SELF => []];
		foreach ($this->mapper->findByTenant($tenantId) as $a) {
			$out[$a->getSource()][$a->getViewer()][$a->getOwner()] = [
				'level' => $a->getLevel(), 'at' => $a->getUpdatedAt(), 'by' => $a->getUpdatedBy(),
			];
		}
		return $out;
	}

	/** @return array<string,array{shares:?array<string,string>,at:?int}> owner → reported shares (null: never) */
	private function applied(int $tenantId): array {
		$out = [];
		foreach ($this->status->findByTenant($tenantId) as $s) {
			$json = $s->getAppliedShares();
			$shares = null;
			if ($json !== null) {
				$list = json_decode($json, true);
				$shares = [];
				foreach (is_array($list) ? $list : [] as $e) {
					if (is_array($e) && is_string($e['uid'] ?? null) && is_string($e['access'] ?? null)) {
						$shares[$e['uid']] = $e['access'];
					}
				}
			}
			$out[$s->getUid()] = ['shares' => $shares, 'at' => $s->getAppliedAt()];
		}
		return $out;
	}

	/**
	 * Target levels of the whole team: owner → viewer → level.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function snapshot(int $tenantId): array {
		$roles = $this->tenants->memberRoles($tenantId);
		$flags = $this->tenants->overrideFlags($tenantId);
		$entries = $this->entries($tenantId);
		$out = [];
		foreach (array_map('strval', array_keys($roles)) as $owner) {
			foreach (array_map('strval', array_keys($roles)) as $viewer) {
				if ($viewer !== $owner) {
					$out[$owner][$viewer] = AccessRules::field($viewer, $owner, $roles, $flags, $entries)['level'];
				}
			}
		}
		return $out;
	}

	/**
	 * After a change (shares, roles, leaving): whoever lost a share of their
	 * calendar gets a reminder, so the withdrawal takes effect soon.
	 *
	 * @param array<string,array<string,string>> $before from {@see snapshot}
	 */
	public function remindWithdrawals(int $tenantId, array $before, ?string $except = null): void {
		$this->tenants->reset();
		$after = $this->snapshot($tenantId);
		foreach (array_map('strval', array_keys($before)) as $owner) {
			if ($owner === $except || !isset($after[$owner])) {
				continue;
			}
			foreach ($before[$owner] as $viewer => $level) {
				if (AccessRules::rank($after[$owner][$viewer] ?? AccessRules::NONE) < AccessRules::rank($level)) {
					$this->reminder->notify($tenantId, $owner);
					break;
				}
			}
		}
	}

	/** @return list<array{uid:string,access:string}> for `/me.share_targets` */
	public function shareTargets(Membership $m): array {
		return AccessRules::shareTargets(
			$m->uid,
			$this->tenants->memberRoles($m->tenantId),
			$this->tenants->overrideFlags($m->tenantId),
			$this->entries($m->tenantId),
		);
	}

	/**
	 * What the caller may do with each other member's time calendar, as
	 * the matrix sets it: owner → none|view|edit.
	 *
	 * @return array<string,string>
	 */
	public function levelsOf(Membership $m): array {
		$roles = $this->tenants->memberRoles($m->tenantId);
		$flags = $this->tenants->overrideFlags($m->tenantId);
		$entries = $this->entries($m->tenantId);
		$out = [];
		foreach (array_map('strval', array_keys($roles)) as $owner) {
			if ($owner !== $m->uid && isset($roles[$m->uid])) {
				$out[$owner] = AccessRules::field($m->uid, $owner, $roles, $flags, $entries)['level'];
			}
		}
		return $out;
	}

	public function mayOverride(Membership $m): bool {
		return $this->tenants->overrideFlags($m->tenantId)[$m->uid] ?? false;
	}

	/**
	 * GET /team/access: Team Admins the whole team, everyone else their own
	 * column (who sees me) and row (whom I see).
	 *
	 * @return array<string,mixed>
	 */
	public function matrix(Membership $m): array {
		$roles = $this->tenants->memberRoles($m->tenantId);
		$flags = $this->tenants->overrideFlags($m->tenantId);
		$entries = $this->entries($m->tenantId);
		$applied = $this->applied($m->tenantId);
		$all = $m->manages();
		$uids = array_map('strval', array_keys($roles));
		usort($uids, fn (string $a, string $b) => [-Role::rank($roles[$a]), mb_strtolower($this->tenants->displayName($a))]
			<=> [-Role::rank($roles[$b]), mb_strtolower($this->tenants->displayName($b))]);
		$members = [];
		foreach ($uids as $uid) {
			$e = ['uid' => $uid, 'display_name' => $this->tenants->displayName($uid), 'role' => $roles[$uid]];
			if ($all || $uid === $m->uid) {
				$e['may_override'] = $flags[$uid] ?? false;
				$e['reported_at'] = Time::iso($applied[$uid]['at'] ?? null);
			}
			$members[] = $e;
		}
		$fields = [];
		foreach ($uids as $viewer) {
			foreach ($uids as $owner) {
				if ($viewer === $owner || (!$all && $viewer !== $m->uid && $owner !== $m->uid)) {
					continue;
				}
				$f = AccessRules::field($viewer, $owner, $roles, $flags, $entries);
				$shares = $applied[$owner]['shares'] ?? null;
				$effective = AccessRules::effective($viewer, $f['level'], $shares);
				$fields[] = [
					'viewer' => $viewer,
					'owner' => $owner,
					'level' => $f['level'],
					'admin_level' => $f['admin_level'],
					'self_level' => $f['self_level'],
					'overridden' => $f['overridden'],
					'resting' => $f['resting'],
					'applied' => $shares === null ? null : AccessRules::levelOf($shares[$viewer] ?? null),
					'effective' => $effective,
					'pending' => AccessRules::pending($f['level'], $effective),
					'changed_at' => Time::iso($f['changed_at']),
					'changed_by' => $f['changed_by'],
				];
			}
		}
		return [
			'can_edit' => $all,
			'may_override' => $flags[$m->uid] ?? false,
			'members' => $members,
			'fields' => $fields,
		];
	}

	/**
	 * PUT /team/access: several fields in one transaction, all or none.
	 *
	 * @param list<array{viewer:string,owner:string,level:string}> $changes from {@see AccessRules::validateChanges}
	 * @return array<string,mixed> the matrix afterwards, as GET
	 */
	public function set(Membership $m, array $changes): array {
		$roles = $this->tenants->memberRoles($m->tenantId);
		$flags = $this->tenants->overrideFlags($m->tenantId);
		$todo = [];
		foreach ($changes as $c) {
			$level = $c['level'];
			$source = AccessRules::authorize($m->uid, $m->role, $c['viewer'], $c['owner'], $roles, $flags);
			$todo[$c['viewer'] . "\n" . $c['owner'] . "\n" . $source] = [$c['viewer'], $c['owner'], $source, $level];
		}
		$before = $this->snapshot($m->tenantId);
		$now = $this->time->getTime();
		$this->db->beginTransaction();
		try {
			foreach ($todo as [$viewer, $owner, $source, $level]) {
				$found = $this->mapper->findOne($m->tenantId, $viewer, $owner, $source);
				if ($level === AccessRules::DEFAULT) {
					if ($found !== null) {
						$this->mapper->delete($found);
					}
					continue;
				}
				$a = $found ?? new Access();
				if ($found === null) {
					$a->setTenantId($m->tenantId);
					$a->setViewer($viewer);
					$a->setOwner($owner);
					$a->setSource($source);
				}
				$a->setLevel($level);
				$a->setUpdatedBy($m->uid);
				$a->setUpdatedAt($now);
				$found === null ? $this->mapper->insert($a) : $this->mapper->update($a);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		// Whoever changes their own column applies it on their own client: no reminder to themselves.
		$this->remindWithdrawals($m->tenantId, $before, $m->uid);
		return $this->matrix($m);
	}

	/**
	 * POST /team/access/remind: a notification to everyone whose client has
	 * not yet set what applies.
	 *
	 * @return array{notified:list<string>}
	 */
	public function remind(Membership $m): array {
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins remind people.');
		}
		$out = [];
		foreach ($this->pendingOwners($m->tenantId) as $owner) {
			if ($owner !== $m->uid) {
				$this->reminder->notify($m->tenantId, $owner);
				$out[] = $owner;
			}
		}
		return ['notified' => $out];
	}

	/** After a status report with `applied_shares`: done means the reminder goes away. */
	public function afterReport(Membership $m): void {
		if (!in_array($m->uid, $this->pendingOwners($m->tenantId), true)) {
			$this->reminder->done($m->tenantId, $m->uid);
		}
	}

	/** @return list<string> owners with at least one pending field, sorted */
	private function pendingOwners(int $tenantId): array {
		$applied = $this->applied($tenantId);
		$out = [];
		$snapshot = $this->snapshot($tenantId);
		foreach (array_map('strval', array_keys($snapshot)) as $owner) {
			$shares = $applied[$owner]['shares'] ?? null;
			foreach (array_map('strval', array_keys($snapshot[$owner])) as $viewer) {
				$level = $snapshot[$owner][$viewer];
				if (AccessRules::pending($level, AccessRules::effective($viewer, $level, $shares))) {
					$out[] = $owner;
					break;
				}
			}
		}
		sort($out, SORT_STRING);
		return $out;
	}
}
