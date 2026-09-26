<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/** Wer fragt: Konto, Team und stärkste Rolle darin. */
final class Membership {
	public function __construct(
		public readonly string $uid,
		public readonly int $tenantId,
		public readonly string $role,
	) {
	}

	public function manages(): bool {
		return Role::manages($this->role);
	}
}
