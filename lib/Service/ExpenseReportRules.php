<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The expense report (record `expense_report`, schema 2 since 0.23.0),
 * pure: the person's expense list, the frozen lines and sums of a fixed
 * report, its ledger payload, the status `open` → `fixed` → `paid` /
 * `reversed`, and who reads and writes it. Reports of 0.22.0 (schema 1,
 * `submitted`/`approved`) are ignored: they hold no receipt any more.
 */
final class ExpenseReportRules {
	public const KIND = 'expense_report';
	public const SCHEMA = 2;
	public const OPEN = 'open';
	public const FIXED = 'fixed';
	public const PAID = 'paid';
	public const REVERSED = 'reversed';
	public const STATUSES = [self::OPEN, self::FIXED, self::PAID, self::REVERSED];
	/** Owned by the server: ignored on a client's write, the stored value is kept. */
	public const SERVER = ['status', 'number', 'lines', 'sums', 'fixed_on', 'fixed_by', 'paid_on', 'reversed_on', 'reverse_reason',
		'document', 'document_state', 'document_error', 'document_tries', 'ledger_seq', 'hash'];
	public const MAX_RECEIPTS = 500;
	/** A receipt is on the expense list when approved – general ones are approved to `internal` – or billed. */
	public const ELIGIBLE = [ReceiptRules::APPROVED, ReceiptRules::INTERNAL, ReceiptRules::BILLED];
	public const DOCUMENT_PENDING = 'pending';
	public const DOCUMENT_DONE = 'done';
	public const DOCUMENT_FAILED = 'failed';

	/** A client's content: `person`, `ready_on`; the rest is the server's. */
	public static function validate(\stdClass $d): void {
		$s = $d->schema ?? null;
		if ($s !== null && (!is_int($s) || $s < 1 || $s > 1000)) {
			throw ApiException::invalidField('schema');
		}
		$person = $d->person ?? null;
		if (!is_string($person) || $person === '' || mb_strlen($person) > ReceiptRules::UID_MAX || preg_match('/[\x00-\x1F\x7F]/', $person)) {
			throw ApiException::invalid('“person” is required: the account’s user ID.');
		}
		$ready = $d->ready_on ?? null;
		if ($ready !== null && (!is_string($ready) || !Time::isDay($ready))) {
			throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => 'ready_on']));
		}
	}

	// ------------------------------------------------------------- status

	/** A report of 0.23.0 (schema 2); older ones are ignored. */
	public static function isCurrent(?\stdClass $d): bool {
		return ($d?->schema ?? null) === self::SCHEMA && in_array($d?->status ?? null, self::STATUSES, true);
	}

	/** Does the report hold its receipts? Fixed or paid; a reversed one has released them. */
	public static function holds(?\stdClass $d): bool {
		return self::isCurrent($d) && in_array($d?->status ?? null, [self::FIXED, self::PAID], true);
	}

	/** The person's open report among the live reports (key → data), or null. @param array<string,?\stdClass> $reports */
	public static function openOf(array $reports, string $person): ?string {
		foreach ($reports as $key => $d) {
			if (self::isCurrent($d) && $d?->status === self::OPEN && ($d->person ?? null) === $person) {
				return (string)$key;
			}
		}
		return null;
	}

	/** Is it a current report with this status? */
	public static function is(?\stdClass $d, string $status): bool {
		return self::isCurrent($d) && ($d?->status ?? null) === $status;
	}

	// -------------------------------------------------------- expense list

	/** The report a receipt is in, when that report still holds it. @param array<string,?\stdClass> $reports */
	public static function heldBy(\stdClass $r, array $reports): ?string {
		$k = $r->report ?? null;
		return is_string($k) && self::holds($reports[$k] ?? null) ? $k : null;
	}

	/** On the person's expense list: theirs, reimbursed, approved (or internal, billed), in no fixed report. @param array<string,?\stdClass> $reports */
	public static function onList(\stdClass $r, string $person, array $reports): bool {
		return ($r->person ?? null) === $person && ($r->reimburse ?? false) === true
			&& in_array($r->status ?? null, self::ELIGIBLE, true) && self::heldBy($r, $reports) === null;
	}

	/**
	 * The receipts to fix: the whole expense list of the person, or the
	 * given part of it (each once, each on the list). Sorted by date and
	 * key. 422 when none is left.
	 *
	 * @param array<string,\stdClass> $receipts live receipts by key
	 * @param array<string,?\stdClass> $reports live reports by key
	 * @return list<string>
	 */
	public static function pick(array $receipts, array $reports, string $person, mixed $given): array {
		if ($given === null) {
			$keys = array_keys(array_filter($receipts, static fn (\stdClass $r): bool => self::onList($r, $person, $reports)));
		} else {
			if (!is_array($given) || !array_is_list($given) || $given === [] || count($given) > self::MAX_RECEIPTS) {
				throw ApiException::invalid(Message::of('“receipts” must be a list of 1 to {max} receipt keys.', ['max' => self::MAX_RECEIPTS]));
			}
			$keys = [];
			foreach ($given as $k) {
				if (!is_string($k) || preg_match(ReceiptRules::KEY_PATTERN, $k) !== 1) {
					throw ApiException::invalid(Message::of('“receipts” must be a list of 1 to {max} receipt keys.', ['max' => self::MAX_RECEIPTS]));
				}
				if (isset($keys[$k])) {
					throw ApiException::invalid(Message::of('The receipt {key} is in the list twice.', ['key' => $k]));
				}
				$r = $receipts[$k] ?? null;
				$held = $r === null ? null : self::heldBy($r, $reports);
				if ($held !== null) {
					throw ApiException::conflict(Message::of('The receipt {key} is in the expense report {report}.', ['key' => $k, 'report' => (string)($reports[$held]->number ?? $held)]));
				}
				if ($r === null || !self::onList($r, $person, $reports)) {
					throw ApiException::invalid(Message::of('The receipt {key} is not on the expense list of this person.', ['key' => $k]));
				}
				$keys[$k] = true;
			}
			$keys = array_keys($keys);
		}
		$keys = array_map('strval', $keys);
		if ($keys === []) {
			throw ApiException::invalid('The expense list of this person holds no receipts.');
		}
		usort($keys, static fn (string $a, string $b): int => [(string)($receipts[$a]->date ?? ''), $a] <=> [(string)($receipts[$b]->date ?? ''), $b]);
		return $keys;
	}

	// ------------------------------------------------------------ freezing

	/**
	 * The frozen line of a receipt: its own amounts (VAT per rate as a list:
	 * its `vat_lines`, else one line of its rate, else none), `booked` as on
	 * the receipt, and whether it has no file.
	 *
	 * @return array<string,mixed>
	 */
	public static function line(string $key, \stdClass $r): array {
		$rate = $r->vat_rate ?? null;
		$lines = ReceiptRecognition::hasLines($r)
			? ReceiptRecognition::rates($r)
			: (is_int($rate) || is_float($rate) ? [['rate' => (float)$rate, 'net' => (float)($r->net ?? 0), 'vat' => (float)($r->vat ?? 0)]] : []);
		$booked = $r->booked ?? null;
		return [
			'receipt' => $key, 'date' => (string)($r->date ?? ''), 'supplier' => $r->supplier ?? null, 'text' => $r->text ?? null,
			'project' => is_string($r->project ?? null) ? $r->project : null, 'cost' => is_int($r->cost ?? null) ? $r->cost : null,
			'vat_lines' => $lines, 'net' => $r->net ?? 0, 'vat' => $r->vat ?? 0, 'gross' => $r->gross ?? 0,
			'currency' => is_string($r->currency ?? null) ? $r->currency : null,
			'booked' => $booked instanceof \stdClass ? ['gross' => $booked->gross ?? 0, 'currency' => $booked->currency ?? null] : null,
			'without_file' => self::withoutReceipt($r),
		];
	}

	/** The VAT rates of a frozen line (none, one or several). @param array<string,mixed>|\stdClass $line @return list<float> */
	public static function ratesOf(array|\stdClass $line): array {
		$l = $line instanceof \stdClass ? ($line->vat_lines ?? null) : ($line['vat_lines'] ?? null);
		$out = [];
		foreach (is_array($l) ? $l : [] as $x) {
			$out[] = (float)($x instanceof \stdClass ? ($x->rate ?? 0) : (is_array($x) ? ($x['rate'] ?? 0) : 0));
		}
		return $out;
	}

	/** A frozen line read as a receipt, for {@see ReceiptBooking::forExpenses()}. @param array<string,mixed>|\stdClass $line */
	public static function asReceipt(array|\stdClass $line): \stdClass {
		$o = new \stdClass();
		foreach ($line instanceof \stdClass ? get_object_vars($line) : $line as $k => $v) {
			$o->{$k} = $v;
		}
		$b = $o->booked ?? null;
		$o->booked = is_array($b) ? (object)$b : ($b instanceof \stdClass ? $b : null);
		return $o;
	}

	/**
	 * The sums with VatLines over the amounts for expenses (booked in the
	 * team's currency): net per rate (none counts as 0, several once per
	 * rate), VAT per rate, gross; all in one currency (422 otherwise).
	 *
	 * @param list<array<string,mixed>|\stdClass> $lines frozen lines (or receipts)
	 * @return array{currency:string,net:float,vat_lines:list<array{rate:float,net:float,vat:float}>,gross:float}
	 */
	public static function sums(array $lines, string $fallbackCurrency): array {
		$currency = null;
		$vat = [];
		foreach ($lines as $l) {
			$e = ReceiptBooking::forExpenses(self::asReceipt($l), $fallbackCurrency);
			if ($currency !== null && $e['currency'] !== $currency) {
				throw ApiException::invalid('All receipts of an expense report are in one currency.');
			}
			$currency = $e['currency'];
			foreach ($e['rates'] as $x) {
				$vat[] = ['kind' => 'costs', 'amount' => $x['net'], 'vat' => $x['rate']];
			}
		}
		$s = VatLines::of($vat, 0.0, TaxRules::DOMESTIC);
		return ['currency' => $currency ?? $fallbackCurrency, 'net' => $s->net, 'vat_lines' => $s->lines, 'gross' => $s->gross];
	}

	/**
	 * The ledger payload of `expense_fixed`: everything frozen, nothing
	 * that changes later.
	 *
	 * @param list<array<string,mixed>> $lines
	 * @param array<string,mixed> $sums
	 * @return array<string,mixed>
	 */
	public static function payload(string $key, string $number, string $person, string $fixedOn, string $fixedBy, ?string $readyOn, array $lines, array $sums): array {
		return ['key' => $key, 'number' => $number, 'person' => $person, 'fixed_on' => $fixedOn, 'fixed_by' => $fixedBy,
			'ready_on' => $readyOn, 'lines' => $lines, 'sums' => $sums];
	}

	/** The receipt has no file: trust and recurring receipts. */
	public static function withoutReceipt(\stdClass $r): bool {
		return in_array($r->kind ?? null, [ReceiptRules::TRUST, ReceiptRules::RECURRING], true);
	}

	// ------------------------------------------------------------- rights

	/** The person and Team Admins. */
	public static function canRead(bool $manages, string $uid, ?\stdClass $d): bool {
		return $manages || ($d?->person ?? null) === $uid;
	}

	/** A client writes only the open report, as its person or a Team Admin; the person stays. */
	public static function requireWrite(bool $manages, string $uid, ?\stdClass $before, ?\stdClass $after): void {
		if ($before !== null && !(self::isCurrent($before) && $before->status === self::OPEN)) {
			throw ApiException::conflict('A fixed expense report changes only through its actions (paid, reverse).');
		}
		if ($before !== null && $after !== null && ($after->person ?? null) !== ($before->person ?? null)) {
			throw ApiException::conflict('The person of an expense report does not change.');
		}
		if ($manages) {
			return;
		}
		foreach ([$before, $after] as $d) {
			if ($d !== null && ($d->person ?? null) !== $uid) {
				throw ApiException::forbidden('An expense report is written by its person or by a Team Admin.');
			}
		}
	}

	/** The server's fields kept from the stored report; a new one is open. */
	public static function mergeServer(?\stdClass $before, \stdClass $after): void {
		foreach (self::SERVER as $f) {
			$after->{$f} = $before?->{$f} ?? null;
		}
		$after->schema = self::SCHEMA;
		$after->status = $before === null ? self::OPEN : $before->status;
	}
}
