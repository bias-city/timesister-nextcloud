<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The expense report (record `expense_report`, schema 2 since 0.23.0),
 * pure. Since 0.30.0 (PLAN-SPESEN 9) settling is fixing: the person's open
 * expenses of a period, frozen as lines of the expense table
 * ({@see ExpenseTable}), numbered and in the ledger; the files come from
 * the app; a client writes no report. Status `fixed` → `reversed`; reports
 * of 0.23.0 that are `open` or `paid` stay readable, paid ones keep their
 * receipts. Reports of 0.22.0 (schema 1) are ignored: they hold no receipt.
 */
final class ExpenseReportRules {
	public const KIND = 'expense_report';
	public const SCHEMA = 2;
	public const OPEN = 'open';
	public const FIXED = 'fixed';
	public const PAID = 'paid';
	public const REVERSED = 'reversed';
	public const STATUSES = [self::OPEN, self::FIXED, self::PAID, self::REVERSED];
	/** At most this many expenses in one report. */
	public const MAX_RECEIPTS = 500;
	/** The frozen lines make a report large, as an invoice. */
	public const MAX_BYTES = 786432;
	/** The app's files of a report (9 F): name, size, how many. */
	public const DOCUMENT_NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}\.(pdf|csv)$/';
	/** A file of the app: 32 MiB since 0.31.0 (64 before). */
	public const DOCUMENT_MAX = 32 * 1024 * 1024;
	public const DOCUMENTS_MAX = 5;

	/** What every stored report carries: `person`, and `ready_on` of 0.23.0 when set; the rest is the server's. */
	public static function validate(\stdClass $d): void {
		$s = $d->schema ?? null;
		if ($s !== null && (!is_int($s) || $s < 1 || $s > 1000)) {
			throw ApiException::invalidField('schema');
		}
		$person = $d->person ?? null;
		if (!is_string($person) || $person === '' || mb_strlen($person) > ReceiptRules::UID_MAX || preg_match('/[\x00-\x1F\x7F]/', $person)) {
			throw ApiException::invalid('“person” is required: the account’s user ID.')->field('person');
		}
		$ready = $d->ready_on ?? null;
		if ($ready !== null && (!is_string($ready) || !Time::isDay($ready))) {
			throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => 'ready_on']));
		}
	}

	// ------------------------------------------------------------- status

	/** A report of 0.23.0 or later (schema 2); older ones are ignored. */
	public static function isCurrent(?\stdClass $d): bool {
		return ($d?->schema ?? null) === self::SCHEMA && in_array($d?->status ?? null, self::STATUSES, true);
	}

	/** Does the report hold its receipts? Fixed or paid; a reversed one has released them. */
	public static function holds(?\stdClass $d): bool {
		return self::isCurrent($d) && in_array($d?->status ?? null, [self::FIXED, self::PAID], true);
	}

	/** Is it a current report with this status? */
	public static function is(?\stdClass $d, string $status): bool {
		return self::isCurrent($d) && ($d?->status ?? null) === $status;
	}

	/** The report a receipt is in, when that report still holds it. @param array<string,?\stdClass> $reports */
	public static function heldBy(\stdClass $r, array $reports): ?string {
		$k = $r->report ?? null;
		return is_string($k) && self::holds($reports[$k] ?? null) ? $k : null;
	}

	// ------------------------------------------------------------ settling

	/** `from` and `to` of a settlement: days, `from` not after `to`; 422 otherwise. @return array{0:string,1:string} */
	public static function period(mixed $from, mixed $to): array {
		if (!is_string($from) || !Time::isDay($from) || !is_string($to) || !Time::isDay($to) || $from > $to) {
			throw ApiException::invalid('“from” and “to” are days (YYYY-MM-DD), “from” not after “to”.');
		}
		return [$from, $to];
	}

	/**
	 * Is the receipt an open expense of the person in the period? Theirs,
	 * reimbursed, not rejected – open ones too: expenses need no approval –,
	 * on no fixed or paid report, dated from `from` to `to`.
	 *
	 * @param array<string,?\stdClass> $reports
	 */
	public static function settleable(\stdClass $r, string $person, string $from, string $to, array $reports): bool {
		$date = $r->date ?? null;
		return ($r->person ?? null) === $person && ($r->reimburse ?? false) === true
			&& ($r->status ?? null) !== ReceiptRules::REJECTED && self::heldBy($r, $reports) === null
			&& is_string($date) && $date >= $from && $date <= $to;
	}

	/**
	 * The receipts to settle (9 C), by date and key. 422 when there are
	 * none or too many, and for the first that lacks its category or an
	 * amount in the team's currency.
	 *
	 * @param array<string,\stdClass> $receipts live receipts by key
	 * @param array<string,?\stdClass> $reports live reports by key
	 * @return list<string>
	 */
	public static function pick(array $receipts, array $reports, string $person, string $from, string $to, string $team): array {
		$keys = [];
		foreach ($receipts as $k => $r) {
			if (self::settleable($r, $person, $from, $to, $reports)) {
				$keys[] = $k;
			}
		}
		if ($keys === []) {
			throw ApiException::invalid('No open expenses of this person in this period.')->code(ErrorCode::REPORT_NO_EXPENSES);
		}
		usort($keys, static fn (string $a, string $b): int => strcmp((string)($receipts[$a]->date ?? ''), (string)($receipts[$b]->date ?? '')) ?: strcmp($a, $b));
		if (count($keys) > self::MAX_RECEIPTS) {
			throw ApiException::invalid(Message::of('An expense report holds at most {max} expenses; choose a shorter period.', ['max' => self::MAX_RECEIPTS]));
		}
		foreach ($keys as $k) {
			$p = ['key' => $k, 'date' => (string)($receipts[$k]->date ?? '')];
			$lacks = ExpenseTable::lacks($receipts[$k], $team);
			if ($lacks === 'category') {
				throw ApiException::invalid(Message::of('The expense {key} of {date} has no category.', $p))->code(ErrorCode::REPORT_NO_CATEGORY)->field('category');
			}
			if ($lacks === 'rate') {
				throw ApiException::invalid(Message::of('The expense {key} of {date} has no rate to {currency}.', $p + ['currency' => $team]))->code(ErrorCode::REPORT_NO_RATE)->field('rate');
			}
		}
		return $keys;
	}

	/**
	 * The ledger payload of `expense_fixed` (9 E): everything frozen,
	 * nothing that changes later.
	 *
	 * @param list<array<string,mixed>> $lines
	 * @param array<string,mixed> $sums
	 * @return array<string,mixed>
	 */
	public static function payload(string $key, string $number, string $person, string $from, string $to, string $fixedOn, string $fixedBy, array $lines, array $sums): array {
		return ['key' => $key, 'number' => $number, 'person' => $person, 'from' => $from, 'to' => $to,
			'fixed_on' => $fixedOn, 'fixed_by' => $fixedBy, 'lines' => $lines, 'sums' => $sums];
	}

	/** The receipt has no file: trust and recurring receipts. */
	public static function withoutReceipt(\stdClass $r): bool {
		return in_array($r->kind ?? null, [ReceiptRules::TRUST, ReceiptRules::RECURRING], true);
	}

	// ---------------------------------------------------- lines of 0.23.0

	/** A line of 0.30.0: `amount` in the team's currency; older lines carry the receipt's own amounts. */
	public static function isTableLine(\stdClass $line): bool {
		return ReceiptRules::isNumber($line->amount ?? null);
	}

	/** The VAT rates of a line of 0.23.0 (none, one or several). @return list<float> */
	public static function ratesOf(\stdClass $line): array {
		$out = [];
		foreach (Fields::list($line, 'vat_lines') as $x) {
			$out[] = (float)($x instanceof \stdClass ? ($x->rate ?? 0) : (is_array($x) ? ($x['rate'] ?? 0) : 0));
		}
		return $out;
	}

	/** A line of 0.23.0 read as a receipt, for {@see ReceiptBooking::forExpenses()}. */
	public static function asReceipt(\stdClass $line): \stdClass {
		$o = clone $line;
		$b = $o->booked ?? null;
		$o->booked = is_array($b) ? (object)$b : ($b instanceof \stdClass ? $b : null);
		return $o;
	}

	// ------------------------------------------------------ files of the app

	/** The name of a file of the app (9 F); 422 otherwise. */
	public static function documentName(mixed $name): string {
		if (!is_string($name) || preg_match(self::DOCUMENT_NAME, $name) !== 1) {
			throw ApiException::invalid('“name” is a file name ending in .pdf or .csv.')->field('name');
		}
		return $name;
	}

	/** The file from Base64 (the form of 0.30.0): decoded, then as {@see documentBytes()}. */
	public static function documentContent(string $name, mixed $content): string {
		if (!is_string($content)) {
			throw ApiException::invalid('“content” is the file in Base64.')->field('content');
		}
		if (strlen($content) > 4 * intdiv(self::DOCUMENT_MAX + 2, 3) + 1024) {
			throw ApiException::tooLarge('A document may be at most 32 MiB.');
		}
		$bin = base64_decode($content, true);
		if ($bin === false) {
			throw ApiException::invalid('“content” is the file in Base64.')->field('content');
		}
		return self::documentBytes($name, $bin);
	}

	/** The raw file (0.31.0): at most 32 MiB (413); a PDF begins with `%PDF-`, a CSV is UTF-8 (422). */
	public static function documentBytes(string $name, string $bin): string {
		if (strlen($bin) > self::DOCUMENT_MAX) {
			throw ApiException::tooLarge('A document may be at most 32 MiB.');
		}
		if ((str_ends_with($name, '.pdf') && !str_starts_with($bin, '%PDF-')) || (str_ends_with($name, '.csv') && !mb_check_encoding($bin, 'UTF-8'))) {
			throw ApiException::invalid('The file does not fit its name (PDF or UTF-8 CSV, at most 32 MiB).')->field('content');
		}
		return $bin;
	}

	/** The app's files of a report. @return list<\stdClass> */
	public static function documents(?\stdClass $report): array {
		return array_values(array_filter(Fields::list($report, 'documents'), static fn (mixed $x): bool => $x instanceof \stdClass));
	}

	/**
	 * A file of this name stored before: the same content answers it
	 * unchanged, other content is 409; a name beyond the fifth is 409 too.
	 */
	public static function documentAgain(\stdClass $report, string $name, string $sha256): ?\stdClass {
		$docs = self::documents($report);
		foreach ($docs as $doc) {
			if (($doc->name ?? null) === $name) {
				if (($doc->sha256 ?? null) !== $sha256) {
					throw ApiException::conflict('A document of this name is stored already.')->code(ErrorCode::REPORT_DOCUMENT_EXISTS);
				}
				return $doc;
			}
		}
		if (count($docs) >= self::DOCUMENTS_MAX) {
			throw ApiException::conflict(Message::of('An expense report holds at most {max} documents.', ['max' => self::DOCUMENTS_MAX]))->code(ErrorCode::REPORT_DOCUMENTS_MAX);
		}
		return null;
	}

	/** The path of a stored file: by name; without one the first PDF, else an old report's `document`. 404 otherwise. */
	public static function documentPath(\stdClass $report, mixed $name): string {
		$docs = self::documents($report);
		if (is_string($name) && $name !== '') {
			foreach ($docs as $doc) {
				if (($doc->name ?? null) === $name && is_string($doc->path ?? null)) {
					return $doc->path;
				}
			}
			throw ApiException::notFound(Message::of('This expense report has no document “{name}”.', ['name' => $name]));
		}
		foreach ($docs as $doc) {
			if (str_ends_with((string)($doc->name ?? ''), '.pdf') && is_string($doc->path ?? null)) {
				return $doc->path;
			}
		}
		return Fields::str(Fields::obj($report, 'document'), 'path') ?? throw ApiException::notFound('This expense report has no document yet.');
	}

	/** The type of a stored file by its name. */
	public static function documentType(string $path): string {
		return str_ends_with(strtolower($path), '.csv') ? 'text/csv; charset=utf-8' : 'application/pdf';
	}

	// ------------------------------------------------------------- rights

	/** The person and Team Admins. */
	public static function canRead(bool $manages, string $uid, ?\stdClass $d): bool {
		return $manages || ($d?->person ?? null) === $uid;
	}

	/** A client never writes a report (0.30.0): it is made and changed only through its actions. */
	public static function requireWrite(): void {
		throw ApiException::conflict('An expense report changes only through its actions (fix, documents, reverse).')->code(ErrorCode::REPORT_NOT_WRITABLE);
	}
}
