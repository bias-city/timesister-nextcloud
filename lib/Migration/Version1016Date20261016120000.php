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
 * 0.21.0: VAT per invoice line. `ts_inv_invoices` gets the frozen net and
 * VAT per rate (JSON), the sum of the pass-through items and the total to
 * pay. Null on invoices issued before. Additive only.
 */
final class Version1016Date20261016120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		$invoices = $schema->getTable('ts_inv_invoices');
		foreach ([
			['vat_lines_json', Types::TEXT, ['notnull' => false]],
			['pass_through', Types::DECIMAL, ['notnull' => false, 'precision' => 14, 'scale' => 2]],
			['total', Types::DECIMAL, ['notnull' => false, 'precision' => 14, 'scale' => 2]],
		] as [$name, $type, $options]) {
			if (!$invoices->hasColumn($name)) {
				$invoices->addColumn($name, $type, $options);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}
}
