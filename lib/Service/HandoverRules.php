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
	 * @param ?array{mode:string,system:?string,copy:string} $mode
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
	 * @param ?array{mode:string,system:?string,copy:string} $mode
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

	/** The invoice's status a webhook reports after a ledger action without a frozen row. */
	public static function statusAfter(string $action): string {
		return match ($action) {
			LedgerRules::EXTERNAL_ISSUED => self::STATUS,
			LedgerRules::EXTERNAL_REVOKED => InvoiceRules::DRAFT,
			default => '',
		};
	}

	private static function text(mixed $v): ?string {
		return is_string($v) ? $v : null;
	}
}
