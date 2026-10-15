<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A webhook delivery (0.20.0): one row per hook and ledger entry of a
 * listed event – the address, the body as sent, the tries, when the next
 * one is due, the last error and when it was delivered.
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method int getLedgerSeq()
 * @method void setLedgerSeq(int $ledgerSeq)
 * @method string getUrl()
 * @method void setUrl(string $url)
 * @method string getEvent()
 * @method void setEvent(string $event)
 * @method string|null getBody()
 * @method void setBody(?string $body)
 * @method int getTries()
 * @method void setTries(int $tries)
 * @method int getNextAt()
 * @method void setNextAt(int $nextAt)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method int|null getDeliveredAt()
 * @method void setDeliveredAt(?int $deliveredAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
final class WebhookQueue extends Entity {
	protected int $tenantId = 0;
	protected int $ledgerSeq = 0;
	protected string $url = '';
	protected string $event = '';
	protected ?string $body = null;
	protected int $tries = 0;
	protected int $nextAt = 0;
	protected ?string $lastError = null;
	protected ?int $deliveredAt = null;
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('ledgerSeq', Types::INTEGER);
		$this->addType('url', Types::STRING);
		$this->addType('event', Types::STRING);
		$this->addType('body', Types::STRING);
		$this->addType('tries', Types::SMALLINT);
		$this->addType('nextAt', Types::INTEGER);
		$this->addType('lastError', Types::STRING);
		$this->addType('deliveredAt', Types::INTEGER);
		$this->addType('createdAt', Types::INTEGER);
	}
}
