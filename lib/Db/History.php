<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A version of a record. Every write stores its new version here.
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getRkey()
 * @method void setRkey(string $rkey)
 * @method int getVersion()
 * @method void setVersion(int $version)
 * @method int getDeleted()
 * @method void setDeleted(int $deleted)
 * @method string|null getData()
 * @method void setData(?string $data)
 * @method string getModifiedBy()
 * @method void setModifiedBy(string $modifiedBy)
 * @method int getModifiedAt()
 * @method void setModifiedAt(int $modifiedAt)
 */
final class History extends Entity {
	protected int $tenantId = 0;
	protected string $kind = '';
	protected string $rkey = '';
	protected int $version = 0;
	protected int $deleted = 0;
	protected ?string $data = null;
	protected string $modifiedBy = '';
	protected int $modifiedAt = 0;

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('kind', Types::STRING);
		$this->addType('rkey', Types::STRING);
		$this->addType('version', Types::INTEGER);
		$this->addType('deleted', Types::INTEGER);
		$this->addType('data', Types::STRING);
		$this->addType('modifiedBy', Types::STRING);
		$this->addType('modifiedAt', Types::INTEGER);
	}
}
