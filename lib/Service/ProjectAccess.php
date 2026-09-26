<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Projekte (Fassung 2): voll nur für Verwaltung, Admin und die Leitungen
 * des Projekts; alle anderen bekommen den Buchungskatalog. Die Leitung ist
 * Admin im Projekt: Sie ändert es ganz, legt aber keine neuen an. Rein:
 * kennt nur `data` und die Personenschlüssel.
 */
final class ProjectAccess {
	/** Positivliste: Was hier fehlt, sieht nur die volle Sicht. */
	public const CATALOG = ['schema', 'id', 'name', 'codes', 'subprojects', 'start', 'end', 'status', 'mapping'];
	public const LEADS = 'leads';
	public const FORBIDDEN = 'Ein Projekt ändern nur Verwaltung, Admin und seine Leitung; neue Projekte legen Verwaltung und Admin an.';

	/**
	 * Leitet der Aufrufer dieses Projekt? `data.leads` enthält einen seiner
	 * Personenschlüssel (Kennung oder Person mit ihm in `accounts`).
	 *
	 * @param list<string> $ownKeys
	 */
	public static function isLead(mixed $data, array $ownKeys): bool {
		$leads = $data instanceof \stdClass ? ($data->{self::LEADS} ?? null) : null;
		if (!is_array($leads)) {
			return false;
		}
		foreach ($leads as $k) {
			if (is_string($k) && in_array($k, $ownKeys, true)) {
				return true;
			}
		}
		return false;
	}

	/** Der Buchungskatalog: nur die Felder aus CATALOG, die es gibt. */
	public static function catalog(\stdClass $data): \stdClass {
		$out = new \stdClass();
		foreach (self::CATALOG as $field) {
			if (property_exists($data, $field)) {
				$out->{$field} = $data->{$field};
			}
		}
		return $out;
	}
}
