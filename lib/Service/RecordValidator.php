<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Prüft Art, Schlüssel und Inhalt eines Datensatzes. Rein.
 *
 * Die App rechnet nicht mit den Feldern; sie prüft nur, was die Rechte
 * und die Eindeutigkeit brauchen: Schlüsselfeld gleich Schlüssel, `accounts`
 * als Liste von Kennungen, Grösse.
 */
final class RecordValidator {
	public const KINDS = ['person', 'region', 'project', 'customer', 'setting'];
	public const SETTING_KEYS = ['targethours', 'settings'];
	public const KEY_PATTERN = '/^[A-Za-z0-9@._+-]{1,128}$/';
	public const MAX_DATA_BYTES = 262144; // 256 KB
	/** Arten, deren Schlüssel im Feld `id` steht. */
	private const ID_KINDS = ['region', 'project', 'customer'];

	public static function isKind(string $kind): bool {
		return in_array($kind, self::KINDS, true);
	}

	public static function isKey(string $key): bool {
		return preg_match(self::KEY_PATTERN, $key) === 1;
	}

	/** Art und Schlüssel aus dem Pfad oder einer Batch-Schreibung. Falsch: 400. */
	public static function checkAddress(string $kind, string $key): void {
		if (!self::isKind($kind)) {
			throw ApiException::badRequest('Unbekannte Art. Erlaubt: ' . implode(', ', self::KINDS) . '.');
		}
		if (!self::isKey($key)) {
			throw ApiException::badRequest('Ungültiger Schlüssel. Erlaubt sind 1–128 Zeichen aus A–Z, a–z, 0–9 und @ . _ + -');
		}
	}

	/** Die Fassung, auf der eine Schreibung beruht: ganze Zahl ≥ 0. */
	public static function checkVersion(mixed $v, string $field = 'version'): int {
		if (is_string($v) && preg_match('/^\d{1,9}$/', $v)) {
			$v = (int)$v;
		}
		if (!is_int($v) || $v < 0) {
			throw ApiException::badRequest("Das Feld „{$field}“ muss eine ganze Zahl ≥ 0 sein.");
		}
		return $v;
	}

	/**
	 * Den Inhalt prüfen und in die gespeicherte Form bringen.
	 *
	 * @return array{json:string,accounts:list<string>}
	 * @throws ApiException 422 invalid, 413 too_large
	 */
	public static function validate(string $kind, string $key, mixed $data): array {
		self::checkAddress($kind, $key);
		if (!($data instanceof \stdClass)) {
			throw ApiException::invalid('„data“ muss ein JSON-Objekt sein.');
		}
		if ($kind === 'setting' && !in_array($key, self::SETTING_KEYS, true)) {
			throw ApiException::invalid('Einstellungen heissen „targethours“ oder „settings“.');
		}
		if ($kind === 'person') {
			if (!isset($data->login) || !is_string($data->login) || $data->login !== $key) {
				throw ApiException::invalid('Bei einer Person muss „login“ gleich dem Schlüssel sein.');
			}
		} elseif (in_array($kind, self::ID_KINDS, true)) {
			if (!isset($data->id) || !is_string($data->id) || $data->id !== $key) {
				throw ApiException::invalid('Das Feld „id“ muss gleich dem Schlüssel sein.');
			}
		}
		$accounts = [];
		if ($kind === 'person' && property_exists($data, 'accounts') && $data->accounts !== null) {
			$accounts = self::accounts($data->accounts);
		}
		try {
			$json = Json::encode($data);
		} catch (\JsonException) {
			throw ApiException::invalid('„data“ lässt sich nicht als JSON speichern.');
		}
		if (strlen($json) > self::MAX_DATA_BYTES) {
			throw ApiException::tooLarge('Ein Datensatz darf höchstens 256 KB gross sein.');
		}
		return ['json' => $json, 'accounts' => $accounts];
	}

	/** @return list<string> */
	private static function accounts(mixed $v): array {
		if (!is_array($v) || !array_is_list($v)) {
			throw ApiException::invalid('„accounts“ muss eine Liste von Kennungen sein.');
		}
		$out = [];
		foreach ($v as $a) {
			if (!is_string($a) || $a === '' || strlen($a) > 64) {
				throw ApiException::invalid('„accounts“ muss eine Liste von Kennungen sein.');
			}
			$out[$a] = true;
		}
		return array_keys($out);
	}
}
