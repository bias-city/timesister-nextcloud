<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The person record's `cost_rates` (0.16.0): the person's hourly cost,
 * valid from a day on, like `workload`. Personnel cost, so only Team
 * Admins read it – never the person, never a Lead's name card
 * ({@see PersonAccess}). Pure; checks only the shape.
 */
final class PersonRules {
	public const COST_RATES = 'cost_rates';
	public const CREDITOR = 'creditor_account';
	/** What only Team Admins read, even on the own person. */
	public const ADMIN_ONLY = [self::COST_RATES];

	/**
	 * `cost_rates`: missing or null, or a list of `{from: YYYY-MM-DD, rate: number ≥ 0}`
	 * with no day twice.
	 *
	 * @throws ApiException 422
	 */
	public static function validate(\stdClass $data): void {
		// 0.22.0: the person's creditor account for the DATEV export of expenses.
		$creditor = $data->{self::CREDITOR} ?? null;
		if ($creditor !== null && (!is_string($creditor) || preg_match('/^\d{1,9}$/', $creditor) !== 1)) {
			throw ApiException::invalid('“creditor_account” must be null or 1 to 9 digits.');
		}
		$rates = $data->{self::COST_RATES} ?? null;
		if ($rates === null) {
			return;
		}
		if (!is_array($rates) || !array_is_list($rates)) {
			throw ApiException::invalid('“cost_rates” must be a list of { from, rate }.');
		}
		$seen = [];
		foreach ($rates as $r) {
			$from = $r instanceof \stdClass ? ($r->from ?? null) : null;
			if (!is_string($from) || !Time::isDay($from) || !ProjectRules::isRate($r->rate ?? null)) {
				throw ApiException::invalid('Each entry of “cost_rates” needs a day “from” (YYYY-MM-DD) and a “rate” ≥ 0.');
			}
			if (isset($seen[$from])) {
				throw ApiException::invalid(Message::of('“cost_rates” names the day {from} twice.', ['from' => $from]));
			}
			$seen[$from] = true;
		}
	}
}
