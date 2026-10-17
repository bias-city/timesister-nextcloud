<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCA\TimeSister\Service\InvoicingModeRules;
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
 * @method int getInvoicing()
 * @method void setInvoicing(int $invoicing)
 * @method string|null getDocumentsJson()
 * @method void setDocumentsJson(?string $documentsJson)
 * @method string|null getDatevJson()
 * @method void setDatevJson(?string $datevJson)
 * @method string|null getWebhooksJson()
 * @method void setWebhooksJson(?string $webhooksJson)
 * @method string|null getInvoicingModeJson()
 * @method void setInvoicingModeJson(?string $invoicingModeJson)
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
	/** Team setting `invoicing` (0.18.0), 0 or 1: issuing on the server. */
	protected int $invoicing = 1;
	/** Team setting `documents` (0.19.0) as JSON: folders, per year, chain mail; null: defaults. */
	protected ?string $documentsJson = null;
	/** Team settings `datev` and `webhooks` (0.20.0) as JSON; null: nothing set. */
	protected ?string $datevJson = null;
	protected ?string $webhooksJson = null;
	/** Team setting `invoicing_mode` (0.24.0) as JSON; null: TimeSister issues, no copy. */
	protected ?string $invoicingModeJson = null;

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
		$this->addType('invoicing', Types::SMALLINT);
		$this->addType('documentsJson', Types::STRING);
		$this->addType('datevJson', Types::STRING);
		$this->addType('webhooksJson', Types::STRING);
		$this->addType('invoicingModeJson', Types::STRING);
	}

	/** Issuing on the server (0.18.0) is on for this team. */
	public function invoicingOn(): bool {
		return $this->invoicing === 1;
	}

	/** The team setting `invoicing_mode` (0.24.0). @return ?array{mode:string,system:?string,copy:string,archive_pdf?:bool} */
	public function invoicingMode(): ?array {
		return InvoicingModeRules::decode($this->invoicingModeJson);
	}
}
