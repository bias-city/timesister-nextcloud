<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Ein Team (Mandant).
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
 */
final class Tenant extends Entity {
	protected string $name = '';
	protected string $slug = '';
	protected int $revision = 0;
	protected int $createdAt = 0;
	protected ?int $brokenAt = null;

	public function __construct() {
		$this->addType('name', Types::STRING);
		$this->addType('slug', Types::STRING);
		$this->addType('revision', Types::INTEGER);
		$this->addType('createdAt', Types::INTEGER);
		$this->addType('brokenAt', Types::INTEGER);
	}
}
