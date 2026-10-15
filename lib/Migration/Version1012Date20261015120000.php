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
 * 0.17.0: the team's time entries. `ts_time_calendars` names the time
 * calendar of a member (or the feed of an external), `ts_entries` holds
 * the calendar objects as received; `ts_tenants` gets the entries'
 * revision counter and the cutoff date. Additive only.
 */
final class Version1012Date20261015120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		if (!$schema->hasTable('ts_time_calendars')) {
			$t = $schema->createTable('ts_time_calendars');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			// Account of a member, or the key of an external's person record.
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('calendar_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('calendar_uri', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('feed_url', Types::TEXT, ['notnull' => false]);
			$t->addColumn('named_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->addColumn('last_read_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('last_full_read_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('last_error', Types::TEXT, ['notnull' => false]);
			$t->addColumn('reread', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$t->addColumn('etag', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['tenant_id', 'uid', 'source'], 'ts_tcal_uid');
			// The listener looks a calendar id up on every calendar write.
			$t->addIndex(['calendar_id'], 'ts_tcal_cal');
			$changed = true;
		}
		if (!$schema->hasTable('ts_entries')) {
			$t = $schema->createTable('ts_entries');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->addColumn('tenant_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('event_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('object_uri', Types::STRING, ['notnull' => true, 'default' => '', 'length' => 255]);
			$t->addColumn('etag', Types::STRING, ['notnull' => true, 'default' => '', 'length' => 255]);
			// The object as received; empty on a tombstone.
			$t->addColumn('ics', Types::TEXT, ['notnull' => false]);
			$t->addColumn('summary', Types::STRING, ['notnull' => true, 'default' => '', 'length' => 255]);
			$t->addColumn('codes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('first_start', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('last_end', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('cancelled', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('kept', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('deleted', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('revision', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->addColumn('modified_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['tenant_id', 'uid', 'source', 'event_uid'], 'ts_ent_uid');
			$t->addIndex(['tenant_id', 'revision'], 'ts_ent_rev');
			$changed = true;
		}
		$tenants = $schema->getTable('ts_tenants');
		if (!$tenants->hasColumn('entries_revision')) {
			$tenants->addColumn('entries_revision', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			// Cutoff as YYYY-MM-DD; null: two years before the team was created.
			$tenants->addColumn('entries_since', Types::STRING, ['notnull' => false, 'length' => 10]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
