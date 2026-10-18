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
 * 0.26.1: the frozen layout of an issued invoice (grouping by level,
 * appended tables) as JSON on `ts_inv_invoices`. Additive only.
 */
final class Version1020Date20261017220000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$t = $schema->getTable('ts_inv_invoices');
		if ($t->hasColumn('layout_json')) {
			return null;
		}
		$t->addColumn('layout_json', Types::TEXT, ['notnull' => false]);
		return $schema;
	}
}
