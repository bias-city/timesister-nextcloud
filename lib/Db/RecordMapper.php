<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCA\TimeSister\Service\Json;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Every query filters by the team (`tenant_id`). The only exception is
 * `findPersonsOf()` without a team, for the "account deleted" listener.
 *
 * @template-extends QBMapper<Record>
 */
final class RecordMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_records', Record::class);
	}

	public function findOne(int $tenantId, string $kind, string $key): ?Record {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('rkey', $qb->createNamedParameter($key)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Records with `since < revision <= upTo`. With `since = 0` only live
	 * ones, otherwise including tombstones. Ascending by revision.
	 *
	 * @return list<Record>
	 */
	public function findChanged(int $tenantId, int $since, int $upTo): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('revision', $qb->createNamedParameter($upTo, IQueryBuilder::PARAM_INT)));
		if ($since > 0) {
			$qb->andWhere($qb->expr()->gt('revision', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
		} else {
			$qb->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('revision', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * All live records of one kind in the team, by key.
	 *
	 * @return list<Record>
	 */
	public function findLiveByKind(int $tenantId, string $kind): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('rkey');
		return $this->findEntities($qb);
	}

	/**
	 * Person records of an account: key = identifier, or `accounts`
	 * contains it. Across all teams when `$tenantId` is omitted.
	 *
	 * @return list<Record>
	 */
	public function findPersonsOf(string $uid, ?int $tenantId, bool $liveOnly = false): array {
		$qb = $this->db->getQueryBuilder();
		$needle = '%' . $this->db->escapeLikeParameter(Json::encode($uid)) . '%';
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('kind', $qb->createNamedParameter('person')))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('rkey', $qb->createNamedParameter($uid)),
				$qb->expr()->like('accounts', $qb->createNamedParameter($needle)),
			));
		if ($tenantId !== null) {
			$qb->andWhere($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		}
		if ($liveOnly) {
			$qb->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('rkey');
		// LIKE is only a pre-filter; the list checks exactly.
		return array_values(array_filter(
			$this->findEntities($qb),
			static fn (Record $r) => $r->getRkey() === $uid || in_array($uid, $r->accountList(), true),
		));
	}

	/**
	 * Every record of the team, including tombstones, for the team backup.
	 *
	 * @return list<Record>
	 */
	public function findAll(int $tenantId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->orderBy('kind')->addOrderBy('rkey');
		return $this->findEntities($qb);
	}

	/** @return array{live:int,all:int,persons_account_deleted:int,last:?int} */
	public function stats(int $tenantId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('deleted', 'kind', 'account_deleted_at', 'modified_at')
			->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$live = 0;
		$all = 0;
		$gone = 0;
		$last = null;
		while ($row = $res->fetch()) {
			$all++;
			if ((int)$row['deleted'] === 0) {
				$live++;
				if ($row['kind'] === 'person' && $row['account_deleted_at'] !== null) {
					$gone++;
				}
			}
			$t = (int)$row['modified_at'];
			$last = $last === null ? $t : max($last, $t);
		}
		$res->closeCursor();
		return ['live' => $live, 'all' => $all, 'persons_account_deleted' => $gone, 'last' => $last];
	}

	/** A new revision without a new version: the record reads differently for someone (0.7.6, feed). */
	public function touchRevision(int $id, int $revision): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('revision', $qb->createNamedParameter($revision, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function markAccountDeleted(int $id, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('account_deleted_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('account_deleted_at'));
		$qb->executeStatement();
	}
}
