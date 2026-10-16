<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Checks kind, key and content of a record. Pure.
 *
 * The app does not compute with the fields; it only checks what the
 * rights and uniqueness need: key field equals key, `accounts` as a list
 * of identifiers, size.
 */
final class RecordValidator {
	public const KINDS = ['person', 'region', 'project', 'customer', 'setting', 'job', 'billing', BookingRules::REPORT, BookingRules::MARK, InvoiceRules::KIND, QuoteRules::KIND,
		ReceiptRules::KIND, ExpenseRuleRules::KIND, ExpenseReportRules::KIND];
	/** Jobs (0.6.0): readable as records, written only through /jobs. */
	public const JOB = 'job';
	/** Billing marks (0.7.6): rights per person, see {@see BillingRules}. */
	public const BILLING = BillingRules::KIND;
	public const SETTING_KEYS = ['targethours', 'settings', PrivacyRules::KEY];
	public const KEY_PATTERN = '/^[A-Za-z0-9@._+-]{1,128}$/';
	public const MAX_DATA_BYTES = 262144; // 256 KB
	/** Kinds whose key lives in the field `id`. */
	private const ID_KINDS = ['region', 'project', 'customer'];

	public static function isKind(string $kind): bool {
		return in_array($kind, self::KINDS, true);
	}

	public static function isKey(string $key): bool {
		return preg_match(self::KEY_PATTERN, $key) === 1;
	}

	/** Kind and key from the path or a batch write. Wrong: 400. */
	public static function checkAddress(string $kind, string $key): void {
		if (!self::isKind($kind)) {
			throw ApiException::badRequest(Message::of('Unknown kind. Allowed: {kinds}.', ['kinds' => implode(', ', self::KINDS)]));
		}
		if (!self::isKey($key)) {
			throw ApiException::badRequest('Invalid key. Allowed are 1–128 characters from A–Z, a–z, 0–9 and @ . _ + -');
		}
	}

	/** The version a write is based on: an integer ≥ 0. */
	public static function checkVersion(mixed $v, string $field = 'version'): int {
		if (is_string($v) && preg_match('/^\d{1,9}$/', $v)) {
			$v = (int)$v;
		}
		if (!is_int($v) || $v < 0) {
			throw ApiException::badRequest(Message::of('The field “{field}” must be an integer ≥ 0.', ['field' => $field]));
		}
		return $v;
	}

	/**
	 * Check the content and bring it into the stored form.
	 *
	 * @return array{json:string,accounts:list<string>}
	 * @throws ApiException 422 invalid, 413 too_large
	 */
	public static function validate(string $kind, string $key, mixed $data): array {
		self::checkAddress($kind, $key);
		if (!($data instanceof \stdClass)) {
			throw ApiException::invalid('“data” must be a JSON object.');
		}
		if ($kind === 'setting' && !in_array($key, self::SETTING_KEYS, true)) {
			throw ApiException::invalid('Settings are called “targethours”, “settings” or “privacy”.');
		}
		if ($kind === 'setting' && $key === PrivacyRules::KEY) {
			PrivacyRules::validate($data);
		}
		if ($kind === 'setting' && $key === 'settings' && property_exists($data, InvoiceNumbering::FIELD)) {
			InvoiceNumbering::validate($data->{InvoiceNumbering::FIELD});
		}
		if ($kind === 'setting' && $key === 'settings' && property_exists($data, IssuerRules::FIELD)) {
			IssuerRules::validate($data->{IssuerRules::FIELD});
		}
		// 0.21.0: the module switches (costs among them) are booleans.
		if ($kind === 'setting' && $key === 'settings' && property_exists($data, ModuleRules::FIELD)) {
			ModuleRules::validate($data->{ModuleRules::FIELD});
		}
		if ($kind === 'person') {
			if (!isset($data->login) || !is_string($data->login) || $data->login !== $key) {
				throw ApiException::invalid('For a person, “login” must equal the key.');
			}
			PersonRules::validate($data);
		} elseif (in_array($kind, self::ID_KINDS, true)) {
			if (!isset($data->id) || !is_string($data->id) || $data->id !== $key) {
				throw ApiException::invalid('The field “id” must equal the key.');
			}
			// Codes (one per project, none for a quote), status, team, currency, rate.
			if ($kind === 'project') {
				ProjectRules::validate($data);
			}
			// E-invoice and DATEV fields of a customer (0.20.0).
			if ($kind === 'customer') {
				CustomerRules::validate($data);
			}
		} elseif ($kind === self::BILLING) {
			BillingRules::validate($key, $data);
		} elseif (BookingRules::isKind($kind)) {
			BookingRules::validate($kind, $key, $data);
		} elseif ($kind === InvoiceRules::KIND) {
			InvoiceRules::validate($data);
		} elseif ($kind === QuoteRules::KIND) {
			QuoteRules::validate($key, $data);
		} elseif ($kind === ReceiptRules::KIND) {
			// 0.22.0: receipts, expense rules and reports (the server's own fields are merged by CostWrites).
			ReceiptRules::validate($key, $data);
		} elseif ($kind === ExpenseRuleRules::KIND) {
			ExpenseRuleRules::validate($data);
		} elseif ($kind === ExpenseReportRules::KIND) {
			ExpenseReportRules::validate($data);
		}
		$accounts = [];
		if ($kind === 'person' && property_exists($data, 'accounts') && $data->accounts !== null) {
			$accounts = self::accounts($data->accounts);
		}
		try {
			$json = Json::encode($data);
		} catch (\JsonException) {
			throw ApiException::invalid('“data” cannot be stored as JSON.');
		}
		$max = match ($kind) {
			BookingRules::REPORT => BookingRules::MAX_BYTES,
			InvoiceRules::KIND => InvoiceRules::MAX_BYTES,
			QuoteRules::KIND => QuoteRules::MAX_BYTES,
			default => self::MAX_DATA_BYTES,
		};
		if (strlen($json) > $max) {
			throw match ($kind) {
				BookingRules::REPORT => ApiException::tooLarge('A booking report may be at most 768 KB.'),
				InvoiceRules::KIND => ApiException::tooLarge('An invoice may be at most 768 KB.'),
				QuoteRules::KIND => ApiException::tooLarge('A quote may be at most 768 KB.'),
				default => ApiException::tooLarge('A record may be at most 256 KB.'),
			};
		}
		return ['json' => $json, 'accounts' => $accounts];
	}

	/** @return list<string> */
	private static function accounts(mixed $v): array {
		if (!is_array($v) || !array_is_list($v)) {
			throw ApiException::invalid('“accounts” must be a list of user IDs.');
		}
		$out = [];
		foreach ($v as $a) {
			if (!is_string($a) || $a === '' || strlen($a) > 64) {
				throw ApiException::invalid('“accounts” must be a list of user IDs.');
			}
			$out[$a] = true;
		}
		return array_keys($out);
	}
}
