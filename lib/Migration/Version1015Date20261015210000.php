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
 * 0.20.0: e-invoices, exports and webhooks. `ts_inv_invoices` gets the
 * counter number taken at issue, the QR reference, the XRechnung file's
 * path and hash and the hand-over to an accounting system; `ts_tenants`
 * the team settings `datev` and `webhooks` as JSON; the new table
 * `ts_inv_webhook_queue` holds the deliveries. Additive only.
 */
final class Version1015Date20261015210000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		$invoices = $schema->getTable('ts_inv_invoices');
		foreach ([
			['counter_no', Types::INTEGER, ['notnull' => false]],
			['qr_reference', Types::STRING, ['notnull' => false, 'length' => 27]],
			['xml_path', Types::STRING, ['notnull' => false, 'length' => 512]],
			['xml_sha256', Types::STRING, ['notnull' => false, 'length' => 64]],
			['external_json', Types::TEXT, ['notnull' => false]],
		] as [$name, $type, $options]) {
			if (!$invoices->hasColumn($name)) {
				$invoices->addColumn($name, $type, $options);
				$changed = true;
			}
		}
		$tenants = $schema->getTable('ts_tenants');
		foreach (['datev_json', 'webhooks_json'] as $name) {
			if (!$tenants->hasColumn($name)) {
				$tenants->addColumn($name, Types::TEXT, ['notnull' => false]);
				$changed = true;
			}
		}
		if (!$schema->hasTable('ts_inv_webhook_queue')) {
			$t = $schema->createTable('ts_inv_webhook_queue');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('ledger_seq', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('url', Types::STRING, ['notnull' => true, 'length' => 512]);
			$t->addColumn('event', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('body', Types::TEXT, ['notnull' => false]);
			$t->addColumn('tries', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('next_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->addColumn('last_error', Types::TEXT, ['notnull' => false]);
			$t->addColumn('delivered_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['tenant_id'], 'ts_invwh_tenant');
			$t->addIndex(['delivered_at', 'next_at'], 'ts_invwh_due');
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
