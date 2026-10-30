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
 * only through IssueService (status, paid, reversed_by, chain fields);
 * since 0.32.0 a row is first `issuing` (reserved) and deleted again when
 * the reservation is cancelled or expires.
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

	/** The row with this id in the team, or null. */
	public function findById(int $tenantId, int $id): ?InvInvoice {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
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
	 * The frozen invoices issued in a period (0.20.0, exports): bounds
	 * inclusive, null unbounded; by issue date, then number.
	 *
	 * @return list<InvInvoice>
	 */
	public function findIssuedBetween(int $tenantId, ?string $from, ?string $to): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		if ($from !== null) {
			$qb->andWhere($qb->expr()->gte('issued_on', $qb->createNamedParameter($from)));
		}
		if ($to !== null) {
			$qb->andWhere($qb->expr()->lte('issued_on', $qb->createNamedParameter($to)));
		}
		$qb->orderBy('issued_on', 'ASC')->addOrderBy('number', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * The number of an invoice of this project and budget that bills
	 * bookings – `issuing`, `issued` or `paid` with rows in `ts_inv_hours` –,
	 * or null (0.33.0, the lock of the fee model).
	 */
	public function billingBudget(int $tenantId, string $project, string $budget): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('i.number')
			->from($this->getTableName(), 'i')
			->innerJoin('i', 'ts_inv_hours', 'h', $qb->expr()->andX(
				$qb->expr()->eq('h.invoice_id', 'i.id'),
				$qb->expr()->eq('h.tenant_id', 'i.tenant_id'),
			))
			->where($qb->expr()->eq('i.tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('i.project', $qb->createNamedParameter($project)))
			->andWhere($qb->expr()->eq('i.budget', $qb->createNamedParameter($budget)))
			->andWhere($qb->expr()->in('i.status', $qb->createNamedParameter([InvInvoice::ISSUING, InvInvoice::ISSUED, InvInvoice::PAID], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('i.id', 'ASC')->setMaxResults(1);
		$res = $qb->executeQuery();
		$n = $res->fetchOne();
		$res->closeCursor();
		return $n === false || $n === null ? null : (string)$n;
	}

	/** The team's open reservation (0.32.0): at most one invoice is `issuing` per team. */
	public function findIssuing(int $tenantId): ?InvInvoice {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(InvInvoice::ISSUING)))
			->orderBy('id', 'ASC')->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Reservations past their expiry, across all teams, oldest first – for
	 * the job IssueExpiry (0.32.0).
	 *
	 * @return list<InvInvoice>
	 */
	public function findExpired(int $now, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(InvInvoice::ISSUING)))
			->andWhere($qb->expr()->lt('issue_expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
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
