<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\BackgroundJob;

use OCA\TimeSister\Service\BackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Checked hourly: every team member with consent gets a server backup of
 * their time calendar for each ISO week. The service logs errors per
 * account; the other accounts keep running.
 */
final class WeeklyBackup extends TimedJob {
	/** At most this many accounts per run; the rest follow in the next hour. */
	public const MAX_PER_RUN = 100;

	public function __construct(
		ITimeFactory $time,
		private BackupService $backups,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run(mixed $argument): void {
		$n = $this->backups->weekly(self::MAX_PER_RUN);
		if ($n['done'] + $n['failed'] > 0) {
			$this->logger->info('TimeSister: weekly backup – {done} backed up, {failed} failed, {none} without time calendar', [
				'app' => 'timesister', 'done' => $n['done'], 'failed' => $n['failed'], 'none' => $n['no_calendar'],
			]);
		}
	}
}
