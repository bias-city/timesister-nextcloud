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
 * 0.27.0 (K-Serie, Entscheid 2026-10-21): the protocol of corrections names
 * the occurrence (`recurrence`) and whether a row was applied or restored
 * (`kind`). Additive only.
 */
final class Version1022Date20261021150000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('ts_entry_corrections')) {
			return null;
		}
		$t = $schema->getTable('ts_entry_corrections');
		$changed = false;
		if (!$t->hasColumn('recurrence')) {
			$t->addColumn('recurrence', Types::STRING, ['notnull' => false, 'length' => 32]);
			$changed = true;
		}
		if (!$t->hasColumn('kind')) {
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'applied']);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
