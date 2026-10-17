<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Expense rules (0.22.0, B4a): approving a rule. The reports are fixed by
 * ExpenseReportService (0.23.0).
 */
final class ExpenseService {
	public function __construct(
		private RecordMapper $records,
		private RecordService $recordService,
		private RecordRights $rights,
		private ITimeFactory $time,
	) {
	}

	/** A live record of the kind the caller reads. @return array{0:Record,1:\stdClass} */
	private function load(Membership $m, string $kind, string $key, Message $missing): array {
		RecordValidator::checkAddress($kind, $key);
		$r = $this->records->findOne($m->tenantId, $kind, $key);
		$d = $r === null || $r->isTombstone() ? null : InvoiceRules::decode($r->getData());
		if ($r === null || $d === null) {
			throw new ApiException(404, ApiException::NOT_FOUND, $missing);
		}
		if (!$this->rights->canReadCost($m, $kind, $d)) {
			throw ApiException::forbidden('This account may not read this record.');
		}
		return [$r, $d];
	}

	private function today(): string {
		return gmdate('Y-m-d', $this->time->getTime());
	}

	// --------------------------------------------------------------- rule

	/** `POST /expense-rules/{key}/approve` – Team Admins; with a project also its Lead. @return array<string,mixed> the record */
	public function approveRule(Membership $m, string $key): array {
		[$rec, $d] = $this->load($m, ExpenseRuleRules::KIND, $key, Message::of('This expense rule does not exist.'));
		if (!ExpenseRuleRules::canApprove($m->manages(), $m->manages() ? [] : $this->rights->ledProjects($m), $d)) {
			throw ApiException::forbidden('Expense rules are approved by Team Admins and, with a project, by its Leads.');
		}
		$d->approved_by = $m->uid;
		$d->approved_on = $this->today();
		return $this->recordService->writeAsServer($m, ExpenseRuleRules::KIND, $key, $rec->getVersion(), $d);
	}
}
