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
 * Backups, version 1.1: origin and visible copy per backup, backup owner
 * per team, consent per account. Additive only.
 */
final class Version1001Date20260926090000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$t = $schema->getTable('ts_backups');
		if (!$t->hasColumn('source')) {
			// Existing rows all came from clients.
			$t->addColumn('source', Types::STRING, ['notnull' => true, 'default' => 'client', 'length' => 8]);
		}
		if (!$t->hasColumn('file_path')) {
			// Path of the visible copy in file_owner's folder.
			$t->addColumn('file_path', Types::STRING, ['notnull' => false, 'length' => 512]);
		}
		if (!$t->hasColumn('file_owner')) {
			$t->addColumn('file_owner', Types::STRING, ['notnull' => false, 'length' => 64]);
		}

		$t = $schema->getTable('ts_tenants');
		if (!$t->hasColumn('backup_owner')) {
			$t->addColumn('backup_owner', Types::STRING, ['notnull' => false, 'length' => 64]);
		}

		if (!$schema->hasTable('ts_backup_consent')) {
			$t = $schema->createTable('ts_backup_consent');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('consent', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('since', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('revoked_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			// Last confirmed version of the disclosure text, stays when withdrawn.
			$t->addColumn('notice', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('notice_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['tenant_id', 'uid'], 'ts_bc_uid');
		}

		return $schema;
	}
}
