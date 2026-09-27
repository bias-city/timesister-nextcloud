<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A file the app created in a person's home (copy with the backup owner or
 * in the own folder). The app may only delete what is listed here, and only
 * as long as the file ID still points to this path.
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getTarget()
 * @method void setTarget(string $target)
 * @method string getOwner()
 * @method void setOwner(string $owner)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getPath()
 * @method void setPath(string $path)
 * @method string getTakenOn()
 * @method void setTakenOn(string $takenOn)
 * @method string getSha256()
 * @method void setSha256(string $sha256)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
final class BackupFile extends Entity {
	public const ADMIN = 'admin';
	public const OWN = 'own';

	protected int $tenantId = 0;
	protected string $uid = '';
	protected string $target = '';
	protected string $owner = '';
	protected int $fileId = 0;
	protected string $path = '';
	protected string $takenOn = '';
	protected string $sha256 = '';
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('uid', Types::STRING);
		$this->addType('target', Types::STRING);
		$this->addType('owner', Types::STRING);
		$this->addType('fileId', Types::INTEGER);
		$this->addType('path', Types::STRING);
		$this->addType('takenOn', Types::STRING);
		$this->addType('sha256', Types::STRING);
		$this->addType('createdAt', Types::INTEGER);
	}
}
