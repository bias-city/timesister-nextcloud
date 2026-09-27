<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.5.0: three roles and the shares matrix. Additive only: the shares
 * (ts_access), “allow overriding” per member, the shares a client reports
 * as set; afterwards every stored `subadmin` becomes `lead`.
 */
final class Version1005Date20260927100000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ts_access')) {
			$t = $schema->createTable('ts_access');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// Who sees (row) and whose time calendar (column).
			$t->addColumn('viewer', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 64]);
			// admin: set by a Team Admin; self: the owner's own choice.
			$t->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 8]);
			// none, view or edit.
			$t->addColumn('level', Types::STRING, ['notnull' => true, 'length' => 8]);
			$t->addColumn('updated_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['tenant_id', 'viewer', 'owner', 'source'], 'ts_acc_field');
			$t->addIndex(['viewer'], 'ts_acc_viewer');
			$t->addIndex(['owner'], 'ts_acc_owner');
		}

		$m = $schema->getTable('ts_members');
		if (!$m->hasColumn('may_override')) {
			// Null or 0: the person only sees their column.
			$m->addColumn('may_override', Types::SMALLINT, ['notnull' => false]);
		}

		$s = $schema->getTable('ts_client_status');
		if (!$s->hasColumn('applied_shares')) {
			// JSON: [{uid, access}] as the client set them; null: never reported.
			$s->addColumn('applied_shares', Types::TEXT, ['notnull' => false]);
			$s->addColumn('applied_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		}

		return $schema;
	}

	/** The role Manager is gone: every stored `subadmin` becomes `lead`. */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();
		$n = $qb->update('ts_members')
			->set('role', $qb->createNamedParameter('lead'))
			->where($qb->expr()->eq('role', $qb->createNamedParameter('subadmin')))
			->executeStatement();
		if ($n > 0) {
			$output->info("TimeSister: $n former Manager role(s) are now Lead");
		}
	}
}
