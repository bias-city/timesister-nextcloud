<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\BackgroundJob;

use OCA\TimeSister\Service\DocumentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Every 15 minutes: invoice documents still `pending` or `failed` are
 * rendered again, up to ten tries each (0.19.0). The service logs every
 * failure. Expense reports have no server document since 0.30.0.
 */
final class DocumentRetry extends TimedJob {
	public const MAX_PER_RUN = 50;

	public function __construct(
		ITimeFactory $time,
		private DocumentService $documents,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(900);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		// One run at a time: a second cron would only double the work.
		$this->setAllowParallelRuns(false);
	}

	protected function run(mixed $argument): void {
		$n = $this->documents->retry(self::MAX_PER_RUN);
		if ($n['done'] + $n['failed'] > 0) {
			$this->logger->info('TimeSister: invoice documents – {done} rendered, {failed} failed', ['app' => 'timesister', 'done' => $n['done'], 'failed' => $n['failed']]);
		}
	}
}
