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
 * 0.33.0 (fee models): the category of a booking – `X-TIMESISTER-CATEGORY`
 * of the master in `ts_entries.category`, the category a booking was
 * billed in beside its rate in `ts_inv_hours.category`. Additive only.
 */
final class Version1024Date20261029180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		foreach (['ts_entries', 'ts_inv_hours'] as $table) {
			if (!$schema->hasTable($table)) {
				continue;
			}
			$t = $schema->getTable($table);
			if (!$t->hasColumn('category')) {
				$t->addColumn('category', Types::STRING, ['notnull' => false, 'length' => 40]);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}
}
