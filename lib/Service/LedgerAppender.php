<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\InvLedger;
use OCA\TimeSister\Db\InvLedgerMapper;

/**
 * Appends to a team's invoice ledger: the next `seq`, the previous entry's
 * hash and the entry hash (LedgerRules), then queues the webhooks
 * (0.20.0). Shared by issuing (0.18.0), the documents (0.19.0) and the
 * exports; the callers hold the transaction and the team lock.
 */
final class LedgerAppender {
	public function __construct(
		private InvLedgerMapper $ledger,
		private WebhookService $webhooks,
	) {
	}

	public function append(int $tenantId, string $actor, string $action, int $invoiceId, string $number, string $canonical, int $now): InvLedger {
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
