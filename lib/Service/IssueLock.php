<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\InvInvoice;
use OCA\TimeSister\Db\InvInvoiceMapper;

/**
 * One issue at a time per team (0.32.0): while an invoice is `issuing`,
 * the team's ledger is closed – every other ledger write, `next-number`
 * and the writes to that record answer `409 issue_pending`. A reservation
 * past its expiry is released by the next call that touches the ledger
 * (and by the job IssueExpiry). The callers hold the transaction.
 */
final class IssueLock {
	public function __construct(
		private InvInvoiceMapper $invoices,
		private IssueRelease $release,
	) {
	}

	/** The team's open reservation, or null. */
	public function pending(int $tenantId): ?InvInvoice {
		return $this->invoices->findIssuing($tenantId);
	}

	/**
	 * The ledger must be free: an expired reservation is released first
	 * (then the call proceeds), an open one is `409 issue_pending`. A
	 * reservation whose number is no longer the counter's last cannot be
	 * released and keeps the ledger closed until its PDF arrives.
	 */
	public function require(int $tenantId, int $now): void {
		$row = $this->pending($tenantId);
		if ($row === null) {
			return;
		}
		if ($this->release->isExpired($row, $now)) {
			try {
				$this->release->release($row, $now);
				return;
			} catch (ApiException) {
				// `number_not_last`: the reservation stays; the pending answer below says so.
			}
		}
		throw self::pendingOf($row);
	}

	public static function pendingOf(InvInvoice $row): ApiException {
		return IssueRules::pending($row->getRkey(), $row->getNumber(), (int)$row->getIssueExpiresAt());
	}
}
