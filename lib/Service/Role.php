<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Die vier Rollen eines Teams, von schwach nach stark. Seit Fassung 2:
 * `admin` ist, wer die Teamgruppe in Nextcloud verwaltet; `lead` und
 * `subadmin` vergibt die App (ts_members), sonst `user`.
 */
final class Role {
	public const USER = 'user';
	public const LEAD = 'lead';
	public const SUBADMIN = 'subadmin';
	public const ADMIN = 'admin';

	/** Alle Rollen, schwächste zuerst. */
	public const ALL = [self::USER, self::LEAD, self::SUBADMIN, self::ADMIN];

	/** Was PUT /team/members setzen kann; `admin` ist keine App-Rolle. */
	public const APP_ROLES = [self::USER, self::LEAD, self::SUBADMIN];

	/** Die Teamgruppe: die einzige Zeile in ts_role_groups, die zählt (Fassung 2). */
	public const TEAM_GROUP = 'team';

	/**
	 * Fassung 1, ungenutzt: die Konten-Gruppe. Alte Zeilen mit diesem oder
	 * einem Rollenwert in ts_role_groups bleiben stehen und werden ignoriert.
	 */
	public const ACCOUNTS = 'accounts';

	public static function isValid(string $role): bool {
		return in_array($role, self::ALL, true);
	}

	public static function rank(string $role): int {
		$i = array_search($role, self::ALL, true);
		if ($i === false) {
			throw new \InvalidArgumentException('Unbekannte Rolle');
		}
		return $i;
	}

	/** Die stärkere von zwei Rollen. */
	public static function stronger(string $a, string $b): string {
		return self::rank($a) >= self::rank($b) ? $a : $b;
	}

	/** subadmin und admin verwalten das Team. */
	public static function manages(string $role): bool {
		return self::rank($role) >= self::rank(self::SUBADMIN);
	}

	/** lead und darüber lesen alles. */
	public static function readsAll(string $role): bool {
		return self::rank($role) >= self::rank(self::LEAD);
	}
}
