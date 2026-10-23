<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * One calendar object of a person's time calendar or feed (0.17.0): the
 * iCal text as received, the codes the server read for the role filter,
 * and its state – `kept` (vanished, but billed), `deleted` (tombstone).
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method string getEventUid()
 * @method void setEventUid(string $eventUid)
 * @method string getObjectUri()
 * @method void setObjectUri(string $objectUri)
 * @method string getEtag()
 * @method void setEtag(string $etag)
 * @method string|null getIcs()
 * @method void setIcs(?string $ics)
 * @method string getSummary()
 * @method void setSummary(string $summary)
 * @method string|null getCodes()
 * @method void setCodes(?string $codes)
 * @method string|null getFirstStart()
 * @method void setFirstStart(?string $firstStart)
 * @method string|null getLastEnd()
 * @method void setLastEnd(?string $lastEnd)
 * @method string|null getLevel()
 * @method void setLevel(?string $level)
 * @method int getCancelled()
 * @method void setCancelled(int $cancelled)
 * @method int getKept()
 * @method void setKept(int $kept)
 * @method int getDeleted()
 * @method void setDeleted(int $deleted)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 * @method int getModifiedAt()
 * @method void setModifiedAt(int $modifiedAt)
 */
final class Entry extends Entity {
	protected int $tenantId = 0;
	protected string $uid = '';
	protected string $source = '';
	protected string $eventUid = '';
	protected string $objectUri = '';
	protected string $etag = '';
	protected ?string $ics = null;
	protected string $summary = '';
	protected ?string $codes = null;
	protected ?string $firstStart = null;
	protected ?string $lastEnd = null;
	protected ?string $level = null;
	protected int $cancelled = 0;
	protected int $kept = 0;
	protected int $deleted = 0;
	protected int $revision = 0;
	protected int $modifiedAt = 0;

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('uid', Types::STRING);
		$this->addType('source', Types::STRING);
		$this->addType('eventUid', Types::STRING);
		$this->addType('objectUri', Types::STRING);
		$this->addType('etag', Types::STRING);
		$this->addType('ics', Types::STRING);
		$this->addType('summary', Types::STRING);
		$this->addType('codes', Types::STRING);
		$this->addType('firstStart', Types::STRING);
		$this->addType('lastEnd', Types::STRING);
		$this->addType('level', Types::STRING);
		$this->addType('cancelled', Types::SMALLINT);
		$this->addType('kept', Types::SMALLINT);
		$this->addType('deleted', Types::SMALLINT);
		$this->addType('revision', Types::INTEGER);
		$this->addType('modifiedAt', Types::INTEGER);
	}

	/** Live: neither a tombstone nor the book's copy of a vanished object. */
	public function isLive(): bool {
		return $this->getDeleted() === 0 && $this->getKept() === 0;
	}

	/** @return list<string> */
	public function codeList(): array {
		$c = $this->getCodes();
		return $c === null || $c === '' ? [] : array_values(array_filter(explode(',', $c), static fn (string $x) => $x !== ''));
	}

	/**
	 * As in GET /entries.
	 *
	 * @return array<string,mixed>
	 */
	public function present(): array {
		return [
			'uid' => $this->getUid(),
			'source' => $this->getSource(),
			'event_uid' => $this->getEventUid(),
			'object_uri' => $this->getObjectUri(),
			'etag' => $this->getEtag(),
			'ics' => $this->getDeleted() === 1 ? '' : (string)$this->getIcs(),
			'codes' => $this->codeList(),
			'cancelled' => $this->getCancelled() === 1,
			'level' => $this->getLevel(),
			'kept' => $this->getKept() === 1,
			'deleted' => $this->getDeleted() === 1,
			'revision' => $this->getRevision(),
			'modified_at' => gmdate('Y-m-d\TH:i:s\Z', $this->getModifiedAt()),
		];
	}
}
