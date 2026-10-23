<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * The record of a correction (0.27.0): who changed which booking when, and
 * what – the book's protocol, read by Team Admins and Leads.
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getEventUid()
 * @method void setEventUid(string $eventUid)
 * @method string getActor()
 * @method void setActor(string $actor)
 * @method int getAt()
 * @method void setAt(int $at)
 * @method string getChanges()
 * @method void setChanges(string $changes)
 * @method ?string getRecurrence()
 * @method void setRecurrence(?string $recurrence)
 * @method string getKind()
 * @method void setKind(string $kind)
 */
final class Correction extends Entity {
	protected int $tenantId = 0;
	protected string $uid = '';
	protected string $eventUid = '';
	protected string $actor = '';
	protected int $at = 0;
	protected string $changes = '';
	/** K-Serie: the occurrence (`YYYYMMDDTHHMMSS` / `YYYYMMDD`), null for the whole series. */
	protected ?string $recurrence = null;
	/** `applied` by a Lead or Team Admin, `reapplied` by the book after the person changed a corrected field. */
	protected string $kind = 'applied';

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('uid', Types::STRING);
		$this->addType('eventUid', Types::STRING);
		$this->addType('actor', Types::STRING);
		$this->addType('at', Types::INTEGER);
		$this->addType('changes', Types::STRING);
		$this->addType('recurrence', Types::STRING);
		$this->addType('kind', Types::STRING);
	}

	/** @return array<string,mixed> */
	public function present(): array {
		$changes = json_decode($this->getChanges(), true);
		return [
			'uid' => $this->getUid(),
			'event_uid' => $this->getEventUid(),
			'by' => $this->getActor(),
			'at' => gmdate('Y-m-d\TH:i:s\Z', $this->getAt()),
			'changes' => is_array($changes) ? $changes : [],
			'recurrence' => $this->getRecurrence(),
			'kind' => $this->getKind(),
		];
	}
}
