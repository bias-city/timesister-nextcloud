<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Team-Einstellungen, Fassung 1.2. Nur hinzufügen. */
final class Version1003Date20260926120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$t = $schema->getTable('ts_tenants');
		if (!$t->hasColumn('leads_see_calendars')) {
			$t->addColumn('leads_see_calendars', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
		}
		if (!$t->hasColumn('backup_required')) {
			$t->addColumn('backup_required', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
		}

		return $schema;
	}
}
