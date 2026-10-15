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
 * 0.18.0: invoicing on the server. `ts_inv_invoices` holds the frozen
 * invoice, `ts_inv_hours` the hours it bills, `ts_inv_ledger` the
 * append-only ledger with the SHA-256 chain; `ts_tenants` gets the team
 * setting `invoicing`. Amounts are DECIMAL (exact; the entity carries them
 * as strings, the API hands floats out). Additive only.
 */
final class Version1013Date20261015150000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		$money = ['notnull' => true, 'precision' => 14, 'scale' => 2, 'default' => 0];
		if (!$schema->hasTable('ts_inv_invoices')) {
			$t = $schema->createTable('ts_inv_invoices');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// The key of the record `invoice` this row freezes.
			$t->addColumn('rkey', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('number', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('project', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('budget', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('customer', Types::STRING, ['notnull' => false, 'length' => 128]);
			$t->addColumn('period_from', Types::STRING, ['notnull' => true, 'length' => 10]);
			$t->addColumn('period_to', Types::STRING, ['notnull' => true, 'length' => 10]);
			$t->addColumn('currency', Types::STRING, ['notnull' => true, 'length' => 3]);
			$t->addColumn('vat', Types::DECIMAL, ['notnull' => true, 'precision' => 5, 'scale' => 2, 'default' => 0]);
			$t->addColumn('issuer_json', Types::TEXT, ['notnull' => false]);
			$t->addColumn('customer_json', Types::TEXT, ['notnull' => false]);
			$t->addColumn('positions_json', Types::TEXT, ['notnull' => false]);
			$t->addColumn('greeting', Types::TEXT, ['notnull' => false]);
			$t->addColumn('body', Types::TEXT, ['notnull' => false]);
			$t->addColumn('footer', Types::TEXT, ['notnull' => false]);
			$t->addColumn('net', Types::DECIMAL, $money);
			$t->addColumn('vat_amount', Types::DECIMAL, $money);
			$t->addColumn('gross', Types::DECIMAL, $money);
			$t->addColumn('hours', Types::DECIMAL, ['notnull' => true, 'precision' => 10, 'scale' => 2, 'default' => 0]);
			$t->addColumn('issued_on', Types::STRING, ['notnull' => true, 'length' => 10]);
			$t->addColumn('issued_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('due_on', Types::STRING, ['notnull' => true, 'length' => 10]);
			$t->addColumn('paid_on', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('paid_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('reverses', Types::STRING, ['notnull' => false, 'length' => 128]);
			$t->addColumn('reversed_by', Types::STRING, ['notnull' => false, 'length' => 128]);
			$t->addColumn('credits', Types::STRING, ['notnull' => false, 'length' => 128]);
			// The ledger entry of the issue.
			$t->addColumn('seq', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('prev_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			// Documents (0.19.0): file ids and the state of rendering.
			$t->addColumn('pdf_file_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('xml_file_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('document_state', Types::STRING, ['notnull' => true, 'default' => 'pending', 'length' => 16]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->addColumn('modified_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['tenant_id', 'rkey'], 'ts_invi_key');
			$t->addUniqueIndex(['tenant_id', 'number'], 'ts_invi_no');
			$changed = true;
		}
		if (!$schema->hasTable('ts_inv_hours')) {
			$t = $schema->createTable('ts_inv_hours');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			// The team as well, so every query stays within it.
			$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('invoice_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('event_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
			// The occurrence: a day or a local time, as in the invoice's bookings.
			$t->addColumn('start', Types::STRING, ['notnull' => true, 'length' => 19]);
			$t->addColumn('hours', Types::DECIMAL, ['notnull' => true, 'precision' => 10, 'scale' => 2, 'default' => 0]);
			$t->addColumn('rate', Types::DECIMAL, ['notnull' => true, 'precision' => 10, 'scale' => 2, 'default' => 0]);
			$t->addColumn('amount', Types::DECIMAL, $money);
			$t->addColumn('code', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['invoice_id', 'uid', 'event_uid', 'start'], 'ts_invh_occ');
			$t->addIndex(['tenant_id', 'uid', 'event_uid'], 'ts_invh_ev');
			$changed = true;
		}
		if (!$schema->hasTable('ts_inv_ledger')) {
			$t = $schema->createTable('ts_inv_ledger');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('seq', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// UTC, YYYY-MM-DDTHH:MM:SSZ – part of the hash, so stored as text.
			$t->addColumn('at', Types::STRING, ['notnull' => true, 'length' => 20]);
			$t->addColumn('actor', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('invoice_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('number', Types::STRING, ['notnull' => true, 'length' => 64]);
			// The canonical JSON the payload hash was taken of, for verifying and for the clients.
			$t->addColumn('payload', Types::TEXT, ['notnull' => false]);
			$t->addColumn('payload_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('prev_hash', Types::STRING, ['notnull' => true, 'default' => '', 'length' => 64]);
			$t->addColumn('hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['tenant_id', 'seq'], 'ts_invl_seq');
			$changed = true;
		}
		$tenants = $schema->getTable('ts_tenants');
		if (!$tenants->hasColumn('invoicing')) {
			// Team setting `invoicing` (default on): off, the invoicing endpoints answer 404.
			$tenants->addColumn('invoicing', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
