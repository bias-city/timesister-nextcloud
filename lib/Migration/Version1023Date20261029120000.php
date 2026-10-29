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
 * 0.32.0 (one renderer): the issue is a reservation first – `issue_seq`,
 * `issue_prev_hash` and `issue_expires_at` hold the chain link until the
 * app's PDF closes it – and the filed documents stand in `documents_json`.
 * Additive only; `document_state`, `document_error` and `document_tries`
 * stay in place unused.
 */
final class Version1023Date20261029120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('ts_inv_invoices')) {
			return null;
		}
		$t = $schema->getTable('ts_inv_invoices');
		$changed = false;
		if (!$t->hasColumn('issue_seq')) {
			$t->addColumn('issue_seq', Types::INTEGER, ['notnull' => false]);
			$changed = true;
		}
		if (!$t->hasColumn('issue_prev_hash')) {
			$t->addColumn('issue_prev_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$changed = true;
		}
		if (!$t->hasColumn('issue_expires_at')) {
			$t->addColumn('issue_expires_at', Types::BIGINT, ['notnull' => false]);
			$changed = true;
		}
		if (!$t->hasColumn('documents_json')) {
			$t->addColumn('documents_json', Types::TEXT, ['notnull' => false]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
