<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\InvHourMapper;
use OCA\TimeSister\Db\InvInvoice;
use OCA\TimeSister\Db\InvInvoiceMapper;
use OCA\TimeSister\Db\InvLedger;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\TenantMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Hand-over (0.24.0): in the team setting `invoicing_mode` external the
 * accounting system issues and numbers the invoice. The server records
 * that in one transaction – billing marks, billed receipts, the invoice
 * record with status external, ledger `external_issued` – and takes it
 * back on revoke (`external_revoked`). No frozen row, no number of the
 * team's counter, no document. Team Admins; independent of `invoicing`.
 */
final class HandoverService {
	public function __construct(
		private TenantService $team,
		private TenantMapper $tenants,
		private RecordMapper $records,
		private RecordService $recordService,
		private InvInvoiceMapper $invoices,
		private InvHourMapper $hours,
		private DraftResolver $drafts,
		private LedgerAppender $appender,
		private Transaction $tx,
		private ITimeFactory $time,
		private TeamTree $tree,
		private DocumentFolder $folders,
	) {
	}

	private function requireAdmin(Membership $m): void {
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins hand invoices over to an accounting system.');
		}
	}

	// ----------------------------------------------------------- handover

	/** `POST /invoices/{key}/handover`. @return array{record:array<string,mixed>,ledger:array{seq:int,prev_hash:string,hash:string},billed:array{bookings:int,receipts:int}} */
	public function handover(Membership $m, string $key, \stdClass $body): array {
		$this->requireAdmin($m);
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$own = HandoverRules::requireMode($this->team->tenant($m->tenantId)->invoicingMode());
		$now = $this->time->getTime();
		$v = HandoverRules::body($body, $now);
		HandoverRules::requireSystem($own, $v['system']);
		return $this->tx->run(fn (): array => $this->handoverWithin($m, $key, $v, $now));
	}

	/**
	 * @param array{system:string,id:string,number:string,url:?string,issued_on:string} $v
	 * @return array{record:array<string,mixed>,ledger:array{seq:int,prev_hash:string,hash:string},billed:array{bookings:int,receipts:int}}
	 */
	private function handoverWithin(Membership $m, string $key, array $v, int $now): array {
		$tid = $m->tenantId;
		[, $stored] = $this->drafts->invoiceRecord($tid, $key);
		HandoverRules::refuseHandedOver($stored);
		if (($stored->status ?? null) !== InvoiceRules::DRAFT || ($stored->number ?? null) !== null || $this->invoices->findByKey($tid, $key) !== null) {
			throw ApiException::conflict('Only a draft without a number can be handed over.');
		}
		$h = $this->drafts->head($tid, $key);
		$d = $h['d'];
		[$bookings, , $receipts] = $this->drafts->billables($tid, $key, $d, $h['projectKey']);

		$marks = [];
		foreach ($bookings as $b) {
			$marks[BillingRules::key((string)$b->person, (string)$b->uid)] = $b;
		}
		// The team lock first, as in IssueService.
		$top = $this->tenants->bumpRevision($tid, 1 + count($marks) + count($receipts));
		$payload = HandoverRules::payload($key, $v, $h['projectKey'], (string)$d->budget, $h['customerKey'], $h['currency'], $bookings, array_keys($receipts));
		$entry = $this->appender->append($tid, $m->uid, LedgerRules::EXTERNAL_ISSUED, 0, $v['number'], LedgerRules::canonical($payload), $now);

		$ops = [];
		foreach ($marks as $b) {
			$ops[] = $this->markOp($tid, (string)$b->person, (string)$b->uid, $v, $m->uid);
		}
		foreach (array_keys($receipts) as $rk) {
			$ops[] = $this->receiptOp($tid, $rk, ReceiptRules::BILLED, $key);
		}
		$d->status = HandoverRules::STATUS;
		$d->number = null;
		$d->issued_on = $v['issued_on'];
		$d->{ExportRules::EXTERNAL_FIELD} = HandoverRules::externalBlock($v, $entry->getAt(), $m->uid);
		$ops[] = $this->invoiceOp($key, $h['record'], $d);
		$written = $this->recordService->applyWithin($m, $ops, false, $top, true);
		return [
			'record' => $written['records'][count($ops) - 1],
			'ledger' => self::block($entry),
			'billed' => ['bookings' => count($bookings), 'receipts' => count($receipts)],
		];
	}

	// ------------------------------------------------------------- revoke

	/** `POST /invoices/{key}/handover/revoke`. @return array{record:array<string,mixed>,ledger:array{seq:int,prev_hash:string,hash:string},released:array{bookings:int,receipts:int}} */
	public function revoke(Membership $m, string $key, \stdClass $body): array {
		$this->requireAdmin($m);
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$reason = HandoverRules::reason($body);
		$now = $this->time->getTime();
		return $this->tx->run(fn (): array => $this->revokeWithin($m, $key, $reason, $now));
	}

	/** @return array{record:array<string,mixed>,ledger:array{seq:int,prev_hash:string,hash:string},released:array{bookings:int,receipts:int}} */
	private function revokeWithin(Membership $m, string $key, ?string $reason, int $now): array {
		$tid = $m->tenantId;
		[$cur, $d] = $this->drafts->invoiceRecord($tid, $key);
		if (HandoverRules::handedOverTo($d) === null) {
			throw ApiException::conflict('Only a handed-over invoice can be revoked.');
		}
		$external = $d->{ExportRules::EXTERNAL_FIELD} ?? null;
		$external = $external instanceof \stdClass ? $external : new \stdClass();
		$n = $external->number ?? null;
		$number = is_string($n) ? $n : '';

		// The marks of this invoice, unless another invoice bills an occurrence of the event (series per UID).
		$others = $this->drafts->handedOver($tid, $key);
		$release = [];
		$bookings = IssueRules::bookingsOf($d);
		foreach ($bookings as $b) {
			$person = (string)($b->person ?? '');
			$uid = (string)($b->uid ?? '');
			$mark = $this->records->findOne($tid, BillingRules::KIND, BillingRules::key($person, $uid));
			$md = $mark === null || $mark->isTombstone() ? null : InvoiceRules::decode($mark->getData());
			if ($mark === null || $md === null || ($md->document ?? null) !== $number || isset($others[$person . "\n" . $uid]) || $this->billedOnServer($tid, $person, $uid)) {
				continue;
			}
			$release[$mark->getRkey()] = $mark;
		}
		// The receipts it billed go back to approved, as on a reversal (0.22.0).
		$receipts = [];
		foreach (ReceiptFiles::keysOf(IssueRules::positionsOf($d)) as $rk) {
			$rd = $this->drafts->live($tid, ReceiptRules::KIND, $rk);
			if (($rd?->status ?? null) === ReceiptRules::BILLED && ($rd?->invoice ?? null) === $key) {
				$receipts[] = $rk;
			}
		}
		$top = $this->tenants->bumpRevision($tid, 1 + count($release) + count($receipts));
		$entry = $this->appender->append($tid, $m->uid, LedgerRules::EXTERNAL_REVOKED, 0, $number,
			LedgerRules::canonical(HandoverRules::revokePayload($key, $external, $reason)), $now);

		$ops = [];
		foreach ($release as $mark) {
			$ops[] = ['kind' => BillingRules::KIND, 'key' => $mark->getRkey(), 'version' => $mark->getVersion(), 'json' => null, 'accounts' => null];
		}
		foreach ($receipts as $rk) {
			$ops[] = $this->receiptOp($tid, $rk, ReceiptRules::APPROVED, null);
		}
		$d->status = InvoiceRules::DRAFT;
		$d->{ExportRules::EXTERNAL_FIELD} = null;
		$d->issued_on = null;
		$ops[] = $this->invoiceOp($key, $cur, $d);
		$written = $this->recordService->applyWithin($m, $ops, false, $top, true);
		return [
			'record' => $written['records'][count($ops) - 1],
			'ledger' => self::block($entry),
			'released' => ['bookings' => count($release), 'receipts' => count($receipts)],
		];
	}

	// ----------------------------------------------- the system's PDF (0.24.1)

	/**
	 * `PUT /invoices/{key}/external-document`: the accounting system's PDF of
	 * a handed-over invoice goes to `Buchhaltung/<YYYY>/Rechnungen/` as
	 * `<issued_on>_<system>-<number>_<customer>.pdf`, ledger
	 * `external_document`, the record's `external.document`. The same file
	 * again changes nothing; another one 409.
	 *
	 * @return array{record:array<string,mixed>,document:array{path:string,sha256:string,size:int},stored:bool,ledger:?array{seq:int,prev_hash:string,hash:string}}
	 */
	public function document(Membership $m, string $key, string $content): array {
		$this->requireAdmin($m);
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		HandoverRules::requireArchive($this->team->tenant($m->tenantId)->invoicingMode());
		HandoverRules::requireDocument($content);
		$sha = hash('sha256', $content);
		$tid = $m->tenantId;
		[$cur, $d] = $this->drafts->invoiceRecord($tid, $key);
		$external = $this->handedOver($d, true);
		$same = HandoverRules::documentAgain($external, $sha);
		if ($same !== null) {
			return ['record' => $this->recordService->present($cur), 'document' => (array)$same, 'stored' => false, 'ledger' => null];
		}
		$on = $d->issued_on ?? null;
		$issued = is_string($on) ? $on : gmdate('Y-m-d', $this->time->getTime());
		$place = $this->tree->of($tid);
		$folder = DocumentTree::invoiceFolder($place['root'], $issued);
		$name = HandoverRules::documentName($issued, (string)$external->system, (string)($external->number ?? $external->id), $this->drafts->customerName($tid, $d));
		// The file first, as with TimeSister's own documents; the record and the ledger in one transaction.
		$written = $this->folders->store($place['owner'], $folder, $name, $content, null);
		$now = $this->time->getTime();
		return $this->tx->run(function () use ($m, $key, $sha, $written, $now): array {
			[$cur, $d] = $this->drafts->invoiceRecord($m->tenantId, $key);
			$external = $this->handedOver($d, true);
			$top = $this->tenants->bumpRevision($m->tenantId, 1);
			$entry = $this->appender->append($m->tenantId, $m->uid, LedgerRules::EXTERNAL_DOCUMENT, 0, (string)($external->number ?? ''),
				LedgerRules::canonical(HandoverRules::documentPayload($key, $external, $written, $sha)), $now);
			$doc = ['path' => $written['path'], 'sha256' => $sha, 'size' => $written['size']];
			$external->document = (object)$doc;
			$d->{ExportRules::EXTERNAL_FIELD} = $external;
			$out = $this->recordService->applyWithin($m, [$this->invoiceOp($key, $cur, $d)], false, $top, true);
			return ['record' => $out['records'][0], 'document' => $doc, 'stored' => true, 'ledger' => self::block($entry)];
		});
	}

	/**
	 * `POST /invoices/{key}/external-cancelled`: the accounting system
	 * cancelled the handed-over invoice (read by its plugin's status). The
	 * ledger notes it once (`external_cancelled`), the record keeps status
	 * and PDF and gets `external.cancelled_at`.
	 *
	 * @return array{record:array<string,mixed>,noted:bool,ledger:?array{seq:int,prev_hash:string,hash:string}}
	 */
	public function cancelled(Membership $m, string $key): array {
		$this->requireAdmin($m);
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$now = $this->time->getTime();
		return $this->tx->run(function () use ($m, $key, $now): array {
			[$cur, $d] = $this->drafts->invoiceRecord($m->tenantId, $key);
			$external = $this->handedOver($d, false);
			if (is_string($external->cancelled_at ?? null)) {
				return ['record' => $this->recordService->present($cur), 'noted' => false, 'ledger' => null];
			}
			$top = $this->tenants->bumpRevision($m->tenantId, 1);
			$entry = $this->appender->append($m->tenantId, $m->uid, LedgerRules::EXTERNAL_CANCELLED, 0, (string)($external->number ?? ''),
				LedgerRules::canonical(HandoverRules::cancelPayload($key, $external)), $now);
			$external->cancelled_at = $entry->getAt();
			$d->{ExportRules::EXTERNAL_FIELD} = $external;
			$out = $this->recordService->applyWithin($m, [$this->invoiceOp($key, $cur, $d)], false, $top, true);
			return ['record' => $out['records'][0], 'noted' => true, 'ledger' => self::block($entry)];
		});
	}

	/** The `external` block of a handed-over invoice, else 409. */
	private function handedOver(\stdClass $d, bool $forDocument): \stdClass {
		$external = $d->{ExportRules::EXTERNAL_FIELD} ?? null;
		if (HandoverRules::handedOverTo($d) === null || !$external instanceof \stdClass) {
			throw $forDocument
				? ApiException::conflict('Only a handed-over invoice gets the accounting system’s PDF.')
				: ApiException::conflict('Only a handed-over invoice can be noted as cancelled in the accounting system.');
		}
		return $external;
	}

	/** Does an issued or paid invoice of the server bill an occurrence of the event? */
	private function billedOnServer(int $tenantId, string $person, string $uid): bool {
		foreach ($this->hours->billingsOf($tenantId, $person, $uid) as $h) {
			if ($h['status'] === InvInvoice::ISSUED || $h['status'] === InvInvoice::PAID) {
				return true;
			}
		}
		return false;
	}

	// ---------------------------------------------------------------- ops

	/**
	 * A billing mark of the hand-over: the accounting system's number as
	 * the document, the system as the source.
	 *
	 * @param array{system:string,id:string,number:string,url:?string,issued_on:string} $v
	 * @return array{kind:string,key:string,version:int,json:?string,accounts:?list<string>}
	 */
	private function markOp(int $tenantId, string $person, string $uid, array $v, string $by): array {
		$key = BillingRules::key($person, $uid);
		$data = (object)['person' => $person, 'uid' => $uid, 'billed_on' => $v['issued_on'], 'by' => $by, 'source' => $v['system'], 'external_id' => $v['id'], 'document' => $v['number']];
		$checked = RecordValidator::validate(BillingRules::KIND, $key, $data);
		$cur = $this->records->findOne($tenantId, BillingRules::KIND, $key);
		return ['kind' => BillingRules::KIND, 'key' => $key, 'version' => $cur?->getVersion() ?? 0, 'json' => $checked['json'], 'accounts' => $checked['accounts']];
	}

	/** @return array{kind:string,key:string,version:int,json:?string,accounts:?list<string>} */
	private function receiptOp(int $tenantId, string $key, string $status, ?string $invoice): array {
		$cur = $this->records->findOne($tenantId, ReceiptRules::KIND, $key);
		$d = InvoiceRules::decode($cur?->getData()) ?? throw ApiException::invalid(Message::of('The receipt {key} does not exist.', ['key' => $key]));
		$d->status = $status;
		$d->invoice = $invoice;
		$checked = RecordValidator::validate(ReceiptRules::KIND, $key, $d);
		return ['kind' => ReceiptRules::KIND, 'key' => $key, 'version' => $cur?->getVersion() ?? 0, 'json' => $checked['json'], 'accounts' => $checked['accounts']];
	}

	/** @return array{kind:string,key:string,version:int,json:?string,accounts:?list<string>} */
	private function invoiceOp(string $key, Record $cur, \stdClass $d): array {
		$checked = RecordValidator::validate(InvoiceRules::KIND, $key, $d);
		return ['kind' => InvoiceRules::KIND, 'key' => $key, 'version' => $cur->getVersion(), 'json' => $checked['json'], 'accounts' => $checked['accounts']];
	}

	/** @return array{seq:int,prev_hash:string,hash:string} */
	private static function block(InvLedger $e): array {
		return ['seq' => $e->getSeq(), 'prev_hash' => $e->getPrevHash(), 'hash' => $e->getHash()];
	}
}
