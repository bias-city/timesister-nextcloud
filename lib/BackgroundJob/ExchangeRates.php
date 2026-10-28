<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\BackgroundJob;

use OCA\TimeSister\Service\ExchangeRateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Every hour: the ECB rates of the days on which reimbursed expenses in a
 * foreign currency wait for a rate, for the teams with
 * `settings.exchange_rates` on (0.30.0); at most 50 days per run. The
 * service logs every failure.
 */
final class ExchangeRates extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ExchangeRateService $rates,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		// One run at a time: a second cron would only double the work.
		$this->setAllowParallelRuns(false);
	}

	protected function run(mixed $argument): void {
		$n = $this->rates->fetchDue();
		if ($n['days'] + $n['failed'] > 0) {
			$this->logger->info('TimeSister: exchange rates – {days} days fetched, {failed} failed', ['app' => 'timesister', 'days' => $n['days'], 'failed' => $n['failed']]);
		}
	}
}
