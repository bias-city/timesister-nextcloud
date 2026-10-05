<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One absence a person's client reported: the source event's UID, the
 * category and the days (`end` exclusive, like DTEND of an all-day event).
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getSourceUid()
 * @method void setSourceUid(string $sourceUid)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getStart()
 * @method void setStart(string $start)
 * @method string getEnd()
 * @method void setEnd(string $end)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
final class Absence extends Entity {
	protected int $tenantId = 0;
	protected string $uid = '';
	protected string $sourceUid = '';
	protected string $kind = '';
	protected string $start = '';
	protected string $end = '';
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('uid', Types::STRING);
		$this->addType('sourceUid', Types::STRING);
		$this->addType('kind', Types::STRING);
		$this->addType('start', Types::STRING);
		$this->addType('end', Types::STRING);
		$this->addType('updatedAt', Types::INTEGER);
	}

	/** @return array{uid:string,source_uid:string,kind:string,start:string,end:string} */
	public function row(): array {
		return ['uid' => $this->getUid(), 'source_uid' => $this->getSourceUid(), 'kind' => $this->getKind(),
			'start' => $this->getStart(), 'end' => $this->getEnd()];
	}
}
