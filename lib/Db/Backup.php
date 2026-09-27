<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A calendar backup. The file lives in IAppData under
 * `t<team>/<account>/<day>.ics`; the name never comes from the request. The
 * visible copy lives with `fileOwner` under `filePath`.
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getTakenOn()
 * @method void setTakenOn(string $takenOn)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method string getSha256()
 * @method void setSha256(string $sha256)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method string|null getFilePath()
 * @method void setFilePath(?string $filePath)
 * @method string|null getFileOwner()
 * @method void setFileOwner(?string $fileOwner)
 */
final class Backup extends Entity {
	protected int $tenantId = 0;
	protected string $uid = '';
	protected string $takenOn = '';
	protected int $size = 0;
	protected string $sha256 = '';
	protected int $createdAt = 0;
	protected string $source = 'client';
	protected ?string $filePath = null;
	protected ?string $fileOwner = null;

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('uid', Types::STRING);
		$this->addType('takenOn', Types::STRING);
		$this->addType('size', Types::INTEGER);
		$this->addType('sha256', Types::STRING);
		$this->addType('createdAt', Types::INTEGER);
		$this->addType('source', Types::STRING);
		$this->addType('filePath', Types::STRING);
		$this->addType('fileOwner', Types::STRING);
	}
}
