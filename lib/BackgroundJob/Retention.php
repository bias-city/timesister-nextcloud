<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\BackgroundJob;

use OCA\TimeSister\Db\HistoryMapper;
use OCA\TimeSister\Service\BackupThinner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily: thin the history older than two years (the newest version of each
 * record always stays), thin calendar backups by the schedule (`Thinning`).
 * Only tables and files the app created itself.
 */
final class Retention extends TimedJob {
	public const HISTORY_DAYS = 730;

	public function __construct(
		ITimeFactory $time,
		private HistoryMapper $history,
		private BackupThinner $backups,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run(mixed $argument): void {
		$now = $this->time->getTime();
		$history = $this->history->prune($now - self::HISTORY_DAYS * 86400);
		$backups = $this->backups->thin(gmdate('Y-m-d', $now));
		if ($history > 0 || $backups > 0) {
			$this->logger->info('TimeSister: retention – {h} versions, {b} backups removed', [
				'app' => 'timesister', 'h' => $history, 'b' => $backups,
			]);
		}
	}
}
