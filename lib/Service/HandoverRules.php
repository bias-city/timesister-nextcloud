<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Hand-over of an invoice to the accounting system that issues it (0.24.0,
 * team setting `invoicing_mode` external). Pure: the bodies, the refusals
 * for issuing and for client writes, the record's `external` block and the
 * ledger payloads.
 */
final class HandoverRules {
	/** The invoice record's status after a hand-over. */
	public const STATUS = 'external';
	public const REASON_MAX = IssueRules::NOTE_MAX;
	/** 0.24.1: the accounting system's PDF of a handed-over invoice, at most 20 MB. */
	public const DOCUMENT_MAX = 20 * 1024 * 1024;

	/**
	 * The body of `POST /invoices/{key}/handover`.
	 *
	 * @return array{system:string,id:string,number:string,url:?string,issued_on:string}
	 */
	public static function body(\stdClass $b, int $now): array {
		$v = ExportRules::external($b);
		return $v + [
			'number' => ExportRules::externalNumber($b->number ?? null, 'number'),
			'issued_on' => IssueRules::issuedOn($b->issued_on ?? null, $now),
		];
	}

	/** The reason of a revocation: null or text ≤ 500. */
	public static function reason(\stdClass $b): ?string {
		return IssueRules::shortText($b->reason ?? null, 'reason');
	}

	/**
	 * Handing over needs the mode external (409); returns its system.
	 *
	 * @param ?array{mode:string,system:?string,copy:string,archive_pdf?:bool} $mode
	 */
	public static function requireMode(?array $mode): string {
		return InvoicingModeRules::externalSystem($mode)
			?? throw ApiException::conflict('Handing over needs the team setting invoicing_mode external.');
	}

	/** The body's system must be the team's (409). */
	public static function requireSystem(string $own, string $given): void {
		if ($own !== $given) {
			throw ApiException::conflict(Message::of('This team hands its invoices over to {system}, not to {given}.', ['system' => $own, 'given' => $given]));
		}
	}

	/**
	 * Issuing in the mode external: 409.
	 *
	 * @param ?array{mode:string,system:?string,copy:string,archive_pdf?:bool} $mode
	 */
	public static function refuseIssue(?array $mode): void {
		$system = InvoicingModeRules::externalSystem($mode);
		if ($system !== null) {
			throw ApiException::conflict(Message::of('This team hands its invoices over to {system}; TimeSister does not issue them.', ['system' => $system]));
		}
	}

	/** The system a record was handed over to, or null when it is not handed over. */
	public static function handedOverTo(?\stdClass $d): ?string {
		if (($d?->status ?? null) !== self::STATUS) {
			return null;
		}
		$s = $d->external->system ?? null;
		return is_string($s) && $s !== '' ? $s : '?';
	}

	/** A handed-over invoice is not reversed, written or deleted by a client: 409. */
	public static function refuseHandedOver(?\stdClass $d): void {
		$system = self::handedOverTo($d);
		if ($system !== null) {
			throw ApiException::conflict(Message::of('This invoice was handed over to {system}; revoke the hand-over first.', ['system' => $system]));
		}
	}

	/**
	 * A client's write of an invoice record: a handed-over one is frozen
	 * (409), and only the server sets the status external (422).
	 */
	public static function checkClientWrite(?\stdClass $stored, ?\stdClass $new): void {
		self::refuseHandedOver($stored);
		if (($new?->status ?? null) === self::STATUS) {
			throw ApiException::invalid('Only the server sets the status “external”, when an invoice is handed over.');
		}
	}

	/**
	 * The record's `external` block after a hand-over (the form of 0.23.1
	 * plus `number`).
	 *
	 * @param array{system:string,id:string,number:string,url:?string,issued_on:string} $v
	 */
	public static function externalBlock(array $v, string $at, string $by): \stdClass {
		$b = new \stdClass();
		foreach (['system' => $v['system'], 'id' => $v['id'], 'number' => $v['number'], 'url' => $v['url'], 'at' => $at, 'by' => $by] as $k => $x) {
			$b->{$k} = $x;
		}
		return $b;
	}

	/**
	 * The ledger payload of `external_issued`.
	 *
	 * @param array{system:string,id:string,number:string,url:?string,issued_on:string} $v
	 * @param list<\stdClass> $bookings
	 * @param list<string> $receipts
	 * @return array<string,mixed>
	 */
	public static function payload(string $key, array $v, string $project, string $budget, string $customer, string $currency, array $bookings, array $receipts): array {
		return [
			'key' => $key, 'system' => $v['system'], 'id' => $v['id'], 'number' => $v['number'], 'url' => $v['url'], 'issued_on' => $v['issued_on'],
			'project' => $project, 'budget' => $budget, 'customer_key' => $customer, 'currency' => $currency,
			'bookings' => array_map(static fn (\stdClass $b): array => ['uid' => (string)($b->person ?? ''), 'event_uid' => (string)($b->uid ?? ''), 'start' => (string)($b->start ?? '')], $bookings),
			'receipts' => $receipts,
		];
	}

	/**
	 * The ledger payload of `external_revoked`.
	 *
	 * @return array<string,mixed>
	 */
	public static function revokePayload(string $key, \stdClass $external, ?string $reason): array {
		return [
			'key' => $key, 'system' => self::text($external->system ?? null), 'id' => self::text($external->id ?? null),
			'number' => self::text($external->number ?? null), 'reason' => $reason,
		];
	}

	// ------------------------------------------------- the system's PDF (0.24.1)

	/** The PDF of the accounting system: at most 20 MB (413), a PDF (422). */
	public static function requireDocument(string $content): void {
		if (strlen($content) > self::DOCUMENT_MAX) {
			throw ApiException::tooLarge('The PDF may be at most 20 MB.');
		}
		if (!str_starts_with($content, '%PDF-')) {
			throw ApiException::invalid('The document must be a PDF.');
		}
	}

	/**
	 * Storing the PDF needs the mode external with `archive_pdf` on (409).
	 *
	 * @param ?array{mode:string,system:?string,copy:string,archive_pdf?:bool} $mode
	 */
	public static function requireArchive(?array $mode): void {
		if (!InvoicingModeRules::archivesPdf($mode)) {
			throw ApiException::conflict('This team does not store the accounting system’s PDFs (invoicing_mode.archive_pdf).');
		}
	}

	/**
	 * The stored document of a handed-over invoice: null when none yet, the
	 * same file again (same hash) is no change, another one 409 – the first
	 * stays.
	 */
	public static function documentAgain(\stdClass $external, string $sha256): ?\stdClass {
		$doc = $external->document ?? null;
		if (!$doc instanceof \stdClass) {
			return null;
		}
		if (($doc->sha256 ?? null) !== $sha256) {
			throw ApiException::conflict('The PDF of this invoice is already stored; it stays as it is.');
		}
		return $doc;
	}

	/** `<issued_on>_<system>-<number>_<customer>.pdf`. */
	public static function documentName(string $issuedOn, string $system, string $number, string $customer): string {
		return DocumentTree::invoiceName($issuedOn, $system . '-' . $number, $customer);
	}

	/**
	 * The ledger payload of `external_document`.
	 *
	 * @param array{path:string,size:int,file_id?:int} $written
	 * @return array<string,mixed>
	 */
	public static function documentPayload(string $key, \stdClass $external, array $written, string $sha256): array {
		return [
			'key' => $key, 'system' => self::text($external->system ?? null), 'id' => self::text($external->id ?? null),
			'number' => self::text($external->number ?? null), 'path' => $written['path'], 'size' => $written['size'], 'sha256' => $sha256,
		];
	}

	/**
	 * The ledger payload of `external_cancelled`: the accounting system
	 * cancelled the invoice; its PDF stays.
	 *
	 * @return array<string,mixed>
	 */
	public static function cancelPayload(string $key, \stdClass $external): array {
		$doc = $external->document ?? null;
		return [
			'key' => $key, 'system' => self::text($external->system ?? null), 'id' => self::text($external->id ?? null),
			'number' => self::text($external->number ?? null), 'document' => $doc instanceof \stdClass ? self::text($doc->path ?? null) : null,
		];
	}

	/** The invoice's status a webhook reports after a ledger action without a frozen row. */
	public static function statusAfter(string $action): string {
		return match ($action) {
			LedgerRules::EXTERNAL_ISSUED, LedgerRules::EXTERNAL_DOCUMENT, LedgerRules::EXTERNAL_CANCELLED => self::STATUS,
			LedgerRules::EXTERNAL_REVOKED => InvoiceRules::DRAFT,
			default => '',
		};
	}

	private static function text(mixed $v): ?string {
		return is_string($v) ? $v : null;
	}
}
