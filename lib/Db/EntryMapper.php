<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Every query filters by the team.
 *
 * @template-extends QBMapper<Entry>
 */
final class EntryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_entries', Entry::class);
	}

	public function findOne(int $tenantId, string $uid, string $source, string $eventUid): ?Entry {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('source', $qb->createNamedParameter($source)))
			->andWhere($qb->expr()->eq('event_uid', $qb->createNamedParameter($eventUid)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * The entries of one source, by event UID; without `$all` only the
	 * live ones (not kept, not deleted). `ics` is left out: the full read
	 * compares etags and only loads what changed.
	 *
	 * @return array<string,Entry> event_uid → entry
	 */
	public function bySource(int $tenantId, string $uid, string $source, bool $all = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'tenant_id', 'uid', 'source', 'event_uid', 'object_uri', 'etag', 'summary', 'codes', 'first_start', 'last_end', 'cancelled', 'kept', 'deleted', 'revision', 'modified_at')
			->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('source', $qb->createNamedParameter($source)));
		if (!$all) {
			$qb->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('kept', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		}
		$out = [];
		foreach ($this->findEntities($qb) as $e) {
			$out[$e->getEventUid()] = $e;
		}
		return $out;
	}

	/**
	 * A page in revision order: `revision > $since`, at most `$limit`.
	 * With `$since = 0` tombstones are left out, as for the records.
	 *
	 * @return list<Entry>
	 */
	public function findChanged(int $tenantId, int $since, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('revision', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
		if ($since <= 0) {
			$qb->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('revision', 'ASC')->addOrderBy('id', 'ASC')->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Objects that are not tombstones, per source.
	 *
	 * @return array<string,int> "<source>/<uid>" → count
	 */
	public function countBySource(int $tenantId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid', 'source')->selectAlias($qb->func()->count('id'), 'n')
			->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->groupBy('uid', 'source');
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[(string)$row['source'] . '/' . (string)$row['uid']] = (int)$row['n'];
		}
		$res->closeCursor();
		return $out;
	}

	public function deleteByTenant(int $tenantId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
