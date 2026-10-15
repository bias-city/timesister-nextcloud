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
 * 0.12.0: the projects a person booked on, as their client reports them
 * (`POST /status`, `projects`). They give a Lead the people on their
 * projects in the shares matrix. Additive only.
 */
final class Version1009Date20261006120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$s = $schema->getTable('ts_client_status');
		if ($s->hasColumn('projects')) {
			return null;
		}
		// JSON list of project keys; null: never reported.
		$s->addColumn('projects', Types::TEXT, ['notnull' => false]);
		$s->addColumn('projects_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		return $schema;
	}
}
