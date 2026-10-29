<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\CounterMapper;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\TenantMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * `POST /quotes/{key}/issue` (0.32.0, `quotes: 4`): Team Admins and the
 * quote's Leads give a draft its number from the team's quote counter and
 * pattern (`settings.quote_number`, default `OF-{year}-{no}` per year),
 * its date and the status `quoted`, and freeze the offered part –
 * budgets, texts, attachments, customer, sums – as the server field
 * `issued`. A quote is no accounting record: nothing goes into the ledger.
 */
final class QuoteIssueService {
	public function __construct(
		private TenantMapper $tenants,
		private RecordMapper $records,
		private RecordService $recordService,
		private RecordRights $rights,
		private CounterMapper $counters,
		private ITimeFactory $time,
		private Transaction $tx,
	) {
	}

	/** The stored quote, readable by the caller; 404 when there is none. @return array{0:Record,1:\stdClass} */
	public function load(Membership $m, string $key): array {
		RecordValidator::checkAddress(QuoteRules::KIND, $key);
		$r = $this->records->findOne($m->tenantId, QuoteRules::KIND, $key);
		if (!$this->rights->canRead($m, QuoteRules::KIND, $key, [], $this->recordService->ownKeys($m), $r)) {
			throw ApiException::forbidden('Only Team Admins and the quote’s or project’s Leads issue a quote and store its documents.')->code(ErrorCode::QUOTE_LEAD_ONLY);
		}
		$d = $r === null || $r->isTombstone() ? null : InvoiceRules::decode($r->getData());
		if ($r === null || $d === null) {
			throw ApiException::notFound('This quote does not exist.');
		}
		return [$r, $d];
	}

	/** The write rule of `quotes: 2` with its code: Team Admins and the quote's or project's Leads. */
	public function requireLead(Membership $m, \stdClass $d): void {
		QuoteRules::requireLead($m->manages(), $d, $this->recordService->ownKeys($m), $m->manages() ? [] : $this->rights->ledProjects($m));
	}

	/** @return array<string,mixed> the record */
	public function issue(Membership $m, string $key, \stdClass $body): array {
		[, $d] = $this->load($m, $key);
		$this->requireLead($m, $d);
		$now = $this->time->getTime();
		$v = QuoteRules::issueBody($body, $now);
		return $this->tx->run(function () use ($m, $key, $v, $now): array {
			$tid = $m->tenantId;
			$top = $this->tenants->bumpRevision($tid, 1);
			[$rec, $d] = $this->load($m, $key);
			if (($d->status ?? null) !== 'draft') {
				throw ApiException::conflict('Only a draft quote is issued; a changed offer is a new quote with its own number.')->code(ErrorCode::QUOTE_NOT_DRAFT);
			}
			$name = $d->name ?? null;
			if (!is_string($name) || trim($name) === '') {
				throw QuoteRules::nameRequired();
			}
			$number = is_string($d->number ?? null) && $d->number !== '' ? $d->number : $this->takeNumber($tid, $d, $v['date'], $now);
			$customerKey = is_string($d->customer ?? null) ? $d->customer : null;
			$c = $customerKey === null ? null : $this->records->findOne($tid, 'customer', $customerKey);
			$customerName = Fields::str($c === null || $c->isTombstone() ? null : InvoiceRules::decode($c->getData()), 'name');
			$d->number = $number;
			$d->date = $v['date'];
			if ($v['valid_until'] !== null) {
				$d->valid_until = $v['valid_until'];
			}
			$d->status = 'quoted';
			$d->issued = QuoteRules::issuedCopy($d, LedgerRules::at($now), $m->uid, $number, $v['date'], is_string($d->valid_until ?? null) ? $d->valid_until : null, $v['hours'], $v['amount'], ['key' => $customerKey, 'name' => $customerName]);
			$x = RecordValidator::validate(QuoteRules::KIND, $key, $d);
			$op = ['kind' => QuoteRules::KIND, 'key' => $key, 'version' => $rec->getVersion(), 'json' => $x['json'], 'accounts' => $x['accounts']];
			return $this->recordService->applyWithin($m, [$op], false, $top, true)['records'][0];
		});
	}

	/** The next quote number from the counter and pattern; `409 quote_number_taken` when another quote carries it. */
	private function takeNumber(int $tid, \stdClass $d, string $date, int $now): string {
		$settings = InvoiceRules::decode($this->records->findOne($tid, 'setting', 'settings')?->getData());
		$n = InvoiceNumbering::of($settings, QuoteRules::NUMBER_FIELD);
		$year = (int)substr($date, 0, 4);
		$no = $this->counters->takeWithin($tid, InvoiceNumbering::counter($n['per_year'], $year, QuoteRules::NUMBER_COUNTER), $n['start'], $now);
		$draft = ($d->project_draft ?? null) instanceof \stdClass ? Fields::str($d->project_draft, 'code') : null;
		$project = Fields::str($d, 'project') ?? $draft ?? '';
		$number = InvoiceNumbering::fill(InvoiceNumbering::patternOf($settings, QuoteRules::NUMBER_FIELD), $no, $year, $project, Fields::str($d, 'customer'), QuoteRules::NUMBER_DEFAULT);
		foreach ($this->records->findLiveByKind($tid, QuoteRules::KIND) as $q) {
			if ((InvoiceRules::decode($q->getData())?->number ?? null) === $number) {
				throw ApiException::conflict(Message::of('The quote number {number} is already taken; check the quote number pattern.', ['number' => $number]))->code(ErrorCode::QUOTE_NUMBER_TAKEN);
			}
		}
		return $number;
	}
}
