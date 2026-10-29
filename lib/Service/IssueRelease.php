<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\CounterMapper;
use OCA\TimeSister\Db\InvHourMapper;
use OCA\TimeSister\Db\InvInvoice;
use OCA\TimeSister\Db\InvInvoiceMapper;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\TenantMapper;

/**
 * Releases a reservation (0.32.0): `POST /invoices/{key}/issue/cancel`
 * and the expiry after 15 minutes do the same – the counter steps back
 * (only while the reserved number is its last: `number_not_last`
 * otherwise), the hours and the billing marks of the reservation go, the
 * row is deleted and the record is a draft again; a reversal's or credit
 * note's server-created record becomes a tombstone. Nothing is written to
 * the ledger – the link was never appended, so no gap. The caller holds
 * the transaction; the team lock is taken here.
 */
final class IssueRelease {
	public function __construct(
		private TenantMapper $tenants,
		private RecordMapper $records,
		private RecordService $recordService,
		private InvInvoiceMapper $invoices,
		private InvHourMapper $hours,
		private CounterMapper $counters,
		private DraftResolver $drafts,
	) {
	}

	public function isExpired(InvInvoice $row, int $now): bool {
		return $row->isIssuing() && $row->getIssueExpiresAt() !== null && $row->getIssueExpiresAt() < $now;
	}

	/**
	 * Undoes the reservation; the record writes run as the Team Admin who
	 * reserved (`issued_by`), so the history names them.
	 *
	 * @return array{record:array<string,mixed>,released:array{number:string,seq:int}}
	 */
	public function release(InvInvoice $row, int $now): array {
		$tid = $row->getTenantId();
		$m = new Membership($row->getIssuedBy(), $tid, Role::ADMIN);
		if (!$row->isIssuing()) {
			throw ApiException::conflict('The document or the cancel needs an invoice in status issuing.')->code(ErrorCode::NOT_ISSUING);
		}
		$key = $row->getRkey();
		$cur = $this->records->findOne($tid, InvoiceRules::KIND, $key);
		$d = InvoiceRules::decode($cur?->getData());
		// Gapless numbers: the reserved number must still be the counter's last.
		$settings = $this->drafts->live($tid, 'setting', 'settings');
		$n = InvoiceNumbering::of($settings);
		$counter = InvoiceNumbering::counter($n['per_year'], (int)substr($row->getIssuedOn(), 0, 4));
		$last = $this->counters->last($tid, $counter);
		$no = $row->getCounterNo();
		if ($no === null || $last !== $no) {
			throw ApiException::conflict('Cancelling would leave a gap: the reserved number is no longer the counter’s last. Deliver the PDF instead.',
				['number' => $row->getNumber(), 'last' => $last])->code(ErrorCode::NUMBER_NOT_LAST);
		}
		$ops = [];
		if ($d !== null && $cur !== null) {
			if ($row->getReverses() === null && $row->getCredits() === null) {
				// The reservation's billing marks and receipts go back; the draft keeps its content.
				foreach (IssueRules::bookingsOf($d) as $b) {
					$mark = $this->records->findOne($tid, BillingRules::KIND, BillingRules::key((string)($b->person ?? ''), (string)($b->uid ?? '')));
					$md = InvoiceRules::decode($mark?->getData());
					if ($mark !== null && !$mark->isTombstone() && ($md?->document ?? null) === $row->getNumber()) {
						$ops[] = ['kind' => BillingRules::KIND, 'key' => $mark->getRkey(), 'version' => $mark->getVersion(), 'json' => null, 'accounts' => null];
					}
				}
				foreach (ReceiptFiles::keysOf(IssueRules::positionsOf($d)) as $rk) {
					$rec = $this->records->findOne($tid, ReceiptRules::KIND, $rk);
					$rd = InvoiceRules::decode($rec?->getData());
					if ($rec !== null && $rd !== null && ($rd->status ?? null) === ReceiptRules::BILLED && ($rd->invoice ?? null) === $key) {
						$rd->status = ReceiptRules::APPROVED;
						$rd->invoice = null;
						$v = RecordValidator::validate(ReceiptRules::KIND, $rk, $rd);
						$ops[] = ['kind' => ReceiptRules::KIND, 'key' => $rk, 'version' => $rec->getVersion(), 'json' => $v['json'], 'accounts' => $v['accounts']];
					}
				}
				$d->status = InvoiceRules::DRAFT;
				foreach (['number', 'issued_on', 'due_on', 'hash', 'ledger_seq'] as $f) {
					$d->{$f} = null;
				}
				$v = RecordValidator::validate(InvoiceRules::KIND, $key, $d);
				$ops[] = ['kind' => InvoiceRules::KIND, 'key' => $key, 'version' => $cur->getVersion(), 'json' => $v['json'], 'accounts' => $v['accounts']];
			} elseif (!$cur->isTombstone()) {
				// A reversal or credit note: the server-created record goes; the original was never touched.
				$ops[] = ['kind' => InvoiceRules::KIND, 'key' => $key, 'version' => $cur->getVersion(), 'json' => null, 'accounts' => null];
			}
		}
		$top = $this->tenants->bumpRevision($tid, count($ops));
		$this->counters->giveBack($tid, $counter, $no, $now);
		$this->hours->deleteByInvoice($tid, $row->getId());
		$this->invoices->delete($row);
		$written = $ops === [] ? null : $this->recordService->applyWithin($m, $ops, false, $top, true);
		$record = $written === null || $written['records'] === [] ? [] : $written['records'][array_key_last($written['records'])];
		if ($record === [] && $cur !== null) {
			$record = $this->recordService->present($this->records->findOne($tid, InvoiceRules::KIND, $key) ?? $cur);
		}
		return ['record' => $record, 'released' => ['number' => $row->getNumber(), 'seq' => (int)$row->getIssueSeq()]];
	}
}
