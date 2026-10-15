<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\CounterMapper;
use OCA\TimeSister\Db\EntryMapper;
use OCA\TimeSister\Db\InvHour;
use OCA\TimeSister\Db\InvHourMapper;
use OCA\TimeSister\Db\InvInvoice;
use OCA\TimeSister\Db\InvInvoiceMapper;
use OCA\TimeSister\Db\InvLedger;
use OCA\TimeSister\Db\InvLedgerMapper;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\Tenant;
use OCA\TimeSister\Db\TenantMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * Issuing on the server (0.18.0): one transaction takes the number, freezes
 * the invoice, writes its hours, the billing marks and the invoice record
 * (as a batch of records, under the team lock) and appends the ledger
 * entry. Reversal, credit note, paid and unpaid likewise. Team Admins;
 * `fixed` also for the project's Leads. Off (team setting `invoicing`):
 * 404 everywhere.
 */
final class IssueService {
	public const LIMIT_DEFAULT = 500;
	public const LIMIT_MAX = 2000;

	public function __construct(
		private IDBConnection $db,
		private TenantMapper $tenants,
		private RecordMapper $records,
		private RecordService $recordService,
		private InvInvoiceMapper $invoices,
		private InvHourMapper $hours,
		private InvLedgerMapper $ledger,
		private EntryMapper $entries,
		private CounterMapper $counters,
		private TenantService $team,
		private RecordRights $rights,
		private ITimeFactory $time,
	) {
	}

	// ------------------------------------------------------------- guards

	/** 404 when the team issues on the Mac (setting off). */
	private function requireOn(Membership $m): Tenant {
		$t = $this->team->tenant($m->tenantId);
		if (!$t->invoicingOn()) {
			throw ApiException::notFound('Invoicing on the server is off for this team.');
		}
		return $t;
	}

	private function requireAdmin(Membership $m): void {
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins issue, reverse and settle invoices.');
		}
	}

	/** A live record's data, or null. */
	private function live(int $tenantId, string $kind, string $key): ?\stdClass {
		$r = $this->records->findOne($tenantId, $kind, $key);
		return $r === null || $r->isTombstone() ? null : InvoiceRules::decode($r->getData());
	}

	/** The stored invoice record (live) and its data; 404 when there is none. */
	private function invoiceRecord(int $tenantId, string $key): array {
		$r = $this->records->findOne($tenantId, InvoiceRules::KIND, $key);
		$d = $r === null || $r->isTombstone() ? null : InvoiceRules::decode($r->getData());
		if ($r === null || $d === null) {
			throw ApiException::notFound('This invoice does not exist.');
		}
		return [$r, $d];
	}

	/** One transaction, retried on a deadlock like the record writes. @return array<string,mixed> */
	private function transaction(callable $fn): array {
		for ($attempt = 1; ; $attempt++) {
			try {
				$this->db->beginTransaction();
				try {
					$out = $fn();
					$this->db->commit();
					return $out;
				} catch (\Throwable $e) {
					if ($this->db->inTransaction()) {
						$this->db->rollBack();
					}
					throw $e;
				}
			} catch (DbException $e) {
				$retry = in_array($e->getReason(), [DbException::REASON_DEADLOCK, DbException::REASON_LOCK_WAIT_TIMEOUT], true);
				if (!$retry || $attempt >= 3) {
					throw $e;
				}
				usleep(50000 * $attempt);
			}
		}
	}

	// -------------------------------------------------------------- issue

	/** `POST /invoices/{key}/issue`. @return array<string,mixed> the record plus `issue` */
	public function issue(Membership $m, string $key, \stdClass $body): array {
		$this->requireOn($m);
		$this->requireAdmin($m);
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$now = $this->time->getTime();
		$issuedOn = IssueRules::issuedOn($body->issued_on ?? null, $now);
		$dueDays = IssueRules::dueDays($body->due_days ?? null);
		return $this->transaction(fn (): array => $this->issueWithin($m, $key, $issuedOn, $dueDays, $now));
	}

	/** @return array<string,mixed> */
	private function issueWithin(Membership $m, string $key, string $issuedOn, int $dueDays, int $now): array {
		$tid = $m->tenantId;
		[$cur, $d] = $this->invoiceRecord($tid, $key);
		if ($this->invoices->findByKey($tid, $key) !== null) {
			throw ApiException::issued();
		}
		IssueRules::requireIssuable($d);
		$projectKey = (string)$d->project;
		$project = $this->live($tid, 'project', $projectKey) ?? throw ApiException::invalid('The invoice’s project does not exist.');
		$budget = IssueRules::budgetOf($project, (string)$d->budget) ?? throw ApiException::invalid('The invoice’s budget is not in the project.');
		$currency = IssueRules::currencyOf($budget, $d) ?? throw ApiException::invalid('The budget has no currency.');
		$customerKey = IssueRules::customerKeyOf($project) ?? throw ApiException::invalid('The project names no customer.');
		$customer = $this->live($tid, 'customer', $customerKey) ?? throw ApiException::invalid('The project’s customer does not exist.');
		$settings = $this->live($tid, 'setting', 'settings');
		$issuer = IssueRules::issuerOf($settings) ?? throw ApiException::invalid('The team has no invoice issuer; set “issuer” in the settings first.');
		$vat = IssueRules::vatOf($d, $settings);
		$bookings = IssueRules::bookingsOf($d);
		$this->requireBillable($tid, $bookings);
		$positions = IssueRules::positionsOf($d);
		$sums = IssueRules::sums($positions, $vat);
		$hours = IssueRules::hoursOf($bookings);
		$dueOn = IssueRules::dueOn($issuedOn, $dueDays);

		// The team lock first: one issue at a time, the revision for the marks and the record.
		$top = $this->tenants->bumpRevision($tid, 1 + count($bookings));
		$number = $this->takeNumber($tid, $settings, $issuedOn, $projectKey, $customerKey, $now);

		$row = new InvInvoice();
		$row->setTenantId($tid);
		$row->setRkey($key);
		$row->setNumber($number);
		$row->setStatus(InvInvoice::ISSUED);
		$row->setDocumentState(InvInvoice::DOCUMENT_PENDING);
		$row->setProject($projectKey);
		$row->setBudget((string)$d->budget);
		$row->setCustomer($customerKey);
		$row->setPeriodFrom((string)$d->from);
		$row->setPeriodTo((string)$d->to);
		$row->setCurrency($currency);
		$row->setVat(IssueRules::decimal($vat));
		$row->setIssuerJson(Json::encode($issuer));
		$customerBlock = IssueRules::customerBlock($customer, $d);
		$row->setCustomerJson(Json::encode($customerBlock));
		$row->setPositionsJson(json_encode($positions, Json::FLAGS));
		$row->setGreeting(IssueRules::textOf($d, 'greeting'));
		$row->setBody(IssueRules::textOf($d, 'body'));
		$row->setFooter(IssueRules::textOf($d, 'footer'));
		$row->setNet(IssueRules::decimal($sums['net']));
		$row->setVatAmount(IssueRules::decimal($sums['vat_amount']));
		$row->setGross(IssueRules::decimal($sums['gross']));
		$row->setHours(IssueRules::decimal(IssueRules::totalHours($hours)));
		$row->setIssuedOn($issuedOn);
		$row->setIssuedBy($m->uid);
		$row->setDueOn($dueOn);
		$row->setCreatedAt($now);
		$row->setModifiedAt($now);
		$row = $this->invoices->insert($row);
		foreach ($hours as $h) {
			$this->insertHour($tid, $row->getId(), $h);
		}

		$payload = IssueRules::payload($key, $number, $issuedOn, $m->uid, $dueOn, $projectKey, (string)$d->budget, $customerKey, (string)$d->from, (string)$d->to, $currency, $vat, $sums, $issuer, $customerBlock, $positions, $hours, $row->getGreeting(), $row->getBody(), $row->getFooter(), null, null);
		$entry = $this->append($tid, $m->uid, LedgerRules::ISSUED, $row->getId(), $number, LedgerRules::canonical($payload), $now);
		$this->chain($row, $entry, $now);

		$ops = [];
		foreach ($bookings as $b) {
			$ops[] = $this->markOp($tid, (string)$b->person, (string)$b->uid, $issuedOn, $m->uid, $number);
		}
		$d->status = InvInvoice::ISSUED;
		$d->number = $number;
		$d->issued_on = $issuedOn;
		$d->due_on = $dueOn;
		$d->hours = IssueRules::totalHours($hours);
		$d->amount = $sums['net'];
		$d->currency = $currency;
		$d->vat = $vat;
		$d->ledger_seq = $entry->getSeq();
		$d->hash = $entry->getHash();
		$ops[] = $this->invoiceOp($key, $cur, $d);
		$written = $this->recordService->applyWithin($m, $ops, false, $top, true);
		$record = $written['records'][count($ops) - 1];
		$record['issue'] = $this->issueBlock($row, $entry);
		return $record;
	}

	/**
	 * Every booking must be in the book and not billed by another live
	 * invoice: per occurrence through the invoices' hours, and through a
	 * billing mark of the person's event when no server invoice bills an
	 * occurrence of it (a mark set by hand or by a client).
	 *
	 * @param list<\stdClass> $bookings
	 */
	private function requireBillable(int $tenantId, array $bookings): void {
		foreach ($bookings as $i => $b) {
			$person = (string)($b->person ?? '');
			$uid = (string)($b->uid ?? '');
			$start = (string)($b->start ?? '');
			if ($this->entries->findByEvent($tenantId, $person, $uid) === null) {
				throw ApiException::invalid(Message::of('Booking {n} is not in the book.', ['n' => $i + 1]));
			}
			$live = false;
			foreach ($this->hours->billingsOf($tenantId, $person, $uid) as $h) {
				if ($h['status'] !== InvInvoice::ISSUED && $h['status'] !== InvInvoice::PAID) {
					continue;
				}
				if ($h['start'] === $start) {
					throw ApiException::conflict(Message::of('Booking {n} is billed by invoice {number}.', ['n' => $i + 1, 'number' => $h['number']]));
				}
				$live = true;
			}
			if (!$live) {
				$mark = $this->live($tenantId, BillingRules::KIND, BillingRules::key($person, $uid));
				if ($mark !== null) {
					$doc = $mark->document ?? null;
					throw ApiException::conflict(Message::of('Booking {n} is billed by invoice {number}.', ['n' => $i + 1, 'number' => is_string($doc) && $doc !== '' ? $doc : '?']));
				}
			}
		}
	}

	/** The next number of the team's counter, filled into its pattern; unique per team. */
	private function takeNumber(int $tenantId, ?\stdClass $settings, string $issuedOn, string $project, ?string $customer, int $now): string {
		$n = InvoiceNumbering::of($settings);
		$year = (int)substr($issuedOn, 0, 4);
		$no = $this->counters->takeWithin($tenantId, InvoiceNumbering::counter($n['per_year'], $year), $n['start'], $now);
		$number = InvoiceNumbering::fill(InvoiceNumbering::patternOf($settings), $no, $year, $project, $customer);
		if ($this->invoices->findByNumber($tenantId, $number) !== null) {
			throw ApiException::conflict(Message::of('The number {number} is already taken; check the invoice number pattern.', ['number' => $number]));
		}
		return $number;
	}

	/** @param array{uid:string,event_uid:string,start:string,hours:float,rate:float,amount:float,code:?string} $h */
	private function insertHour(int $tenantId, int $invoiceId, array $h): void {
		$row = new InvHour();
		$row->setTenantId($tenantId);
		$row->setInvoiceId($invoiceId);
		$row->setUid($h['uid']);
		$row->setEventUid($h['event_uid']);
		$row->setStart($h['start']);
		$row->setHours(IssueRules::decimal($h['hours']));
		$row->setRate(IssueRules::decimal($h['rate']));
		$row->setAmount(IssueRules::decimal($h['amount']));
		$row->setCode($h['code']);
		$this->hours->insert($row);
	}

	/** Appends the team's next ledger entry; the chain continues from the last one. */
	private function append(int $tenantId, string $actor, string $action, int $invoiceId, string $number, string $canonical, int $now): InvLedger {
		$last = $this->ledger->last($tenantId);
		$seq = $last === null ? 1 : $last->getSeq() + 1;
		$prev = $last === null ? '' : $last->getHash();
		$at = LedgerRules::at($now);
		$payloadHash = LedgerRules::payloadHash($canonical);
		$e = new InvLedger();
		$e->setTenantId($tenantId);
		$e->setSeq($seq);
		$e->setAt($at);
		$e->setActor($actor);
		$e->setAction($action);
		$e->setInvoiceId($invoiceId);
		$e->setNumber($number);
		$e->setPayload($canonical);
		$e->setPayloadHash($payloadHash);
		$e->setPrevHash($prev);
		$e->setHash(LedgerRules::entryHash($seq, $at, $actor, $action, $number, $payloadHash, $prev));
		return $this->ledger->insert($e);
	}

	/** The invoice row carries its issue's chain fields. */
	private function chain(InvInvoice $row, InvLedger $entry, int $now): void {
		$row->setSeq($entry->getSeq());
		$row->setPrevHash($entry->getPrevHash());
		$row->setHash($entry->getHash());
		$row->setModifiedAt($now);
		$this->invoices->update($row);
	}

	/**
	 * A billing mark as a record write (an existing one – a tombstone, or
	 * the mark of another occurrence of a series – is updated).
	 *
	 * @return array{kind:string,key:string,version:int,json:?string,accounts:?list<string>}
	 */
	private function markOp(int $tenantId, string $person, string $uid, string $billedOn, string $by, string $number): array {
		$key = BillingRules::key($person, $uid);
		$data = (object)['person' => $person, 'uid' => $uid, 'billed_on' => $billedOn, 'by' => $by, 'source' => 'timesister', 'document' => $number];
		$v = RecordValidator::validate(BillingRules::KIND, $key, $data);
		$cur = $this->records->findOne($tenantId, BillingRules::KIND, $key);
		return ['kind' => BillingRules::KIND, 'key' => $key, 'version' => $cur?->getVersion() ?? 0, 'json' => $v['json'], 'accounts' => $v['accounts']];
	}

	/** @return array{kind:string,key:string,version:int,json:?string,accounts:?list<string>} */
	private function invoiceOp(string $key, ?Record $cur, \stdClass $d): array {
		$v = RecordValidator::validate(InvoiceRules::KIND, $key, $d);
		return ['kind' => InvoiceRules::KIND, 'key' => $key, 'version' => $cur?->getVersion() ?? 0, 'json' => $v['json'], 'accounts' => $v['accounts']];
	}

	/** @return array{kind:string,key:string,version:int,json:?string,accounts:?list<string>} */
	private function deleteOp(string $kind, Record $cur): array {
		return ['kind' => $kind, 'key' => $cur->getRkey(), 'version' => $cur->getVersion(), 'json' => null, 'accounts' => null];
	}

	/** @return array<string,mixed> */
	private function issueBlock(InvInvoice $row, InvLedger $entry): array {
		return [
			'number' => $row->getNumber(),
			'seq' => $entry->getSeq(),
			'prev_hash' => $entry->getPrevHash(),
			'hash' => $entry->getHash(),
			'net' => (float)$row->getNet(),
			'vat_amount' => (float)$row->getVatAmount(),
			'gross' => (float)$row->getGross(),
			'currency' => $row->getCurrency(),
			'due_on' => $row->getDueOn(),
			'document_state' => $row->getDocumentState(),
		];
	}

	// ------------------------------------------------------------ reverse

	/** `POST /invoices/{key}/reverse`. @return array<string,mixed> `{ record, original, issue }` */
	public function reverse(Membership $m, string $key, \stdClass $body): array {
		$this->requireOn($m);
		$this->requireAdmin($m);
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$now = $this->time->getTime();
		$kind = IssueRules::kind($body->kind ?? null);
		$reason = IssueRules::shortText($body->reason ?? null, 'reason');
		$issuedOn = IssueRules::issuedOn($body->issued_on ?? null, $now);
		$given = $body->positions ?? null;
		if ($given !== null) {
			InvoiceRules::checkPositions($given);
		}
		$given = is_array($given) ? array_values(array_filter($given, static fn (mixed $x): bool => $x instanceof \stdClass)) : null;
		return $this->transaction(fn (): array => $this->reverseWithin($m, $key, $kind, $reason, $issuedOn, $kind === IssueRules::CREDIT ? $given : null, $now));
	}

	/** @param ?list<\stdClass> $given @return array<string,mixed> */
	private function reverseWithin(Membership $m, string $key, string $kind, ?string $reason, string $issuedOn, ?array $given, int $now): array {
		$tid = $m->tenantId;
		[$cur, $d] = $this->invoiceRecord($tid, $key);
		$orig = $this->invoices->findByKey($tid, $key);
		if ($orig === null || !$orig->isLive()) {
			throw ApiException::invalid('Only an issued or paid invoice can be reversed or credited.');
		}
		if ($orig->getReversedBy() !== null) {
			throw ApiException::conflict('This invoice is already reversed.');
		}
		$settings = $this->live($tid, 'setting', 'settings');
		$issuer = InvoiceRules::decode($orig->getIssuerJson()) ?? new \stdClass();
		$customerBlock = InvoiceRules::decode($orig->getCustomerJson()) ?? new \stdClass();
		$origPositions = json_decode((string)$orig->getPositionsJson(), false);
		$origPositions = is_array($origPositions) ? array_values(array_filter($origPositions, static fn (mixed $x): bool => $x instanceof \stdClass)) : [];
		$positions = $given ?? IssueRules::negate($origPositions);
		$vat = (float)$orig->getVat();
		$sums = IssueRules::sums($positions, $vat);
		$dueOn = IssueRules::dueOn($issuedOn, IssueRules::DUE_DAYS);
		$newKey = IssueRules::newKey();
		while ($this->records->findOne($tid, InvoiceRules::KIND, $newKey) !== null) {
			$newKey = IssueRules::newKey();
		}

		// The marks to release (reversal): the original's events, unless another live invoice bills an occurrence.
		$release = [];
		if ($kind === IssueRules::REVERSAL) {
			foreach ($this->hours->findByInvoice($tid, $orig->getId()) as $h) {
				$other = false;
				foreach ($this->hours->billingsOf($tid, $h->getUid(), $h->getEventUid()) as $b) {
					if ($b['invoice_id'] !== $orig->getId() && ($b['status'] === InvInvoice::ISSUED || $b['status'] === InvInvoice::PAID)) {
						$other = true;
					}
				}
				$mark = $this->records->findOne($tid, BillingRules::KIND, BillingRules::key($h->getUid(), $h->getEventUid()));
				if (!$other && $mark !== null && !$mark->isTombstone()) {
					$release[$mark->getRkey()] = $mark;
				}
			}
		}
		$top = $this->tenants->bumpRevision($tid, 1 + ($kind === IssueRules::REVERSAL ? 1 + count($release) : 0));
		$number = $this->takeNumber($tid, $settings, $issuedOn, $orig->getProject(), $orig->getCustomer(), $now);

		$row = new InvInvoice();
		$row->setTenantId($tid);
		$row->setRkey($newKey);
		$row->setNumber($number);
		$row->setStatus(InvInvoice::ISSUED);
		$row->setDocumentState(InvInvoice::DOCUMENT_PENDING);
		$row->setProject($orig->getProject());
		$row->setBudget($orig->getBudget());
		$row->setCustomer($orig->getCustomer());
		$row->setPeriodFrom($orig->getPeriodFrom());
		$row->setPeriodTo($orig->getPeriodTo());
		$row->setCurrency($orig->getCurrency());
		$row->setVat($orig->getVat());
		$row->setIssuerJson($orig->getIssuerJson());
		$row->setCustomerJson($orig->getCustomerJson());
		$row->setPositionsJson(json_encode($positions, Json::FLAGS));
		$row->setGreeting($orig->getGreeting());
		$row->setBody($reason ?? $orig->getBody());
		$row->setFooter($orig->getFooter());
		$row->setNet(IssueRules::decimal($sums['net']));
		$row->setVatAmount(IssueRules::decimal($sums['vat_amount']));
		$row->setGross(IssueRules::decimal($sums['gross']));
		$row->setIssuedOn($issuedOn);
		$row->setIssuedBy($m->uid);
		$row->setDueOn($dueOn);
		if ($kind === IssueRules::REVERSAL) {
			$row->setReverses($key);
		} else {
			$row->setCredits($key);
		}
		$row->setCreatedAt($now);
		$row->setModifiedAt($now);
		$row = $this->invoices->insert($row);

		$payload = IssueRules::payload($newKey, $number, $issuedOn, $m->uid, $dueOn, $orig->getProject(), $orig->getBudget(), $orig->getCustomer(), $orig->getPeriodFrom(), $orig->getPeriodTo(), $orig->getCurrency(), $vat, $sums, $issuer, $customerBlock, $positions, [], $row->getGreeting(), $row->getBody(), $row->getFooter(), $row->getReverses(), $row->getCredits());
		$entry = $this->append($tid, $m->uid, $kind, $row->getId(), $number, LedgerRules::canonical($payload), $now);
		$this->chain($row, $entry, $now);

		// The new invoice as a record: the original's data, negated and renumbered.
		$n = clone $d;
		$n->status = InvInvoice::ISSUED;
		$n->number = $number;
		$n->issued_on = $issuedOn;
		$n->due_on = $dueOn;
		$n->paid_on = null;
		$n->bookings = [];
		$n->hours = 0;
		$n->amount = $sums['net'];
		$n->positions = $positions;
		$n->note = $reason;
		$n->created_by = $m->uid;
		$n->created_on = $issuedOn;
		$n->ledger_seq = $entry->getSeq();
		$n->hash = $entry->getHash();
		unset($n->reversed_by, $n->reverses, $n->credits);
		if ($kind === IssueRules::REVERSAL) {
			$n->reverses = $key;
		} else {
			$n->credits = $key;
		}
		$ops = [$this->invoiceOp($newKey, null, $n)];
		if ($kind === IssueRules::REVERSAL) {
			$orig->setStatus(InvInvoice::CANCELLED);
			$orig->setReversedBy($newKey);
			$orig->setModifiedAt($now);
			$this->invoices->update($orig);
			$this->append($tid, $m->uid, LedgerRules::CANCELLED, $orig->getId(), $orig->getNumber(),
				LedgerRules::canonical(['key' => $key, 'number' => $orig->getNumber(), 'reversed_by' => $newKey, 'reason' => $reason]), $now);
			$d->status = InvInvoice::CANCELLED;
			$d->reversed_by = $newKey;
			$ops[] = $this->invoiceOp($key, $cur, $d);
			foreach ($release as $mark) {
				$ops[] = $this->deleteOp(BillingRules::KIND, $mark);
			}
		}
		$written = $this->recordService->applyWithin($m, $ops, false, $top, true);
		return [
			'record' => $written['records'][0],
			'original' => $kind === IssueRules::REVERSAL ? $written['records'][1] : $this->recordService->present($cur),
			'issue' => $this->issueBlock($row, $entry),
		];
	}

	// --------------------------------------------------------------- paid

	/** `POST /invoices/{key}/paid` and `/unpaid`. @return array<string,mixed> the record plus `ledger` */
	public function paid(Membership $m, string $key, \stdClass $body, bool $paid): array {
		$this->requireOn($m);
		$this->requireAdmin($m);
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$now = $this->time->getTime();
		$paidOn = $paid ? IssueRules::paidOn($body->paid_on ?? null) : null;
		$note = IssueRules::shortText($body->note ?? null, 'note');
		return $this->transaction(fn (): array => $this->paidWithin($m, $key, $paidOn, $note, $now));
	}

	/** @return array<string,mixed> */
	private function paidWithin(Membership $m, string $key, ?string $paidOn, ?string $note, int $now): array {
		$tid = $m->tenantId;
		[$cur, $d] = $this->invoiceRecord($tid, $key);
		$row = $this->invoices->findByKey($tid, $key);
		if ($paidOn !== null && ($row === null || $row->getStatus() !== InvInvoice::ISSUED)) {
			throw ApiException::invalid('Only an issued invoice can be marked paid.');
		}
		if ($paidOn === null && ($row === null || $row->getStatus() !== InvInvoice::PAID)) {
			throw ApiException::invalid('This invoice is not marked paid.');
		}
		$top = $this->tenants->bumpRevision($tid, 1);
		$row->setStatus($paidOn === null ? InvInvoice::ISSUED : InvInvoice::PAID);
		$row->setPaidOn($paidOn);
		$row->setPaidBy($paidOn === null ? null : $m->uid);
		$row->setModifiedAt($now);
		$this->invoices->update($row);
		$entry = $this->append($tid, $m->uid, $paidOn === null ? LedgerRules::UNPAID : LedgerRules::PAID, $row->getId(), $row->getNumber(),
			LedgerRules::canonical(['key' => $key, 'number' => $row->getNumber(), 'paid_on' => $paidOn, 'note' => $note]), $now);
		$d->status = $row->getStatus();
		$d->paid_on = $paidOn;
		$written = $this->recordService->applyWithin($m, [$this->invoiceOp($key, $cur, $d)], false, $top, true);
		$record = $written['records'][0];
		$record['ledger'] = ['seq' => $entry->getSeq(), 'prev_hash' => $entry->getPrevHash(), 'hash' => $entry->getHash()];
		return $record;
	}

	// ------------------------------------------------------------ reading

	/** `GET /invoices/{key}/fixed` – Team Admins and the project's Leads. @return array<string,mixed> */
	public function fixed(Membership $m, string $key): array {
		$this->requireOn($m);
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$r = $this->records->findOne($m->tenantId, InvoiceRules::KIND, $key);
		if (!$m->manages()) {
			$project = InvoiceRules::projectOf($r?->getData());
			if ($project === null || !isset($this->rights->ledProjects($m)[$project])) {
				throw ApiException::forbidden('Only Team Admins and the project’s Leads read the fixed invoice.');
			}
		}
		if ($r === null || $r->isTombstone()) {
			throw ApiException::notFound('This invoice does not exist.');
		}
		$row = $this->invoices->findByKey($m->tenantId, $key);
		if ($row === null) {
			throw ApiException::notFound('Nothing is fixed yet: this invoice is a draft.');
		}
		return $this->fixedOf($row);
	}

	/** The frozen content of a row, as in `GET /invoices/{key}/fixed` and in the team ZIP. @return array<string,mixed> */
	public function fixedOf(InvInvoice $row): array {
		$positions = json_decode((string)$row->getPositionsJson(), false);
		return [
			'key' => $row->getRkey(),
			'number' => $row->getNumber(),
			'status' => $row->getStatus(),
			'project' => $row->getProject(),
			'budget' => $row->getBudget(),
			'customer_key' => $row->getCustomer(),
			'period' => ['from' => $row->getPeriodFrom(), 'to' => $row->getPeriodTo()],
			'issued_on' => $row->getIssuedOn(),
			'issued_by' => $row->getIssuedBy(),
			'due_on' => $row->getDueOn(),
			'paid_on' => $row->getPaidOn(),
			'paid_by' => $row->getPaidBy(),
			'currency' => $row->getCurrency(),
			'vat' => (float)$row->getVat(),
			'net' => (float)$row->getNet(),
			'vat_amount' => (float)$row->getVatAmount(),
			'gross' => (float)$row->getGross(),
			'hours_total' => (float)$row->getHours(),
			'issuer' => InvoiceRules::decode($row->getIssuerJson()),
			'customer' => InvoiceRules::decode($row->getCustomerJson()),
			'positions' => is_array($positions) ? $positions : [],
			'hours' => array_map(static fn (InvHour $h): array => $h->present(), $this->hours->findByInvoice($row->getTenantId(), $row->getId())),
			'greeting' => $row->getGreeting(),
			'body' => $row->getBody(),
			'footer' => $row->getFooter(),
			'chain' => ['seq' => $row->getSeq(), 'prev_hash' => $row->getPrevHash(), 'hash' => $row->getHash()],
			'reverses' => $row->getReverses(),
			'reversed_by' => $row->getReversedBy(),
			'credits' => $row->getCredits(),
			'document_state' => $row->getDocumentState(),
		];
	}

	/** `GET /invoices/ledger?since=&limit=` – Team Admins. @return array{entries:list<array<string,mixed>>,more:bool} */
	public function ledger(Membership $m, mixed $since, mixed $limit): array {
		$this->requireOn($m);
		$this->requireAdmin($m);
		$since = self::number($since, 0, PHP_INT_MAX, 0, 'since');
		$limit = self::number($limit, 1, self::LIMIT_MAX, self::LIMIT_DEFAULT, 'limit');
		$rows = $this->ledger->findSince($m->tenantId, $since, $limit + 1);
		$more = count($rows) > $limit;
		$rows = array_slice($rows, 0, $limit);
		return ['entries' => array_map(static fn (InvLedger $e): array => $e->present(), $rows), 'more' => $more];
	}

	/** `GET /invoices/chain/verify` – Team Admins. @return array<string,mixed> */
	public function verify(Membership $m): array {
		$this->requireOn($m);
		$this->requireAdmin($m);
		return LedgerRules::verify(array_map(static fn (InvLedger $e): array => $e->present(), $this->ledger->findAll($m->tenantId)));
	}

	/** A query number within bounds; missing: the default. */
	private static function number(mixed $v, int $min, int $max, int $default, string $field): int {
		if ($v === null || $v === '') {
			return $default;
		}
		if (is_string($v) && preg_match('/^\d{1,12}$/', $v)) {
			$v = (int)$v;
		}
		if (!is_int($v) || $v < $min || $v > $max) {
			throw ApiException::invalid(Message::of('“{field}” must be an integer from {min} to {max}.', ['field' => $field, 'min' => $min, 'max' => $max]));
		}
		return $v;
	}
}
