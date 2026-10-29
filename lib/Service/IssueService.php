<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\CounterMapper;
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
use Psr\Log\LoggerInterface;

/**
 * Issuing on the server (0.18.0): one transaction takes the number, freezes
 * the invoice, writes its hours, the billing marks and the invoice record
 * (as a batch of records, under the team lock) and appends the ledger
 * entry. Reversal, credit note, paid and unpaid likewise. Team Admins;
 * `fixed` also for the project's Leads. Off (team setting `invoicing`):
 * 404 everywhere. After the transaction the document is rendered
 * (0.19.0); a failure there never undoes the issue.
 */
final class IssueService {
	public const LIMIT_DEFAULT = 500;
	public const LIMIT_MAX = 2000;

	public function __construct(
		private TenantMapper $tenants,
		private RecordMapper $records,
		private RecordService $recordService,
		private InvInvoiceMapper $invoices,
		private InvHourMapper $hours,
		private InvLedgerMapper $ledger,
		private CounterMapper $counters,
		private TenantService $team,
		private RecordRights $rights,
		private ITimeFactory $time,
		private DraftResolver $drafts,
		private LedgerAppender $appender,
		private InvoiceFixed $fixed,
		private DocumentService $documents,
		private LoggerInterface $logger,
		private Transaction $tx,
	) {
	}

	// ------------------------------------------------------------- guards

	/** 404 when the team issues on the Mac (setting off). */
	public function requireOn(Membership $m): Tenant {
		$t = $this->team->tenant($m->tenantId);
		if (!$t->invoicingOn()) {
			throw ApiException::notFound('Invoicing on the server is off for this team.');
		}
		return $t;
	}

	public function requireAdmin(Membership $m): void {
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins issue, reverse and settle invoices.')->code(ErrorCode::INVOICE_ISSUE_ADMIN_ONLY);
		}
	}

	/** A live record's data, or null. */
	private function live(int $tenantId, string $kind, string $key): ?\stdClass {
		return $this->drafts->live($tenantId, $kind, $key);
	}

	/** The stored invoice record (live) and its data; 404 when there is none. @return array{0:Record,1:\stdClass} */
	private function invoiceRecord(int $tenantId, string $key): array {
		return $this->drafts->invoiceRecord($tenantId, $key);
	}

	/**
	 * After issuing, reversing or crediting: the document is rendered and
	 * stored (0.19.0). Outside the transaction; an error is logged and
	 * left to the retry job, the issue stands.
	 */
	private function renderAfter(Membership $m, string $key, int $now): void {
		try {
			$row = $this->invoices->findByKey($m->tenantId, $key);
			if ($row !== null) {
				$this->documents->render($this->team->tenant($m->tenantId), $row, $m->uid, $now);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('TimeSister: rendering the document of {key} failed: {message}', ['app' => 'timesister', 'key' => $key, 'message' => $e->getMessage()]);
		}
	}

	/** One transaction, retried on a deadlock like the record writes. @return array<string,mixed> */
	private function transaction(callable $fn): array {
		return $this->tx->run($fn);
	}

	// -------------------------------------------------------------- issue

	/** `POST /invoices/{key}/issue`. @return array<string,mixed> the record plus `issue` */
	public function issue(Membership $m, string $key, \stdClass $body): array {
		$t = $this->requireOn($m);
		$this->requireAdmin($m);
		// 0.24.0: in the mode external the accounting system issues.
		HandoverRules::refuseIssue($t->invoicingMode());
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$now = $this->time->getTime();
		$issuedOn = IssueRules::issuedOn($body->issued_on ?? null, $now);
		// 0.27.0: without `due_days` the customer's payment term counts, then the default.
		$dueDays = IssueRules::dueDaysGiven($body->due_days ?? null);
		$out = $this->transaction(fn (): array => $this->issueWithin($m, $key, $issuedOn, $dueDays, $now));
		$this->renderAfter($m, $key, $now);
		return $out;
	}

	/** @return array<string,mixed> */
	private function issueWithin(Membership $m, string $key, string $issuedOn, ?int $dueDays, int $now): array {
		$tid = $m->tenantId;
		if ($this->invoices->findByKey($tid, $key) !== null) {
			throw ApiException::issued();
		}
		$r = $this->drafts->resolve($tid, $key);
		$cur = $r['record'];
		$d = $r['d'];
		$projectKey = $r['projectKey'];
		$currency = $r['currency'];
		$customerKey = $r['customerKey'];
		$settings = $r['settings'];
		$issuer = $r['issuer'];
		$bookings = $r['bookings'];
		$positions = $r['positions'];
		$sums = $r['sums'];
		$hours = $r['hours'];
		$customerBlock = $r['customerBlock'];
		$dueOn = IssueRules::dueOn($issuedOn, $dueDays ?? CustomerRules::paymentDays($r['customer']) ?? IssueRules::DUE_DAYS);

		// The team lock first: one issue at a time, the revision for the marks and the record.
		$receipts = $r['receipts'];
		$top = $this->tenants->bumpRevision($tid, 1 + count($bookings) + count($receipts));
		[$number, $counterNo] = $this->takeNumber($tid, $settings, $issuedOn, $projectKey, $customerKey, $now);
		// 0.20.0: a QR-IBAN in the currency gets the QR reference from the BESR id, the counter and the year.
		$account = IssueRules::accountOf($issuer, $currency);
		$besr = $issuer->besr_id ?? null;
		$qrReference = $account !== null && QrReferenceRules::isQrIban($account[0]) ? QrReferenceRules::reference(is_string($besr) ? $besr : null, $counterNo, $issuedOn) : null;

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
		$row->setIssuerJson(Json::encode($issuer));
		$row->setCustomerJson(Json::encode($customerBlock));
		$row->setPositionsJson(json_encode($positions, Json::FLAGS));
		$layout = InvoiceLayout::check($d->{InvoiceLayout::FIELD} ?? null);
		$row->setLayoutJson($layout === null ? null : Json::encode($layout));
		$row->setGreeting(IssueRules::textOf($d, 'greeting'));
		$row->setBody(IssueRules::textOf($d, 'body'));
		$row->setFooter(IssueRules::textOf($d, 'footer'));
		self::setSums($row, $sums);
		$row->setHours(IssueRules::decimal(IssueRules::totalHours($hours)));
		$row->setIssuedOn($issuedOn);
		$row->setIssuedBy($m->uid);
		$row->setDueOn($dueOn);
		$row->setCounterNo($counterNo);
		$row->setQrReference($qrReference);
		$row->setCreatedAt($now);
		$row->setModifiedAt($now);
		$row = $this->invoices->insert($row);
		foreach ($hours as $h) {
			$this->insertHour($tid, $row->getId(), $h);
		}

		$payload = IssueRules::payload($key, $number, $issuedOn, $m->uid, $dueOn, $projectKey, (string)$d->budget, $customerKey, (string)$d->from, (string)$d->to, $currency, $sums, $issuer, $customerBlock, $positions, $hours, $row->getGreeting(), $row->getBody(), $row->getFooter(), null, null, $qrReference, $layout);
		$entry = $this->append($tid, $m->uid, LedgerRules::ISSUED, $row->getId(), $number, LedgerRules::canonical($payload), $now);
		$this->chain($row, $entry, $now);

		$ops = [];
		foreach ($bookings as $b) {
			$ops[] = $this->markOp($tid, (string)$b->person, (string)$b->uid, $issuedOn, $m->uid, $number);
		}
		// 0.22.0: the receipts on the invoice are billed by it, in the same transaction.
		foreach (array_keys($receipts) as $rk) {
			$ops[] = $this->receiptOp($tid, $rk, ReceiptRules::BILLED, $key);
		}
		$d->status = InvInvoice::ISSUED;
		$d->number = $number;
		$d->issued_on = $issuedOn;
		$d->due_on = $dueOn;
		$d->hours = IssueRules::totalHours($hours);
		// 0.21.0: the record's amount is what the customer pays.
		$d->amount = $sums->total;
		$d->currency = $currency;
		$d->vat = $sums->rate;
		$d->ledger_seq = $entry->getSeq();
		$d->hash = $entry->getHash();
		$ops[] = $this->invoiceOp($key, $cur, $d);
		$written = $this->recordService->applyWithin($m, $ops, false, $top, true);
		$record = $written['records'][count($ops) - 1];
		$record['issue'] = $this->issueBlock($row, $entry);
		return $record;
	}

	/** The next number of the team's counter, filled into its pattern; unique per team. @return array{0:string,1:int} the number and the counter value */
	private function takeNumber(int $tenantId, ?\stdClass $settings, string $issuedOn, string $project, ?string $customer, int $now): array {
		$n = InvoiceNumbering::of($settings);
		$year = (int)substr($issuedOn, 0, 4);
		$no = $this->counters->takeWithin($tenantId, InvoiceNumbering::counter($n['per_year'], $year), $n['start'], $now);
		$number = InvoiceNumbering::fill(InvoiceNumbering::patternOf($settings), $no, $year, $project, $customer);
		if ($this->invoices->findByNumber($tenantId, $number) !== null) {
			throw ApiException::conflict(Message::of('The number {number} is already taken; check the invoice number pattern.', ['number' => $number]))->code(ErrorCode::INVOICE_NUMBER_TAKEN);
		}
		return [$number, $no];
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
		return $this->appender->append($tenantId, $actor, $action, $invoiceId, $number, $canonical, $now);
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

	/**
	 * A receipt billed by an invoice, or released again (approved, no invoice).
	 *
	 * @return array{kind:string,key:string,version:int,json:?string,accounts:?list<string>}
	 */
	private function receiptOp(int $tenantId, string $key, string $status, ?string $invoice): array {
		$cur = $this->records->findOne($tenantId, ReceiptRules::KIND, $key);
		$d = InvoiceRules::decode($cur?->getData()) ?? throw ApiException::invalid(Message::of('The receipt {key} does not exist.', ['key' => $key]));
		$d->status = $status;
		$d->invoice = $invoice;
		$v = RecordValidator::validate(ReceiptRules::KIND, $key, $d);
		return ['kind' => ReceiptRules::KIND, 'key' => $key, 'version' => $cur?->getVersion() ?? 0, 'json' => $v['json'], 'accounts' => $v['accounts']];
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
			...InvoiceFixed::sumsOf($row)->frozen(),
			'currency' => $row->getCurrency(),
			'due_on' => $row->getDueOn(),
			'document_state' => $row->getDocumentState(),
		];
	}

	/** The frozen sums on the row: the invoice's rate, the taxable part, per rate, pass-through, total. */
	private static function setSums(InvInvoice $row, VatLines $sums): void {
		$row->setVat(IssueRules::decimal($sums->rate));
		$row->setNet(IssueRules::decimal($sums->net));
		$row->setVatAmount(IssueRules::decimal($sums->vatAmount));
		$row->setGross(IssueRules::decimal($sums->gross));
		$row->setVatLinesJson(json_encode($sums->lines, Json::FLAGS));
		$row->setPassThrough(IssueRules::decimal($sums->passThrough));
		$row->setTotal(IssueRules::decimal($sums->total));
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
			// A credit note's own lines are negated lines: the advance signs mirror (0.27.0).
			InvoiceRules::checkPositions($given, true);
		}
		$given = is_array($given) ? array_values(array_filter($given, static fn (mixed $x): bool => $x instanceof \stdClass)) : null;
		$out = $this->transaction(fn (): array => $this->reverseWithin($m, $key, $kind, $reason, $issuedOn, $kind === IssueRules::CREDIT ? $given : null, $now));
		$newKey = $out['record']['key'] ?? null;
		if (is_string($newKey)) {
			$this->renderAfter($m, $newKey, $now);
		}
		return $out;
	}

	/** @param ?list<\stdClass> $given @return array<string,mixed> */
	private function reverseWithin(Membership $m, string $key, string $kind, ?string $reason, string $issuedOn, ?array $given, int $now): array {
		$tid = $m->tenantId;
		[$cur, $d] = $this->invoiceRecord($tid, $key);
		HandoverRules::refuseHandedOver($d);
		$orig = $this->invoices->findByKey($tid, $key);
		if ($orig === null || !$orig->isLive()) {
			throw ApiException::invalid('Only an issued or paid invoice can be reversed or credited.');
		}
		if ($orig->getReversedBy() !== null) {
			throw ApiException::conflict('This invoice is already reversed.')->code(ErrorCode::INVOICE_ALREADY_REVERSED);
		}
		$settings = $this->live($tid, 'setting', 'settings');
		$issuer = InvoiceRules::decode($orig->getIssuerJson()) ?? new \stdClass();
		$customerBlock = InvoiceRules::decode($orig->getCustomerJson()) ?? new \stdClass();
		$origPositions = json_decode((string)$orig->getPositionsJson(), false);
		$origPositions = is_array($origPositions) ? array_values(array_filter($origPositions, static fn (mixed $x): bool => $x instanceof \stdClass)) : [];
		// Negated lines keep their rate and mode; a credit note's own lines without a rate take the original's.
		$taxCase = TaxRules::ofBlock(get_object_vars($customerBlock));
		$positions = VatLines::freeze($given ?? IssueRules::negate($origPositions), (float)$orig->getVat(), $taxCase);
		$sums = VatLines::of($positions, (float)$orig->getVat(), $taxCase);
		$dueOn = IssueRules::dueOn($issuedOn, IssueRules::DUE_DAYS);
		$newKey = IssueRules::newKey();
		while ($this->records->findOne($tid, InvoiceRules::KIND, $newKey) !== null) {
			$newKey = IssueRules::newKey();
		}

		// The marks to release (reversal): the original's events, unless another live invoice bills an occurrence.
		$release = [];
		if ($kind === IssueRules::REVERSAL) {
			$handed = $this->drafts->handedOver($tid);
			foreach ($this->hours->findByInvoice($tid, $orig->getId()) as $h) {
				$other = false;
				foreach ($this->hours->billingsOf($tid, $h->getUid(), $h->getEventUid()) as $b) {
					if ($b['invoice_id'] !== $orig->getId() && ($b['status'] === InvInvoice::ISSUED || $b['status'] === InvInvoice::PAID)) {
						$other = true;
					}
				}
				// 0.24.0: or a handed-over invoice.
				$other = $other || isset($handed[$h->getUid() . "\n" . $h->getEventUid()]);
				$mark = $this->records->findOne($tid, BillingRules::KIND, BillingRules::key($h->getUid(), $h->getEventUid()));
				if (!$other && $mark !== null && !$mark->isTombstone()) {
					$release[$mark->getRkey()] = $mark;
				}
			}
		}
		// 0.22.0: a reversal releases the receipts the original billed.
		$receipts = [];
		if ($kind === IssueRules::REVERSAL) {
			foreach (ReceiptFiles::keysOf($origPositions) as $rk) {
				$rd = $this->live($tid, ReceiptRules::KIND, $rk);
				if (($rd?->status ?? null) === ReceiptRules::BILLED && ($rd?->invoice ?? null) === $key) {
					$receipts[] = $rk;
				}
			}
		}
		$top = $this->tenants->bumpRevision($tid, 1 + ($kind === IssueRules::REVERSAL ? 1 + count($release) + count($receipts) : 0));
		[$number, $counterNo] = $this->takeNumber($tid, $settings, $issuedOn, $orig->getProject(), $orig->getCustomer(), $now);

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
		$row->setIssuerJson($orig->getIssuerJson());
		$row->setCustomerJson($orig->getCustomerJson());
		$row->setPositionsJson(json_encode($positions, Json::FLAGS));
		$row->setGreeting($orig->getGreeting());
		$row->setBody($reason ?? $orig->getBody());
		$row->setFooter($orig->getFooter());
		self::setSums($row, $sums);
		$row->setIssuedOn($issuedOn);
		$row->setIssuedBy($m->uid);
		$row->setDueOn($dueOn);
		// No bill on a reversal or credit note: no QR reference.
		$row->setCounterNo($counterNo);
		if ($kind === IssueRules::REVERSAL) {
			$row->setReverses($key);
		} else {
			$row->setCredits($key);
		}
		$row->setCreatedAt($now);
		$row->setModifiedAt($now);
		$row = $this->invoices->insert($row);

		$payload = IssueRules::payload($newKey, $number, $issuedOn, $m->uid, $dueOn, $orig->getProject(), $orig->getBudget(), $orig->getCustomer(), $orig->getPeriodFrom(), $orig->getPeriodTo(), $orig->getCurrency(), $sums, $issuer, $customerBlock, $positions, [], $row->getGreeting(), $row->getBody(), $row->getFooter(), $row->getReverses(), $row->getCredits());
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
		$n->amount = $sums->total;
		$n->vat = $sums->rate;
		$n->positions = $positions;
		$n->note = $reason;
		$n->created_by = $m->uid;
		$n->created_on = $issuedOn;
		$n->ledger_seq = $entry->getSeq();
		$n->hash = $entry->getHash();
		// The hand-over of the original (0.23.1/0.24.0) is not the new document's.
		unset($n->reversed_by, $n->reverses, $n->credits, $n->external);
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
			foreach ($receipts as $rk) {
				$ops[] = $this->receiptOp($tid, $rk, ReceiptRules::APPROVED, null);
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

	/**
	 * `POST /invoices/{key}/paid` and `/unpaid`. 0.27.0: `amount` records a
	 * payment (a part or, missing, the open rest) in `payments`; the invoice
	 * is paid once the payments reach its amount, `paid_on` is then the day
	 * of the last payment. `/unpaid` takes all payments back.
	 *
	 * @return array<string,mixed> the record plus `ledger`
	 */
	public function paid(Membership $m, string $key, \stdClass $body, bool $paid): array {
		$this->requireOn($m);
		$this->requireAdmin($m);
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$now = $this->time->getTime();
		$paidOn = $paid ? IssueRules::paidOn($body->paid_on ?? null) : null;
		$note = IssueRules::shortText($body->note ?? null, 'note');
		$amount = $paid ? IssueRules::paymentAmount($body->amount ?? null) : null;
		return $this->transaction(fn (): array => $this->paidWithin($m, $key, $paidOn, $note, $amount, $now));
	}

	/** @return array<string,mixed> */
	private function paidWithin(Membership $m, string $key, ?string $paidOn, ?string $note, ?float $amount, int $now): array {
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
		$payments = is_array($d->payments ?? null) ? $d->payments : [];
		$total = is_int($d->amount ?? null) || is_float($d->amount ?? null) ? (float)$d->amount : null;
		$full = true;
		if ($paidOn === null) {
			$payments = [];
		} else {
			// The payment: the given amount, else the open rest (a reversal's negative total settles at once).
			$rest = $total === null ? null : round($total - InvoiceRules::paymentsSum($d), 2);
			$sum = $amount ?? ($rest !== null && $rest > 0 ? $rest : null);
			if ($sum !== null) {
				$p = new \stdClass();
				$p->date = $paidOn;
				$p->amount = $sum;
				$p->note = $note;
				$payments[] = $p;
				usort($payments, static fn (\stdClass $a, \stdClass $b): int => strcmp((string)$a->date, (string)$b->date));
			}
			$d->payments = $payments;
			$full = $amount === null || $total === null || InvoiceRules::paymentsSum($d) + 0.005 >= $total;
			if ($full) {
				$paidOn = $payments === [] ? $paidOn : (string)end($payments)->date;
			}
		}
		$d->payments = $payments;
		$settled = $paidOn !== null && $full;
		$row->setStatus($settled ? InvInvoice::PAID : InvInvoice::ISSUED);
		$row->setPaidOn($settled ? $paidOn : null);
		$row->setPaidBy($settled ? $m->uid : null);
		$row->setModifiedAt($now);
		$this->invoices->update($row);
		$action = $paidOn === null ? LedgerRules::UNPAID : ($full ? LedgerRules::PAID : LedgerRules::PAYMENT);
		$payload = ['key' => $key, 'number' => $row->getNumber(), 'paid_on' => $settled ? $paidOn : null, 'note' => $note];
		if ($amount !== null) {
			$payload['amount'] = $amount;
			$payload['date'] = $paidOn;
		}
		$entry = $this->append($tid, $m->uid, $action, $row->getId(), $row->getNumber(), LedgerRules::canonical($payload), $now);
		$d->status = $row->getStatus();
		$d->paid_on = $settled ? $paidOn : null;
		$written = $this->recordService->applyWithin($m, [$this->invoiceOp($key, $cur, $d)], false, $top, true);
		$record = $written['records'][0];
		$record['ledger'] = ['seq' => $entry->getSeq(), 'prev_hash' => $entry->getPrevHash(), 'hash' => $entry->getHash()];
		return $record;
	}

	// ------------------------------------------------------------ dunning

	/**
	 * `POST /invoices/{key}/dunning {level, on?}` (0.27.0): the dunning level
	 * 0–3 of an issued invoice with the day of the last reminder; no ledger
	 * entry - a reminder is no step of the invoice. Team Admins.
	 *
	 * @return array<string,mixed> the record
	 */
	public function dunning(Membership $m, string $key, \stdClass $body): array {
		$this->requireOn($m);
		$this->requireAdmin($m);
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$now = $this->time->getTime();
		$level = IssueRules::dunningLevel($body->level ?? null);
		$on = $level === 0 ? null : IssueRules::dunnedOn($body->on ?? null, $now);
		return $this->transaction(function () use ($m, $key, $level, $on): array {
			$tid = $m->tenantId;
			[$cur, $d] = $this->invoiceRecord($tid, $key);
			$row = $this->invoices->findByKey($tid, $key);
			if ($row === null || $row->getStatus() !== InvInvoice::ISSUED) {
				throw ApiException::invalid('Only an issued invoice can be dunned.');
			}
			$d->dunning_level = $level;
			$d->dunned_on = $on;
			$written = $this->recordService->applyWithin($m, [$this->invoiceOp($key, $cur, $d)], false, null, true);
			return $written['records'][0];
		});
	}

	// ------------------------------------------------------------ reading

	/** `GET /invoices/{key}/fixed` – Team Admins and the project's Leads. @return array<string,mixed> */
	public function fixed(Membership $m, string $key): array {
		$this->requireOn($m);
		$this->requireReader($m, $key);
		$row = $this->invoices->findByKey($m->tenantId, $key);
		if ($row === null) {
			throw ApiException::notFound('Nothing is fixed yet: this invoice is a draft.');
		}
		return $this->fixedOf($row);
	}

	/** The frozen content of a row, as in `GET /invoices/{key}/fixed` and in the team ZIP. @return array<string,mixed> */
	public function fixedOf(InvInvoice $row): array {
		return $this->fixed->of($row);
	}

	/** The rights of `fixed`, `preview` and `document`: Team Admins and the project's Leads (0.19.0 shares it). */
	public function requireReader(Membership $m, string $key): void {
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$r = $this->records->findOne($m->tenantId, InvoiceRules::KIND, $key);
		if (!$m->manages()) {
			$project = InvoiceRules::projectOf($r?->getData());
			if ($project === null || !isset($this->rights->ledProjects($m)[$project])) {
				throw ApiException::forbidden('Only Team Admins and the project’s Leads read the fixed invoice.')->code(ErrorCode::INVOICE_READ_LEAD_ONLY);
			}
		}
		if ($r === null || $r->isTombstone()) {
			throw ApiException::notFound('This invoice does not exist.');
		}
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
