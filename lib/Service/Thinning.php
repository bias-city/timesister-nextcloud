<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Staffel der Sicherungen, für alle drei Ablagen gleich: alle der letzten
 * 4 Wochen, dann die jüngste je Kalendermonat bis 12 Monate, dann die
 * jüngste je Kalenderjahr bis 10 Jahre; Älteres fällt weg. Rein, ohne Uhr.
 */
final class Thinning {
	public const WEEKS = 4;
	public const MONTHS = 12;
	/**
	 * Jahresstufe. Nie unter die gesetzliche Mindestfrist für
	 * Arbeitszeitnachweise, derzeit CH ArGV 1 Art. 73 mit 5 Jahren.
	 */
	public const JAHRE = 10;

	/**
	 * Die Tage, die bleiben (neueste zuerst). Grenzen: `heute − 28 Tage`,
	 * `heute − 12 Monate`, `heute − 10 Jahre`, jeweils einschliesslich.
	 * Tage in der Zukunft bleiben.
	 *
	 * @param list<string> $days YYYY-MM-DD
	 * @return list<string>
	 */
	public static function keep(array $days, string $today): array {
		$t = \DateTimeImmutable::createFromFormat('!Y-m-d', BackupRules::checkDay($today), new \DateTimeZone('UTC'));
		if ($t === false) {
			throw new \InvalidArgumentException('Ungültiger Tag');
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
			// Jüngste je Kalendermonat bzw. -jahr: die erste in absteigender Folge.
			$group = $d >= $months ? 'm' . substr($d, 0, 7) : 'y' . substr($d, 0, 4);
			if (!isset($seen[$group])) {
				$seen[$group] = true;
				$keep[] = $d;
			}
		}
		return $keep;
	}
}
