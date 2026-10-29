<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\InvHour;
use OCA\TimeSister\Db\InvHourMapper;
use OCA\TimeSister\Db\InvInvoice;

/**
 * The frozen content of an issued invoice as `GET /invoices/{key}/fixed`
 * hands it out and the team ZIP stores it; since 0.20.0 with the tax case,
 * the QR reference and the hand-over; since 0.21.0 with net and VAT per
 * rate, the pass-through items and the total; since 0.32.0 with
 * `documents[]`, the `chain_line` of the footer and, while `issuing`, the
 * reserved link and its expiry (the app renders from this).
 */
final class InvoiceFixed {
	public function __construct(
		private InvHourMapper $hours,
	) {
	}

	/** @return array<string,mixed> */
	public function of(InvInvoice $row, string $lang = DocumentRules::EN): array {
		$positions = json_decode((string)$row->getPositionsJson(), false);
		$customer = InvoiceRules::decode($row->getCustomerJson());
		$external = $row->getExternalJson() === null ? null : InvoiceRules::decode($row->getExternalJson());
		$sums = self::sumsOf($row);
		$issuing = $row->isIssuing();
		$seq = $issuing ? $row->getIssueSeq() : $row->getSeq();
		$prev = $issuing ? $row->getIssuePrevHash() : $row->getPrevHash();
		return ($issuing ? ['expires_at' => LedgerRules::at((int)$row->getIssueExpiresAt())] : []) + [
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
			// 0.21.0: per rate, pass-through, the amount to pay (older invoices: one line, gross).
			...$sums->frozen(),
			'hours_total' => (float)$row->getHours(),
			'issuer' => InvoiceRules::decode($row->getIssuerJson()),
			'customer' => $customer,
			'positions' => is_array($positions) ? $positions : [],
			'hours' => array_map(static fn (InvHour $h): array => $h->present(), $this->hours->findByInvoice($row->getTenantId(), $row->getId())),
			'greeting' => $row->getGreeting(),
			'body' => $row->getBody(),
			'footer' => $row->getFooter(),
			'chain' => ['seq' => $seq, 'prev_hash' => $prev, 'hash' => $issuing ? null : $row->getHash()],
			// 0.32.0: the footer sentence the app prints, and the filed documents.
			'chain_line' => $seq === null ? null : IssueRules::chainLine($lang, $row->getNumber(), $seq, (string)$prev),
			'documents' => $issuing ? [] : $row->documents(),
			'reverses' => $row->getReverses(),
			'reversed_by' => $row->getReversedBy(),
			'credits' => $row->getCredits(),
			'document_path' => $row->getDocumentPath(),
			'document_sha256' => $row->getDocumentSha256(),
			// 0.20.0: the tax case, the QR reference, the counter, the XRechnung file, the hand-over.
			'tax_case' => TaxRules::ofBlock($customer === null ? [] : get_object_vars($customer)),
			'qr_reference' => $row->getQrReference(),
			'counter_no' => $row->getCounterNo(),
			'xml_path' => $row->getXmlPath(),
			'xml_sha256' => $row->getXmlSha256(),
			'external' => $external,
			// 0.26.0: grouping by level and appended tables, as issued.
			'layout' => $row->getLayoutJson() === null ? null : InvoiceRules::decode($row->getLayoutJson()),
		];
	}

	/** The frozen sums of a row; an invoice from before 0.21.0 gets one line of its rate. */
	public static function sumsOf(InvInvoice $row): VatLines {
		$pass = $row->getPassThrough();
		$total = $row->getTotal();
		return VatLines::restore($row->getVatLinesJson(), $pass === null ? 0.0 : (float)$pass, $total === null ? null : (float)$total,
			(float)$row->getVat(), (float)$row->getNet(), (float)$row->getVatAmount(), (float)$row->getGross());
	}
}
