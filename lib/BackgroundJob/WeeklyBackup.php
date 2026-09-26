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
 * Stündlich prüfen: Jedes Teammitglied mit Freigabe bekommt je ISO-Woche
 * eine Server-Sicherung seines Zeitkalenders. Fehler je Konto protokolliert
 * der Dienst; die anderen Konten laufen weiter.
 */
final class WeeklyBackup extends TimedJob {
	/** Höchstens so viele Konten je Lauf; der Rest folgt in der nächsten Stunde. */
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
			$this->logger->info('TimeSister: Wochensicherung – {done} gesichert, {failed} fehlgeschlagen, {none} ohne Zeitkalender', [
				'app' => 'timesister', 'done' => $n['done'], 'failed' => $n['failed'], 'none' => $n['no_calendar'],
			]);
		}
	}
}
