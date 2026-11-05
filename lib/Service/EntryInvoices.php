<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The invoice of a time entry (0.38.0, `GET /entries` → `invoice`): the
 * living invoice (`draft`, `issuing`, `issued`, `paid`, `external`) whose
 * `bookings` hold an occurrence of the entry's event. A billed one wins
 * over a draft; among equals the first. Pure: the records come from the
 * caller.
 */
final class EntryInvoices {
	public const LIVING = [InvoiceRules::DRAFT, 'issuing', 'issued', 'paid', HandoverRules::STATUS];
	public const BILLED = ['issuing', 'issued', 'paid', HandoverRules::STATUS];

	/**
	 * Person and event UID → the invoice that holds one of its occurrences.
	 *
	 * @param iterable<array{0:string,1:?\stdClass}> $invoices key and data of the team's live invoice records
	 * @return array<string,array{key:string,number:?string,status:string,project:?string}>
	 */
	public static function index(iterable $invoices): array {
		$out = [];
		foreach ($invoices as [$key, $d]) {
			$status = $d?->status ?? null;
			if ($d === null || !in_array($status, self::LIVING, true)) {
				continue;
			}
			$number = InvoiceRules::numberOf($d);
			$inv = ['key' => $key, 'number' => $number === '' ? null : $number, 'status' => $status, 'project' => InvoiceRules::keyOf($d->project ?? null)];
			foreach (IssueRules::bookingsOf($d) as $b) {
				$id = self::id((string)($b->person ?? ''), (string)($b->uid ?? ''));
				$had = $out[$id] ?? null;
				if ($had === null || (!in_array($had['status'], self::BILLED, true) && in_array($inv['status'], self::BILLED, true))) {
					$out[$id] = $inv;
				}
			}
		}
		return $out;
	}

	public static function id(string $person, string $eventUid): string {
		return $person . "\n" . $eventUid;
	}

	/**
	 * What the reader is shown: Team Admins every invoice; others a billed
	 * one (as its billing mark says already) and a draft of a project they
	 * lead; else null.
	 *
	 * @param ?array{key:string,number:?string,status:string,project:?string} $inv
	 * @param array<string,true> $led the active projects the reader leads
	 * @return ?array{key:string,number:?string,status:string}
	 */
	public static function shown(?array $inv, bool $manages, array $led): ?array {
		if ($inv === null) {
			return null;
		}
		$own = in_array($inv['status'], self::BILLED, true) || ($inv['project'] !== null && isset($led[$inv['project']]));
		return $manages || $own ? ['key' => $inv['key'], 'number' => $inv['number'], 'status' => $inv['status']] : null;
	}
}
