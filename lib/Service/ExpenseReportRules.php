<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The expense report (0.22.0, record `expense_report`, B4a), pure:
 * content, which receipts it may hold, its sums per VAT rate and who
 * reads, writes and approves it. Writing it is submitting it.
 */
final class ExpenseReportRules {
	public const KIND = 'expense_report';
	public const SUBMITTED = 'submitted';
	public const APPROVED = 'approved';
	public const PAID = 'paid';
	public const SERVER = ['status', 'sums', 'submitted_on', 'approved_by', 'approved_on', 'paid_on', 'document'];
	public const MAX_RECEIPTS = 500;
	/** A receipt may be in a report when approved – general ones are approved to `internal` – or billed. */
	public const ELIGIBLE = [ReceiptRules::APPROVED, ReceiptRules::INTERNAL, ReceiptRules::BILLED];

	public static function validate(\stdClass $d): void {
		$s = $d->schema ?? null;
		if ($s !== null && (!is_int($s) || $s < 1 || $s > 1000)) {
			throw ApiException::invalidField('schema');
		}
		$person = $d->person ?? null;
		if (!is_string($person) || $person === '' || mb_strlen($person) > ReceiptRules::UID_MAX || preg_match('/[\x00-\x1F\x7F]/', $person)) {
			throw ApiException::invalid('“person” is required: the account’s user ID.');
		}
		foreach (['from', 'to'] as $f) {
			$v = $d->{$f} ?? null;
			if (!is_string($v) || !Time::isDay($v)) {
				throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => $f]));
			}
		}
		if ($d->from > $d->to) {
			throw ApiException::invalid('“from” must not be after “to”.');
		}
		$list = $d->receipts ?? null;
		if (!is_array($list) || !array_is_list($list) || $list === [] || count($list) > self::MAX_RECEIPTS) {
			throw ApiException::invalid(Message::of('“receipts” must be a list of 1 to {max} receipt keys.', ['max' => self::MAX_RECEIPTS]));
		}
		$seen = [];
		foreach ($list as $k) {
			if (!is_string($k) || preg_match(ReceiptRules::KEY_PATTERN, $k) !== 1) {
				throw ApiException::invalid(Message::of('“receipts” must be a list of 1 to {max} receipt keys.', ['max' => self::MAX_RECEIPTS]));
			}
			if (isset($seen[$k])) {
				throw ApiException::invalid(Message::of('The receipt {key} is in the list twice.', ['key' => $k]));
			}
			$seen[$k] = true;
		}
	}

	/** @return list<string> */
	public static function receiptsOf(?\stdClass $d): array {
		return array_values(array_filter(Fields::list($d, 'receipts'), 'is_string'));
	}

	/**
	 * May this receipt be in the report? The person's, reimbursed,
	 * approved (or internal or billed), dated within the period, in no
	 * other live report (409).
	 */
	public static function requireEligible(\stdClass $report, string $reportKey, string $key, ?\stdClass $r): void {
		$p = ['key' => $key];
		if ($r === null) {
			throw ApiException::invalid(Message::of('The receipt {key} does not exist.', $p));
		}
		if (($r->person ?? null) !== $report->person || ($r->reimburse ?? false) !== true) {
			throw ApiException::invalid(Message::of('The receipt {key} is not reimbursed to this person.', $p));
		}
		if (!in_array($r->status ?? null, self::ELIGIBLE, true)) {
			throw ApiException::invalid(Message::of('The receipt {key} is not approved.', $p));
		}
		$date = (string)($r->date ?? '');
		if ($date < $report->from || $date > $report->to) {
			throw ApiException::invalid(Message::of('The receipt {key} is not dated within the report’s period.', $p));
		}
		$other = $r->report ?? null;
		if (is_string($other) && $other !== $reportKey) {
			throw ApiException::conflict(Message::of('The receipt {key} is in the expense report {report}.', ['key' => $key, 'report' => $other]));
		}
	}

	/**
	 * The sums of the receipts with VatLines: net per rate (a receipt
	 * without rate counts as 0, one with VAT lines once per rate), VAT per rate, gross; all in one currency.
	 *
	 * @param list<\stdClass> $receipts
	 * @return array{currency:string,net:float,vat_lines:list<array{rate:float,net:float,vat:float}>,gross:float}
	 */
	public static function sums(array $receipts, string $fallbackCurrency): array {
		$currency = null;
		$lines = [];
		foreach ($receipts as $r) {
			$e = ReceiptBooking::forExpenses($r, $fallbackCurrency);
			$c = $e['currency'];
			if ($currency !== null && $c !== $currency) {
				throw ApiException::invalid('All receipts of an expense report are in one currency.');
			}
			$currency = $c;
			// A receipt with several rates counts once per rate.
			foreach ($e['rates'] as $l) {
				$lines[] = ['kind' => 'costs', 'amount' => $l['net'], 'vat' => $l['rate']];
			}
		}
		$s = VatLines::of($lines, 0.0, TaxRules::DOMESTIC);
		return ['currency' => $currency ?? $fallbackCurrency, 'net' => $s->net, 'vat_lines' => $s->lines, 'gross' => $s->gross];
	}

	/** Is the receipt's rate missing (counts as 0)? Without file: trust and recurring receipts. */
	public static function withoutReceipt(\stdClass $r): bool {
		return in_array($r->kind ?? null, [ReceiptRules::TRUST, ReceiptRules::RECURRING], true);
	}

	// ------------------------------------------------------------- rights

	/** A Lead approves when every receipt belongs to a project they lead. @param list<?\stdClass> $receipts @param array<string,true> $led */
	public static function leadApproves(array $led, array $receipts): bool {
		if ($led === [] || $receipts === []) {
			return false;
		}
		foreach ($receipts as $r) {
			if (!ReceiptRules::ofLed($r, $led)) {
				return false;
			}
		}
		return true;
	}

	/** The person, Team Admins, a Lead who may approve it. @param list<?\stdClass> $receipts @param array<string,true> $led */
	public static function canRead(bool $manages, string $uid, array $led, ?\stdClass $d, array $receipts): bool {
		return $manages || ($d?->person ?? null) === $uid || self::leadApproves($led, $receipts);
	}

	/** Submitted by the person or a Team Admin for them; changed or deleted only while submitted. */
	public static function requireWrite(bool $manages, string $uid, ?\stdClass $before, ?\stdClass $after): void {
		if ($before !== null && ($before->status ?? null) !== self::SUBMITTED) {
			throw ApiException::conflict('An approved expense report does not change.');
		}
		if ($manages) {
			return;
		}
		foreach ([$before, $after] as $d) {
			if ($d !== null && ($d->person ?? null) !== $uid) {
				throw ApiException::forbidden('An expense report is submitted by its person or by a Team Admin.');
			}
		}
	}

	/** The server's fields: kept from the stored report, set on a new one; the sums always computed. @param array<string,mixed> $sums */
	public static function mergeServer(?\stdClass $before, \stdClass $after, array $sums, string $today): void {
		foreach (self::SERVER as $f) {
			$after->{$f} = $before?->{$f} ?? null;
		}
		$after->status = self::SUBMITTED;
		$after->submitted_on = $before?->submitted_on ?? $today;
		$after->sums = (object)$sums;
	}
}
