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
 * 0.19.0: invoice documents. `ts_inv_invoices` gets the rendering's error,
 * the stored file's path and SHA-256 and the number of tries;
 * `ts_tenants` the team setting `documents` (folders, per year, chain
 * mail) as JSON. Additive only.
 */
final class Version1014Date20261015180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		$invoices = $schema->getTable('ts_inv_invoices');
		if (!$invoices->hasColumn('document_error')) {
			$invoices->addColumn('document_error', Types::TEXT, ['notnull' => false]);
			$changed = true;
		}
		if (!$invoices->hasColumn('document_path')) {
			// Relative to the backup owner's files, e.g. `TimeSister Invoices/Team/2026/RE-2026-0007.pdf`.
			$invoices->addColumn('document_path', Types::STRING, ['notnull' => false, 'length' => 512]);
			$changed = true;
		}
		if (!$invoices->hasColumn('document_sha256')) {
			$invoices->addColumn('document_sha256', Types::STRING, ['notnull' => false, 'length' => 64]);
			$changed = true;
		}
		if (!$invoices->hasColumn('document_tries')) {
			$invoices->addColumn('document_tries', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$changed = true;
		}
		$tenants = $schema->getTable('ts_tenants');
		if (!$tenants->hasColumn('documents_json')) {
			$tenants->addColumn('documents_json', Types::TEXT, ['notnull' => false]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
