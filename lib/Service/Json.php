<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * JSON so, wie der Client es geschickt hat: Objekte bleiben Objekte
 * (`{}` wird nicht zu `[]`), darum immer `stdClass` statt Arrays.
 */
final class Json {
	public const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

	/** @throws \JsonException */
	public static function decode(string $json): mixed {
		return json_decode($json, false, 128, JSON_THROW_ON_ERROR);
	}

	/**
	 * @throws \JsonException 
	 *
	 * @param \stdClass|null|string|string[] $value
	 *
	 * @psalm-param \stdClass|non-empty-list<string>|null|string $value
	 */
	public static function encode(mixed $value): string {
		return json_encode($value, self::FLAGS);
	}

	/**
	 * Den Rumpf einer Anfrage als JSON-Objekt, höchstens `$maxBytes`.
	 *
	 * @throws ApiException 413 zu gross, 400 kein JSON-Objekt
	 */
	public static function body(string $raw, int $maxBytes): \stdClass {
		if (strlen($raw) > $maxBytes) {
			throw ApiException::tooLarge('Die Anfrage ist zu gross.');
		}
		if (trim($raw) === '') {
			throw ApiException::badRequest('Die Anfrage hat keinen Rumpf.');
		}
		try {
			$v = self::decode($raw);
		} catch (\JsonException) {
			throw ApiException::badRequest('Der Rumpf ist kein gültiges JSON.');
		}
		if (!($v instanceof \stdClass)) {
			throw ApiException::badRequest('Der Rumpf muss ein JSON-Objekt sein.');
		}
		return $v;
	}

	/** Liest `php://input`, aber nie mehr als `$maxBytes + 1` Bytes. */
	public static function readInput(int $maxBytes): string {
		$raw = @file_get_contents('php://input', false, null, 0, $maxBytes + 1);
		return $raw === false ? '' : $raw;
	}
}
