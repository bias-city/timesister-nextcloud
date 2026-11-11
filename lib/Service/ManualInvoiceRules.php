<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The invoicing mode manual (0.48.0), pure: the app issues the invoice
 * itself (number from the team counter, status by hand) and files its PDF,
 * Word file and XRechnung here. No ledger, no fixed row: the file of an
 * invoice and kind is simply replaced.
 */
final class ManualInvoiceRules {
	public const KIND_DOCX = 'docx';
	public const KINDS = [DocumentRules::KIND_PDF, self::KIND_DOCX, DocumentRules::KIND_XML];
	/** The statuses whose documents are filed. */
	public const FILED = ['issued', 'paid'];
	public const TYPES = [
		DocumentRules::KIND_PDF => 'application/pdf',
		self::KIND_DOCX => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		DocumentRules::KIND_XML => 'application/xml',
	];

	/**
	 * Issuing on the server in the mode manual: 409.
	 *
	 * @param ?array{mode:string,system:?string,copy:string,archive_pdf?:bool} $mode
	 */
	public static function refuseIssue(?array $mode): void {
		if (InvoicingModeRules::isManual($mode)) {
			throw ApiException::conflict('In the mode manual the app issues invoices; the server keeps no ledger.')->code(ErrorCode::INVOICING_MANUAL);
		}
	}

	/** `?kind=`: pdf (also when missing), docx or xml; 422 otherwise. */
	public static function kind(mixed $v): string {
		if ($v === null || $v === '') {
			return DocumentRules::KIND_PDF;
		}
		if (!is_string($v) || !in_array($v, self::KINDS, true)) {
			throw ApiException::invalid('“kind” must be “pdf”, “docx” or “xml”.')->field('kind');
		}
		return $v;
	}

	/** The bytes fit their kind: a PDF, an e-invoice XML, a Word file (a ZIP, `PK`); ≤ 20 MB. */
	public static function requireBytes(string $kind, string $bytes): void {
		if ($kind === DocumentRules::KIND_PDF) {
			DocumentRules::requirePdf($bytes);
			return;
		}
		if ($kind === DocumentRules::KIND_XML) {
			DocumentRules::requireXml($bytes);
			return;
		}
		if (strlen($bytes) > DocumentRules::FILE_MAX) {
			throw ApiException::tooLarge('A file may be at most 20 MB.');
		}
		if (!str_starts_with($bytes, 'PK')) {
			throw ApiException::invalid('The document must be a Word file (.docx).')->field('content');
		}
	}

	/**
	 * Number and date of an invoice record whose files are written: issued
	 * or paid, with `number` and `issued_on`; 409 otherwise (a draft).
	 *
	 * @return array{number:string,date:string}
	 */
	public static function fileable(\stdClass $d): array {
		$at = self::placed($d);
		if ($at === null || !in_array($d->status ?? null, self::FILED, true)) {
			throw ApiException::conflict('Only an issued or paid invoice with a number and a date gets its documents filed.')->code(ErrorCode::INVOICE_MANUAL_NOT_ISSUED);
		}
		return $at;
	}

	/**
	 * Number and date that name the files of an invoice record, any status;
	 * null without them.
	 *
	 * @return ?array{number:string,date:string}
	 */
	public static function placed(\stdClass $d): ?array {
		$number = $d->number ?? null;
		$date = $d->issued_on ?? null;
		if (!is_string($number) || trim($number) === '' || !is_string($date) || !Time::isDay($date)) {
			return null;
		}
		return ['number' => $number, 'date' => $date];
	}

	/** `<issued_on>_<number>_<customer>.<pdf|docx|xml>` – fixed, so a second upload replaces the first. */
	public static function name(string $date, string $number, string $customer, string $kind): string {
		return DocumentTree::invoiceName($date, $number, $customer, $kind);
	}
}
