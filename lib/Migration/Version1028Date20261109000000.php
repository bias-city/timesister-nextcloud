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
 * 0.45.0: `ts_client_status.editing` and `.editing_at` – the records a
 * person holds open for editing, reported with the status (presence, not a
 * lock). Adds only.
 */
final class Version1028Date20261109000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('ts_client_status')) {
			return null;
		}
		$s = $schema->getTable('ts_client_status');
		if ($s->hasColumn('editing')) {
			return null;
		}
		$s->addColumn('editing', Types::TEXT, ['notnull' => false]);
		$s->addColumn('editing_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		return $schema;
	}
}
