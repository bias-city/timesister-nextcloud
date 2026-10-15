<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Migration;

use Closure;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.11.0: the role Lead is no longer set, it follows from the leads of
 * the active projects. Every stored `lead` (or old `subadmin`) in
 * ts_members becomes User (stored as empty); the rights stay through the
 * derivation while the person leads a project. No schema change.
 */
final class Version1008Date20261006090000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$stored = ['lead', 'subadmin'];
		// Count per team first, so the log says what the derivation now has to carry.
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.tenant_id', 't.slug')
			->selectAlias($qb->func()->count('m.uid'), 'n')
			->from('ts_members', 'm')
			->leftJoin('m', 'ts_tenants', 't', $qb->expr()->eq('t.id', 'm.tenant_id'))
			->where($qb->expr()->in('m.role', $qb->createNamedParameter($stored, IQueryBuilder::PARAM_STR_ARRAY)))
			->groupBy('m.tenant_id', 't.slug')
			->orderBy('m.tenant_id');
		$result = $qb->executeQuery();
		$perTeam = [];
		while ($row = $result->fetch()) {
			$perTeam[] = sprintf('%s (team %d): %d', (string)($row['slug'] ?? '?'), (int)$row['tenant_id'], (int)$row['n']);
		}
		$result->closeCursor();

		$qb = $this->db->getQueryBuilder();
		$n = $qb->update('ts_members')
			->set('role', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->in('role', $qb->createNamedParameter($stored, IQueryBuilder::PARAM_STR_ARRAY)))
			->executeStatement();
		if ($n > 0) {
			$output->info("TimeSister: $n stored Lead role(s) are now derived from the project leads – " . implode(', ', $perTeam));
		}
	}
}
