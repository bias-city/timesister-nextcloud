<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\InvInvoiceMapper;
use OCA\TimeSister\Db\TenantMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * `POST /invoices/{key}/assign` (0.38.0): the budget of an issued, paid or
 * handed-over invoice is an assignment for controlling, not part of what
 * was fixed – it changes here, with a ledger entry `assign`, the record and
 * the frozen row in one transaction. Team Admins and the project's Leads.
 */
final class InvoiceAssignService {
	public function __construct(
		private DraftResolver $drafts,
		private InvInvoiceMapper $invoices,
		private TenantMapper $tenants,
		private RecordService $recordService,
		private RecordRights $rights,
		private LedgerAppender $appender,
		private Transaction $tx,
		private ITimeFactory $time,
	) {
	}

	/** @return array{record:array<string,mixed>,ledger:?array{seq:int,prev_hash:string,hash:string}} */
	public function assign(Membership $m, string $key, \stdClass $body): array {
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$budget = InvoiceRules::assignedBudget($body);
		$now = $this->time->getTime();
		return $this->tx->run(fn (): array => $this->assignWithin($m, $key, $budget, $now));
	}

	/** @return array{record:array<string,mixed>,ledger:?array{seq:int,prev_hash:string,hash:string}} */
	private function assignWithin(Membership $m, string $key, ?string $budget, int $now): array {
		$tid = $m->tenantId;
		try {
			[$cur, $d] = $this->drafts->invoiceRecord($tid, $key);
		} catch (ApiException $e) {
			// As for reading: a Lead learns nothing about invoices they may not see.
			throw !$m->manages() && $e->getStatus() === 404 ? ApiException::forbidden('Only Team Admins and the project’s Leads write invoices.')->code(ErrorCode::INVOICE_LEAD_ONLY) : $e;
		}
		InvoiceRules::requireAssign($m->manages(), $m->manages() ? [] : $this->rights->ledProjects($m), $d, $budget);
		if ($budget !== null) {
			$project = $this->drafts->live($tid, 'project', (string)$d->project);
			if ($project === null || IssueRules::budgetOf($project, $budget) === null) {
				throw ApiException::invalid('The invoice’s budget is not in the project.')->field('budget');
			}
		}
		$before = InvoiceRules::keyOf($d->budget ?? null);
		if ($before === $budget) {
			// Nothing changes: no ledger entry, the record as it is.
			return ['record' => $this->recordService->present($cur), 'ledger' => null];
		}
		$top = $this->tenants->bumpRevision($tid, 1);
		$row = $this->invoices->findByKey($tid, $key);
		$number = $row?->getNumber() ?? InvoiceRules::numberOf($d);
		$entry = $this->appender->append($tid, $m->uid, LedgerRules::ASSIGN, $row?->getId() ?? 0, $number,
			LedgerRules::canonical(InvoiceRules::assignPayload($key, $number, $before, $budget)), $now);
		if ($row !== null) {
			$row->setBudget($budget);
			$row->setModifiedAt($now);
			$this->invoices->update($row);
		}
		$d->budget = $budget;
		$v = RecordValidator::validate(InvoiceRules::KIND, $key, $d);
		$op = ['kind' => InvoiceRules::KIND, 'key' => $key, 'version' => $cur->getVersion(), 'json' => $v['json'], 'accounts' => $v['accounts']];
		$written = $this->recordService->applyWithin($m, [$op], false, $top, true);
		return [
			'record' => $written['records'][0],
			'ledger' => ['seq' => $entry->getSeq(), 'prev_hash' => $entry->getPrevHash(), 'hash' => $entry->getHash()],
		];
	}
}
