<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The app's six tables (plan 3.2). Times as Unix seconds (UTC), JSON as
 * text.
 */
final class Version1000Date20260926000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ts_tenants')) {
			$t = $schema->createTable('ts_tenants');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 200]);
			$t->addColumn('slug', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('revision', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->addColumn('broken_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['slug'], 'ts_tenants_slug');
		}

		if (!$schema->hasTable('ts_role_groups')) {
			$t = $schema->createTable('ts_role_groups');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('gid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->setPrimaryKey(['id']);
			// A group belongs to at most one team, a role has exactly one group.
			$t->addUniqueIndex(['gid'], 'ts_rg_gid');
			$t->addUniqueIndex(['tenant_id', 'role'], 'ts_rg_role');
		}

		if (!$schema->hasTable('ts_records')) {
			$t = $schema->createTable('ts_records');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('rkey', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('data', Types::TEXT, ['notnull' => false]);
			$t->addColumn('accounts', Types::TEXT, ['notnull' => false]);
			$t->addColumn('version', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->addColumn('revision', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->addColumn('deleted', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('modified_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('modified_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->addColumn('account_deleted_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['tenant_id', 'kind', 'rkey'], 'ts_rec_key');
			$t->addIndex(['tenant_id', 'revision'], 'ts_rec_rev');
		}

		if (!$schema->hasTable('ts_history')) {
			$t = $schema->createTable('ts_history');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('rkey', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('version', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('deleted', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('data', Types::TEXT, ['notnull' => false]);
			$t->addColumn('modified_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('modified_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['tenant_id', 'kind', 'rkey', 'version'], 'ts_hist_ver');
			$t->addIndex(['modified_at'], 'ts_hist_time');
		}

		if (!$schema->hasTable('ts_client_status')) {
			$t = $schema->createTable('ts_client_status');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('app_version', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('last_sync', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('last_backup', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('calendar_url', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('seen_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['tenant_id', 'uid'], 'ts_cs_uid');
		}

		if (!$schema->hasTable('ts_backups')) {
			$t = $schema->createTable('ts_backups');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('taken_on', Types::STRING, ['notnull' => true, 'length' => 10]);
			$t->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->addColumn('sha256', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['tenant_id', 'uid', 'taken_on'], 'ts_bk_day');
		}

		return $schema;
	}
}
