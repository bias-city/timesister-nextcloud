<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\BackgroundJob;

use OCA\TimeSister\Service\EntryFetcher;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Every 5 minutes: full reads of the named time calendars that are due
 * (marked, never read, a day old) and the feeds of external persons
 * (`EntryFetcher::readAll`). One source failing does not stop the others.
 */
final class EntryReader extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private EntryFetcher $fetcher,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run(mixed $argument): void {
		$n = $this->fetcher->readAll();
		if ($n['read'] + $n['failed'] > 0) {
			$this->logger->info('TimeSister: time entries – {read} sources read, {changed} changes, {failed} failed', [
				'app' => 'timesister', 'read' => $n['read'], 'changed' => $n['changed'], 'failed' => $n['failed'],
			]);
		}
	}
}
