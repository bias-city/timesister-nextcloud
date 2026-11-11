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
 * 0.47.0: `ts_inv_invoices.references_json` – the invoice's reference lines
 * `[{kind, title, value}]`, frozen on issue (`references` in the API; the
 * column name avoids the SQL keyword). Adds only.
 */
final class Version1029Date20261110120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('ts_inv_invoices')) {
			return null;
		}
		$t = $schema->getTable('ts_inv_invoices');
		if ($t->hasColumn('references_json')) {
			return null;
		}
		$t->addColumn('references_json', Types::TEXT, ['notnull' => false]);
		return $schema;
	}
}
