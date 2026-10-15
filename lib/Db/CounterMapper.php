<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCA\TimeSister\Service\InvoiceNumbering;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Counters per team (`ts_counters`, 0.16.0): `last_no` is the last number
 * given. Taking the next one is atomic – the UPDATE locks the row until
 * commit, a concurrent first INSERT fails on the unique index and retries.
 */
final class CounterMapper {
	private const TABLE = 'ts_counters';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** The last number given; null if none yet. */
	public function last(int $tenantId, string $name): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('last_no')->from(self::TABLE)
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)));
		$res = $qb->executeQuery();
		$v = $res->fetchOne();
		$res->closeCursor();
		return $v === false ? null : (int)$v;
	}

	/** Takes the next number, at least `$start`. */
	public function take(int $tenantId, string $name, int $start, int $now): int {
		for ($attempt = 1; ; $attempt++) {
			try {
				return $this->takeOnce($tenantId, $name, $start, $now);
			} catch (DbException $e) {
				$retry = in_array($e->getReason(), [DbException::REASON_DEADLOCK, DbException::REASON_LOCK_WAIT_TIMEOUT, DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION], true);
				if (!$retry || $attempt >= 5) {
					throw $e;
				}
				usleep(20000 * $attempt);
			}
		}
	}

	private function takeOnce(int $tenantId, string $name, int $start, int $now): int {
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE)
				->set('last_no', $qb->func()->add('last_no', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
				->set('modified_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)));
			if ($qb->executeStatement() === 0) {
				$value = InvoiceNumbering::next(null, $start);
				$ins = $this->db->getQueryBuilder();
				$ins->insert(self::TABLE)->values([
					'tenant_id' => $ins->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT),
					'name' => $ins->createNamedParameter($name),
					'last_no' => $ins->createNamedParameter($value, IQueryBuilder::PARAM_INT),
					'modified_at' => $ins->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				]);
				$ins->executeStatement();
			} else {
				$value = (int)$this->last($tenantId, $name);
				if ($value < $start) {
					// The start was raised: continue there.
					$value = $start;
					$set = $this->db->getQueryBuilder();
					$set->update(self::TABLE)
						->set('last_no', $set->createNamedParameter($value, IQueryBuilder::PARAM_INT))
						->where($set->expr()->eq('tenant_id', $set->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
						->andWhere($set->expr()->eq('name', $set->createNamedParameter($name)));
					$set->executeStatement();
				}
			}
			$this->db->commit();
			return $value;
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/** Deleting a team (0.10.1): its counters. @return int rows */
	public function deleteByTenant(int $tenantId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
