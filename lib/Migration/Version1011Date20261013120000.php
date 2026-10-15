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
 * 0.16.0: counters per team, for the invoice number (`invoice:2026` per
 * year, `invoice` across years). `last_no` is the last number given.
 * Additive only.
 */
final class Version1011Date20261013120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('ts_counters')) {
			return null;
		}
		$t = $schema->createTable('ts_counters');
		$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 64]);
		$t->addColumn('last_no', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
		$t->addColumn('modified_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
		$t->setPrimaryKey(['id']);
		$t->addUniqueIndex(['tenant_id', 'name'], 'ts_cnt_name');
		return $schema;
	}
}
