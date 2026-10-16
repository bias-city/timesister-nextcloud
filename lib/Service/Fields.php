<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/** Typed reads of a decoded record's fields (0.22.0), pure. */
final class Fields {
	/** A text field, null when missing or not text. */
	public static function str(?\stdClass $o, string $field): ?string {
		$v = $o?->{$field} ?? null;
		return is_string($v) ? $v : null;
	}

	/** A text field, empty when missing. */
	public static function text(?\stdClass $o, string $field): string {
		return self::str($o, $field) ?? '';
	}

	/** An object field, null when missing. */
	public static function obj(?\stdClass $o, string $field): ?\stdClass {
		$v = $o?->{$field} ?? null;
		return $v instanceof \stdClass ? $v : null;
	}

	/** A list field, empty when missing. @return array<array-key,mixed> */
	public static function list(?\stdClass $o, string $field): array {
		$v = $o?->{$field} ?? null;
		return is_array($v) ? $v : [];
	}
}
