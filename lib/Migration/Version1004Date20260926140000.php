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
 * Fassung 2: App-Rollen und Austritt je Konto und Team, dazu der
 * gemeldete Stand der Kalenderfreigabe. Nur hinzufügen.
 */
final class Version1004Date20260926140000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ts_members')) {
			$t = $schema->createTable('ts_members');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			// lead, subadmin oder null (user).
			$t->addColumn('role', Types::STRING, ['notnull' => false, 'length' => 16]);
			$t->addColumn('left_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('updated_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['tenant_id', 'uid'], 'ts_mem_uid');
			$t->addIndex(['uid'], 'ts_mem_by_uid');
		}

		$s = $schema->getTable('ts_client_status');
		if (!$s->hasColumn('calendar_shared')) {
			// Null: nie gemeldet.
			$s->addColumn('calendar_shared', Types::SMALLINT, ['notnull' => false]);
		}

		return $schema;
	}
}
