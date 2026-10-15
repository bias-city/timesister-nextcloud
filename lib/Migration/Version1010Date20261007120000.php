<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Migration;

use Closure;
use OCA\TimeSister\Service\Role;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Group\ISubAdmin;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.14.0: only Team Admins see the time calendars of others. Every stored
 * level in ts_access whose viewer is no Team Admin (group admin of the
 * team group) becomes `none`, logged per team; nothing is deleted. The
 * owners' clients withdraw the shares at their next sync. A team whose
 * group or Team Admins cannot be read is left as is – the server reads
 * those rows as `none` anyway.
 */
final class Version1010Date20261007120000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
		private IGroupManager $groups,
		private ISubAdmin $subAdmin,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		foreach ($this->teams() as $t) {
			$group = $this->groups->get($t['gid']);
			$admins = $group === null ? [] : array_values(array_map(static fn (IUser $u) => $u->getUID(), $this->subAdmin->getGroupsSubAdmins($group)));
			if ($admins === []) {
				$output->info(sprintf('TimeSister: %s (team %d) – no Team Admins found, shares left as they are', $t['slug'], $t['id']));
				continue;
			}
			$ids = self::withdrawnRows($this->rows($t['id']), $admins);
			foreach (array_chunk($ids, 500) as $chunk) {
				$qb = $this->db->getQueryBuilder();
				$qb->update('ts_access')
					->set('level', $qb->createNamedParameter('none'))
					->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
			if ($ids !== []) {
				$output->info(sprintf('TimeSister: %s (team %d) – %d share(s) of Leads or Users set to none', $t['slug'], $t['id'], count($ids)));
			}
		}
	}

	/**
	 * Stored entries that no longer apply: every level other than `none`
	 * whose viewer is no Team Admin of the team. (Formerly AccessRules; the
	 * shares matrix is gone since 0.17.0, this step stays as it ran.)
	 *
	 * @param list<array{id:int,viewer:string,level:string}> $rows
	 * @param list<string> $admins
	 * @return list<int> the rows to set to `none`
	 */
	private static function withdrawnRows(array $rows, array $admins): array {
		$out = [];
		foreach ($rows as $r) {
			if ($r['level'] !== 'none' && !in_array($r['viewer'], $admins, true)) {
				$out[] = $r['id'];
			}
		}
		return $out;
	}

	/** @return list<array{id:int,slug:string,gid:string}> teams with their team group */
	private function teams(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('g.tenant_id', 'g.gid', 't.slug')
			->from('ts_role_groups', 'g')
			->leftJoin('g', 'ts_tenants', 't', $qb->expr()->eq('t.id', 'g.tenant_id'))
			->where($qb->expr()->eq('g.role', $qb->createNamedParameter(Role::TEAM_GROUP)))
			->orderBy('g.tenant_id');
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[] = ['id' => (int)$row['tenant_id'], 'slug' => (string)($row['slug'] ?? '?'), 'gid' => (string)$row['gid']];
		}
		$res->closeCursor();
		return $out;
	}

	/** @return list<array{id:int,viewer:string,level:string}> */
	private function rows(int $tenantId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'viewer', 'level')
			->from('ts_access')
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[] = ['id' => (int)$row['id'], 'viewer' => (string)$row['viewer'], 'level' => (string)$row['level']];
		}
		$res->closeCursor();
		return $out;
	}
}
