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
 * Täglich: Verlauf älter als zwei Jahre ausdünnen (die neueste Fassung je
 * Datensatz bleibt immer), Kalendersicherungen nach der Staffel ausdünnen
 * (`Thinning`). Nur Tabellen und Dateien, die die App selbst angelegt hat.
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
			$this->logger->info('TimeSister: Aufbewahrung – {h} Fassungen, {b} Sicherungen entfernt', [
				'app' => 'timesister', 'h' => $history, 'b' => $backups,
			]);
		}
	}
}
