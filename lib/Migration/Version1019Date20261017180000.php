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
 * 0.25.1: the team setting `catalogs` (the template catalogs in use) as
 * JSON on `ts_tenants`. Null: the client's default. Additive only.
 */
final class Version1019Date20261017180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$tenants = $schema->getTable('ts_tenants');
		if ($tenants->hasColumn('catalogs_json')) {
			return null;
		}
		$tenants->addColumn('catalogs_json', Types::TEXT, ['notnull' => false]);
		return $schema;
	}
}
