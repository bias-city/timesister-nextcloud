<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Conflict logic per record. Pure.
 *
 * Writing (`PUT`, batch with `data`, restore):
 * - no record: only `version = 0` → version 1
 * - tombstone: `0` or the tombstone's version → tombstone + 1
 * - live record: exactly its version → + 1
 * Deleting (`DELETE`, batch with `data: null`):
 * - no record or tombstone: not found
 * - live record: exactly its version → tombstone with + 1
 * Everything else is a conflict.
 */
final class VersionCheck {
	public const OK = 'ok';
	public const CONFLICT = 'conflict';
	public const NOT_FOUND = 'not_found';

	/**
	 * @param array{version:int,deleted:bool}|null $current
	 * @return array{0:string,1:int} [outcome, new version]
	 */
	public static function decide(?array $current, int $base, bool $delete): array {
		if ($delete) {
			if ($current === null || $current['deleted']) {
				return [self::NOT_FOUND, 0];
			}
			return $base === $current['version']
				? [self::OK, $current['version'] + 1]
				: [self::CONFLICT, 0];
		}
		if ($current === null) {
			return $base === 0 ? [self::OK, 1] : [self::CONFLICT, 0];
		}
		if ($current['deleted']) {
			return ($base === 0 || $base === $current['version'])
				? [self::OK, $current['version'] + 1]
				: [self::CONFLICT, 0];
		}
		return $base === $current['version']
			? [self::OK, $current['version'] + 1]
			: [self::CONFLICT, 0];
	}
}
