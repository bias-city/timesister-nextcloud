<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\InvLedger;
use OCA\TimeSister\Db\InvLedgerMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Appends to a team's invoice ledger: the next `seq`, the previous entry's
 * hash and the entry hash (LedgerRules), then queues the webhooks
 * (0.20.0). Shared by issuing (0.18.0), the documents, the hand-over, the
 * expense reports and the exports; the callers hold the transaction and
 * the team lock. Since 0.32.0 the ledger is closed while an issue of the
 * team is pending: `append` releases an expired reservation first and
 * answers `409 issue_pending` for an open one; `appendReserved` writes the
 * entry of the close with the link the reservation took.
 */
final class LedgerAppender {
	public function __construct(
		private InvLedgerMapper $ledger,
		private WebhookService $webhooks,
		private IssueLock $lock,
		private ITimeFactory $time,
	) {
	}

	public function append(int $tenantId, string $actor, string $action, int $invoiceId, string $number, string $canonical, int $now): InvLedger {
		$this->lock->require($tenantId, $this->time->getTime());
		$last = $this->ledger->last($tenantId);
		return $this->insert($tenantId, $last === null ? 1 : $last->getSeq() + 1, $last === null ? '' : $last->getHash(), $actor, $action, $invoiceId, $number, $canonical, $now);
	}

	/**
	 * The entry of a close (0.32.0) with the reserved `seq` and `prev_hash`.
	 * The head cannot have moved – the ledger was closed meanwhile; if it
	 * did, that is a bug and the close fails (500) rather than forking.
	 */
	public function appendReserved(int $tenantId, int $seq, string $prevHash, string $actor, string $action, int $invoiceId, string $number, string $canonical, int $now): InvLedger {
		$last = $this->ledger->last($tenantId);
		$expectSeq = $last === null ? 1 : $last->getSeq() + 1;
		$expectPrev = $last === null ? '' : $last->getHash();
		if ($seq !== $expectSeq || $prevHash !== $expectPrev) {
			throw new \RuntimeException('The ledger head moved while an issue was pending (reserved ' . $seq . ', head ' . ($expectSeq - 1) . ').');
		}
		return $this->insert($tenantId, $seq, $prevHash, $actor, $action, $invoiceId, $number, $canonical, $now);
	}

	private function insert(int $tenantId, int $seq, string $prev, string $actor, string $action, int $invoiceId, string $number, string $canonical, int $now): InvLedger {
		$at = LedgerRules::at($now);
		$payloadHash = LedgerRules::payloadHash($canonical);
		$e = new InvLedger();
		$e->setTenantId($tenantId);
		$e->setSeq($seq);
		$e->setAt($at);
		$e->setActor($actor);
		$e->setAction($action);
		$e->setInvoiceId($invoiceId);
		$e->keepInvoiceId();
		$e->setNumber($number);
		$e->setPayload($canonical);
		$e->setPayloadHash($payloadHash);
		$e->setPrevHash($prev);
		$e->setHash(LedgerRules::entryHash($seq, $at, $actor, $action, $number, $payloadHash, $prev));
		$e = $this->ledger->insert($e);
		// 0.20.0: the webhooks listening to this event get a queue row (never throws).
		$this->webhooks->enqueue($tenantId, $e, $now);
		return $e;
	}
}
