<?php

declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Migration;

use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\BackgroundJob\AbsenceMirror;
use OCA\TimeSister\BackgroundJob\ChainHead;
use OCA\TimeSister\BackgroundJob\EntryReader;
use OCA\TimeSister\BackgroundJob\ExchangeRates;
use OCA\TimeSister\BackgroundJob\IssueExpiry;
use OCA\TimeSister\BackgroundJob\Retention;
use OCA\TimeSister\BackgroundJob\WebhookDelivery;
use OCA\TimeSister\BackgroundJob\WeeklyBackup;
use OCP\BackgroundJob\IJobList;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Runs when the app is removed (Apps page “Remove”, `occ app:remove`):
 * drops what belongs to the app alone – its tables, its app config, its
 * background jobs, its migration rows and its app data. Nothing in the
 * people's files, no calendar, no account, no group (0.43.0).
 */
final class Uninstall implements IRepairStep {
	/** Every table the migrations created, newest dependents first. */
	public const TABLES = ['ts_inv_webhook_queue', 'ts_inv_ledger', 'ts_inv_hours', 'ts_inv_invoices', 'ts_entry_corrections', 'ts_entries',
		'ts_time_calendars', 'ts_counters', 'ts_backup_files', 'ts_backups', 'ts_backup_consent', 'ts_client_status', 'ts_absences',
		'ts_access', 'ts_members', 'ts_role_groups', 'ts_history', 'ts_records', 'ts_tenants'];

	/** @var list<class-string<\OCP\BackgroundJob\IJob>> */
	public const JOBS = [Retention::class, WeeklyBackup::class, AbsenceMirror::class, EntryReader::class, IssueExpiry::class,
		ChainHead::class, WebhookDelivery::class, ExchangeRates::class];

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $config,
		private IJobList $jobs,
		private IAppDataFactory $appDataFactory,
		private LoggerInterface $logger,
	) {
	}

	public function getName(): string {
		return 'TimeSister: remove the app’s tables, config, jobs and app data';
	}

	public function run(IOutput $output): void {
		$this->dropTables($output);
		foreach (self::JOBS as $job) {
			try {
				$this->jobs->remove($job);
			} catch (\Throwable $e) {
				$this->logger->warning('TimeSister uninstall: job not removed', ['app' => 'timesister', 'job' => $job, 'exception' => $e]);
			}
		}
		try {
			$this->config->deleteApp(Application::APP_ID);
		} catch (\Throwable $e) {
			$this->logger->warning('TimeSister uninstall: app config not removed', ['app' => 'timesister', 'exception' => $e]);
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('migrations')->where($qb->expr()->eq('app', $qb->createNamedParameter(Application::APP_ID)));
			$qb->executeStatement();
		} catch (\Throwable $e) {
			$this->logger->warning('TimeSister uninstall: migration rows not removed', ['app' => 'timesister', 'exception' => $e]);
		}
		try {
			foreach ($this->appDataFactory->get(Application::APP_ID)->getDirectoryListing() as $folder) {
				$folder->delete();
			}
		} catch (\Throwable $e) {
			$this->logger->warning('TimeSister uninstall: app data not removed', ['app' => 'timesister', 'exception' => $e]);
		}
		$output->info('TimeSister: tables, config, jobs and app data removed. Files, calendars, accounts and groups stay.');
	}

	private function dropTables(IOutput $output): void {
		$dropped = 0;
		foreach (self::TABLES as $t) {
			try {
				if ($this->db->tableExists($t)) {
					$this->db->dropTable($t);
					$dropped++;
				}
			} catch (\Throwable $e) {
				$this->logger->error('TimeSister uninstall: table not dropped', ['app' => 'timesister', 'table' => $t, 'exception' => $e]);
				$output->warning("TimeSister: table $t could not be dropped: " . $e->getMessage());
			}
		}
		$output->info("TimeSister: $dropped tables dropped.");
	}
}
