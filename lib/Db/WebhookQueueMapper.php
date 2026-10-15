<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The webhook queue (0.20.0). Rows are added after a ledger entry, taken
 * by the delivery job when due, and go only with their team.
 *
 * @template-extends QBMapper<WebhookQueue>
 */
final class WebhookQueueMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_inv_webhook_queue', WebhookQueue::class);
	}

	/**
	 * Deliveries due now: not delivered, fewer than `$maxTries` tries,
	 * `next_at` passed; oldest first.
	 *
	 * @return list<WebhookQueue>
	 */
	public function findDue(int $now, int $maxTries, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->isNull('delivered_at'))
			->andWhere($qb->expr()->lt('tries', $qb->createNamedParameter($maxTries, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('next_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** Every row of a team, newest first (the status endpoint sums them up). @return list<WebhookQueue> */
	public function findByTenant(int $tenantId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** Deleting a team: its queue. @return int rows */
	public function deleteByTenant(int $tenantId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
