<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The table `ts_access` of the former shares matrix (removed in 0.17.0:
 * the app holds the time entries itself, clients read no shared calendars).
 * Nothing writes or reads it any more; the table stays (schema only grows),
 * and its rows go with the team or the account.
 */
final class AccessMapper {
	private const TABLE = 'ts_access';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function deleteByTenant(int $tenantId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/** Account deleted: a new account with the same identifier inherits nothing. */
	public function deleteByUid(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->orX(
				$qb->expr()->eq('viewer', $qb->createNamedParameter($uid)),
				$qb->expr()->eq('owner', $qb->createNamedParameter($uid)),
			));
		$qb->executeStatement();
	}
}
