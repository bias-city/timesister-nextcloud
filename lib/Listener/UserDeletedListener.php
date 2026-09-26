<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Listener;

use OCA\TimeSister\Service\RecordService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Konto gelöscht: Die Personendatensätze bleiben – an ihnen hängen gebuchte
 * Stunden. Sie bekommen nur den Vermerk `account_deleted_at`; `data`,
 * Fassung und Revision bleiben unverändert.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
final class UserDeletedListener implements IEventListener {
	public function __construct(
		private RecordService $records,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}
		$n = $this->records->markAccountDeleted($event->getUser()->getUID());
		if ($n > 0) {
			// Ohne Kennung im Protokoll: keine Personaldaten.
			$this->logger->info('TimeSister: Konto gelöscht, {n} Personendatensätze vermerkt', ['app' => 'timesister', 'n' => $n]);
		}
	}
}
