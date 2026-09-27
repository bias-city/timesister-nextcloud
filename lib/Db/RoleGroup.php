<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A team's role and the Nextcloud group for it.
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method string getGid()
 * @method void setGid(string $gid)
 */
final class RoleGroup extends Entity {
	protected int $tenantId = 0;
	protected string $role = '';
	protected string $gid = '';

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('role', Types::STRING);
		$this->addType('gid', Types::STRING);
	}
}
