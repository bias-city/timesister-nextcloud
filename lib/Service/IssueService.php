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
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Issuing on the server, since 0.32.0 in two steps (one renderer, the Mac
 * app): `issue` reserves number, dates and the next chain link and freezes
 * the content (status `issuing`); the app renders the PDF and
 * `PUT /invoices/{key}/document` closes – the file goes into the tree, the
 * ledger entry carries the reserved link and the document's hash, the
 * invoice is `issued`. `issue/cancel` or 15 minutes without a document
 * release number and link (IssueRelease). `reverse` reserves the same
 * way. Paid, unpaid, dunning, `fixed`, the ledger and the chain check as
 * before. Team Admins; `fixed` also for the project's Leads. Off (team
 * setting `invoicing`): 404 everywhere.
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
		private IssueLock $lock,
		private IssueRelease $release,
		private IConfig $config,
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

	/** The rights of `fixed` and the documents: Team Admins and the project's Leads. */
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

	/** A live record's data, or null. */
	private function live(int $tenantId, string $kind, string $key): ?\stdClass {
		return $this->drafts->live($tenantId, $kind, $key);
	}

	/** The stored invoice record (live) and its data; 404 when there is none. @return array{0:Record,1:\stdClass} */
	private function invoiceRecord(int $tenantId, string $key): array {
		return $this->drafts->invoiceRecord($tenantId, $key);
	}

	/** The document language of the team: `settings.language`, else the server's default. */
	private function lang(int $tenantId): string {
		return DocumentRules::language($this->live($tenantId, 'setting', 'settings'), $this->config->getSystemValueString('default_language', DocumentRules::EN));
	}

	/** One transaction, retried on a deadlock like the record writes. @return array<string,mixed> */
	private function transaction(callable $fn): array {
		return $this->tx->run($fn);
	}

	// -------------------------------------------------------------- issue

	/** `POST /invoices/{key}/issue` – the reservation. @return array<string,mixed> `{ record, fixed, issue }` */
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
		return $this->transaction(fn (): array => $this->reserveWithin($m, $key, $issuedOn, $dueDays, $now));
	}

	/** @return array<string,mixed> */
	private function reserveWithin(Membership $m, string $key, string $issuedOn, ?int $dueDays, int $now): array {
		$tid = $m->tenantId;
		$existing = $this->invoices->findByKey($tid, $key);
		if ($existing !== null) {
			throw $existing->isIssuing() ? IssueLock::pendingOf($existing) : ApiException::issued();
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
		// One issue per team: an expired reservation is released here, an open one answers issue_pending.
		$this->lock->require($tid, $now);
		[$number, $counterNo] = $this->takeNumber($tid, $settings, $issuedOn, $projectKey, $customerKey, $now);
		// 0.20.0: a QR-IBAN in the currency gets the QR reference from the BESR id, the counter and the year.
		$account = IssueRules::accountOf($issuer, $currency);
		$besr = $issuer->besr_id ?? null;
		$qrReference = $account !== null && QrReferenceRules::isQrIban($account[0]) ? QrReferenceRules::reference(is_string($besr) ? $besr : null, $counterNo, $issuedOn) : null;

		$row = new InvInvoice();
		$row->setTenantId($tid);
		$row->setRkey($key);
		$row->setNumber($number);
		$row->setStatus(InvInvoice::ISSUING);
		$row->setDocumentState('none');
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
		$this->reserveLink($row, $now);
		$row->setCreatedAt($now);
		$row->setModifiedAt($now);
		$row = $this->invoices->insert($row);
		foreach ($hours as $h) {
			$this->insertHour($tid, $row->getId(), $h);
		}

		$ops = [];
		foreach ($bookings as $b) {
			$ops[] = $this->markOp($tid, (string)$b->person, (string)$b->uid, $issuedOn, $m->uid, $number);
		}
		// 0.22.0: the receipts on the invoice are billed by it, in the same transaction.
		foreach (array_keys($receipts) as $rk) {
			$ops[] = $this->receiptOp($tid, $rk, ReceiptRules::BILLED, $key);
		}
		$d->status = InvInvoice::ISSUING;
		$d->number = $number;
		$d->issued_on = $issuedOn;
		$d->due_on = $dueOn;
		$d->hours = IssueRules::totalHours($hours);
		// 0.21.0: the record's amount is what the customer pays.
		$d->amount = $sums->total;
		$d->currency = $currency;
		$d->vat = $sums->rate;
		$d->ledger_seq = $row->getIssueSeq();
		$d->hash = null;
		$ops[] = $this->invoiceOp($key, $cur, $d);
		$written = $this->recordService->applyWithin($m, $ops, false, $top, true);
		return $this->reservation($written['records'][count($ops) - 1], $row);
	}

	/** The next free chain link, reserved on the row: head + 1, the head's hash, the expiry. */
	private function reserveLink(InvInvoice $row, int $now): void {
		$last = $this->ledger->last($row->getTenantId());
		$row->setIssueSeq($last === null ? 1 : $last->getSeq() + 1);
		$row->setIssuePrevHash($last === null ? '' : $last->getHash());
		$row->setIssueExpiresAt($now + IssueRules::EXPIRY);
	}

	/** The answer of `issue` and `reverse`: the record, the frozen content and the reservation. @param array<string,mixed> $record @return array<string,mixed> */
	private function reservation(array $record, InvInvoice $row): array {
		$lang = $this->lang($row->getTenantId());
		return ['record' => $record, 'fixed' => $this->fixed->of($row, $lang), 'issue' => $this->reservationBlock($row, $lang)];
	}

	/** @return array<string,mixed> */
	private function reservationBlock(InvInvoice $row, string $lang): array {
		$seq = (int)$row->getIssueSeq();
		$prev = (string)$row->getIssuePrevHash();
		$customer = InvoiceRules::decode($row->getCustomerJson());
		return [
			'number' => $row->getNumber(),
			'issued_on' => $row->getIssuedOn(),
			'due_on' => $row->getDueOn(),
			'seq' => $seq,
			'prev_hash' => $prev,
			'position' => $seq . ' of ' . $seq,
			'chain_line' => IssueRules::chainLine($lang, $row->getNumber(), $seq, $prev),
			'net' => (float)$row->getNet(),
			'vat_amount' => (float)$row->getVatAmount(),
			'gross' => (float)$row->getGross(),
			'total' => InvoiceFixed::sumsOf($row)->total,
			'currency' => $row->getCurrency(),
			'tax_case' => TaxRules::ofBlock($customer === null ? [] : get_object_vars($customer)),
			'qr_reference' => $row->getQrReference(),
			'language' => $lang,
			'expires_at' => LedgerRules::at((int)$row->getIssueExpiresAt()),
		];
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

	// -------------------------------------------------------------- close

	/**
	 * `PUT /invoices/{key}/document` (`kind` pdf): the app's PDF closes the
	 * reservation. The checks in the contract's order: rights, the record,
	 * the status, the size, the magic, number and chain line in the bytes.
	 *
	 * @return array<string,mixed> `{ record, issue, ledger, stored }`
	 */
	public function close(Membership $m, string $key, string $bytes): array {
		$t = $this->requireOn($m);
		$this->requireAdmin($m);
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$tid = $m->tenantId;
		$this->invoiceRecord($tid, $key);
		$row = $this->invoices->findByKey($tid, $key);
		if ($row === null) {
			throw self::notIssuing();
		}
		$lang = $this->lang($tid);
		$sha = hash('sha256', $bytes);
		if (!$row->isIssuing()) {
			$pdf = $row->document(DocumentRules::KIND_PDF);
			if ($pdf !== null) {
				// Idempotent: the same bytes again answer the stored close; other bytes are refused.
				if ($pdf['sha256'] !== $sha) {
					throw ApiException::conflict('The PDF of this invoice is filed already; it stays.')->code(ErrorCode::DOCUMENT_EXISTS);
				}
				return $this->closed($this->recordService->present($this->records->findOne($tid, InvoiceRules::KIND, $key) ?? throw ApiException::notFound('This invoice does not exist.')), $row, null, false);
			}
			// An issued invoice without a filed PDF (≤ 0.31.0, the render failed): the app's PDF once, as a `document` entry.
			DocumentRules::requirePdf($bytes);
			DocumentRules::requireMentions($bytes, [$row->getNumber()]);
			return $this->repair($m, $t, $row, $bytes, $sha);
		}
		DocumentRules::requirePdf($bytes);
		DocumentRules::requireMentions($bytes, [$row->getNumber(), IssueRules::chainLine($lang, $row->getNumber(), (int)$row->getIssueSeq(), (string)$row->getIssuePrevHash())]);
		// The file first (outside the transaction, as the other documents); the ledger and the rows in one transaction.
		$written = $this->documents->file($t, $row, $bytes, DocumentRules::KIND_PDF);
		$now = $this->time->getTime();
		return $this->transaction(fn (): array => $this->closeWithin($m, $key, $written, $sha, $now));
	}

	/** @param array{path:string,file_id:int,size:int,name:string} $written @return array<string,mixed> */
	private function closeWithin(Membership $m, string $key, array $written, string $sha, int $now): array {
		$tid = $m->tenantId;
		[$cur, $d] = $this->invoiceRecord($tid, $key);
		$row = $this->invoices->findByKey($tid, $key);
		if ($row === null || !$row->isIssuing()) {
			throw self::notIssuing();
		}
		$kind = $row->getReverses() !== null ? LedgerRules::REVERSAL : ($row->getCredits() !== null ? LedgerRules::CREDIT : LedgerRules::ISSUED);
		$orig = null;
		$origCur = null;
		$origD = null;
		$release = [];
		$receipts = [];
		if ($kind === LedgerRules::REVERSAL) {
			$origKey = (string)$row->getReverses();
			[$origCur, $origD] = $this->invoiceRecord($tid, $origKey);
			$orig = $this->invoices->findByKey($tid, $origKey) ?? throw ApiException::notFound('This invoice does not exist.');
			[$release, $receipts] = $this->releasedByReversal($tid, $orig, $origKey);
		}
		$top = $this->tenants->bumpRevision($tid, 1 + ($kind === LedgerRules::REVERSAL ? 1 + count($release) + count($receipts) : 0));

		$document = IssueRules::documentBlock(DocumentRules::KIND_PDF, $written['name'], $written['size'], $sha);
		$payload = $this->payloadOf($row) + ['document' => $document];
		$entry = $this->appender->appendReserved($tid, (int)$row->getIssueSeq(), (string)$row->getIssuePrevHash(), $m->uid, $kind, $row->getId(), $row->getNumber(), LedgerRules::canonical($payload), $now);
		$row->setStatus(InvInvoice::ISSUED);
		$row->setSeq($entry->getSeq());
		$row->setPrevHash($entry->getPrevHash());
		$row->setHash($entry->getHash());
		$row->setDocumentPath($written['path']);
		$row->setDocumentSha256($sha);
		$row->setPdfFileId($written['file_id']);
		$row->setDocumentsJson(json_encode([$document + ['path' => $written['path']]], Json::FLAGS));
		$row->setIssueSeq(null);
		$row->setIssuePrevHash(null);
		$row->setIssueExpiresAt(null);
		$row->setModifiedAt($now);
		$this->invoices->update($row);

		$d->status = InvInvoice::ISSUED;
		$d->hash = $entry->getHash();
		$d->documents = [Json::object($document + ['path' => $written['path']])];
		$ops = [$this->invoiceOp($key, $cur, $d)];
		if ($orig !== null && $origCur !== null && $origD !== null) {
			// The original becomes cancelled now (not at the reservation), its marks and receipts are released.
			$orig->setStatus(InvInvoice::CANCELLED);
			$orig->setReversedBy($key);
			$orig->setModifiedAt($now);
			$this->invoices->update($orig);
			$this->appender->append($tid, $m->uid, LedgerRules::CANCELLED, $orig->getId(), $orig->getNumber(),
				LedgerRules::canonical(['key' => $orig->getRkey(), 'number' => $orig->getNumber(), 'reversed_by' => $key, 'reason' => $row->getBody()]), $now);
			$origD->status = InvInvoice::CANCELLED;
			$origD->reversed_by = $key;
			$ops[] = $this->invoiceOp($orig->getRkey(), $origCur, $origD);
			foreach ($release as $mark) {
				$ops[] = $this->deleteOp(BillingRules::KIND, $mark);
			}
			foreach ($receipts as $rk) {
				$ops[] = $this->receiptOp($tid, $rk, ReceiptRules::APPROVED, null);
			}
		}
		$written2 = $this->recordService->applyWithin($m, $ops, false, $top, true);
		return $this->closed($written2['records'][0], $row, $entry, true);
	}

	/** The answer of a close: the record, the issue with its document, the ledger entry. @param array<string,mixed> $record @return array<string,mixed> */
	private function closed(array $record, InvInvoice $row, ?InvLedger $entry, bool $stored): array {
		$pdf = $row->document(DocumentRules::KIND_PDF);
		return [
			'record' => $record,
			'issue' => [
				'number' => $row->getNumber(), 'seq' => $row->getSeq(), 'prev_hash' => $row->getPrevHash(), 'hash' => $row->getHash(),
				'document' => $pdf,
			],
			'ledger' => $entry === null
				? ['seq' => $row->getSeq(), 'prev_hash' => $row->getPrevHash(), 'hash' => $row->getHash()]
				: ['seq' => $entry->getSeq(), 'prev_hash' => $entry->getPrevHash(), 'hash' => $entry->getHash()],
			'stored' => $stored,
		];
	}

	/** The ledger payload of an issue, reversal or credit note, rebuilt from the frozen row (0.18.0 shape). @return array<string,mixed> */
	private function payloadOf(InvInvoice $row): array {
		$positions = json_decode((string)$row->getPositionsJson(), false);
		$positions = is_array($positions) ? array_values(array_filter($positions, static fn (mixed $x): bool => $x instanceof \stdClass)) : [];
		$hours = array_map(static fn (InvHour $h): array => $h->present(), $this->hours->findByInvoice($row->getTenantId(), $row->getId()));
		$layout = $row->getLayoutJson() === null ? null : InvoiceRules::decode($row->getLayoutJson());
		return IssueRules::payload($row->getRkey(), $row->getNumber(), $row->getIssuedOn(), $row->getIssuedBy(), $row->getDueOn(), $row->getProject(), $row->getBudget(), $row->getCustomer(),
			$row->getPeriodFrom(), $row->getPeriodTo(), $row->getCurrency(), InvoiceFixed::sumsOf($row), InvoiceRules::decode($row->getIssuerJson()) ?? new \stdClass(),
			InvoiceRules::decode($row->getCustomerJson()) ?? new \stdClass(), $positions, $hours, $row->getGreeting(), $row->getBody(), $row->getFooter(),
			$row->getReverses(), $row->getCredits(), $row->getQrReference(), $layout);
	}

	/**
	 * The marks and receipts a reversal releases: the original's events,
	 * unless another live or handed-over invoice bills an occurrence; the
	 * receipts the original billed.
	 *
	 * @return array{0:array<string,Record>,1:list<string>}
	 */
	private function releasedByReversal(int $tid, InvInvoice $orig, string $origKey): array {
		$release = [];
		$handed = $this->drafts->handedOver($tid);
		foreach ($this->hours->findByInvoice($tid, $orig->getId()) as $h) {
			$other = false;
			foreach ($this->hours->billingsOf($tid, $h->getUid(), $h->getEventUid()) as $b) {
				if ($b['invoice_id'] !== $orig->getId() && ($b['status'] === InvInvoice::ISSUED || $b['status'] === InvInvoice::PAID)) {
					$other = true;
				}
			}
			$other = $other || isset($handed[$h->getUid() . "\n" . $h->getEventUid()]);
			$mark = $this->records->findOne($tid, BillingRules::KIND, BillingRules::key($h->getUid(), $h->getEventUid()));
			if (!$other && $mark !== null && !$mark->isTombstone()) {
				$release[$mark->getRkey()] = $mark;
			}
		}
		$receipts = [];
		$origPositions = json_decode((string)$orig->getPositionsJson(), false);
		foreach (ReceiptFiles::keysOf(is_array($origPositions) ? array_values(array_filter($origPositions, static fn (mixed $x): bool => $x instanceof \stdClass)) : []) as $rk) {
			$rd = $this->live($tid, ReceiptRules::KIND, $rk);
			if (($rd?->status ?? null) === ReceiptRules::BILLED && ($rd?->invoice ?? null) === $origKey) {
				$receipts[] = $rk;
			}
		}
		return [$release, $receipts];
	}

	/** The repair case: an issued invoice of ≤ 0.31.0 without a filed PDF takes the app's PDF once, with a `document` entry as 0.19.0. @return array<string,mixed> */
	private function repair(Membership $m, Tenant $t, InvInvoice $row, string $bytes, string $sha): array {
		$written = $this->documents->file($t, $row, $bytes, DocumentRules::KIND_PDF);
		$now = $this->time->getTime();
		return $this->transaction(function () use ($m, $row, $written, $sha, $now): array {
			$tid = $m->tenantId;
			$key = $row->getRkey();
			[$cur, $d] = $this->invoiceRecord($tid, $key);
			$row = $this->invoices->findByKey($tid, $key) ?? throw self::notIssuing();
			if ($row->document(DocumentRules::KIND_PDF) !== null) {
				throw ApiException::conflict('The PDF of this invoice is filed already; it stays.')->code(ErrorCode::DOCUMENT_EXISTS);
			}
			$top = $this->tenants->bumpRevision($tid, 1);
			$entry = $this->appender->append($tid, $m->uid, DocumentRules::ACTION, $row->getId(), $row->getNumber(), LedgerRules::canonical([
				'key' => $key, 'number' => $row->getNumber(), 'kind' => DocumentRules::KIND_PDF, 'name' => $written['name'], 'path' => $written['path'], 'size' => $written['size'], 'sha256' => $sha,
			]), $now);
			$document = IssueRules::documentBlock(DocumentRules::KIND_PDF, $written['name'], $written['size'], $sha) + ['path' => $written['path']];
			$row->setDocumentPath($written['path']);
			$row->setDocumentSha256($sha);
			$row->setPdfFileId($written['file_id']);
			$row->setDocumentsJson(json_encode([$document, ...array_values(array_filter($row->documents(), static fn (array $x): bool => $x['kind'] !== DocumentRules::KIND_PDF))], Json::FLAGS));
			$row->setModifiedAt($now);
			$this->invoices->update($row);
			$d->documents = array_map(static fn (array $x): \stdClass => Json::object($x), $row->documents());
			$record = $this->recordService->applyWithin($m, [$this->invoiceOp($key, $cur, $d)], false, $top, true)['records'][0];
			return $this->closed($record, $row, $entry, true);
		});
	}

	/** `409 not_issuing`. */
	private static function notIssuing(): ApiException {
		return ApiException::conflict('The document or the cancel needs an invoice in status issuing.')->code(ErrorCode::NOT_ISSUING);
	}

	// ---------------------------------------------------------------- xml

	/**
	 * `PUT /invoices/{key}/document?kind=xml`: the app's XRechnung file of
	 * an issued invoice, filed next to the PDF with a `document` entry.
	 *
	 * @return array<string,mixed> `{ record, document, ledger, stored }`
	 */
	public function storeXml(Membership $m, string $key, string $bytes): array {
		$t = $this->requireOn($m);
		$this->requireAdmin($m);
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$tid = $m->tenantId;
		$this->invoiceRecord($tid, $key);
		$row = $this->invoices->findByKey($tid, $key) ?? throw ApiException::notFound('Nothing is fixed yet: this invoice is a draft.');
		if ($row->isIssuing() || $row->document(DocumentRules::KIND_PDF) === null) {
			throw self::documentMissing();
		}
		$sha = hash('sha256', $bytes);
		$xml = $row->document(DocumentRules::KIND_XML);
		if ($xml !== null) {
			if ($xml['sha256'] !== $sha) {
				throw ApiException::conflict('The e-invoice XML of this invoice is filed already; it stays.')->code(ErrorCode::DOCUMENT_EXISTS);
			}
			return $this->xmlAnswer($this->recordService->present($this->records->findOne($tid, InvoiceRules::KIND, $key) ?? throw ApiException::notFound('This invoice does not exist.')), $row, null, false);
		}
		DocumentRules::requireXml($bytes);
		DocumentRules::requireMentions($bytes, [$row->getNumber()]);
		$written = $this->documents->file($t, $row, $bytes, DocumentRules::KIND_XML);
		$now = $this->time->getTime();
		return $this->transaction(function () use ($m, $key, $written, $sha, $now): array {
			$tid = $m->tenantId;
			[$cur, $d] = $this->invoiceRecord($tid, $key);
			$row = $this->invoices->findByKey($tid, $key) ?? throw self::documentMissing();
			if ($row->document(DocumentRules::KIND_XML) !== null) {
				throw ApiException::conflict('The e-invoice XML of this invoice is filed already; it stays.')->code(ErrorCode::DOCUMENT_EXISTS);
			}
			$top = $this->tenants->bumpRevision($tid, 1);
			$entry = $this->appender->append($tid, $m->uid, DocumentRules::ACTION, $row->getId(), $row->getNumber(), LedgerRules::canonical([
				'key' => $key, 'number' => $row->getNumber(), 'kind' => DocumentRules::KIND_XRECHNUNG, 'name' => $written['name'], 'path' => $written['path'], 'size' => $written['size'], 'sha256' => $sha,
			]), $now);
			$document = IssueRules::documentBlock(DocumentRules::KIND_XML, $written['name'], $written['size'], $sha) + ['path' => $written['path']];
			$row->setXmlPath($written['path']);
			$row->setXmlSha256($sha);
			$row->setXmlFileId($written['file_id']);
			$row->setDocumentsJson(json_encode([...$row->documents(), $document], Json::FLAGS));
			$row->setModifiedAt($now);
			$this->invoices->update($row);
			$d->documents = array_map(static fn (array $x): \stdClass => Json::object($x), $row->documents());
			$record = $this->recordService->applyWithin($m, [$this->invoiceOp($key, $cur, $d)], false, $top, true)['records'][0];
			return $this->xmlAnswer($record, $row, $entry, true);
		});
	}

	/** @param array<string,mixed> $record @return array<string,mixed> */
	private function xmlAnswer(array $record, InvInvoice $row, ?InvLedger $entry, bool $stored): array {
		$xmlEntry = $entry;
		if ($xmlEntry === null) {
			// The same bytes again: the entry that filed the XML, found by its hash in the payload.
			foreach (array_reverse($this->ledger->findAll($row->getTenantId())) as $e) {
				if ($e->getAction() === DocumentRules::ACTION && $e->getInvoiceId() === $row->getId() && str_contains((string)$e->getPayload(), (string)$row->getXmlSha256())) {
					$xmlEntry = $e;
					break;
				}
			}
		}
		return [
			'record' => $record,
			'document' => $row->document(DocumentRules::KIND_XML),
			'ledger' => $xmlEntry === null ? null : ['seq' => $xmlEntry->getSeq(), 'prev_hash' => $xmlEntry->getPrevHash(), 'hash' => $xmlEntry->getHash()],
			'stored' => $stored,
		];
	}

	/** `409 document_missing`: no filed PDF yet. */
	public static function documentMissing(): ApiException {
		return ApiException::conflict('This invoice has no filed PDF yet.')->code(ErrorCode::DOCUMENT_MISSING);
	}

	// ------------------------------------------------------------- cancel

	/** `POST /invoices/{key}/issue/cancel` – releases number and chain link of an `issuing` invoice. @return array<string,mixed> `{ record, released }` */
	public function cancel(Membership $m, string $key): array {
		$this->requireOn($m);
		$this->requireAdmin($m);
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$now = $this->time->getTime();
		return $this->transaction(function () use ($m, $key, $now): array {
			$tid = $m->tenantId;
			$this->invoiceRecord($tid, $key);
			$row = $this->invoices->findByKey($tid, $key);
			if ($row === null || !$row->isIssuing()) {
				throw self::notIssuing();
			}
			return $this->release->release($row, $now);
		});
	}

	/** The job IssueExpiry: reservations past their expiry are released like a cancel. @return array{released:int,kept:int} */
	public function expire(int $limit): array {
		$n = ['released' => 0, 'kept' => 0];
		$now = $this->time->getTime();
		foreach ($this->invoices->findExpired($now, $limit) as $row) {
			try {
				$this->transaction(fn (): array => $this->release->release($row, $now));
				$n['released']++;
			} catch (\Throwable $e) {
				// `number_not_last`: the reservation stays until its PDF arrives; anything else is logged too.
				$n['kept']++;
				$this->logger->warning('TimeSister: reservation {number} not released: {message}', ['app' => 'timesister', 'number' => $row->getNumber(), 'message' => $e->getMessage()]);
			}
		}
		return $n;
	}

	// ------------------------------------------------------------ reverse

	/** `POST /invoices/{key}/reverse` – reserves the reversal or credit note. @return array<string,mixed> `{ record, original, fixed, issue }` */
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
		return $this->transaction(fn (): array => $this->reverseWithin($m, $key, $kind, $reason, $issuedOn, $kind === IssueRules::CREDIT ? $given : null, $now));
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
		$top = $this->tenants->bumpRevision($tid, 1);
		$this->lock->require($tid, $now);
		[$number, $counterNo] = $this->takeNumber($tid, $settings, $issuedOn, $orig->getProject(), $orig->getCustomer(), $now);

		$row = new InvInvoice();
		$row->setTenantId($tid);
		$row->setRkey($newKey);
		$row->setNumber($number);
		$row->setStatus(InvInvoice::ISSUING);
		$row->setDocumentState('none');
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
		$this->reserveLink($row, $now);
		$row->setCreatedAt($now);
		$row->setModifiedAt($now);
		$row = $this->invoices->insert($row);

		// The new invoice as a record: the original's data, negated and renumbered; the original stays until the close.
		$n = clone $d;
		$n->status = InvInvoice::ISSUING;
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
		$n->ledger_seq = $row->getIssueSeq();
		$n->hash = null;
		// The hand-over of the original (0.23.1/0.24.0) and its documents are not the new document's.
		unset($n->reversed_by, $n->reverses, $n->credits, $n->external, $n->documents, $n->payments, $n->dunning_level, $n->dunned_on);
		if ($kind === IssueRules::REVERSAL) {
			$n->reverses = $key;
		} else {
			$n->credits = $key;
		}
		$written = $this->recordService->applyWithin($m, [$this->invoiceOp($newKey, null, $n)], false, $top, true);
		return $this->reservation($written['records'][0], $row) + ['original' => $this->recordService->present($cur)];
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
		$entry = $this->appender->append($tid, $m->uid, $action, $row->getId(), $row->getNumber(), LedgerRules::canonical($payload), $now);
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
		return $this->fixed->of($row, $this->lang($row->getTenantId()));
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

	/** `GET /invoices/chain/verify[?files=true]` – Team Admins; with `files` every filed PDF and XML is re-hashed against the ledger. @return array<string,mixed> */
	public function verify(Membership $m, bool $files = false): array {
		$t = $this->requireOn($m);
		$this->requireAdmin($m);
		$entries = array_map(static fn (InvLedger $e): array => $e->present(), $this->ledger->findAll($m->tenantId));
		$out = LedgerRules::verify($entries);
		if (!$files || !$out['ok']) {
			return $out;
		}
		$break = $this->documents->verifyFiles($t, DocumentRules::hashedFiles($entries));
		return $break === null ? $out + ['files' => true] : $break + ['entries' => $out['entries']];
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
