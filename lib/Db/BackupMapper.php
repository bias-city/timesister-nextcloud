<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Backup> */
final class BackupMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_backups', Backup::class);
	}

	public function findDay(int $tenantId, string $uid, string $day): ?Backup {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('taken_on', $qb->createNamedParameter($day)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findInTenant(int $tenantId, int $id): ?Backup {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Backup> neueste zuerst */
	public function listFor(int $tenantId, string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->orderBy('taken_on', 'DESC');
		return $this->findEntities($qb);
	}

	/** @return list<Backup> über alle Teams, für die Aufbewahrung */
	public function findBefore(string $day): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->lt('taken_on', $qb->createNamedParameter($day)));
		return $this->findEntities($qb);
	}

	public function countByTenant(int $tenantId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$n = (int)$res->fetchOne();
		$res->closeCursor();
		return $n;
	}
}
