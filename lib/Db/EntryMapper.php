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
	/** The columns of {@see Entry} without `ics`; named, since the unused `category` of 0.33.0's drafts stays in the table. */
	private const COLUMNS = ['id', 'tenant_id', 'uid', 'source', 'event_uid', 'object_uri', 'etag', 'summary', 'codes', 'first_start', 'last_end', 'level', 'cancelled', 'kept', 'deleted', 'revision', 'modified_at'];

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_entries', Entry::class);
	}

	public function findOne(int $tenantId, string $uid, string $source, string $eventUid): ?Entry {
		$qb = $this->db->getQueryBuilder();
		$qb->select(...self::COLUMNS)->addSelect('ics')->from($this->getTableName())
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
	 * The live object of a person's event (0.18.0): calendar or feed, not
	 * a tombstone (a kept one counts – it is billed). Null when the event
	 * is not in the book.
	 */
	public function findByEvent(int $tenantId, string $uid, string $eventUid): ?Entry {
		$qb = $this->db->getQueryBuilder();
		$qb->select(...self::COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('event_uid', $qb->createNamedParameter($eventUid)))
			->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('id')->setMaxResults(1);
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
		$qb->select(...self::COLUMNS)
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
	 * With `$since = 0` tombstones are left out, as for the records. `ics`
	 * is left out (0.28.0): the role filter needs only uid and codes, the
	 * texts follow for the hits with {@see icsByIds()}.
	 *
	 * @return list<Entry>
	 */
	public function findChanged(int $tenantId, int $since, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(...self::COLUMNS)
			->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('revision', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
		if ($since <= 0) {
			$qb->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		}
		$qb->orderBy('revision', 'ASC')->addOrderBy('id', 'ASC')->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * The texts of the given rows, at most 2000 at a time.
	 *
	 * @param list<int> $ids
	 * @return array<int,string> id → ics
	 */
	public function icsByIds(array $ids): array {
		$out = [];
		foreach (array_chunk($ids, 500) as $part) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'ics')->from($this->getTableName())
				->where($qb->expr()->in('id', $qb->createNamedParameter($part, IQueryBuilder::PARAM_INT_ARRAY)));
			$res = $qb->executeQuery();
			while ($row = $res->fetch()) {
				$out[(int)$row['id']] = (string)$row['ics'];
			}
			$res->closeCursor();
		}
		return $out;
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

	/**
	 * The persons with live entries from lists (0.28.0).
	 *
	 * @return list<string>
	 */
	public function listUids(int $tenantId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('uid')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('source', $qb->createNamedParameter(TimeCalendar::LIST)))
			->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('uid');
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[] = (string)$row['uid'];
		}
		$res->closeCursor();
		return $out;
	}

	/**
	 * The texts of the live entries of one source, in event UID order.
	 *
	 * @return list<string>
	 */
	public function icsBySource(int $tenantId, string $uid, string $source): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ics')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('source', $qb->createNamedParameter($source)))
			->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kept', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('event_uid');
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			if ((string)$row['ics'] !== '') {
				$out[] = (string)$row['ics'];
			}
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
