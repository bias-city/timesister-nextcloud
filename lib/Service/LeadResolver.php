<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Record;

/**
 * Who leads which project (0.11.0): the role Lead is no longer set, it
 * follows from `project.leads`. A lead key names a person record (its key,
 * or an account in `accounts`) or an account directly; only live projects
 * whose status is not ended count. Pure.
 */
final class LeadResolver {
	/** Project statuses that end the lead rights (the Mac app's PROJEKT_BEENDET_SQL). */
	public const ENDED = ['completed', 'archived', 'abgeschlossen', 'archiviert'];

	/**
	 * Account → the active projects it leads, sorted by name.
	 *
	 * @param list<Record> $persons live person records
	 * @param list<Record> $projects live project records
	 * @return array<string,list<array{kind:string,key:string,code:?string,name:string}>>
	 */
	public static function leadsByUid(array $persons, array $projects): array {
		// Person key → the accounts behind it; a key that is an account names itself.
		$accountsOf = [];
		foreach ($persons as $p) {
			$accountsOf[$p->getRkey()] = array_values(array_unique(array_merge($accountsOf[$p->getRkey()] ?? [], $p->accountList())));
		}
		$out = [];
		foreach ($projects as $p) {
			$d = self::data($p);
			if ($d === null || !self::isActive($d)) {
				continue;
			}
			$leads = $d->{ProjectAccess::LEADS} ?? null;
			if (!is_array($leads)) {
				continue;
			}
			$entry = self::entry($p, $d);
			$seen = [];
			foreach ($leads as $k) {
				if (!is_string($k) || $k === '') {
					continue;
				}
				// A person without an account leads nothing: nobody logs in as them.
				foreach (array_key_exists($k, $accountsOf) ? $accountsOf[$k] : [$k] as $uid) {
					if (!isset($seen[$uid])) {
						$seen[$uid] = true;
						$out[$uid][] = $entry;
					}
				}
			}
		}
		foreach ($out as &$list) {
			usort($list, static fn (array $a, array $b) => [mb_strtolower($a['name']), $a['key']] <=> [mb_strtolower($b['name']), $b['key']]);
		}
		unset($list);
		ksort($out, SORT_STRING);
		return $out;
	}

	/** Not ended: the status is missing or none of ENDED. */
	public static function isActive(\stdClass $d): bool {
		$s = $d->status ?? null;
		return !is_string($s) || !in_array(mb_strtolower(trim($s)), self::ENDED, true);
	}

	/** @return array{kind:string,key:string,code:?string,name:string} as in /me.lead_of */
	private static function entry(Record $p, \stdClass $d): array {
		$codes = $d->codes ?? null;
		$code = is_array($codes) && isset($codes[0]) && is_string($codes[0]) ? $codes[0] : null;
		$n = $d->name ?? null;
		return [
			'kind' => 'project',
			'key' => $p->getRkey(),
			'code' => $code,
			'name' => is_string($n) && trim($n) !== '' ? trim($n) : $p->getRkey(),
		];
	}

	private static function data(Record $r): ?\stdClass {
		$raw = $r->getData();
		if ($r->isTombstone() || $raw === null) {
			return null;
		}
		$d = Json::decode($raw);
		return $d instanceof \stdClass ? $d : null;
	}
}
