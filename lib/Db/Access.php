<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One set field of the shares matrix: who (`viewer`) sees whose time
 * calendar (`owner`), at which `level`, set by a Team Admin (`admin`) or by
 * the owner (`self`). Fields without an entry have the default.
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method string getViewer()
 * @method void setViewer(string $viewer)
 * @method string getOwner()
 * @method void setOwner(string $owner)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method string getLevel()
 * @method void setLevel(string $level)
 * @method string getUpdatedBy()
 * @method void setUpdatedBy(string $updatedBy)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
final class Access extends Entity {
	protected int $tenantId = 0;
	protected string $viewer = '';
	protected string $owner = '';
	protected string $source = '';
	protected string $level = '';
	protected string $updatedBy = '';
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('viewer', Types::STRING);
		$this->addType('owner', Types::STRING);
		$this->addType('source', Types::STRING);
		$this->addType('level', Types::STRING);
		$this->addType('updatedBy', Types::STRING);
		$this->addType('updatedAt', Types::INTEGER);
	}
}
