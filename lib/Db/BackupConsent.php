<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * An account's consent for backup with the admin. No row: no consent.
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method int getConsent()
 * @method void setConsent(int $consent)
 * @method int|null getSince()
 * @method void setSince(?int $since)
 * @method int|null getRevokedAt()
 * @method void setRevokedAt(?int $revokedAt)
 * @method string|null getNotice()
 * @method void setNotice(?string $notice)
 * @method int|null getNoticeAt()
 * @method void setNoticeAt(?int $noticeAt)
 */
final class BackupConsent extends Entity {
	protected int $tenantId = 0;
	protected string $uid = '';
	protected int $consent = 0;
	protected ?int $since = null;
	protected ?int $revokedAt = null;
	protected ?string $notice = null;
	protected ?int $noticeAt = null;

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('uid', Types::STRING);
		$this->addType('consent', Types::SMALLINT);
		$this->addType('since', Types::INTEGER);
		$this->addType('revokedAt', Types::INTEGER);
		$this->addType('notice', Types::STRING);
		$this->addType('noticeAt', Types::INTEGER);
	}
}
