<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Correction> */
final class CorrectionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_entry_corrections', Correction::class);
	}

	/**
	 * The corrections of a team, newest first – of one person and event
	 * when given; at most `$limit`.
	 *
	 * @return list<Correction>
	 */
	public function find(int $tenantId, ?string $uid, ?string $eventUid, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		if ($uid !== null) {
			$qb->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		}
		if ($eventUid !== null) {
			$qb->andWhere($qb->expr()->eq('event_uid', $qb->createNamedParameter($eventUid)));
		}
		$qb->orderBy('id', 'DESC')->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** Deleting a team: every row of it. @return int rows */
	public function deleteByTenant(int $tenantId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
