<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Das letzte Lebenszeichen eines Clients.
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string|null getAppVersion()
 * @method void setAppVersion(?string $appVersion)
 * @method int|null getLastSync()
 * @method void setLastSync(?int $lastSync)
 * @method string|null getLastBackup()
 * @method void setLastBackup(?string $lastBackup)
 * @method string|null getCalendarUrl()
 * @method void setCalendarUrl(?string $calendarUrl)
 * @method int getSeenAt()
 * @method void setSeenAt(int $seenAt)
 */
final class ClientStatus extends Entity {
	protected int $tenantId = 0;
	protected string $uid = '';
	protected ?string $appVersion = null;
	protected ?int $lastSync = null;
	protected ?string $lastBackup = null;
	protected ?string $calendarUrl = null;
	protected int $seenAt = 0;

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('uid', Types::STRING);
		$this->addType('appVersion', Types::STRING);
		$this->addType('lastSync', Types::INTEGER);
		$this->addType('lastBackup', Types::STRING);
		$this->addType('calendarUrl', Types::STRING);
		$this->addType('seenAt', Types::INTEGER);
	}
}
