<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<TimeCalendar> */
final class TimeCalendarMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_time_calendars', TimeCalendar::class);
	}

	public function findOne(int $tenantId, string $uid, string $source): ?TimeCalendar {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('source', $qb->createNamedParameter($source)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<TimeCalendar> all sources of the team, members first */
	public function findByTenant(int $tenantId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->orderBy('source')->addOrderBy('uid');
		return $this->findEntities($qb);
	}

	/** @return list<TimeCalendar> every source of every team, for the job */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('tenant_id')->addOrderBy('source')->addOrderBy('uid');
		return $this->findEntities($qb);
	}

	/**
	 * The named calendars with this dav id – the listener's one lookup.
	 *
	 * @return list<TimeCalendar>
	 */
	public function findByCalendarId(int $calendarId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('calendar_id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('source', $qb->createNamedParameter(TimeCalendar::CALENDAR)));
		return $this->findEntities($qb);
	}

	/**
	 * Every source of an account across teams (account deleted).
	 *
	 * @return list<TimeCalendar>
	 */
	public function findByUid(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		return $this->findEntities($qb);
	}

	/**
	 * Marks every source of the team for a full read.
	 *
	 * @return int rows
	 */
	public function markReread(int $tenantId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('reread', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	public function deleteByTenant(int $tenantId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
