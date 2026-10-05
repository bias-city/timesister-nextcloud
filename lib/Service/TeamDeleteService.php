<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\AbsenceMapper;
use OCA\TimeSister\Db\AccessMapper;
use OCA\TimeSister\Db\BackupConsentMapper;
use OCA\TimeSister\Db\BackupFileMapper;
use OCA\TimeSister\Db\BackupMapper;
use OCA\TimeSister\Db\ClientStatusMapper;
use OCA\TimeSister\Db\HistoryMapper;
use OCA\TimeSister\Db\MemberMapper;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\RoleGroupMapper;
use OCA\TimeSister\Db\Tenant;
use OCA\TimeSister\Db\TenantMapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Deleting a team (0.10.1), also one with content. First a team ZIP is
 * stored exactly as before an import (protected in the app data, visible
 * with the backup owner); without it nothing is deleted. Then rows and
 * protected calendar backups go. The Nextcloud group, the accounts, their
 * time calendars and the visible `.ics` files with the backup owner stay.
 *
 * Not final: the unit test replaces the four steps that need Nextcloud.
 *
 * @psalm-suppress ClassMustBeFinal
 */
class TeamDeleteService {
	public function __construct(
		private IDBConnection $db,
		private TenantMapper $tenants,
		private RoleGroupMapper $roleGroups,
		private RecordMapper $records,
		private HistoryMapper $history,
		private BackupMapper $backups,
		private BackupFileMapper $backupFiles,
		private ClientStatusMapper $status,
		private BackupConsentMapper $consents,
		private MemberMapper $members,
		private AccessMapper $access,
		private AbsenceMapper $absences,
		private ProtectedStore $store,
		private TeamExportService $export,
		private TenantService $tenantService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param mixed $confirm the typed team name
	 * @return array<string,mixed> `{ id, deleted, backup, counts }`
	 * @throws ApiException 422 `confirm`, 409 when the ZIP cannot be stored
	 */
	public function delete(Tenant $t, mixed $confirm): array {
		TeamDeleteRules::checkConfirm($t->getName(), $confirm);
		$id = $t->getId();
		$content = $this->countContent($id);
		$backup = TeamDeleteRules::hasContent($content['records'], $content['backups']) ? $this->keep($t) : null;
		$counts = $this->wipe($t);
		$this->tenantService->reset();
		$this->wipeFiles($id);
		$this->logger->info('TimeSister: team {team} deleted', ['app' => 'timesister', 'team' => $id]);
		return TeamDeleteRules::answer($id, $backup, $counts);
	}

	/** @return array{records:int,backups:int} records including tombstones */
	protected function countContent(int $id): array {
		return ['records' => $this->records->stats($id)['all'], 'backups' => $this->backups->countByTenant($id)];
	}

	/**
	 * The ZIP, the same way as before an import.
	 *
	 * @return array{protected:string,visible:?string}
	 */
	protected function keep(Tenant $t): array {
		try {
			return $this->export->keepBeforeImport($t);
		} catch (\Throwable $e) {
			$this->logger->error('TimeSister: backup before deleting team {team} failed', ['app' => 'timesister', 'team' => $t->getId(), 'exception' => $e]);
			throw ApiException::conflict('The backup before deleting could not be written; nothing was deleted.');
		}
	}

	/**
	 * Every row of the team in one transaction, the team itself last.
	 *
	 * @return array<string,int> rows per table
	 */
	protected function wipe(Tenant $t): array {
		$id = $t->getId();
		$this->db->beginTransaction();
		try {
			$counts = [
				'records' => $this->records->deleteByTenant($id),
				'versions' => $this->history->deleteByTenant($id),
				'status' => $this->status->deleteByTenant($id),
				'absences' => $this->absences->deleteByTenant($id),
				'members' => $this->members->deleteByTenant($id),
				'access' => $this->access->deleteByTenant($id),
				'consents' => $this->consents->deleteByTenant($id),
				'groups' => $this->roleGroups->deleteByTenant($id),
				'backups' => $this->backups->deleteByTenant($id),
				// Only the tracking rows: the visible .ics files stay with the backup owner.
				'backup_files' => $this->backupFiles->deleteByTenant($id),
			];
			$this->tenants->delete($t);
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
		return $counts;
	}

	/** The protected calendar backups in the app data; `exports/` stays. */
	protected function wipeFiles(int $id): void {
		try {
			$this->store->deleteTeam($id);
		} catch (\Exception $e) {
			$this->logger->warning('TimeSister: protected backups of deleted team {team} not removed', ['app' => 'timesister', 'team' => $id, 'exception' => $e]);
		}
	}
}
