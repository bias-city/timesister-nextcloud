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
 * 0.24.0: the team setting `invoicing_mode` (TimeSister, with a copy to an
 * accounting system, or handed over) as JSON on `ts_tenants`. Null: the
 * default, TimeSister issues. Additive only.
 */
final class Version1017Date20261017120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$tenants = $schema->getTable('ts_tenants');
		if ($tenants->hasColumn('invoicing_mode_json')) {
			return null;
		}
		$tenants->addColumn('invoicing_mode_json', Types::TEXT, ['notnull' => false]);
		return $schema;
	}
}
