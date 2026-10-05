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
 * 0.9.0: the absences a person's client reports (`PUT /me/absences`) – the
 * source of the shared vacation calendar. One row per person and source
 * event; days as YYYY-MM-DD, `end` exclusive. Additive only.
 */
final class Version1007Date20261004120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('ts_absences')) {
			return null;
		}
		$t = $schema->createTable('ts_absences');
		$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
		$t->addColumn('source_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
		$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 32]);
		$t->addColumn('start', Types::STRING, ['notnull' => true, 'length' => 10]);
		$t->addColumn('end', Types::STRING, ['notnull' => true, 'length' => 10]);
		$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
		$t->setPrimaryKey(['id']);
		$t->addUniqueIndex(['tenant_id', 'uid', 'source_uid'], 'ts_abs_key');
		return $schema;
	}
}
