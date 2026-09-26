<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Konfliktlogik je Datensatz. Rein.
 *
 * Schreiben (`PUT`, Batch mit `data`, Restore):
 * - kein Datensatz: nur `version = 0` → Fassung 1
 * - Grabstein: `0` oder die Fassung des Grabsteins → Grabstein + 1
 * - lebender Datensatz: genau seine Fassung → + 1
 * Löschen (`DELETE`, Batch mit `data: null`):
 * - kein Datensatz oder Grabstein: nicht gefunden
 * - lebender Datensatz: genau seine Fassung → Grabstein mit + 1
 * Alles andere ist ein Konflikt.
 */
final class VersionCheck {
	public const OK = 'ok';
	public const CONFLICT = 'conflict';
	public const NOT_FOUND = 'not_found';

	/**
	 * @param array{version:int,deleted:bool}|null $current
	 * @return array{0:string,1:int} [Ergebnis, neue Fassung]
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
