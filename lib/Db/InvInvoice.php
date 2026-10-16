<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * An invoice issued on the server (0.18.0): the frozen content – issuer,
 * customer and positions as JSON, the three texts, the sums – and the
 * ledger entry of its issue. Amounts are DECIMAL and arrive as strings.
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method string getRkey()
 * @method void setRkey(string $rkey)
 * @method string getNumber()
 * @method void setNumber(string $number)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getProject()
 * @method void setProject(string $project)
 * @method string getBudget()
 * @method void setBudget(string $budget)
 * @method string|null getCustomer()
 * @method void setCustomer(?string $customer)
 * @method string getPeriodFrom()
 * @method void setPeriodFrom(string $periodFrom)
 * @method string getPeriodTo()
 * @method void setPeriodTo(string $periodTo)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method string getVat()
 * @method void setVat(string $vat)
 * @method string|null getIssuerJson()
 * @method void setIssuerJson(?string $issuerJson)
 * @method string|null getCustomerJson()
 * @method void setCustomerJson(?string $customerJson)
 * @method string|null getPositionsJson()
 * @method void setPositionsJson(?string $positionsJson)
 * @method string|null getGreeting()
 * @method void setGreeting(?string $greeting)
 * @method string|null getBody()
 * @method void setBody(?string $body)
 * @method string|null getFooter()
 * @method void setFooter(?string $footer)
 * @method string getNet()
 * @method void setNet(string $net)
 * @method string getVatAmount()
 * @method void setVatAmount(string $vatAmount)
 * @method string getGross()
 * @method void setGross(string $gross)
 * @method string getHours()
 * @method void setHours(string $hours)
 * @method string getIssuedOn()
 * @method void setIssuedOn(string $issuedOn)
 * @method string getIssuedBy()
 * @method void setIssuedBy(string $issuedBy)
 * @method string getDueOn()
 * @method void setDueOn(string $dueOn)
 * @method string|null getPaidOn()
 * @method void setPaidOn(?string $paidOn)
 * @method string|null getPaidBy()
 * @method void setPaidBy(?string $paidBy)
 * @method string|null getReverses()
 * @method void setReverses(?string $reverses)
 * @method string|null getReversedBy()
 * @method void setReversedBy(?string $reversedBy)
 * @method string|null getCredits()
 * @method void setCredits(?string $credits)
 * @method int|null getSeq()
 * @method void setSeq(?int $seq)
 * @method string|null getPrevHash()
 * @method void setPrevHash(?string $prevHash)
 * @method string|null getHash()
 * @method void setHash(?string $hash)
 * @method int|null getPdfFileId()
 * @method void setPdfFileId(?int $pdfFileId)
 * @method int|null getXmlFileId()
 * @method void setXmlFileId(?int $xmlFileId)
 * @method string getDocumentState()
 * @method void setDocumentState(string $documentState)
 * @method string|null getDocumentError()
 * @method void setDocumentError(?string $documentError)
 * @method string|null getDocumentPath()
 * @method void setDocumentPath(?string $documentPath)
 * @method string|null getDocumentSha256()
 * @method void setDocumentSha256(?string $documentSha256)
 * @method int getDocumentTries()
 * @method void setDocumentTries(int $documentTries)
 * @method int|null getCounterNo()
 * @method void setCounterNo(?int $counterNo)
 * @method string|null getQrReference()
 * @method void setQrReference(?string $qrReference)
 * @method string|null getXmlPath()
 * @method void setXmlPath(?string $xmlPath)
 * @method string|null getXmlSha256()
 * @method void setXmlSha256(?string $xmlSha256)
 * @method string|null getExternalJson()
 * @method void setExternalJson(?string $externalJson)
 * @method string|null getVatLinesJson()
 * @method void setVatLinesJson(?string $vatLinesJson)
 * @method string|null getPassThrough()
 * @method void setPassThrough(?string $passThrough)
 * @method string|null getTotal()
 * @method void setTotal(?string $total)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getModifiedAt()
 * @method void setModifiedAt(int $modifiedAt)
 */
final class InvInvoice extends Entity {
	public const ISSUED = 'issued';
	public const PAID = 'paid';
	public const CANCELLED = 'cancelled';
	public const DOCUMENT_PENDING = 'pending';
	public const DOCUMENT_DONE = 'done';
	public const DOCUMENT_FAILED = 'failed';

	protected int $tenantId = 0;
	protected string $rkey = '';
	protected string $number = '';
	// Defaults differ from every stored value: the mapper inserts only fields that changed.
	protected string $status = '';
	protected string $project = '';
	protected string $budget = '';
	protected ?string $customer = null;
	protected string $periodFrom = '';
	protected string $periodTo = '';
	protected string $currency = '';
	protected string $vat = '0';
	protected ?string $issuerJson = null;
	protected ?string $customerJson = null;
	protected ?string $positionsJson = null;
	protected ?string $greeting = null;
	protected ?string $body = null;
	protected ?string $footer = null;
	protected string $net = '0';
	protected string $vatAmount = '0';
	protected string $gross = '0';
	protected string $hours = '0';
	protected string $issuedOn = '';
	protected string $issuedBy = '';
	protected string $dueOn = '';
	protected ?string $paidOn = null;
	protected ?string $paidBy = null;
	protected ?string $reverses = null;
	protected ?string $reversedBy = null;
	protected ?string $credits = null;
	protected ?int $seq = null;
	protected ?string $prevHash = null;
	protected ?string $hash = null;
	protected ?int $pdfFileId = null;
	protected ?int $xmlFileId = null;
	protected string $documentState = '';
	// Documents (0.19.0): the last error, the stored file's path and hash, the tries.
	protected ?string $documentError = null;
	protected ?string $documentPath = null;
	protected ?string $documentSha256 = null;
	protected int $documentTries = 0;
	// 0.20.0: the counter number taken at issue, the QR reference, the XRechnung file, the hand-over to an accounting system.
	protected ?int $counterNo = null;
	protected ?string $qrReference = null;
	protected ?string $xmlPath = null;
	protected ?string $xmlSha256 = null;
	protected ?string $externalJson = null;
	// 0.21.0: net and VAT per rate (JSON), the pass-through items, the amount to pay; null before 0.21.0.
	protected ?string $vatLinesJson = null;
	protected ?string $passThrough = null;
	protected ?string $total = null;
	protected int $createdAt = 0;
	protected int $modifiedAt = 0;

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('rkey', Types::STRING);
		$this->addType('number', Types::STRING);
		$this->addType('status', Types::STRING);
		$this->addType('project', Types::STRING);
		$this->addType('budget', Types::STRING);
		$this->addType('customer', Types::STRING);
		$this->addType('periodFrom', Types::STRING);
		$this->addType('periodTo', Types::STRING);
		$this->addType('currency', Types::STRING);
		$this->addType('vat', Types::DECIMAL);
		$this->addType('issuerJson', Types::STRING);
		$this->addType('customerJson', Types::STRING);
		$this->addType('positionsJson', Types::STRING);
		$this->addType('greeting', Types::STRING);
		$this->addType('body', Types::STRING);
		$this->addType('footer', Types::STRING);
		$this->addType('net', Types::DECIMAL);
		$this->addType('vatAmount', Types::DECIMAL);
		$this->addType('gross', Types::DECIMAL);
		$this->addType('hours', Types::DECIMAL);
		$this->addType('issuedOn', Types::STRING);
		$this->addType('issuedBy', Types::STRING);
		$this->addType('dueOn', Types::STRING);
		$this->addType('paidOn', Types::STRING);
		$this->addType('paidBy', Types::STRING);
		$this->addType('reverses', Types::STRING);
		$this->addType('reversedBy', Types::STRING);
		$this->addType('credits', Types::STRING);
		$this->addType('seq', Types::INTEGER);
		$this->addType('prevHash', Types::STRING);
		$this->addType('hash', Types::STRING);
		$this->addType('pdfFileId', Types::INTEGER);
		$this->addType('xmlFileId', Types::INTEGER);
		$this->addType('documentState', Types::STRING);
		$this->addType('documentError', Types::STRING);
		$this->addType('documentPath', Types::STRING);
		$this->addType('documentSha256', Types::STRING);
		$this->addType('documentTries', Types::SMALLINT);
		$this->addType('counterNo', Types::INTEGER);
		$this->addType('qrReference', Types::STRING);
		$this->addType('xmlPath', Types::STRING);
		$this->addType('xmlSha256', Types::STRING);
		$this->addType('externalJson', Types::STRING);
		$this->addType('vatLinesJson', Types::STRING);
		$this->addType('passThrough', Types::DECIMAL);
		$this->addType('total', Types::DECIMAL);
		$this->addType('createdAt', Types::INTEGER);
		$this->addType('modifiedAt', Types::INTEGER);
	}

	/** Issued or paid: the invoice counts, its hours are billed. */
	public function isLive(): bool {
		return $this->status === self::ISSUED || $this->status === self::PAID;
	}
}
