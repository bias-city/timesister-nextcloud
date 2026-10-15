<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One billed occurrence of an issued invoice (0.18.0): the person, the
 * event, its start, the hours and rate as the client sent them, the amount
 * the server multiplied. Stays with the invoice for good (also after a
 * reversal: history).
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method int getInvoiceId()
 * @method void setInvoiceId(int $invoiceId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getEventUid()
 * @method void setEventUid(string $eventUid)
 * @method string getStart()
 * @method void setStart(string $start)
 * @method string getHours()
 * @method void setHours(string $hours)
 * @method string getRate()
 * @method void setRate(string $rate)
 * @method string getAmount()
 * @method void setAmount(string $amount)
 * @method string|null getCode()
 * @method void setCode(?string $code)
 */
final class InvHour extends Entity {
	protected int $tenantId = 0;
	protected int $invoiceId = 0;
	protected string $uid = '';
	protected string $eventUid = '';
	protected string $start = '';
	protected string $hours = '0';
	protected string $rate = '0';
	protected string $amount = '0';
	protected ?string $code = null;

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('invoiceId', Types::INTEGER);
		$this->addType('uid', Types::STRING);
		$this->addType('eventUid', Types::STRING);
		$this->addType('start', Types::STRING);
		$this->addType('hours', Types::DECIMAL);
		$this->addType('rate', Types::DECIMAL);
		$this->addType('amount', Types::DECIMAL);
		$this->addType('code', Types::STRING);
	}

	/** As in `GET /invoices/{key}/fixed`. @return array<string,mixed> */
	public function present(): array {
		return [
			'uid' => $this->getUid(),
			'event_uid' => $this->getEventUid(),
			'start' => $this->getStart(),
			'hours' => (float)$this->getHours(),
			'rate' => (float)$this->getRate(),
			'amount' => (float)$this->getAmount(),
			'code' => $this->getCode(),
		];
	}
}
