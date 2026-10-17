<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One entry of a team's invoice ledger (0.18.0): what happened to which
 * invoice when and by whom, the hash of its payload and the chain
 * (`prev_hash` = the previous entry's `hash`). Appended only.
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method int getSeq()
 * @method void setSeq(int $seq)
 * @method string getAt()
 * @method void setAt(string $at)
 * @method string getActor()
 * @method void setActor(string $actor)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method int getInvoiceId()
 * @method void setInvoiceId(int $invoiceId)
 * @method string getNumber()
 * @method void setNumber(string $number)
 * @method string|null getPayload()
 * @method void setPayload(?string $payload)
 * @method string getPayloadHash()
 * @method void setPayloadHash(string $payloadHash)
 * @method string getPrevHash()
 * @method void setPrevHash(string $prevHash)
 * @method string getHash()
 * @method void setHash(string $hash)
 */
final class InvLedger extends Entity {
	protected int $tenantId = 0;
	protected int $seq = 0;
	protected string $at = '';
	protected string $actor = '';
	protected string $action = '';
	protected int $invoiceId = 0;
	protected string $number = '';
	protected ?string $payload = null;
	protected string $payloadHash = '';
	protected string $prevHash = '';
	protected string $hash = '';

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('seq', Types::INTEGER);
		$this->addType('at', Types::STRING);
		$this->addType('actor', Types::STRING);
		$this->addType('action', Types::STRING);
		$this->addType('invoiceId', Types::INTEGER);
		$this->addType('number', Types::STRING);
		$this->addType('payload', Types::STRING);
		$this->addType('payloadHash', Types::STRING);
		$this->addType('prevHash', Types::STRING);
		$this->addType('hash', Types::STRING);
	}

	/** `invoice_id` goes into the INSERT also when it is 0 (an expense report's entry, 0.23.0). */
	public function keepInvoiceId(): void {
		$this->markFieldUpdated('invoiceId');
	}

	/**
	 * As in `GET /invoices/ledger` and for LedgerRules::verify().
	 *
	 * @return array{seq:int,at:string,actor:string,action:string,invoice:int,number:string,payload:?string,payload_hash:string,prev_hash:string,hash:string}
	 */
	public function present(): array {
		return [
			'seq' => $this->getSeq(),
			'at' => $this->getAt(),
			'actor' => $this->getActor(),
			'action' => $this->getAction(),
			'invoice' => $this->getInvoiceId(),
			'number' => $this->getNumber(),
			'payload' => $this->getPayload(),
			'payload_hash' => $this->getPayloadHash(),
			'prev_hash' => $this->getPrevHash(),
			'hash' => $this->getHash(),
		];
	}
}
