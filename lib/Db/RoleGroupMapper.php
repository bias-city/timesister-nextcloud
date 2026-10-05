<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<RoleGroup> */
final class RoleGroupMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_role_groups', RoleGroup::class);
	}

	/** @return list<array{tenant_id:int,role:string,gid:string}> */
	public function allRows(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('tenant_id', 'role', 'gid')->from($this->getTableName());
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[] = ['tenant_id' => (int)$row['tenant_id'], 'role' => (string)$row['role'], 'gid' => (string)$row['gid']];
		}
		$res->closeCursor();
		return $out;
	}

	/** @return list<RoleGroup> */
	public function findByTenant(int $tenantId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/** @return list<RoleGroup> */
	public function findByGid(string $gid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
		return $this->findEntities($qb);
	}

	public function deleteByTenant(int $tenantId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
