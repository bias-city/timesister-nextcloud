<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * A team's four roles, from weak to strong. Since API version 2: `admin`
 * is whoever manages the team group in Nextcloud; the app (ts_members)
 * assigns `lead` and `subadmin`, otherwise `user`.
 */
final class Role {
	public const USER = 'user';
	public const LEAD = 'lead';
	public const SUBADMIN = 'subadmin';
	public const ADMIN = 'admin';

	/** All roles, weakest first. */
	public const ALL = [self::USER, self::LEAD, self::SUBADMIN, self::ADMIN];

	/** What PUT /team/members can set; `admin` is not an app role. */
	public const APP_ROLES = [self::USER, self::LEAD, self::SUBADMIN];

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

	/** subadmin and admin manage the team. */
	public static function manages(string $role): bool {
		return self::rank($role) >= self::rank(self::SUBADMIN);
	}

	/** lead and above read everything. */
	public static function readsAll(string $role): bool {
		return self::rank($role) >= self::rank(self::LEAD);
	}
}
