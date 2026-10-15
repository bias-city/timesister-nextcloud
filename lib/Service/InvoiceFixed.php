<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\InvHour;
use OCA\TimeSister\Db\InvHourMapper;
use OCA\TimeSister\Db\InvInvoice;

/**
 * The frozen content of an issued invoice as `GET /invoices/{key}/fixed`
 * hands it out and the team ZIP stores it; since 0.19.0 with the
 * document's state, error, path and hash.
 */
final class InvoiceFixed {
	public function __construct(
		private InvHourMapper $hours,
	) {
	}

	/** @return array<string,mixed> */
	public function of(InvInvoice $row): array {
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
			'document_error' => $row->getDocumentError(),
			'document_path' => $row->getDocumentPath(),
			'document_sha256' => $row->getDocumentSha256(),
		];
	}
}
