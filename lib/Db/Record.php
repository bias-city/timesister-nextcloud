<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A record (person, location, project, client, setting).
 *
 * `data` is the JSON object as text, null for tombstones. `accounts` holds
 * a person's accounts as a JSON list and stays even in the tombstone, so
 * the person learns that their record was deleted.
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getRkey()
 * @method void setRkey(string $rkey)
 * @method string|null getData()
 * @method void setData(?string $data)
 * @method string|null getAccounts()
 * @method void setAccounts(?string $accounts)
 * @method int getVersion()
 * @method void setVersion(int $version)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 * @method int getDeleted()
 * @method void setDeleted(int $deleted)
 * @method string getModifiedBy()
 * @method void setModifiedBy(string $modifiedBy)
 * @method int getModifiedAt()
 * @method void setModifiedAt(int $modifiedAt)
 * @method int|null getAccountDeletedAt()
 * @method void setAccountDeletedAt(?int $accountDeletedAt)
 */
final class Record extends Entity {
	protected int $tenantId = 0;
	protected string $kind = '';
	protected string $rkey = '';
	protected ?string $data = null;
	protected ?string $accounts = null;
	protected int $version = 0;
	protected int $revision = 0;
	protected int $deleted = 0;
	protected string $modifiedBy = '';
	protected int $modifiedAt = 0;
	protected ?int $accountDeletedAt = null;

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('kind', Types::STRING);
		$this->addType('rkey', Types::STRING);
		$this->addType('data', Types::STRING);
		$this->addType('accounts', Types::STRING);
		$this->addType('version', Types::INTEGER);
		$this->addType('revision', Types::INTEGER);
		$this->addType('deleted', Types::INTEGER);
		$this->addType('modifiedBy', Types::STRING);
		$this->addType('modifiedAt', Types::INTEGER);
		$this->addType('accountDeletedAt', Types::INTEGER);
	}

	public function isTombstone(): bool {
		return $this->deleted === 1;
	}

	/** @return list<string> */
	public function accountList(): array {
		if ($this->accounts === null || $this->accounts === '') {
			return [];
		}
		$v = json_decode($this->accounts, true);
		return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
	}
}
