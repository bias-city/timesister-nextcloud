<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * A client's write of a receipt, expense rule or expense report (0.22.0)
 * against the stored record, inside the record transaction under the team
 * lock: the rights, the fields the server owns, what must exist (person,
 * project, budget, cost position, rule), the uniqueness of recurring
 * receipts per period; since 0.23.0 approval by assignment; since 0.30.0
 * what a settled receipt keeps, rules without approval and no client
 * writes of expense reports. A quote keeps its
 * server-written `document`. Files of deleted receipts go to the trash
 * after the commit.
 */
final class CostWrites {
	/** @var list<array{0:string,1:string}> owner and path, trashed after the commit */
	private array $trash = [];
	/** @var list<array{0:string,1:string,2:string}> files moved to their current name, moved back after a rollback */
	private array $moved = [];

	public function __construct(
		private RecordMapper $records,
		private RecordRights $rights,
		private TenantService $team,
		private ITimeFactory $time,
		private DocumentFolder $folders,
		private ReceiptPlace $places,
	) {
	}

	public static function handles(string $kind): bool {
		return in_array($kind, RecordRights::COST_KINDS, true) || $kind === QuoteRules::KIND;
	}

	/**
	 * The data to store for this write (null: a deletion).
	 *
	 * @param array{kind:string,key:string,json:?string,...} $op
	 */
	public function prepare(Membership $m, array $op, ?Record $cur, CostBatch $batch): ?string {
		$before = $cur === null || $cur->isTombstone() ? null : InvoiceRules::decode($cur->getData());
		$after = InvoiceRules::decode($op['json']);
		if ($op['kind'] === QuoteRules::KIND) {
			return $this->quote($before, $after, $op['json']);
		}
		$after = match ($op['kind']) {
			ReceiptRules::KIND => $this->receipt($m, $op['key'], $before, $after, $batch),
			ExpenseRuleRules::KIND => $this->rule($m, $op['key'], $before, $after),
			default => $this->report(),
		};
		return $after === null ? null : Json::encode($after);
	}

	/** The quote's PDF is written by the server only (0.22.0): the stored `document` stays. */
	private function quote(?\stdClass $before, ?\stdClass $after, ?string $json): ?string {
		if ($after === null || $before === null || !property_exists($before, 'document')) {
			if ($after !== null && property_exists($after, 'document')) {
				unset($after->document);
				return Json::encode($after);
			}
			return $json;
		}
		$after->document = $before->document;
		return Json::encode($after);
	}

	// ------------------------------------------------------------ receipt

	private function receipt(Membership $m, string $key, ?\stdClass $before, ?\stdClass $after, CostBatch $batch): ?\stdClass {
		$led = $m->manages() ? [] : $this->rights->ledProjects($m);
		// 0.30.0: on a fixed or paid expense report the receipt is settled.
		$settled = $before !== null && $this->settled($m->tenantId, $before);
		if ($after === null) {
			if ($before === null) {
				return null;
			}
			ReceiptRules::requireWrite($m->manages(), $m->uid, $led, $before, null, $settled);
			if (($before->status ?? null) !== ReceiptRules::OPEN && ($before->status ?? null) !== ReceiptRules::REJECTED) {
				throw ApiException::conflict('A receipt is deleted only while open or rejected.')->code(ErrorCode::RECEIPT_APPROVED_NOT_DELETED);
			}
			$path = $before->file->path ?? null;
			$owner = $this->team->backupOwner($this->team->tenant($m->tenantId));
			if (is_string($path) && $owner !== null) {
				$this->trash[] = [$owner, $path];
			}
			return null;
		}
		$tid = $m->tenantId;
		if (!$m->manages() && !ReceiptRules::ofLed($after, $led)) {
			// Member triage: budget, cost position and invoice effect are the Lead's.
			ReceiptRules::keepAssigned($before, $after);
		}
		$project = null;
		$projectKey = Fields::str($after, 'project');
		if ($projectKey !== null) {
			$project = $this->live($tid, 'project', $projectKey) ?? throw ApiException::invalid('The receipt’s project does not exist.');
		}
		$after->currency ??= $this->currency($tid, $project, $after);
		$team = ReceiptBooking::teamCurrency($this->live($tid, 'setting', 'settings'));
		ReceiptBooking::requireTeamCurrency($after, $team);
		$budgetCurrency = ReceiptBooking::budgetCurrencyOf($project, $after, $team);
		ReceiptBooking::requireBudgetCurrency($after, $team, $budgetCurrency, false);
		$this->requireMember($tid, $after->person ?? null);
		if (($after->kind ?? null) === ReceiptRules::RECURRING
			&& ($before === null || ($before->rule ?? null) !== $after->rule || ($before->period ?? null) !== $after->period)) {
			$this->recurring($tid, $key, $after, $batch);
		}
		ReceiptRules::mergeServer($before, $after, $m->uid, $this->today(), ReceiptRules::initialStatus($after));
		ReceiptRules::requireWrite($m->manages(), $m->uid, $led, $before, $after, $settled);
		if ($project !== null) {
			ReceiptRules::requireCost($after, $project);
		}
		// Approved (also a correction afterwards): a budget in a third currency needs its amount.
		ReceiptBooking::requireBudgetCurrency($after, $team, $budgetCurrency, ReceiptRules::isApproved($after));
		if (($after->status ?? null) === ReceiptRules::OPEN) {
			// Until approval the file follows every change of its name.
			$moved = $this->places->follow($tid, $after);
			if ($moved !== null) {
				$this->moved[] = $moved;
			}
			// 0.23.0: assignment is approval; what is still missing goes into the answer.
			$missing = ReceiptRules::approveByWrite($m->manages(), $m->uid, $led, $after, $team, $budgetCurrency, $this->today());
			if ($missing !== null && $missing !== []) {
				$batch->missing[$key] = $missing;
			}
		}
		return $after;
	}

	/** Rule of the same person (it applies from creation, 0.30.0), period in its form and range, once per rule and period (409 duplicate). */
	private function recurring(int $tid, string $key, \stdClass $after, CostBatch $batch): void {
		$rule = $this->live($tid, ExpenseRuleRules::KIND, (string)$after->rule) ?? throw ApiException::invalid('The expense rule of this receipt does not exist.');
		if (($rule->person ?? null) !== ($after->person ?? null) || ($after->paid_by ?? null) !== ReceiptRules::PERSON) {
			throw ApiException::invalid('A recurring receipt is paid by the person of its expense rule.');
		}
		$period = (string)$after->period;
		if (!ExpenseRuleRules::covers($rule, $period)) {
			throw ApiException::invalid(Message::of('The period {period} is not one of the expense rule.', ['period' => $period]));
		}
		$id = $after->rule . '|' . $period;
		$taken = $batch->recurring[$id] ?? null;
		if ($taken === null) {
			foreach ($this->records->findLiveByKind($tid, ReceiptRules::KIND) as $r) {
				$d = InvoiceRules::decode($r->getData());
				if ($r->getRkey() !== $key && ($d?->rule ?? null) === $after->rule && ($d?->period ?? null) === $period) {
					$taken = $r->getRkey();
				}
			}
		}
		if ($taken !== null && $taken !== $key) {
			throw ApiException::duplicate(Message::of('The receipt {key} is already the one of this rule for {period}.', ['key' => $taken, 'period' => $period]))->code(ErrorCode::RULE_PERIOD_DUPLICATE);
		}
		$batch->recurring[$id] = $key;
	}

	/** Is the receipt on a fixed or paid expense report (0.30.0)? */
	private function settled(int $tid, \stdClass $receipt): bool {
		$report = $receipt->report ?? null;
		return is_string($report) && ExpenseReportRules::holds($this->live($tid, ExpenseReportRules::KIND, $report));
	}

	// --------------------------------------------------------------- rule

	private function rule(Membership $m, string $key, ?\stdClass $before, ?\stdClass $after): ?\stdClass {
		ExpenseRuleRules::requireWrite($m->manages(), $m->uid, $before, $after);
		if ($after === null) {
			// 0.30.0: deleted only while no receipt names it.
			ExpenseRuleRules::requireDelete($before !== null && $this->ruleHasReceipts($m->tenantId, $key));
			return null;
		}
		$this->requireMember($m->tenantId, $after->person);
		$projectKey = Fields::str($after, 'project');
		if ($projectKey !== null) {
			$project = $this->live($m->tenantId, 'project', $projectKey) ?? throw ApiException::invalid('The rule’s project does not exist.');
			if (!ReceiptRules::hasCost($project, (string)$after->budget, (int)$after->cost)) {
				throw ApiException::invalid('The budget of the project has no such cost position.');
			}
		}
		ExpenseRuleRules::mergeServer($before, $after);
		return $after;
	}

	/** Does a live receipt name the rule? */
	private function ruleHasReceipts(int $tid, string $rule): bool {
		foreach ($this->records->findLiveByKind($tid, ReceiptRules::KIND) as $r) {
			if ((InvoiceRules::decode($r->getData())?->rule ?? null) === $rule) {
				return true;
			}
		}
		return false;
	}

	// ------------------------------------------------------------- report

	/** A client's write of an expense report: none since 0.30.0 – fix, documents and reverse make and change it. */
	private function report(): ?\stdClass {
		ExpenseReportRules::requireWrite();
		return null;
	}

	// ------------------------------------------------------------ helpers

	/** The person must be an account of the team (also one who left). */
	private function requireMember(int $tid, mixed $uid): void {
		if ($uid !== null && !array_key_exists((string)$uid, $this->team->members($tid))) {
			throw ApiException::invalid(Message::of('“{uid}” is not an account of the team.', ['uid' => (string)$uid]));
		}
	}

	/** The receipt's currency when it names none: the budget's, else `settings.currency`, else CHF. */
	public function currency(int $tid, ?\stdClass $project, ?\stdClass $receipt): string {
		$budget = $project === null || $receipt === null ? null : IssueRules::budgetOf($project, (string)($receipt->budget ?? ''));
		$settings = $this->live($tid, 'setting', 'settings');
		foreach ([$budget?->currency ?? null, $settings?->currency ?? null] as $c) {
			if (is_string($c) && preg_match(ProjectRules::CURRENCY_PATTERN, $c) === 1) {
				return strtoupper($c);
			}
		}
		return 'CHF';
	}

	private function live(int $tid, string $kind, string $key): ?\stdClass {
		$r = $this->records->findOne($tid, $kind, $key);
		return $r === null || $r->isTombstone() ? null : InvoiceRules::decode($r->getData());
	}

	private function today(): string {
		return gmdate('Y-m-d', $this->time->getTime());
	}

	// ------------------------------------------------------------- commit

	/** After the commit: the files of deleted receipts go to the trash. */
	public function afterCommit(): void {
		$trash = $this->trash;
		$this->trash = [];
		$this->moved = [];
		foreach ($trash as [$owner, $path]) {
			try {
				$this->folders->trash($owner, $path);
			} catch (\Throwable) {
				// The record is gone; a file left behind is harmless.
			}
		}
	}

	/** After a rollback: nothing is trashed, moved files go back. */
	public function discard(): void {
		$this->trash = [];
		foreach (array_reverse($this->moved) as $moved) {
			try {
				$this->places->back($moved);
			} catch (\Throwable) {
				// The record names the old path; the file stays findable under the new one.
			}
		}
		$this->moved = [];
	}
}
