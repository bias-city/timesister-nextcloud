<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\BackgroundJob;

use OCA\TimeSister\Service\IssueService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Every 5 minutes (0.32.0, replaces DocumentRetry): reservations without a
 * document after 15 minutes are released like a cancel – number and chain
 * link free again, the record a draft. A reservation whose number is no
 * longer the counter's last stays until its PDF arrives.
 */
final class IssueExpiry extends TimedJob {
	public const MAX_PER_RUN = 100;

	public function __construct(
		ITimeFactory $time,
		private IssueService $issues,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		// One run at a time: a second cron would only double the work.
		$this->setAllowParallelRuns(false);
	}

	protected function run(mixed $argument): void {
		$n = $this->issues->expire(self::MAX_PER_RUN);
		if ($n['released'] + $n['kept'] > 0) {
			$this->logger->info('TimeSister: expired reservations – {released} released, {kept} kept', ['app' => 'timesister', 'released' => $n['released'], 'kept' => $n['kept']]);
		}
	}
}
