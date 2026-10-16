<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Tenant;

/**
 * Where a team's document tree lies (0.22.0): the backup owner, whose
 * files hold it, and the root from `documents.root`.
 */
final class TeamTree {
	public function __construct(
		private TenantService $team,
	) {
	}

	/** @return array{owner:string,root:string,tenant:Tenant} 422 without a backup owner */
	public function of(int $tenantId): array {
		$t = $this->team->tenant($tenantId);
		$owner = $this->team->backupOwner($t) ?? throw ApiException::invalid('The team has no backup owner; documents need one.');
		return ['owner' => $owner, 'root' => self::rootOf($t), 'tenant' => $t];
	}

	/** The owner or null, for reading stored files. */
	public function ownerOf(int $tenantId): ?string {
		return $this->team->backupOwner($this->team->tenant($tenantId));
	}

	public static function rootOf(Tenant $t): string {
		return DocumentRules::rootOf(DocumentRules::settings($t->getDocumentsJson()), $t->getName());
	}
}
