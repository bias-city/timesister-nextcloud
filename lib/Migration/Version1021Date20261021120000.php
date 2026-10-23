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
 * 0.27.0: the level of a booking (`X-TIMESISTER-LEVEL`) on the entries, and
 * the protocol of corrections. Additive only.
 */
final class Version1021Date20261021120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		$e = $schema->getTable('ts_entries');
		if (!$e->hasColumn('level')) {
			$e->addColumn('level', Types::STRING, ['notnull' => false, 'length' => 40]);
			$changed = true;
		}
		if (!$schema->hasTable('ts_entry_corrections')) {
			$t = $schema->createTable('ts_entry_corrections');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('event_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('actor', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->addColumn('changes', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['tenant_id', 'uid', 'event_uid'], 'ts_corr_entry');
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
