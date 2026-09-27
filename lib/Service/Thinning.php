<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Backup retention schedule, the same for all three stores: all of the
 * last 4 weeks, then the newest per calendar month up to 12 months, then
 * the newest per calendar year up to 10 years; older ones drop off. Pure,
 * without a clock.
 */
final class Thinning {
	public const WEEKS = 4;
	public const MONTHS = 12;
	/**
	 * Year tier. Never below the statutory minimum retention period for
	 * working time records, currently CH ArGV 1 Art. 73 with 5 years.
	 */
	public const JAHRE = 10;

	/**
	 * The days that stay (newest first). Cutoffs: `today − 28 days`,
	 * `today − 12 months`, `today − 10 years`, each inclusive. Days in
	 * the future stay.
	 *
	 * @param list<string> $days YYYY-MM-DD
	 * @return list<string>
	 */
	public static function keep(array $days, string $today): array {
		$t = \DateTimeImmutable::createFromFormat('!Y-m-d', BackupRules::checkDay($today), new \DateTimeZone('UTC'));
		if ($t === false) {
			throw new \InvalidArgumentException('Invalid day');
		}
		$weeks = $t->modify('-' . (self::WEEKS * 7) . ' days')->format('Y-m-d');
		$months = $t->modify('-' . self::MONTHS . ' months')->format('Y-m-d');
		$years = $t->modify('-' . self::JAHRE . ' years')->format('Y-m-d');

		$days = array_values(array_unique($days));
		rsort($days, SORT_STRING);
		$keep = [];
		$seen = [];
		foreach ($days as $d) {
			if ($d >= $weeks) {
				$keep[] = $d;
				continue;
			}
			if ($d < $years) {
				continue;
			}
			// Newest per calendar month or year: the first in descending order.
			$group = $d >= $months ? 'm' . substr($d, 0, 7) : 'y' . substr($d, 0, 4);
			if (!isset($seen[$group])) {
				$seen[$group] = true;
				$keep[] = $d;
			}
		}
		return $keep;
	}
}
