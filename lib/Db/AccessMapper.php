<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Access> */
final class AccessMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_access', Access::class);
	}

	/** @return list<Access> all set fields of a team */
	public function findByTenant(int $tenantId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	public function findOne(int $tenantId, string $viewer, string $owner, string $source): ?Access {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('viewer', $qb->createNamedParameter($viewer)))
			->andWhere($qb->expr()->eq('owner', $qb->createNamedParameter($owner)))
			->andWhere($qb->expr()->eq('source', $qb->createNamedParameter($source)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function deleteByTenant(int $tenantId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/** Account deleted: a new account with the same identifier inherits no share. */
	public function deleteByUid(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->eq('viewer', $qb->createNamedParameter($uid)),
				$qb->expr()->eq('owner', $qb->createNamedParameter($uid)),
			));
		$qb->executeStatement();
	}
}
