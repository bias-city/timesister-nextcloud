<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Exports of issued invoices (0.20.0), pure: the team setting `datev`,
 * the query of `GET /invoices/export`, the CSV of the invoices, the DATEV
 * Buchungsstapel (EXTF 700, format version 12 of “Buchungsstapel”), the
 * ledger CSV and the body of `POST /invoices/{key}/external`. The service
 * (ExportService) reads the rows and streams the text.
 */
final class ExportRules {
	public const FIELD = 'datev';
	public const KIND_CSV = 'csv';
	public const KIND_DATEV = 'datev';
	public const KIND_LEDGER = 'ledger';
	public const KINDS = [self::KIND_CSV, self::KIND_DATEV, self::KIND_LEDGER];
	public const BOM = "\xEF\xBB\xBF";
	public const SEP = ';';
	public const EOL = "\r\n";
	/** Ledger action of a hand-over to an accounting system. */
	public const EXPORTED = 'exported';
	/** 0.23.1: any system name (plugins: `sevdesk`, `ch.example.csv`); bexio, lexware and other of 0.20.0 fit. */
	public const SYSTEM_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';
	public const EXTERNAL_ID_MAX = 128;
	public const EXTERNAL_URL_MAX = 512;
	/** 0.23.1: the field `external` of the invoice record. */
	public const EXTERNAL_FIELD = 'external';
	public const EXTERNAL_KEYS = ['system', 'id', 'number', 'url', 'at', 'by', 'document', 'cancelled_at'];
	/** 0.24.1: the stored PDF of the accounting system – {path, sha256, size}. */
	public const EXTERNAL_DOCUMENT_KEYS = ['path', 'sha256', 'size'];
	public const EXTERNAL_PATH_MAX = 1024;
	/** 0.24.0: the accounting system's invoice number of a hand-over (kept only when given). */
	public const EXTERNAL_NUMBER_MAX = 64;
	public const EXTERNAL_AT_MAX = 32;
	public const EXTERNAL_BY_MAX = 64;
	/** The DATEV setting with its defaults (null: not set). */
	public const DATEV_DEFAULTS = ['consultant' => null, 'client' => null, 'fiscal_year_start' => null, 'account_length' => null,
		'debtor_account' => null, 'revenue_domestic' => null, 'revenue_eu' => null, 'revenue_export' => null,
		'bu_domestic' => null, 'bu_eu' => null, 'bu_export' => null, 'revenue_pass_through' => null, 'expense_account' => null];
	/** Belegfeld 1 takes 36 characters of these. */
	private const BELEG_PATTERN = '/[^A-Za-z0-9$&%*+\-\/]/';

	// ----------------------------------------------------------- settings

	/**
	 * The stored `datev` setting (JSON, null = nothing set).
	 *
	 * @return array<string,string|int|null>
	 */
	public static function datev(?string $json): array {
		$out = self::DATEV_DEFAULTS;
		$v = $json === null || $json === '' ? null : json_decode($json, true);
		if (is_array($v)) {
			foreach (array_keys(self::DATEV_DEFAULTS) as $k) {
				if ($k === 'account_length') {
					$out[$k] = is_int($v[$k] ?? null) ? $v[$k] : null;
				} else {
					$out[$k] = isset($v[$k]) && is_string($v[$k]) && $v[$k] !== '' ? $v[$k] : null;
				}
			}
		}
		return $out;
	}

	/**
	 * `datev` from a body: an object; every key optional, null clears it;
	 * digits where the format wants digits. The whole object replaces the
	 * stored one.
	 *
	 * @return array<string,string|int|null>
	 */
	public static function validateDatev(mixed $v): array {
		if ($v instanceof \stdClass) {
			$v = get_object_vars($v);
		}
		if (!is_array($v) || array_is_list($v) && $v !== []) {
			throw ApiException::invalid(Message::of('“{field}” must be an object.', ['field' => self::FIELD]));
		}
		$out = self::DATEV_DEFAULTS;
		foreach ($v as $key => $value) {
			$field = self::FIELD . '.' . (string)$key;
			switch ($key) {
				case 'consultant':
					$out[$key] = self::digits($value, $field, 7);
					break;
				case 'client':
					$out[$key] = self::digits($value, $field, 5);
					break;
				case 'fiscal_year_start':
					if ($value !== null && $value !== '' && (!is_string($value) || !preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $value))) {
						throw ApiException::invalid(Message::of('“{field}” must be a day of the year in the format MM-DD.', ['field' => $field]));
					}
					$out[$key] = $value === '' ? null : $value;
					break;
				case 'account_length':
					if ($value !== null && (!is_int($value) || $value < 4 || $value > 8)) {
						throw ApiException::invalid(Message::of('“{field}” must be an integer from {min} to {max}.', ['field' => $field, 'min' => 4, 'max' => 8]));
					}
					$out[$key] = $value;
					break;
				case 'debtor_account':
				case 'revenue_domestic':
				case 'revenue_eu':
				case 'revenue_export':
				case 'revenue_pass_through':
				case 'expense_account':
					$out[$key] = self::digits($value, $field, 9);
					break;
				case 'bu_domestic':
				case 'bu_eu':
				case 'bu_export':
					$out[$key] = self::digits($value, $field, 4);
					break;
				default:
					throw ApiException::invalid(Message::of('Unknown team setting “{key}”.', ['key' => $field]));
			}
		}
		return $out;
	}

	/** Digits up to `$max`, or null. */
	private static function digits(mixed $v, string $field, int $max): ?string {
		if ($v === null || $v === '') {
			return null;
		}
		if (is_int($v) && $v >= 0) {
			$v = (string)$v;
		}
		if (!is_string($v) || !preg_match('/^\d{1,' . $max . '}$/', $v)) {
			throw ApiException::invalid(Message::of('“{field}” must be up to {max} digits.', ['field' => $field, 'max' => $max]));
		}
		return $v;
	}

	/** The setting as stored (JSON), null when nothing is set. */
	public static function encodeDatev(array $datev): ?string {
		$s = array_intersect_key($datev + self::DATEV_DEFAULTS, self::DATEV_DEFAULTS);
		return $s === self::DATEV_DEFAULTS ? null : json_encode($s, Json::FLAGS);
	}

	/** The DATEV export needs the debtor account and the three revenue accounts: 422 otherwise. @param array<string,mixed> $datev */
	public static function requireAccounts(array $datev): void {
		foreach (['debtor_account', 'revenue_domestic', 'revenue_eu', 'revenue_export'] as $k) {
			if (!is_string($datev[$k] ?? null) || $datev[$k] === '') {
				throw ApiException::invalid(Message::of('The DATEV export needs the accounts in the team setting “datev”: “{field}” is missing.', ['field' => $k]));
			}
		}
	}

	/** Pass-through items (0.21.0) are booked to their own account: 422 when it is missing. @param array<string,mixed> $datev */
	public static function requirePassThroughAccount(array $datev): void {
		if (!is_string($datev['revenue_pass_through'] ?? null) || $datev['revenue_pass_through'] === '') {
			throw ApiException::invalid(Message::of('The DATEV export needs the accounts in the team setting “datev”: “{field}” is missing.', ['field' => 'revenue_pass_through']));
		}
	}

	// -------------------------------------------------------------- query

	/** `kind` of the export: csv, datev or ledger. */
	public static function kind(mixed $v): string {
		if (!is_string($v) || !in_array($v, self::KINDS, true)) {
			throw ApiException::invalid('“kind” must be “csv”, “datev” or “ledger”.');
		}
		return $v;
	}

	/** `from`/`to` of the export: a day or missing (unbounded). */
	public static function day(mixed $v, string $field): ?string {
		if ($v === null || $v === '') {
			return null;
		}
		if (!is_string($v) || !Time::isDay($v)) {
			throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => $field]));
		}
		return $v;
	}

	/** Is the day in the period (bounds inclusive, null unbounded)? */
	public static function inPeriod(string $day, ?string $from, ?string $to): bool {
		return ($from === null || $day >= $from) && ($to === null || $day <= $to);
	}

	/** The file name: `invoices-<from>-<to>.csv`, `datev-….csv`, `ledger-….csv`. */
	public static function fileName(string $kind, ?string $from, ?string $to): string {
		$name = $kind === self::KIND_CSV ? 'invoices' : $kind;
		return $name . '-' . ($from ?? 'start') . '-' . ($to ?? 'end') . '.csv';
	}

	// ---------------------------------------------------------------- CSV

	/** One CSV line: fields quoted when they hold the separator, quotes or line breaks. @param list<string> $fields */
	public static function line(array $fields): string {
		return implode(self::SEP, array_map(static function (string $f): string {
			return preg_match('/[;"\r\n]/', $f) ? '"' . str_replace('"', '""', $f) . '"' : $f;
		}, $fields)) . self::EOL;
	}

	/** An amount with two decimals and a point. */
	public static function amount(float $v): string {
		return number_format($v, 2, '.', '');
	}

	/** 0.21.0 appends the net and the VAT per rate (`8.1:1234.00;2.6:100.00`), the pass-through items and the total. */
	public const CSV_HEAD = ['number', 'status', 'issued_on', 'due_on', 'paid_on', 'customer', 'customer_vat_id', 'project', 'budget', 'currency', 'net', 'vat_rate', 'vat_amount', 'gross', 'tax_case', 'reverses', 'document_sha256', 'chain_seq', 'chain_hash',
		'vat_lines', 'vat_by_rate', 'pass_through', 'total'];

	/**
	 * One invoice as a CSV row (the frozen content, as `fixed`).
	 *
	 * @param array<string,mixed> $f
	 * @return list<string>
	 */
	public static function csvRow(array $f, ?string $reversesNumber): array {
		$c = self::arr($f['customer'] ?? null);
		$sums = VatLines::fromFixed($f);
		return [
			self::str($f['number'] ?? ''), self::str($f['status'] ?? ''), self::str($f['issued_on'] ?? ''), self::str($f['due_on'] ?? ''), self::str($f['paid_on'] ?? ''),
			self::str($c['name'] ?? ''), self::str($c['vat_id'] ?? ''), self::str($f['project'] ?? ''), self::str($f['budget'] ?? ''), self::str($f['currency'] ?? ''),
			self::amount(self::float($f['net'] ?? 0)), self::amount(self::float($f['vat'] ?? 0)), self::amount(self::float($f['vat_amount'] ?? 0)), self::amount(self::float($f['gross'] ?? 0)),
			TaxRules::ofBlock($c), $reversesNumber ?? '', self::str($f['document_sha256'] ?? ''),
			self::str(self::arr($f['chain'] ?? null)['seq'] ?? ''), self::str(self::arr($f['chain'] ?? null)['hash'] ?? ''),
			self::perRate($sums, 'net'), self::perRate($sums, 'vat'), self::amount($sums->passThrough), self::amount($sums->total),
		];
	}

	/** `8.1:1234.00;2.6:100.00` – the rate and one amount per VAT line. @param 'net'|'vat' $which */
	public static function perRate(VatLines $sums, string $which): string {
		return implode(';', array_map(static fn (array $l): string => rtrim(rtrim(number_format($l['rate'], 2, '.', ''), '0'), '.') . ':' . self::amount($l[$which]), $sums->lines));
	}

	public const LEDGER_HEAD = ['seq', 'at', 'actor', 'action', 'number', 'payload_hash', 'prev_hash', 'hash'];

	/** One ledger entry as a CSV row. @param array<string,mixed> $e @return list<string> */
	public static function ledgerRow(array $e): array {
		return array_map(static fn (string $k): string => self::str($e[$k] ?? ''), self::LEDGER_HEAD);
	}

	// -------------------------------------------------------------- DATEV

	/**
	 * The header line of a Buchungsstapel (DATEV-Format, EXTF, version
	 * 700; format category 21, “Buchungsstapel” version 12): 31 fields.
	 * Filled: created at, origin, exported by, consultant and client
	 * numbers, the fiscal year start, the account length, the period, a
	 * label, booking type 1 (Finanzbuchführung), currency, SKR left empty.
	 *
	 * @param array<string,mixed> $datev
	 * @return list<string>
	 */
	public static function datevHeader(array $datev, string $from, string $to, int $now, string $user, string $currency): array {
		$year = substr($from, 0, 4);
		$fiscal = is_string($datev['fiscal_year_start'] ?? null) ? $datev['fiscal_year_start'] : '01-01';
		$fiscalDigits = self::digitsOnly($fiscal);
		$fiscalStart = $year . $fiscalDigits;
		if ($fiscalStart > self::digitsOnly($from)) {
			$fiscalStart = (string)((int)$year - 1) . $fiscalDigits;
		}
		return [
			'EXTF', '700', '21', 'Buchungsstapel', '12',
			gmdate('YmdHis', $now) . '000', '', 'TS', $user, '',
			self::str($datev['consultant'] ?? ''), self::str($datev['client'] ?? ''), $fiscalStart,
			self::str($datev['account_length'] ?? 4), self::digitsOnly($from), self::digitsOnly($to),
			'TimeSister Rechnungen', '', '1', '0', '0', $currency, '', '', '', '', '', '', '', '', '',
		];
	}

	/** The column headers of the rows below (the first 14 fields of the Buchungsstapel). */
	public const DATEV_HEAD = ['Umsatz (ohne Soll/Haben-Kz)', 'Soll/Haben-Kennzeichen', 'WKZ Umsatz', 'Kurs', 'Basis-Umsatz', 'WKZ Basis-Umsatz',
		'Konto', 'Gegenkonto (ohne BU-Schlüssel)', 'BU-Schlüssel', 'Belegdatum', 'Belegfeld 1', 'Belegfeld 2', 'Skonto', 'Buchungstext'];

	/**
	 * One invoice as bookings (0.21.0: one per VAT rate and one for the
	 * pass-through items): Umsatz = |net + VAT| of the rate with a decimal
	 * comma, Soll (S) for an invoice, Haben (H) for a reversal, a credit
	 * note or a negative amount, Konto = the customer's debtor account or
	 * the team's, Gegenkonto = the revenue account of the tax case (the
	 * pass-through items: `revenue_pass_through`), BU-Schlüssel from the
	 * setting (`bu_*`, empty for automatic accounts and pass-through
	 * items), Belegdatum DDMM, Belegfeld 1 = the number, Buchungstext =
	 * customer and project (60 characters). An invoice without amounts:
	 * one row of its gross.
	 *
	 * @param array<string,mixed> $f the frozen content
	 * @param array<string,mixed> $datev
	 * @return list<list<string>>
	 */
	public static function datevRows(array $f, array $datev, string $projectName): array {
		$c = self::arr($f['customer'] ?? null);
		$sums = VatLines::fromFixed($f);
		$taxCase = TaxRules::ofBlock($c);
		$revenue = match ($taxCase) {
			TaxRules::EU_REVERSE => 'revenue_eu',
			TaxRules::EXPORT => 'revenue_export',
			default => 'revenue_domestic',
		};
		$bu = match ($taxCase) {
			TaxRules::EU_REVERSE => 'bu_eu',
			TaxRules::EXPORT => 'bu_export',
			default => 'bu_domestic',
		};
		$parts = [];
		foreach ($sums->lines as $l) {
			$parts[] = [round($l['net'] + $l['vat'], 2), self::str($datev[$revenue] ?? ''), self::str($datev[$bu] ?? '')];
		}
		if ($sums->passThrough !== 0.0) {
			$parts[] = [$sums->passThrough, self::str($datev['revenue_pass_through'] ?? ''), ''];
		}
		if ($parts === []) {
			$parts[] = [$sums->gross, self::str($datev[$revenue] ?? ''), self::str($datev[$bu] ?? '')];
		}
		$creditDoc = ($f['reverses'] ?? null) !== null || ($f['credits'] ?? null) !== null;
		$debtor = self::str($c['datev_account'] ?? '');
		if (!preg_match('/^\d+$/', $debtor)) {
			$debtor = self::str($datev['debtor_account'] ?? '');
		}
		$issued = self::str($f['issued_on'] ?? '');
		$text = mb_substr(trim(self::str($c['name'] ?? '') . ' ' . $projectName), 0, 60);
		$rows = [];
		foreach ($parts as [$amount, $account, $key]) {
			$rows[] = [
				number_format(abs($amount), 2, ',', ''), $creditDoc || $amount < 0 ? 'H' : 'S', self::str($f['currency'] ?? ''), '', '', '',
				$debtor, $account, $key,
				substr($issued, 8, 2) . substr($issued, 5, 2),
				self::belegfeld(self::str($f['number'] ?? '')), '', '', $text,
			];
		}
		return $rows;
	}

	/** Belegfeld 1: at most 36 of A–Z, a–z, 0–9 and $ & % * + - /. */
	public static function belegfeld(string $number): string {
		return substr((string)preg_replace(self::BELEG_PATTERN, '-', $number), 0, 36);
	}

	// ----------------------------------------------------------- external

	/**
	 * The body of `POST /invoices/{key}/external`: the system, its id and
	 * an optional https link. Other fields of the body are ignored.
	 *
	 * @return array{system:string,id:string,url:?string}
	 */
	public static function external(\stdClass $body): array {
		return [
			'system' => self::system($body->system ?? null, 'system'),
			'id' => self::externalId($body->id ?? null, 'id'),
			'url' => self::externalUrl($body->url ?? null, 'url'),
		];
	}

	/**
	 * The field `external` of the invoice record (0.23.1): null or exactly
	 * {system, id, url?, at?, by?}, returned complete (missing ones null,
	 * an integer id as text). 0.24.0: `number` optional, kept only when given.
	 */
	public static function externalBlock(mixed $v): ?\stdClass {
		if ($v === null) {
			return null;
		}
		if (!$v instanceof \stdClass) {
			throw ApiException::invalid('“external” must be null or an object with “system” and “id”.');
		}
		foreach (array_keys(get_object_vars($v)) as $k) {
			if (!in_array($k, self::EXTERNAL_KEYS, true)) {
				throw ApiException::invalid(Message::of('“external” has an unknown field “{field}”; allowed are system, id, number, url, at, by, document and cancelled_at.', ['field' => $k]));
			}
		}
		$out = new \stdClass();
		$out->system = self::system($v->system ?? null, 'external.system');
		$out->id = self::externalId($v->id ?? null, 'external.id');
		if (property_exists($v, 'number')) {
			$out->number = $v->number === null ? null : self::externalNumber($v->number, 'external.number');
		}
		$out->url = self::externalUrl($v->url ?? null, 'external.url');
		$out->at = self::optionalText($v->at ?? null, 'external.at', self::EXTERNAL_AT_MAX);
		$out->by = self::optionalText($v->by ?? null, 'external.by', self::EXTERNAL_BY_MAX);
		// 0.24.1: set by the server only, kept when given.
		if (property_exists($v, 'document')) {
			$out->document = self::externalDocument($v->document);
		}
		if (property_exists($v, 'cancelled_at')) {
			$out->cancelled_at = self::optionalText($v->cancelled_at, 'external.cancelled_at', self::EXTERNAL_AT_MAX);
		}
		return $out;
	}

	/** `external.document`: null or exactly {path, sha256, size}. */
	private static function externalDocument(mixed $v): ?\stdClass {
		if ($v === null) {
			return null;
		}
		$keys = $v instanceof \stdClass ? array_keys(get_object_vars($v)) : [];
		sort($keys);
		$ok = $v instanceof \stdClass && $keys === ['path', 'sha256', 'size']
			&& is_string($v->path) && $v->path !== '' && mb_strlen($v->path) <= self::EXTERNAL_PATH_MAX && preg_match('/[\x00-\x1F\x7F]/', $v->path) !== 1
			&& is_string($v->sha256) && preg_match(LedgerRules::HASH_PATTERN, $v->sha256) === 1
			&& is_int($v->size) && $v->size >= 0;
		if (!$ok) {
			throw ApiException::invalid('“external.document” must be null or an object with “path”, “sha256” and “size”.');
		}
		$out = new \stdClass();
		$out->path = $v->path;
		$out->sha256 = $v->sha256;
		$out->size = $v->size;
		return $out;
	}

	/** A system name after SYSTEM_PATTERN, or 422 naming the field (shared with the invoicing mode, 0.24.0). */
	public static function system(mixed $v, string $field): string {
		if (!is_string($v) || preg_match(self::SYSTEM_PATTERN, $v) !== 1) {
			throw ApiException::invalid(Message::of('“{field}” must be a name of 1 to 64 characters: lowercase letters, digits, “.”, “_” or “-”, starting with a letter or digit (for example “bexio”).', ['field' => $field]));
		}
		return $v;
	}

	public static function externalId(mixed $v, string $field): string {
		if (is_int($v)) {
			$v = (string)$v;
		}
		if (!is_string($v) || trim($v) === '' || mb_strlen($v) > self::EXTERNAL_ID_MAX || preg_match('/[\x00-\x1F\x7F]/', $v)) {
			throw ApiException::invalid(Message::of('“{field}” must be text of at most {max} characters.', ['field' => $field, 'max' => self::EXTERNAL_ID_MAX]));
		}
		return trim($v);
	}

	/** The accounting system's invoice number (0.24.0): text 1–64 without control characters, an integer as text, trimmed. */
	public static function externalNumber(mixed $v, string $field): string {
		if (is_int($v)) {
			$v = (string)$v;
		}
		if (!is_string($v) || trim($v) === '' || mb_strlen(trim($v)) > self::EXTERNAL_NUMBER_MAX || preg_match('/[\x00-\x1F\x7F]/', $v)) {
			throw ApiException::invalid(Message::of('“{field}” must be text of 1 to {max} characters.', ['field' => $field, 'max' => self::EXTERNAL_NUMBER_MAX]));
		}
		return trim($v);
	}

	public static function externalUrl(mixed $v, string $field): ?string {
		if ($v === null || $v === '') {
			return null;
		}
		if (!is_string($v) || strlen($v) > self::EXTERNAL_URL_MAX || !preg_match('#^https://[^\s/?\#]+[^\s]*$#', $v)) {
			throw ApiException::invalid(Message::of('“{field}” must be an https address of at most {max} characters.', ['field' => $field, 'max' => self::EXTERNAL_URL_MAX]));
		}
		return $v;
	}

	private static function optionalText(mixed $v, string $field, int $max): ?string {
		if ($v === null) {
			return null;
		}
		if (!is_string($v) || mb_strlen($v) > $max || preg_match('/[\x00-\x1F\x7F]/', $v)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or text of at most {max} characters.', ['field' => $field, 'max' => $max]));
		}
		return $v;
	}

	// ------------------------------------------------------------ helpers

	/** `2026-10-15` → `20261015`. */
	private static function digitsOnly(string $day): string {
		return (string)preg_replace('/\D/', '', $day);
	}

	/** @return array<string,mixed> */
	private static function arr(mixed $v): array {
		if ($v instanceof \stdClass) {
			$v = get_object_vars($v);
		}
		return is_array($v) ? $v : [];
	}

	private static function str(mixed $v): string {
		return is_string($v) ? $v : (is_int($v) || is_float($v) ? (string)$v : '');
	}

	private static function float(mixed $v): float {
		return is_int($v) || is_float($v) ? (float)$v : (is_numeric($v) ? (float)$v : 0.0);
	}
}
