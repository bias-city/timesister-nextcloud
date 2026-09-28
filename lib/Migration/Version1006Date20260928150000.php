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
 * 0.7.2: the weekly numbers of a person's Jobs, as their client reports
 * them (`POST /jobs/weeks`). Additive only.
 */
final class Version1006Date20260928150000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$s = $schema->getTable('ts_client_status');
		if ($s->hasColumn('job_weeks')) {
			return null;
		}
		// JSON from JobWeeks::parse; null: never reported.
		$s->addColumn('job_weeks', Types::TEXT, ['notnull' => false]);
		$s->addColumn('job_weeks_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
		return $schema;
	}
}
