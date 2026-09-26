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
 * Sicherungen nur bei Änderung, dann ausdünnen: Merkliste der Dateien, die
 * die App in Heimen von Personen anlegt. Nur hinzufügen.
 */
final class Version1002Date20260926110000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('ts_backup_files')) {
			$t = $schema->createTable('ts_backup_files');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// Wessen Kalender, und bei wem die Datei liegt (Sicherungs-Konto oder die Person selbst).
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('target', Types::STRING, ['notnull' => true, 'length' => 8]);
			$t->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('path', Types::STRING, ['notnull' => true, 'length' => 512]);
			$t->addColumn('taken_on', Types::STRING, ['notnull' => true, 'length' => 10]);
			$t->addColumn('sha256', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['uid', 'target'], 'ts_bf_uid');
			$t->addIndex(['owner'], 'ts_bf_owner');
		}

		return $schema;
	}
}
