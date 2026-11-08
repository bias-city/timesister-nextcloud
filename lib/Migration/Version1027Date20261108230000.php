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
 * 0.44.0: `ts_inv_invoices.cost_center` – the customer's cost center or
 * reference, frozen with the invoice and printed in its head. Adds only.
 */
final class Version1027Date20261108230000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('ts_inv_invoices')) {
			return null;
		}
		$t = $schema->getTable('ts_inv_invoices');
		if ($t->hasColumn('cost_center')) {
			return null;
		}
		$t->addColumn('cost_center', Types::STRING, ['notnull' => false, 'length' => 120]);
		return $schema;
	}
}
