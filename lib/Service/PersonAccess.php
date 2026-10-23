<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Person records of others for Leads (0.14.0): only who the person is –
 * name, initials, login and accounts. Workload, regions, vacation,
 * entry and exit, calendars, aliases (mail addresses) and the feed stay
 * with Team Admins and the person. The person's `cost_rates` (0.16.0)
 * stay with Team Admins alone. Pure.
 */
final class PersonAccess {
	/** Allowlist: what is missing here only Team Admins and the person read. */
	/** 0.27.0: `company` too - Leads see whose people booked on their projects. */
	public const CARD = ['schema', 'login', 'first_name', 'last_name', 'initials', 'accounts', 'company'];

	/** The name card: only the fields from CARD that exist; a Lead also gets the person's `levels` (0.27.0). */
	public static function card(\stdClass $data, bool $lead = false): \stdClass {
		$out = new \stdClass();
		foreach ($lead ? [...self::CARD, ...PersonRules::LEAD_ONLY] : self::CARD as $field) {
			if (property_exists($data, $field)) {
				$out->{$field} = $data->{$field};
			}
		}
		return $out;
	}

	/** The own person for whoever does not manage the team: everything but the admin-only fields – and, unless a Lead, the lead-only ones. */
	public static function own(\stdClass $data, bool $lead = false): \stdClass {
		$out = clone $data;
		foreach ($lead ? PersonRules::ADMIN_ONLY : [...PersonRules::ADMIN_ONLY, ...PersonRules::LEAD_ONLY] as $field) {
			unset($out->{$field});
		}
		return $out;
	}
}
