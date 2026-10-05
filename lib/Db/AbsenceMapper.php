<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Absence> */
final class AbsenceMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_absences', Absence::class);
	}

	/** @return list<Absence> */
	public function byTenant(int $tenantId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->orderBy('uid')->addOrderBy('start');
		return $this->findEntities($qb);
	}

	/**
	 * The complete state of one person: everything of hers goes, the given
	 * items come – in one transaction.
	 *
	 * @param list<array{source_uid:string,kind:string,start:string,end:string}> $items
	 */
	public function replace(int $tenantId, string $uid, array $items, int $now): void {
		$this->db->beginTransaction();
		try {
			$this->deleteFor($tenantId, $uid);
			foreach ($items as $i) {
				$a = new Absence();
				$a->setTenantId($tenantId);
				$a->setUid($uid);
				$a->setSourceUid($i['source_uid']);
				$a->setKind($i['kind']);
				$a->setStart($i['start']);
				$a->setEnd($i['end']);
				$a->setUpdatedAt($now);
				$this->insert($a);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function deleteFor(int $tenantId, string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$qb->executeStatement();
	}

	public function deleteByTenant(int $tenantId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/** Account deleted: its absences go; the calendar shows them cancelled with the next run. */
	public function deleteByUid(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$qb->executeStatement();
	}
}
