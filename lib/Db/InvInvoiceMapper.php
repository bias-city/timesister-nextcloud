<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Frozen invoices (0.18.0). Every query filters by the team. Rows change
 * only through IssueService (status, paid, reversed_by, chain fields).
 *
 * @template-extends QBMapper<InvInvoice>
 */
final class InvInvoiceMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_inv_invoices', InvInvoice::class);
	}

	/** The frozen row of a record key; null while the invoice is a draft. */
	public function findByKey(int $tenantId, string $rkey): ?InvInvoice {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('rkey', $qb->createNamedParameter($rkey)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findByNumber(int $tenantId, string $number): ?InvInvoice {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('number', $qb->createNamedParameter($number)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Every frozen invoice of the team, by number (team ZIP).
	 *
	 * @return list<InvInvoice>
	 */
	public function findAll(int $tenantId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->orderBy('number');
		return $this->findEntities($qb);
	}

	/**
	 * Invoices whose document is still to render (0.19.0): `pending` or
	 * `failed` with fewer than `$maxTries` tries, across all teams, oldest
	 * first – for the retry job.
	 *
	 * @return list<InvInvoice>
	 */
	public function findToRender(int $maxTries, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('document_state', $qb->createNamedParameter([InvInvoice::DOCUMENT_PENDING, InvInvoice::DOCUMENT_FAILED], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lt('document_tries', $qb->createNamedParameter($maxTries, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')->setMaxResults($limit);
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
