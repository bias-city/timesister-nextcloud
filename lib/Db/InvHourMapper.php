<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The hours of issued invoices (0.18.0). Every query filters by the team.
 * Rows are written when issuing and never changed.
 *
 * @template-extends QBMapper<InvHour>
 */
final class InvHourMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_inv_hours', InvHour::class);
	}

	/** @return list<InvHour> in the order they were written */
	public function findByInvoice(int $tenantId, int $invoiceId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('invoice_id', $qb->createNamedParameter($invoiceId, IQueryBuilder::PARAM_INT)))
			->orderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * The rows billing an event (every occurrence), with the invoice they
	 * belong to – to find the live invoice that already bills an
	 * occurrence, and to keep a series' billing mark while another live
	 * invoice still bills one of its occurrences.
	 *
	 * @return list<array{invoice_id:int,start:string,number:string,status:string,rkey:string}>
	 */
	public function billingsOf(int $tenantId, string $uid, string $eventUid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('h.invoice_id', 'h.start', 'i.number', 'i.status', 'i.rkey')
			->from($this->getTableName(), 'h')
			->innerJoin('h', 'ts_inv_invoices', 'i', $qb->expr()->eq('h.invoice_id', 'i.id'))
			->where($qb->expr()->eq('h.tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('h.uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('h.event_uid', $qb->createNamedParameter($eventUid)))
			->orderBy('h.id');
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[] = ['invoice_id' => (int)$row['invoice_id'], 'start' => (string)$row['start'], 'number' => (string)$row['number'], 'status' => (string)$row['status'], 'rkey' => (string)$row['rkey']];
		}
		$res->closeCursor();
		return $out;
	}

	/** Deleting a team: every row of it. @return int rows */
	/** A cancelled reservation (0.32.0): the hours of one invoice. @return int rows */
	public function deleteByInvoice(int $tenantId, int $invoiceId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('invoice_id', $qb->createNamedParameter($invoiceId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	public function deleteByTenant(int $tenantId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}
}
