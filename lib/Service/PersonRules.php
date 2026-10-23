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
	/** 0.27.0: the person's level, valid from a day on ({@see LevelRules}) - Team Admins and Leads read it. */
	public const LEVELS = LevelRules::PERSON_FIELD;
	/** 0.27.0: the person's company; null or empty means the team's own. */
	public const COMPANY = 'company';
	public const COMPANY_MAX = 80;
	public const CREDITOR = 'creditor_account';
	/** What only Team Admins read, even on the own person. */
	public const ADMIN_ONLY = [self::COST_RATES];
	/** What Team Admins and Leads read, never a member - not on the own person either. */
	public const LEAD_ONLY = [self::LEVELS];

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
		// 0.27.0: the person's company, null or short text.
		$company = $data->{self::COMPANY} ?? null;
		if ($company !== null && (!is_string($company) || mb_strlen($company) > self::COMPANY_MAX || !mb_check_encoding($company, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $company))) {
			throw ApiException::invalid(Message::of('“{field}” must be null or text of at most {max} characters.', ['field' => self::COMPANY, 'max' => self::COMPANY_MAX]));
		}
		self::rates($data, self::COST_RATES);
		LevelRules::validatePerson($data->{self::LEVELS} ?? null);
	}

	/** A rate series `{from, rate}` per day, each day once; missing or null passes. */
	private static function rates(\stdClass $data, string $field): void {
		$rates = $data->{$field} ?? null;
		if ($rates === null) {
			return;
		}
		if (!is_array($rates) || !array_is_list($rates)) {
			throw ApiException::invalid(Message::of('“{field}” must be a list of { from, rate }.', ['field' => $field]));
		}
		$seen = [];
		foreach ($rates as $r) {
			$from = $r instanceof \stdClass ? ($r->from ?? null) : null;
			if (!is_string($from) || !Time::isDay($from) || !ProjectRules::isRate($r->rate ?? null)) {
				throw ApiException::invalid(Message::of('Each entry of “{field}” needs a day “from” (YYYY-MM-DD) and a “rate” ≥ 0.', ['field' => $field]));
			}
			if (isset($seen[$from])) {
				throw ApiException::invalid(Message::of('“{field}” names the day {from} twice.', ['field' => $field, 'from' => $from]));
			}
			$seen[$from] = true;
		}
	}
}
