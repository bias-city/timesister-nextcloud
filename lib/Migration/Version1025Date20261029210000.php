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
 * 0.33.0 (fee models, one list of levels): the level a booking was billed
 * in, beside its rate, in `ts_inv_hours.level`. Additive only; the
 * `category` columns of {@see Version1024Date20261029180000} stay unused.
 */
final class Version1025Date20261029210000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('ts_inv_hours')) {
			return null;
		}
		$t = $schema->getTable('ts_inv_hours');
		if ($t->hasColumn('level')) {
			return null;
		}
		$t->addColumn('level', Types::STRING, ['notnull' => false, 'length' => 40]);
		return $schema;
	}
}
