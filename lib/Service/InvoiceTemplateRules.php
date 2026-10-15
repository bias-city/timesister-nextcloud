<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The team's invoice template (0.16.0): one Word file, checked before it
 * is stored. Pure apart from reading a ZIP directory, so testable without
 * Nextcloud.
 */
final class InvoiceTemplateRules {
	public const MAX_BYTES = 10 * 1024 * 1024;
	/** Base64 of 10 MB is about 13.4 MB; the rest is room for the name and the JSON. */
	public const MAX_BODY = 14 * 1024 * 1024;
	public const MAX_NAME = 200;
	public const ZIP_SIGNATURE = "PK\x03\x04";
	public const DOCUMENT = 'word/document.xml';

	/** A file name of 1–200 characters ending in .docx, without path or control characters. */
	public static function checkName(mixed $name): string {
		if ($name === null) {
			throw ApiException::missing('name');
		}
		if (!is_string($name) || $name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > self::MAX_NAME
			|| !str_ends_with(strtolower($name), '.docx') || strlen($name) <= 5
			|| preg_match('/[\x00-\x1F\x7F\/\\\\]/', $name)) {
			throw ApiException::invalid('“name” must be a file name of 1 to 200 characters ending in .docx.');
		}
		return $name;
	}

	/**
	 * The file from `base64`: decoded strictly (line breaks pass), at
	 * most 10 MB (413).
	 */
	public static function decode(mixed $base64): string {
		if ($base64 === null) {
			throw ApiException::missing('base64');
		}
		$bytes = is_string($base64) ? base64_decode($base64, true) : false;
		if ($bytes === false) {
			throw ApiException::invalid('“base64” is not valid Base64.');
		}
		if (strlen($bytes) > self::MAX_BYTES) {
			throw ApiException::tooLarge('A template may be at most 10 MB.');
		}
		return $bytes;
	}

	/** A ZIP by its first bytes; the cheap check before the archive is opened. */
	public static function looksLikeZip(string $bytes): bool {
		return str_starts_with($bytes, self::ZIP_SIGNATURE);
	}

	/**
	 * The file at `$path` (the same bytes) is a Word document: a ZIP with
	 * `word/document.xml`. Only the directory is read, nothing unpacked.
	 */
	public static function checkDocx(string $bytes, string $path): void {
		if (!self::looksLikeZip($bytes)) {
			throw ApiException::invalid('The template must be a Word document (.docx).');
		}
		$zip = new \ZipArchive();
		if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
			throw ApiException::invalid('The template must be a Word document (.docx).');
		}
		try {
			$found = $zip->locateName(self::DOCUMENT) !== false;
		} finally {
			$zip->close();
		}
		if (!$found) {
			throw ApiException::invalid('The template is not a Word document: “word/document.xml” is missing.');
		}
	}

	/**
	 * What GET …/info answers and what is stored next to the file.
	 *
	 * @return array{name:string,size:int,sha256:string,modified_at:string,modified_by:string}
	 */
	public static function meta(string $name, string $bytes, int $now, string $by): array {
		return [
			'name' => $name,
			'size' => strlen($bytes),
			'sha256' => hash('sha256', $bytes),
			'modified_at' => (string)Time::iso($now),
			'modified_by' => $by,
		];
	}

	/**
	 * Stored or carried meta as read back (the JSON next to the file, or
	 * the one in a team ZIP); null when it is not well-formed.
	 *
	 * @return ?array{name:string,size:int,sha256:string,modified_at:string,modified_by:string}
	 */
	public static function readMeta(mixed $m): ?array {
		if ($m instanceof \stdClass) {
			$m = get_object_vars($m);
		}
		if (!is_array($m)) {
			return null;
		}
		$name = $m['name'] ?? null;
		$size = $m['size'] ?? null;
		$sha = $m['sha256'] ?? null;
		$at = $m['modified_at'] ?? null;
		$by = $m['modified_by'] ?? null;
		try {
			self::checkName($name);
		} catch (ApiException) {
			return null;
		}
		if (!is_string($name) || !is_int($size) || $size < 0 || $size > self::MAX_BYTES || !is_string($sha) || !preg_match('/^[0-9a-f]{64}$/', $sha)
			|| !is_string($at) || Time::parseIso($at) === null || !is_string($by) || strlen($by) > 64) {
			return null;
		}
		return ['name' => $name, 'size' => $size, 'sha256' => $sha, 'modified_at' => $at, 'modified_by' => $by];
	}
}
