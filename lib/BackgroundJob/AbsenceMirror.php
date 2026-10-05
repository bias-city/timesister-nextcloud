<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\BackgroundJob;

use OCA\TimeSister\Service\AbsenceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Every 15 minutes: the absences the clients reported go into each team's
 * shared vacation calendar (`AbsenceService::mirrorAll`). One team failing
 * does not stop the others.
 */
final class AbsenceMirror extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private AbsenceService $absences,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(900);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run(mixed $argument): void {
		$n = $this->absences->mirrorAll();
		if ($n['written'] + $n['cancelled'] + $n['failed'] > 0) {
			$this->logger->info('TimeSister: vacation calendars – {written} written, {cancelled} cancelled, {failed} teams failed', [
				'app' => 'timesister', 'written' => $n['written'], 'cancelled' => $n['cancelled'], 'failed' => $n['failed'],
			]);
		}
	}
}
