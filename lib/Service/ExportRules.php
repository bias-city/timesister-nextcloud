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
	public const SYSTEMS = ['bexio', 'lexware', 'other'];
	public const EXTERNAL_ID_MAX = 128;
	public const EXTERNAL_URL_MAX = 512;
	/** The DATEV setting with its defaults (null: not set). */
	public const DATEV_DEFAULTS = ['consultant' => null, 'client' => null, 'fiscal_year_start' => null, 'account_length' => null,
		'debtor_account' => null, 'revenue_domestic' => null, 'revenue_eu' => null, 'revenue_export' => null,
		'bu_domestic' => null, 'bu_eu' => null, 'bu_export' => null];
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

	public const CSV_HEAD = ['number', 'status', 'issued_on', 'due_on', 'paid_on', 'customer', 'customer_vat_id', 'project', 'budget', 'currency', 'net', 'vat_rate', 'vat_amount', 'gross', 'tax_case', 'reverses', 'document_sha256', 'chain_seq', 'chain_hash'];

	/**
	 * One invoice as a CSV row (the frozen content, as `fixed`).
	 *
	 * @param array<string,mixed> $f
	 * @return list<string>
	 */
	public static function csvRow(array $f, ?string $reversesNumber): array {
		$c = self::arr($f['customer'] ?? null);
		return [
			self::str($f['number'] ?? ''), self::str($f['status'] ?? ''), self::str($f['issued_on'] ?? ''), self::str($f['due_on'] ?? ''), self::str($f['paid_on'] ?? ''),
			self::str($c['name'] ?? ''), self::str($c['vat_id'] ?? ''), self::str($f['project'] ?? ''), self::str($f['budget'] ?? ''), self::str($f['currency'] ?? ''),
			self::amount(self::float($f['net'] ?? 0)), self::amount(self::float($f['vat'] ?? 0)), self::amount(self::float($f['vat_amount'] ?? 0)), self::amount(self::float($f['gross'] ?? 0)),
			TaxRules::ofBlock($c), $reversesNumber ?? '', self::str($f['document_sha256'] ?? ''),
			self::str(self::arr($f['chain'] ?? null)['seq'] ?? ''), self::str(self::arr($f['chain'] ?? null)['hash'] ?? ''),
		];
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
	 * One invoice as a booking: Umsatz = |gross| with a decimal comma,
	 * Soll (S) for an invoice, Haben (H) for a reversal or credit note,
	 * Konto = the customer's debtor account or the team's, Gegenkonto =
	 * the revenue account of the tax case, BU-Schlüssel from the setting
	 * (`bu_*`, empty for automatic accounts), Belegdatum DDMM, Belegfeld 1
	 * = the number, Buchungstext = customer and project (60 characters).
	 *
	 * @param array<string,mixed> $f the frozen content
	 * @param array<string,mixed> $datev
	 * @return list<string>
	 */
	public static function datevRow(array $f, array $datev, string $projectName): array {
		$c = self::arr($f['customer'] ?? null);
		$gross = self::float($f['gross'] ?? 0);
		$credit = ($f['reverses'] ?? null) !== null || ($f['credits'] ?? null) !== null || $gross < 0;
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
		$debtor = self::str($c['datev_account'] ?? '');
		if (!preg_match('/^\d+$/', $debtor)) {
			$debtor = self::str($datev['debtor_account'] ?? '');
		}
		$issued = self::str($f['issued_on'] ?? '');
		$text = trim(self::str($c['name'] ?? '') . ' ' . $projectName);
		return [
			number_format(abs($gross), 2, ',', ''), $credit ? 'H' : 'S', self::str($f['currency'] ?? ''), '', '', '',
			$debtor, self::str($datev[$revenue] ?? ''), self::str($datev[$bu] ?? ''),
			substr($issued, 8, 2) . substr($issued, 5, 2),
			self::belegfeld(self::str($f['number'] ?? '')), '', '', mb_substr($text, 0, 60),
		];
	}

	/** Belegfeld 1: at most 36 of A–Z, a–z, 0–9 and $ & % * + - /. */
	public static function belegfeld(string $number): string {
		return substr((string)preg_replace(self::BELEG_PATTERN, '-', $number), 0, 36);
	}

	// ----------------------------------------------------------- external

	/**
	 * The body of `POST /invoices/{key}/external`: the system, its id and
	 * an optional https link.
	 *
	 * @return array{system:string,id:string,url:?string}
	 */
	public static function external(\stdClass $body): array {
		$system = $body->system ?? null;
		if (!is_string($system) || !in_array($system, self::SYSTEMS, true)) {
			throw ApiException::invalid('“system” must be “bexio”, “lexware” or “other”.');
		}
		$id = $body->id ?? null;
		if (is_int($id)) {
			$id = (string)$id;
		}
		if (!is_string($id) || trim($id) === '' || mb_strlen($id) > self::EXTERNAL_ID_MAX || preg_match('/[\x00-\x1F\x7F]/', $id)) {
			throw ApiException::invalid(Message::of('“{field}” must be text of at most {max} characters.', ['field' => 'id', 'max' => self::EXTERNAL_ID_MAX]));
		}
		$url = $body->url ?? null;
		if ($url !== null && $url !== '') {
			if (!is_string($url) || strlen($url) > self::EXTERNAL_URL_MAX || !preg_match('#^https://[^\s/?\#]+[^\s]*$#', $url)) {
				throw ApiException::invalid('“url” must be an https address of at most 512 characters.');
			}
		} else {
			$url = null;
		}
		return ['system' => $system, 'id' => trim($id), 'url' => $url];
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
