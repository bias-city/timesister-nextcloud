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
	public const CARD = ['schema', 'login', 'first_name', 'last_name', 'initials', 'accounts'];

	/** The name card: only the fields from CARD that exist. */
	public static function card(\stdClass $data): \stdClass {
		$out = new \stdClass();
		foreach (self::CARD as $field) {
			if (property_exists($data, $field)) {
				$out->{$field} = $data->{$field};
			}
		}
		return $out;
	}

	/** The own person for whoever does not manage the team: everything but the admin-only fields. */
	public static function own(\stdClass $data): \stdClass {
		$out = clone $data;
		foreach (PersonRules::ADMIN_ONLY as $field) {
			unset($out->{$field});
		}
		return $out;
	}
}
