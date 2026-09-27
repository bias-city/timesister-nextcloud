<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * JSON as the client sent it: objects stay objects (`{}` does not become
 * `[]`), so always `stdClass` instead of arrays.
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
	 * The body of a request as a JSON object, at most `$maxBytes`.
	 *
	 * @throws ApiException 413 too large, 400 not a JSON object
	 */
	public static function body(string $raw, int $maxBytes): \stdClass {
		if (strlen($raw) > $maxBytes) {
			throw ApiException::tooLarge('The request is too large.');
		}
		if (trim($raw) === '') {
			throw ApiException::badRequest('The request has no body.');
		}
		try {
			$v = self::decode($raw);
		} catch (\JsonException) {
			throw ApiException::badRequest('The body is not valid JSON.');
		}
		if (!($v instanceof \stdClass)) {
			throw ApiException::badRequest('The body must be a JSON object.');
		}
		return $v;
	}

	/** Reads `php://input`, but never more than `$maxBytes + 1` bytes. */
	public static function readInput(int $maxBytes): string {
		$raw = @file_get_contents('php://input', false, null, 0, $maxBytes + 1);
		return $raw === false ? '' : $raw;
	}
}
