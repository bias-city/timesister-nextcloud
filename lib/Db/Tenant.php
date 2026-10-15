<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A team (tenant).
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int|null getBrokenAt()
 * @method void setBrokenAt(?int $brokenAt)
 * @method string|null getBackupOwner()
 * @method void setBackupOwner(?string $backupOwner)
 * @method int getBackupRequired()
 * @method void setBackupRequired(int $backupRequired)
 * @method int getEntriesRevision()
 * @method void setEntriesRevision(int $entriesRevision)
 * @method string|null getEntriesSince()
 * @method void setEntriesSince(?string $entriesSince)
 */
final class Tenant extends Entity {
	protected string $name = '';
	protected string $slug = '';
	protected int $revision = 0;
	protected int $createdAt = 0;
	protected ?int $brokenAt = null;
	/** Chosen backup owner; null: the first admin by identifier. */
	protected ?string $backupOwner = null;
	/** Column of the former setting `leads_see_calendars` (shares matrix, removed in 0.17.0); unused, kept because the table keeps it. */
	protected int $leadsSeeCalendars = 1;
	/** Team setting (API version 1.2), 0 or 1. */
	protected int $backupRequired = 0;
	/** Time entries (0.17.0): the revision counter and the stored cutoff (null: default). */
	protected int $entriesRevision = 0;
	protected ?string $entriesSince = null;

	public function __construct() {
		$this->addType('name', Types::STRING);
		$this->addType('slug', Types::STRING);
		$this->addType('revision', Types::INTEGER);
		$this->addType('createdAt', Types::INTEGER);
		$this->addType('brokenAt', Types::INTEGER);
		$this->addType('backupOwner', Types::STRING);
		$this->addType('leadsSeeCalendars', Types::SMALLINT);
		$this->addType('backupRequired', Types::SMALLINT);
		$this->addType('entriesRevision', Types::INTEGER);
		$this->addType('entriesSince', Types::STRING);
	}
}
