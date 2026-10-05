<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<History> */
final class HistoryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_history', History::class);
	}

	/** @return list<History> newest first */
	public function findFor(int $tenantId, string $kind, string $key, int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('rkey', $qb->createNamedParameter($key)))
			->orderBy('version', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Every version of the team, for the team backup.
	 *
	 * @return list<History> by kind, key and version ascending
	 */
	public function findByTenant(int $tenantId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->orderBy('kind')->addOrderBy('rkey')->addOrderBy('version');
		return $this->findEntities($qb);
	}

	public function findVersion(int $tenantId, string $kind, string $key, int $version): ?History {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('rkey', $qb->createNamedParameter($key)))
			->andWhere($qb->expr()->eq('version', $qb->createNamedParameter($version, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Delete versions older than `$cutoff` – but never the newest one of a
	 * record (the one matching the record's own version).
	 */
	public function prune(int $cutoff): int {
		$total = 0;
		do {
			$qb = $this->db->getQueryBuilder();
			$qb->select('h.id')
				->from($this->getTableName(), 'h')
				->innerJoin('h', 'ts_records', 'r', $qb->expr()->andX(
					$qb->expr()->eq('h.tenant_id', 'r.tenant_id'),
					$qb->expr()->eq('h.kind', 'r.kind'),
					$qb->expr()->eq('h.rkey', 'r.rkey'),
				))
				->where($qb->expr()->lt('h.modified_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->lt('h.version', 'r.version'))
				->setMaxResults(1000);
			$res = $qb->executeQuery();
			$ids = array_map('intval', $res->fetchAll(\PDO::FETCH_COLUMN));
			$res->closeCursor();
			if ($ids === []) {
				break;
			}
			$del = $this->db->getQueryBuilder();
			$del->delete($this->getTableName())
				->where($del->expr()->in('id', $del->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
			$total += $del->executeStatement();
		} while (count($ids) === 1000);
		return $total;
	}
}
