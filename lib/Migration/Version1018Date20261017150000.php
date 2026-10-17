<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.24.1: `ts_inv_ledger.action` from 16 to 32 characters – the new
 * actions `external_document` and `external_cancelled` are longer. Widens
 * only; no row changes.
 */
final class Version1018Date20261017150000 extends SimpleMigrationStep {
	public const ACTION_LENGTH = 32;

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('ts_inv_ledger')) {
			return null;
		}
		$column = $schema->getTable('ts_inv_ledger')->getColumn('action');
		if (($column->getLength() ?? 0) >= self::ACTION_LENGTH) {
			return null;
		}
		$column->setLength(self::ACTION_LENGTH);
		return $schema;
	}
}
