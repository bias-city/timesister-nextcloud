<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * A team's three roles, from weak to strong (since 0.5.0). `admin` (shown
 * as “Team Admin”) is whoever manages the team group in Nextcloud; `lead`
 * follows from the leads of the active projects (0.11.0, {@see
 * LeadResolver}) and is never set; otherwise `user`. The words people see
 * come from {@see RoleName}.
 */
final class Role {
	public const USER = 'user';
	public const LEAD = 'lead';
	public const ADMIN = 'admin';

	/**
	 * Until 0.4.0 the role “Manager”. The migration to 0.5.0 turned stored
	 * entries into `lead`; since 0.11.0 no stored role counts any more.
	 */
	public const LEGACY_SUBADMIN = 'subadmin';

	/** All roles, weakest first. */
	public const ALL = [self::USER, self::LEAD, self::ADMIN];

	/** What PUT /team/members can set (0.11.0: no `lead`); `admin` sets the group admin in Nextcloud. */
	public const APP_ROLES = [self::USER, self::ADMIN];

	/** The team group: the only row in ts_role_groups that counts (API version 2). */
	public const TEAM_GROUP = 'team';

	/**
	 * API version 1, unused: the accounts group. Old rows with this or a
	 * role value in ts_role_groups are left as is and ignored.
	 */
	public const ACCOUNTS = 'accounts';

	public static function isValid(string $role): bool {
		return in_array($role, self::ALL, true);
	}

	public static function rank(string $role): int {
		$i = array_search($role, self::ALL, true);
		if ($i === false) {
			throw new \InvalidArgumentException('Unknown role');
		}
		return $i;
	}

	/** The stronger of two roles. */
	public static function stronger(string $a, string $b): string {
		return self::rank($a) >= self::rank($b) ? $a : $b;
	}

	/** Only Team Admins manage the team: people, roles, shares, master data. */
	public static function manages(string $role): bool {
		return $role === self::ADMIN;
	}

	/** Lead and Team Admin read master data of others – a Lead only narrowed (0.14.0, {@see RecordRights}). */
	public static function readsAll(string $role): bool {
		return self::rank($role) >= self::rank(self::LEAD);
	}
}
