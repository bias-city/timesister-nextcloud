<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * App-Rolle und Austritt eines Kontos in einem Team (Fassung 2). `role` ist
 * `lead`, `subadmin` oder null (user); `admin` kommt aus Nextcloud.
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
 */
final class Member extends Entity {
	protected int $tenantId = 0;
	protected string $uid = '';
	protected ?string $role = null;
	protected ?int $leftAt = null;
	protected string $updatedBy = '';
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('uid', Types::STRING);
		$this->addType('role', Types::STRING);
		$this->addType('leftAt', Types::INTEGER);
		$this->addType('updatedBy', Types::STRING);
		$this->addType('updatedAt', Types::INTEGER);
	}

	/** @return array{role:?string,left_at:?int} */
	public function entry(): array {
		return ['role' => $this->getRole(), 'left_at' => $this->getLeftAt()];
	}
}
