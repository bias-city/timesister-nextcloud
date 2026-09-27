<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Tenant> */
final class TenantMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_tenants', Tenant::class);
	}

	public function find(int $id): ?Tenant {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findBySlug(string $slug): ?Tenant {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<Tenant> */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('name')->addOrderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * Increases the revision by `$n` and reads it back in the same
	 * transaction. The UPDATE locks the team row until commit (SQLite: the
	 * whole database), so two writes never get the same revision. Without
	 * FOR UPDATE, which SQLite cannot do. Call only inside a transaction.
	 */
	public function bumpRevision(int $id, int $n): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('revision', $qb->func()->add('revision', $qb->createNamedParameter($n, IQueryBuilder::PARAM_INT)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() !== 1) {
			throw new DoesNotExistException('Team not found');
		}
		return $this->revision($id);
	}

	/** The team's current revision. */
	public function revision(int $id): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('revision')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$v = $res->fetchOne();
		$res->closeCursor();
		if ($v === false) {
			throw new DoesNotExistException('Team not found');
		}
		return (int)$v;
	}

	/** @param list<int> $ids */
	public function markBroken(array $ids, int $now): void {
		if ($ids === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('broken_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->isNull('broken_at'));
		$qb->executeStatement();
	}
}
