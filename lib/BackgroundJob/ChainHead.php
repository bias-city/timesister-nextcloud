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
 * Daily: for every team with a ledger, the chain head (`chain-<day>.txt`)
 * goes into the invoices folder of the backup owner's files and, when the
 * team names an address, by mail – the external anchor of the chain
 * (0.19.0).
 */
final class ChainHead extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private DocumentService $documents,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		// One run at a time: a second cron would only double the work.
		$this->setAllowParallelRuns(false);
	}

	protected function run(mixed $argument): void {
		$n = $this->documents->chainHeads();
		if ($n['written'] + $n['mailed'] + $n['failed'] > 0) {
			$this->logger->info('TimeSister: chain head – {written} written, {mailed} mailed, {failed} failed', ['app' => 'timesister', 'written' => $n['written'], 'mailed' => $n['mailed'], 'failed' => $n['failed']]);
		}
	}
}
