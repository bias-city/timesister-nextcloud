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
	public const KINDS = ['person', 'region', 'project', 'customer', 'setting', 'job', 'billing'];
	/** Jobs (0.6.0): readable as records, written only through /jobs. */
	public const JOB = 'job';
	/** Billing marks (0.7.6): rights per person, see {@see BillingRules}. */
	public const BILLING = BillingRules::KIND;
	public const SETTING_KEYS = ['targethours', 'settings'];
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
			throw ApiException::invalid('Settings are called “targethours” or “settings”.');
		}
		if ($kind === 'person') {
			if (!isset($data->login) || !is_string($data->login) || $data->login !== $key) {
				throw ApiException::invalid('For a person, “login” must equal the key.');
			}
		} elseif (in_array($kind, self::ID_KINDS, true)) {
			if (!isset($data->id) || !is_string($data->id) || $data->id !== $key) {
				throw ApiException::invalid('The field “id” must equal the key.');
			}
		} elseif ($kind === self::BILLING) {
			BillingRules::validate($key, $data);
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
		if (strlen($json) > self::MAX_DATA_BYTES) {
			throw ApiException::tooLarge('A record may be at most 256 KB.');
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
