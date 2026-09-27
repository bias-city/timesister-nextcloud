<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The role words: “Team Admin · Lead · User” in every language, exactly as
 * in the Mac app (`marke::ROLLEN`). Never through the l10n; tests make sure
 * no translation changes them.
 */
final class RoleName {
	public const TEAM_ADMIN = 'Team Admin';
	public const LEAD = 'Lead';
	public const USER = 'User';

	/** Role → word, strongest first (the order of the columns). */
	public const ALL = [
		Role::ADMIN => self::TEAM_ADMIN,
		Role::LEAD => self::LEAD,
		Role::USER => self::USER,
	];

	/** The word for a role; unknown roles as they are. */
	public static function of(string $role): string {
		if ($role === Role::LEGACY_SUBADMIN) {
			return self::LEAD;
		}
		return self::ALL[$role] ?? $role;
	}
}
