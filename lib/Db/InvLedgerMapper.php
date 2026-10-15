<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The invoice ledger (0.18.0): append only. This mapper inserts and reads;
 * `update()` and `delete()` are overridden to refuse, so no code path
 * changes or removes an entry. The one exception is deleting a whole team
 * ({@see wipeTeam()}), which TeamDeleteService calls after storing the
 * team ZIP – the ledger goes with the team, never alone.
 *
 * @template-extends QBMapper<InvLedger>
 */
final class InvLedgerMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_inv_ledger', InvLedger::class);
	}

	/** The ledger is append-only: no entry changes. */
	public function update(Entity $entity): never {
		throw new \LogicException('The invoice ledger is append-only.');
	}

	/** The ledger is append-only: no entry is removed. */
	public function delete(Entity $entity): never {
		throw new \LogicException('The invoice ledger is append-only.');
	}

	/** The team's last entry (the chain head); null while the ledger is empty. */
	public function last(int $tenantId): ?InvLedger {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->orderBy('seq', 'DESC')->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** How many entries the team's ledger has. */
	public function count(int $tenantId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'n'))->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$n = $result->fetchOne();
		$result->closeCursor();
		return is_numeric($n) ? (int)$n : 0;
	}

	/**
	 * Entries with `seq > $since` in order, at most `$limit`.
	 *
	 * @return list<InvLedger>
	 */
	public function findSince(int $tenantId, int $since, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('seq', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->orderBy('seq', 'ASC')->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * The whole ledger of the team in order (verify, team ZIP).
	 *
	 * @return list<InvLedger>
	 */
	public function findAll(int $tenantId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->orderBy('seq', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Deleting a team (with its ZIP stored first): its ledger goes with it.
	 * The only removal of ledger rows in the code.
	 *
	 * @return int rows
	 */
	public function wipeTeam(int $tenantId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
