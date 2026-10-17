<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\BackgroundJob;

use OCA\TimeSister\Service\DocumentService;
use OCA\TimeSister\Service\ExpenseReportDocuments;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Every 15 minutes: documents still `pending` or `failed` are rendered
 * again, up to ten tries each (0.19.0; expense reports since 0.23.0). The
 * services log every failure.
 */
final class DocumentRetry extends TimedJob {
	public const MAX_PER_RUN = 50;

	public function __construct(
		ITimeFactory $time,
		private DocumentService $documents,
		private ExpenseReportDocuments $expenses,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(900);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run(mixed $argument): void {
		$n = $this->documents->retry(self::MAX_PER_RUN);
		if ($n['done'] + $n['failed'] > 0) {
			$this->logger->info('TimeSister: invoice documents – {done} rendered, {failed} failed', ['app' => 'timesister', 'done' => $n['done'], 'failed' => $n['failed']]);
		}
		// 0.23.0: the PDFs of fixed expense reports likewise.
		$e = $this->expenses->retry(self::MAX_PER_RUN, $this->time->getTime());
		if ($e['done'] + $e['failed'] > 0) {
			$this->logger->info('TimeSister: expense report documents – {done} rendered, {failed} failed', ['app' => 'timesister', 'done' => $e['done'], 'failed' => $e['failed']]);
		}
	}
}
