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
 * 0.38.0 (invoices without a budget): `ts_inv_invoices.project` and
 * `.budget` may be NULL; new `payment_days`, `kind` and `companion`.
 * Widens and adds only; no row changes.
 */
final class Version1026Date20261105120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('ts_inv_invoices')) {
			return null;
		}
		$t = $schema->getTable('ts_inv_invoices');
		$changed = false;
		foreach (['project', 'budget'] as $name) {
			$column = $t->getColumn($name);
			if ($column->getNotnull()) {
				$column->setNotnull(false);
				$changed = true;
			}
		}
		if (!$t->hasColumn('payment_days')) {
			$t->addColumn('payment_days', Types::SMALLINT, ['notnull' => false]);
			$changed = true;
		}
		if (!$t->hasColumn('kind')) {
			$t->addColumn('kind', Types::STRING, ['notnull' => false, 'length' => 16]);
			$changed = true;
		}
		if (!$t->hasColumn('companion')) {
			$t->addColumn('companion', Types::STRING, ['notnull' => false, 'length' => 128]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
