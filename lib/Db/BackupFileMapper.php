<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<BackupFile> */
final class BackupFileMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ts_backup_files', BackupFile::class);
	}

	/** Die gemerkte Datei an diesem Ort, oder null. */
	public function findAt(string $owner, string $path): ?BackupFile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($owner)))
			->andWhere($qb->expr()->eq('path', $qb->createNamedParameter($path)));
		$all = $this->findEntities($qb);
		return $all[0] ?? null;
	}

	/** Die zuletzt geschriebene Datei dieser Ablage für ein Konto. */
	public function latest(string $uid, string $target): ?BackupFile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('target', $qb->createNamedParameter($target)))
			->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<BackupFile> Kopien beim Admin zu einer Sicherung */
	public function adminCopies(int $tenantId, string $uid, string $day): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('target', $qb->createNamedParameter(BackupFile::ADMIN)))
			->andWhere($qb->expr()->eq('taken_on', $qb->createNamedParameter($day)));
		return $this->findEntities($qb);
	}

	/** @return list<BackupFile> alle Kopien im eigenen Ordner, für das Ausdünnen */
	public function allOwn(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('target', $qb->createNamedParameter(BackupFile::OWN)));
		return $this->findEntities($qb);
	}
}
