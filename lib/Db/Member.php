<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * App role, leaving date and “allow overriding” of an account in a team.
 * `role` is `lead` or null (user); `admin` comes from Nextcloud (group
 * admin). Until 0.4.0 also `subadmin`, which the migration made `lead`.
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string|null getRole()
 * @method void setRole(?string $role)
 * @method int|null getLeftAt()
 * @method void setLeftAt(?int $leftAt)
 * @method string getUpdatedBy()
 * @method void setUpdatedBy(string $updatedBy)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method int|null getMayOverride()
 * @method void setMayOverride(?int $mayOverride)
 */
final class Member extends Entity {
	protected int $tenantId = 0;
	protected string $uid = '';
	protected ?string $role = null;
	protected ?int $leftAt = null;
	protected string $updatedBy = '';
	protected int $updatedAt = 0;
	/** 1: the person may override the shares of their own column. */
	protected ?int $mayOverride = null;

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('uid', Types::STRING);
		$this->addType('role', Types::STRING);
		$this->addType('leftAt', Types::INTEGER);
		$this->addType('updatedBy', Types::STRING);
		$this->addType('updatedAt', Types::INTEGER);
		$this->addType('mayOverride', Types::SMALLINT);
	}

	/** @return array{role:?string,left_at:?int,may_override:bool} */
	public function entry(): array {
		return ['role' => $this->getRole(), 'left_at' => $this->getLeftAt(), 'may_override' => $this->getMayOverride() === 1];
	}
}
